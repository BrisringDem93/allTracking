<?php
/**
 * ATI_Event_Queue — Coda asincrona affidabile per gli eventi GA4.
 *
 * L'invio a GA4 non blocca il salvataggio del lead: gli eventi vengono accodati
 * e processati da un worker WP-Cron (o Action Scheduler se disponibile).
 *
 * La tabella impone un vincolo UNIQUE sulla chiave di deduplica
 * (event_id + event_name + destination), garantendo che lo stesso evento
 * venga inviato una sola volta anche in caso di richieste concorrenti.
 *
 * NESSUNA PII viene memorizzata: il payload contiene solo dati neutrali.
 *
 * @package QuickTrackingIntegration\GA4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coda eventi.
 */
class ATI_Event_Queue {

	const CRON_HOOK      = 'ati_ga4_process_queue';
	const MAX_ATTEMPTS   = 5;
	const BATCH_SIZE     = 20;
	const STUCK_SECONDS  = 300; // Recupero elementi bloccati in "processing".
	const RETENTION_DAYS = 7;   // Pulizia record terminali.

	/**
	 * Nome tabella coda.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'ati_event_queue';
	}

	/**
	 * Crea/aggiorna la tabella della coda.
	 *
	 * @return void
	 */
	public static function install_table() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( self::schema_sql() );
	}

	/**
	 * DDL della tabella coda (usato da install_table e dai test DB).
	 *
	 * @return string
	 */
	public static function schema_sql() {
		global $wpdb;
		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		return "CREATE TABLE $table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			dedup_key char(32) NOT NULL,
			event_id varchar(191) NOT NULL DEFAULT '',
			event_name varchar(64) NOT NULL DEFAULT '',
			destination varchar(32) NOT NULL DEFAULT 'ga4',
			payload longtext NOT NULL,
			status varchar(16) NOT NULL DEFAULT 'pending',
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			next_attempt_at datetime NOT NULL,
			locked_at datetime DEFAULT NULL,
			last_error varchar(300) NOT NULL DEFAULT '',
			reason_code varchar(40) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY dedup_key (dedup_key),
			KEY status_next (status, next_attempt_at)
		) $collate;";
	}

	/**
	 * Chiave di deduplica.
	 *
	 * @param string $event_id    Event id.
	 * @param string $event_name  Nome evento.
	 * @param string $destination Destinazione.
	 * @return string
	 */
	public static function dedup_key( $event_id, $event_name, $destination ) {
		return md5( $event_id . '|' . $event_name . '|' . $destination );
	}

	/**
	 * Accoda un evento. Ritorna false se già presente (deduplicato).
	 *
	 * @param ATI_Event $event       Evento neutrale.
	 * @param string    $destination Destinazione (default ga4).
	 * @return int|false ID inserito oppure false se duplicato/errore.
	 */
	public static function enqueue( ATI_Event $event, $destination = 'ga4' ) {
		global $wpdb;

		$now       = self::now();
		$dedup_key = self::dedup_key( $event->event_id, $event->event_name, $destination );
		$payload   = wp_json_encode( $event->to_array() );
		$table     = self::table();

		// INSERT IGNORE: in caso di chiave duplicata non inserisce e non genera errore.
		$sql = $wpdb->prepare(
			"INSERT IGNORE INTO $table
				(dedup_key, event_id, event_name, destination, payload, status, attempts, created_at, updated_at, next_attempt_at)
			VALUES (%s, %s, %s, %s, %s, 'pending', 0, %s, %s, %s)",
			$dedup_key,
			$event->event_id,
			$event->event_name,
			$destination,
			$payload,
			$now,
			$now,
			$now
		);

		$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL

		if ( 0 === (int) $wpdb->rows_affected ) {
			/** L'evento era già presente entro la finestra di deduplica. */
			do_action( 'ati_event_deduplicated', $event, $destination );
			return false;
		}

		$id = (int) $wpdb->insert_id;

		/**
		 * Evento accodato.
		 *
		 * @param ATI_Event $event       Evento.
		 * @param int       $id          ID coda.
		 * @param string    $destination Destinazione.
		 */
		do_action( 'ati_event_queued', $event, $id, $destination );

		self::schedule_soon();

		return $id;
	}

	/**
	 * Processa un batch della coda. Idempotente e sicuro in concorrenza.
	 *
	 * @return int Numero di eventi elaborati con successo.
	 */
	public static function process() {
		global $wpdb;
		$table = self::table();

		// 1. Recupera gli elementi bloccati in "processing" oltre la soglia.
		self::reclaim_stuck();

		// 2. Seleziona i candidati pronti.
		$now = self::now();
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM $table
				 WHERE status='pending' AND next_attempt_at <= %s
				 ORDER BY id ASC LIMIT %d",
				$now,
				self::BATCH_SIZE
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL

		$processed = 0;
		foreach ( (array) $ids as $id ) {
			$id = (int) $id;

			// 3. Claim atomico: solo un processo riesce a passare pending -> processing.
			$claimed = $wpdb->query(
				$wpdb->prepare(
					"UPDATE $table SET status='processing', locked_at=%s, updated_at=%s
					 WHERE id=%d AND status='pending'",
					self::now(),
					self::now(),
					$id
				)
			); // phpcs:ignore WordPress.DB.PreparedSQL

			if ( 1 !== (int) $claimed ) {
				continue; // Un altro worker l'ha già preso.
			}

			$row = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM $table WHERE id=%d", $id ),
				ARRAY_A
			); // phpcs:ignore WordPress.DB.PreparedSQL

			if ( ! $row ) {
				continue;
			}

			if ( self::deliver( $row ) ) {
				$processed++;
			}
		}

		self::cleanup();

		return $processed;
	}

	/**
	 * Recupera gli elementi rimasti in "processing" oltre la soglia (crash/timeout).
	 *
	 * @return int Righe recuperate.
	 */
	public static function reclaim_stuck() {
		global $wpdb;
		$table        = self::table();
		$stuck_before = self::now( -1 * self::STUCK_SECONDS );
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE $table SET status='pending', locked_at=NULL, updated_at=%s
				 WHERE status='processing' AND locked_at IS NOT NULL AND locked_at < %s",
				self::now(),
				$stuck_before
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Decisione PURA sullo stato finale di consegna (testabile senza DB/HTTP).
	 *
	 * Regole:
	 * - client_id assente        -> discarded (missing_client_id): NON recuperabile
	 *   nel worker (il contesto di richiesta è perso). Mai 'sent'.
	 * - configurazione assente   -> failed (retryable: il secret potrebbe tornare).
	 * - invio ok                 -> sent.
	 * - errore trasporto/HTTP    -> failed se attempts>=MAX, altrimenti retry (pending).
	 *
	 * @param bool  $has_client_id L'evento ha un client_id reale.
	 * @param array $send_result   Risultato di ATI_GA4_Adapter::send o null se non inviato.
	 * @param int   $attempts_next Numero di tentativo (attempts corrente + 1).
	 * @return array{status:string,reason_code:string,last_error:string,retry:bool}
	 */
	public static function classify_delivery( $has_client_id, $send_result, $attempts_next ) {
		// 1. Nessun client_id reale: mai inviato, mai 'sent', nessuna identità inventata.
		if ( ! $has_client_id ) {
			return array(
				'status'      => 'discarded',
				'reason_code' => 'missing_client_id',
				'last_error'  => '',
				'retry'       => false,
			);
		}

		$ok    = is_array( $send_result ) && ! empty( $send_result['ok'] );
		$error = ( is_array( $send_result ) && isset( $send_result['error'] ) ) ? (string) $send_result['error'] : 'unknown';

		// 2. Invio realmente eseguito e accettato dall'adapter.
		if ( $ok ) {
			return array(
				'status'      => 'sent',
				'reason_code' => '',
				'last_error'  => '',
				'retry'       => false,
			);
		}

		// 3. Configurazione mancante: ritentabile (non è un errore permanente del payload).
		$reason = ( 'missing_configuration' === $error ) ? 'missing_configuration' : 'delivery_error';

		// 4. Errore ritentabile fino a MAX_ATTEMPTS, poi failed terminale.
		if ( $attempts_next >= self::MAX_ATTEMPTS ) {
			return array(
				'status'      => 'failed',
				'reason_code' => $reason,
				'last_error'  => substr( $error, 0, 300 ),
				'retry'       => false,
			);
		}

		return array(
			'status'      => 'failed', // stato transitorio: sarà riportato a pending per il retry
			'reason_code' => $reason,
			'last_error'  => substr( $error, 0, 300 ),
			'retry'       => true,
		);
	}

	/**
	 * Consegna un singolo record ed aggiorna il suo stato.
	 *
	 * @param array $row Riga della coda.
	 * @return bool True solo se realmente inviato (status sent).
	 */
	protected static function deliver( array $row ) {
		global $wpdb;
		$table = self::table();

		$data  = json_decode( (string) $row['payload'], true );
		$event = ATI_Event::from_array( is_array( $data ) ? $data : array() );

		$has_client_id = ( '' !== (string) $event->client_id );

		// Non chiamare l'adapter se manca il client_id: l'evento non è inviabile.
		$send_result = $has_client_id ? ATI_GA4_Adapter::send( $event ) : null;

		$attempts_next = (int) $row['attempts'] + 1;
		$decision      = self::classify_delivery( $has_client_id, $send_result, $attempts_next );

		$destination = self::DESTINATION_FROM_ROW( $row );

		// --- Applica lo stato deciso ---
		if ( 'sent' === $decision['status'] ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE $table SET status='sent', locked_at=NULL, updated_at=%s, last_error='', reason_code='' WHERE id=%d",
					self::now(),
					(int) $row['id']
				)
			); // phpcs:ignore WordPress.DB.PreparedSQL
			do_action( 'ati_event_sent', $event, $destination );
			return true;
		}

		if ( 'discarded' === $decision['status'] ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE $table SET status='discarded', locked_at=NULL, updated_at=%s, reason_code=%s, last_error=%s WHERE id=%d",
					self::now(),
					$decision['reason_code'],
					$decision['last_error'],
					(int) $row['id']
				)
			); // phpcs:ignore WordPress.DB.PreparedSQL
			/** Evento scartato definitivamente (es. missing_client_id). */
			do_action( 'ati_event_discarded', $event, $decision['reason_code'] );
			return false;
		}

		// failed: terminale oppure retry con backoff.
		if ( empty( $decision['retry'] ) ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE $table SET status='failed', attempts=%d, locked_at=NULL, updated_at=%s, reason_code=%s, last_error=%s WHERE id=%d",
					$attempts_next,
					self::now(),
					$decision['reason_code'],
					$decision['last_error'],
					(int) $row['id']
				)
			); // phpcs:ignore WordPress.DB.PreparedSQL
			do_action( 'ati_event_failed', $event, $decision['last_error'] );
			return false;
		}

		// Retry: backoff progressivo 1,2,4,8... minuti, torna a pending.
		$delay = (int) min( 3600, 60 * pow( 2, $attempts_next - 1 ) );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE $table SET status='pending', attempts=%d, locked_at=NULL, updated_at=%s, next_attempt_at=%s, reason_code=%s, last_error=%s WHERE id=%d",
				$attempts_next,
				self::now(),
				self::now( $delay ),
				$decision['reason_code'],
				$decision['last_error'],
				(int) $row['id']
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL

		self::schedule_soon();
		return false;
	}

	/**
	 * Estrae la destinazione dalla riga.
	 *
	 * @param array $row Riga.
	 * @return string
	 */
	protected static function DESTINATION_FROM_ROW( array $row ) {
		return isset( $row['destination'] ) ? (string) $row['destination'] : 'ga4';
	}

	/**
	 * Rimuove i record terminali oltre il periodo di ritenzione.
	 *
	 * @return void
	 */
	public static function cleanup() {
		global $wpdb;
		$table  = self::table();
		$before = self::now( -1 * self::RETENTION_DAYS * DAY_IN_SECONDS );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM $table WHERE status IN ('sent','discarded') AND updated_at < %s",
				$before
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Conteggi per stato (pagina diagnostica).
	 *
	 * @return array<string,int>
	 */
	public static function counts() {
		global $wpdb;
		$table  = self::table();
		$rows   = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM $table GROUP BY status", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$counts = array(
			'pending'    => 0,
			'processing' => 0,
			'sent'       => 0,
			'failed'     => 0,
			'discarded'  => 0,
		);
		foreach ( (array) $rows as $r ) {
			$counts[ $r['status'] ] = (int) $r['n'];
		}
		return $counts;
	}

	/**
	 * Conteggio degli eventi scartati raggruppati per reason_code.
	 *
	 * @return array<string,int>
	 */
	public static function discarded_reasons() {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results(
			"SELECT reason_code, COUNT(*) AS n FROM $table WHERE status='discarded' GROUP BY reason_code",
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL
		$out = array();
		foreach ( (array) $rows as $r ) {
			$code         = '' !== (string) $r['reason_code'] ? (string) $r['reason_code'] : 'unknown';
			$out[ $code ] = (int) $r['n'];
		}
		return $out;
	}

	/**
	 * Rimette in coda gli eventi falliti (retry manuale).
	 *
	 * @return int Righe aggiornate.
	 */
	public static function retry_failed() {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE $table SET status='pending', attempts=0, next_attempt_at=%s, updated_at=%s, last_error='' WHERE status='failed'",
				self::now(),
				self::now()
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Elimina gli eventi falliti (azione manuale).
	 *
	 * @return int Righe eliminate.
	 */
	public static function delete_failed() {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->query( "DELETE FROM $table WHERE status='failed'" ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Pianifica un'esecuzione ravvicinata del worker (idempotente).
	 *
	 * @return void
	 */
	public static function schedule_soon() {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			// Action Scheduler se disponibile (senza dipendenza obbligatoria).
			if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::CRON_HOOK ) ) {
				as_enqueue_async_action( self::CRON_HOOK );
			}
			return;
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 30, self::CRON_HOOK );
		}
	}

	/**
	 * Datetime UTC (offset opzionale in secondi).
	 *
	 * @param int $offset Offset in secondi.
	 * @return string
	 */
	protected static function now( $offset = 0 ) {
		return gmdate( 'Y-m-d H:i:s', time() + (int) $offset );
	}
}
