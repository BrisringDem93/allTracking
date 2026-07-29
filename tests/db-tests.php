<?php
/**
 * Test RUNTIME della coda GA4 su un database MariaDB/MySQL REALE (usa-e-getta).
 *
 * Esegui: php tests/db-tests.php
 *
 * Verifica end-to-end contro un vero motore SQL: creazione tabella, deduplica
 * (UNIQUE + INSERT IGNORE), claim atomico anti-concorrenza, recupero elementi
 * "processing" bloccati, transizioni di stato (discarded per missing_client_id,
 * failed ritentabile per configurazione mancante). NON invia nulla in rete.
 *
 * Sicurezza: crea un database temporaneo dedicato e lo elimina alla fine. NON
 * tocca alcun sito WordPress esistente. Se la connessione DB non è disponibile,
 * il test stampa NOT RUN ed esce con codice 0 (non fallisce la pipeline).
 *
 * @package QuickTrackingIntegration\Tests
 */

require __DIR__ . '/wp-stubs.php';

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

// Stub cron non presenti in wp-stubs.
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook ) { return false; }
}
if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( $ts, $hook ) { return true; }
}

// --- Connessione DB (default XAMPP: root senza password su 127.0.0.1) ---
$host = getenv( 'ATI_DB_HOST' ) ?: '127.0.0.1';
$user = getenv( 'ATI_DB_USER' ) ?: 'root';
$pass = getenv( 'ATI_DB_PASS' );        // '' su XAMPP; non è un segreto reale.
$pass = ( false === $pass ) ? '' : $pass;
$port = (int) ( getenv( 'ATI_DB_PORT' ) ?: 3306 );
$dbn  = 'ati_ga4_tmp_test';

mysqli_report( MYSQLI_REPORT_OFF );
$mysqli = @mysqli_connect( $host, $user, $pass, '', $port );
if ( ! $mysqli ) {
	echo "NOT RUN: nessuna connessione MariaDB/MySQL disponibile ($host:$port).\n";
	echo "         Imposta ATI_DB_HOST/USER/PASS/PORT per eseguire i test DB.\n";
	exit( 0 );
}

// Database temporaneo dedicato.
@mysqli_query( $mysqli, "DROP DATABASE IF EXISTS `$dbn`" );
if ( ! @mysqli_query( $mysqli, "CREATE DATABASE `$dbn`" ) ) {
	echo "NOT RUN: impossibile creare il database temporaneo (permessi insufficienti).\n";
	mysqli_close( $mysqli );
	exit( 0 );
}
mysqli_select_db( $mysqli, $dbn );

/**
 * Shim minimale di $wpdb basato su mysqli reale.
 */
class ATI_Test_WPDB {
	public $prefix = 'wptest_';
	public $insert_id = 0;
	public $rows_affected = 0;
	public $last_error = '';
	private $db;

	public function __construct( $mysqli ) {
		$this->db = $mysqli;
	}
	public function get_charset_collate() {
		return 'DEFAULT CHARSET=utf8mb4';
	}
	public function prepare( $query ) {
		$args = array_slice( func_get_args(), 1 );
		if ( isset( $args[0] ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i  = 0;
		$db = $this->db;
		return preg_replace_callback( '/%[sdf]/', function ( $m ) use ( &$i, $args, $db ) {
			$val = array_key_exists( $i, $args ) ? $args[ $i ] : '';
			$i++;
			if ( '%d' === $m[0] ) {
				return (string) (int) $val;
			}
			if ( '%f' === $m[0] ) {
				return (string) (float) $val;
			}
			return "'" . mysqli_real_escape_string( $db, (string) $val ) . "'";
		}, $query );
	}
	public function query( $sql ) {
		$res = mysqli_query( $this->db, $sql );
		$this->last_error = mysqli_error( $this->db );
		if ( false === $res ) {
			return false;
		}
		$this->rows_affected = mysqli_affected_rows( $this->db );
		$this->insert_id     = mysqli_insert_id( $this->db );
		if ( $res instanceof mysqli_result ) {
			mysqli_free_result( $res );
			return true;
		}
		return $this->rows_affected;
	}
	public function get_results( $sql, $output = ARRAY_A ) {
		$res  = mysqli_query( $this->db, $sql );
		$rows = array();
		if ( $res instanceof mysqli_result ) {
			while ( $r = mysqli_fetch_assoc( $res ) ) {
				$rows[] = $r;
			}
			mysqli_free_result( $res );
		}
		return $rows;
	}
	public function get_row( $sql, $output = ARRAY_A ) {
		$rows = $this->get_results( $sql );
		return isset( $rows[0] ) ? $rows[0] : null;
	}
	public function get_col( $sql ) {
		$rows = $this->get_results( $sql );
		$out  = array();
		foreach ( $rows as $r ) {
			$out[] = reset( $r );
		}
		return $out;
	}
	public function get_var( $sql ) {
		$row = $this->get_row( $sql );
		return $row ? reset( $row ) : null;
	}
}

global $wpdb;
$wpdb = new ATI_Test_WPDB( $mysqli );

$base = dirname( __DIR__ ) . '/includes/ga4/';
require $base . 'class-ati-event.php';
require $base . 'class-ati-ga4-config.php';
require $base . 'class-ati-ga4-endpoints.php';
require $base . 'class-ati-ga4-adapter.php';
require $base . 'class-ati-event-queue.php';
require $base . 'class-ati-event-deduplicator.php';

$pass_n = 0;
$fail_n = 0;
function ok( $c, $m ) {
	global $pass_n, $fail_n;
	if ( $c ) { $pass_n++; echo "  PASS  $m\n"; } else { $fail_n++; echo "  FAIL  $m\n"; }
}

echo "DB runtime test su MariaDB reale (database temporaneo: $dbn)\n";
$table = ATI_Event_Queue::table();

// 1. Creazione tabella (DDL reale).
$wpdb->query( ATI_Event_Queue::schema_sql() );
$exists = $wpdb->get_var( "SHOW TABLES LIKE '$table'" );
ok( $exists === $table, "Tabella coda creata ($table)" );
$cols = $wpdb->get_col( "SHOW COLUMNS FROM $table" );
ok( in_array( 'reason_code', $cols, true ), 'Colonna reason_code presente' );

// 2. Enqueue + deduplica (stesso evento due volte -> 1 riga).
$ev = ATI_Event::from_array( array(
	'event_id'   => 'evt_dup_1',
	'event_name' => 'generate_lead',
	'client_id'  => '111.222',
	'session_id' => '1700000000',
	'params'     => array( 'business_area' => 'acquisizione_immobili' ),
) );
$id1 = ATI_Event_Queue::enqueue( $ev, 'ga4' );
$id2 = ATI_Event_Queue::enqueue( $ev, 'ga4' );
$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE event_id='evt_dup_1'" );
ok( is_int( $id1 ) && $id1 > 0, 'Primo enqueue inserisce la riga' );
ok( false === $id2, 'Secondo enqueue (duplicato) NON inserisce (INSERT IGNORE)' );
ok( 1 === $count, 'Una sola riga per lo stesso event_id (un solo invio)' );
ok( true === ATI_Event_Deduplicator::is_duplicate( 'evt_dup_1', 'generate_lead', 'ga4' ), 'is_duplicate=true per evento già accodato' );
ok( false === ATI_Event_Deduplicator::is_duplicate( 'evt_new', 'generate_lead', 'ga4' ), 'is_duplicate=false per evento nuovo' );

// 3. Claim atomico anti-concorrenza (due worker sulla stessa riga pending).
$now = gmdate( 'Y-m-d H:i:s' );
$c1 = $wpdb->query( $wpdb->prepare( "UPDATE $table SET status='processing', locked_at=%s WHERE id=%d AND status='pending'", $now, $id1 ) );
$c2 = $wpdb->query( $wpdb->prepare( "UPDATE $table SET status='processing', locked_at=%s WHERE id=%d AND status='pending'", $now, $id1 ) );
ok( 1 === (int) $c1 && 0 === (int) $c2, 'Claim atomico: solo un worker acquisisce la riga (no doppio invio)' );

// 4. Recupero elementi stuck in processing (crash/timeout).
$old = gmdate( 'Y-m-d H:i:s', time() - 600 ); // oltre STUCK_SECONDS (300).
$wpdb->query( $wpdb->prepare( "UPDATE $table SET status='processing', locked_at=%s WHERE id=%d", $old, $id1 ) );
$reclaimed = ATI_Event_Queue::reclaim_stuck();
$st = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $table WHERE id=%d", $id1 ) );
ok( $reclaimed >= 1 && 'pending' === $st, 'Elemento stuck recuperato: processing -> pending' );

// 5. deliver(): client_id assente -> discarded/missing_client_id (mai sent, nessuna rete).
$deliver = new ReflectionMethod( 'ATI_Event_Queue', 'deliver' );
$deliver->setAccessible( true );

$evNoCid = ATI_Event::from_array( array( 'event_id' => 'evt_nocid', 'event_name' => 'generate_lead', 'client_id' => '' ) );
ATI_Event_Queue::enqueue( $evNoCid, 'ga4' );
$rowNoCid = $wpdb->get_row( "SELECT * FROM $table WHERE event_id='evt_nocid'" );
$deliver->invoke( null, $rowNoCid );
$rowNoCid2 = $wpdb->get_row( "SELECT * FROM $table WHERE event_id='evt_nocid'" );
ok( 'discarded' === $rowNoCid2['status'] && 'missing_client_id' === $rowNoCid2['reason_code'], 'deliver senza client_id -> discarded (missing_client_id), mai sent' );

// 6. deliver(): client_id presente ma configurazione assente -> failed ritentabile (pending), mai sent.
$GLOBALS['__ati_opts']['ati_ga4_server_id']  = 'G-TEST';
$GLOBALS['__ati_opts']['ati_ga4_api_secret'] = ''; // secret assente -> missing_configuration.
$evCfg = ATI_Event::from_array( array( 'event_id' => 'evt_cfg', 'event_name' => 'generate_lead', 'client_id' => '111.222' ) );
ATI_Event_Queue::enqueue( $evCfg, 'ga4' );
$rowCfg = $wpdb->get_row( "SELECT * FROM $table WHERE event_id='evt_cfg'" );
$deliver->invoke( null, $rowCfg );
$rowCfg2 = $wpdb->get_row( "SELECT * FROM $table WHERE event_id='evt_cfg'" );
ok( 'pending' === $rowCfg2['status'] && 'missing_configuration' === $rowCfg2['reason_code'] && 1 === (int) $rowCfg2['attempts'], 'deliver senza secret -> failed ritentabile (pending, attempts=1), mai sent' );

// 7. counts() e discarded_reasons() reali.
$counts = ATI_Event_Queue::counts();
ok( $counts['discarded'] >= 1, 'counts(): discarded conteggiato' );
$reasons = ATI_Event_Queue::discarded_reasons();
ok( isset( $reasons['missing_client_id'] ) && $reasons['missing_client_id'] >= 1, 'discarded_reasons(): missing_client_id presente' );

// --- Pulizia: elimina il database temporaneo ---
mysqli_query( $mysqli, "DROP DATABASE IF EXISTS `$dbn`" );
mysqli_close( $mysqli );

echo "\n---------------------------------------\n";
echo "DB RUNTIME: $pass_n PASS / $fail_n FAIL\n";
exit( $fail_n > 0 ? 1 : 0 );
