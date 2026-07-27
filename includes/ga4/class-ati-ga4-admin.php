<?php
/**
 * ATI_GA4_Admin — Pagina di amministrazione GA4 server-side.
 *
 * Gestisce: configurazione GA4 server, classificazione del progetto, mapping form,
 * API secret mascherato (mai esposto), test di validazione, diagnostica coda.
 *
 * @package QuickTrackingIntegration\GA4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pagina admin GA4.
 */
class ATI_GA4_Admin {

	const GROUP    = 'ati_ga4_settings';
	const PAGE     = 'ati-ga4-settings';
	const NONCE    = 'ati_ga4_admin';

	/**
	 * Aggancia gli hook admin.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'wp_ajax_ati_ga4_test_config', array( __CLASS__, 'ajax_test_config' ) );
		add_action( 'admin_post_ati_ga4_queue_action', array( __CLASS__, 'handle_queue_action' ) );
	}

	/**
	 * Menu.
	 *
	 * @return void
	 */
	public static function add_menu() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		add_options_page(
			'GA4 Server-Side',
			'GA4 Server-Side',
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Registrazione impostazioni.
	 *
	 * @return void
	 */
	public static function register_settings() {
		$text = array( 'sanitize_callback' => 'sanitize_text_field' );

		register_setting( self::GROUP, 'ati_ga4_server_id', $text );
		register_setting( self::GROUP, 'ati_enable_ga4_server', $text );
		register_setting( self::GROUP, 'ati_ga4_confirmed_enabled', $text );
		register_setting( self::GROUP, 'ati_ga4_server_pageview', $text );
		register_setting( self::GROUP, 'ati_ga4_region', array( 'sanitize_callback' => array( __CLASS__, 'sanitize_region' ) ) );
		register_setting( self::GROUP, 'ati_ga4_analytics_consent_mode', array( 'sanitize_callback' => array( __CLASS__, 'sanitize_consent_mode' ) ) );
		register_setting( self::GROUP, 'ati_ga4_missing_cid_policy', array( 'sanitize_callback' => array( __CLASS__, 'sanitize_policy' ) ) );
		register_setting( self::GROUP, 'ati_ga4_dedup_ttl', array( 'sanitize_callback' => 'absint' ) );
		register_setting( self::GROUP, 'ati_ga4_enable_submit_attempt', $text );
		register_setting( self::GROUP, 'ati_ga4_lead_trigger', array( 'sanitize_callback' => array( __CLASS__, 'sanitize_lead_trigger' ) ) );
		register_setting( self::GROUP, 'ati_ga4_debug_mode', $text );
		register_setting( self::GROUP, 'ati_analytics_cookie_name', $text );

		register_setting( self::GROUP, 'ati_class_business_area', array( 'sanitize_callback' => array( 'ATI_Project_Classification', 'sanitize_slug' ) ) );
		register_setting( self::GROUP, 'ati_class_service_type', array( 'sanitize_callback' => array( 'ATI_Project_Classification', 'sanitize_slug' ) ) );
		register_setting( self::GROUP, 'ati_class_audience_type', array( 'sanitize_callback' => array( 'ATI_Project_Classification', 'sanitize_slug' ) ) );
		register_setting( self::GROUP, 'ati_class_site_section', array( 'sanitize_callback' => array( 'ATI_Project_Classification', 'sanitize_slug' ) ) );

		register_setting( self::GROUP, 'ati_ga4_form_map', array( 'sanitize_callback' => array( __CLASS__, 'sanitize_form_map' ) ) );

		// Secret: sanitizer che preserva il valore quando il campo è vuoto/mascherato.
		register_setting( self::GROUP, 'ati_ga4_api_secret', array( 'sanitize_callback' => array( __CLASS__, 'sanitize_api_secret' ) ) );
	}

	/**
	 * @param string $v Valore.
	 * @return string
	 */
	public static function sanitize_region( $v ) {
		return ( 'global' === $v ) ? 'global' : 'eu';
	}

	/**
	 * @param string $v Valore.
	 * @return string
	 */
	public static function sanitize_consent_mode( $v ) {
		$allowed = array( 'auto', 'always' );
		return in_array( $v, $allowed, true ) ? $v : 'auto';
	}

	/**
	 * @param string $v Valore.
	 * @return string
	 */
	public static function sanitize_policy( $v ) {
		return ( 'discard' === $v ) ? 'discard' : 'queue';
	}

	/**
	 * @param string $v Valore.
	 * @return string
	 */
	public static function sanitize_lead_trigger( $v ) {
		return ( 'submit' === $v ) ? 'submit' : 'server';
	}

	/**
	 * Sanitizza la tabella di mapping form.
	 *
	 * @param mixed $rows Righe dal form.
	 * @return array
	 */
	public static function sanitize_form_map( $rows ) {
		$out = array();
		if ( ! is_array( $rows ) ) {
			return $out;
		}
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$provider = isset( $row['provider'] ) ? sanitize_key( $row['provider'] ) : '';
			$form_id  = isset( $row['form_id'] ) ? sanitize_text_field( $row['form_id'] ) : '';
			if ( '' === $form_id && '' === $provider ) {
				continue; // Riga vuota.
			}
			$out[] = array(
				'provider'      => $provider,
				'form_id'       => $form_id,
				'form_name'     => isset( $row['form_name'] ) ? ATI_Project_Classification::sanitize_slug( $row['form_name'] ) : '',
				'business_area' => isset( $row['business_area'] ) ? ATI_Project_Classification::sanitize_slug( $row['business_area'] ) : '',
				'service_type'  => isset( $row['service_type'] ) ? ATI_Project_Classification::sanitize_slug( $row['service_type'] ) : '',
				'audience_type' => isset( $row['audience_type'] ) ? ATI_Project_Classification::sanitize_slug( $row['audience_type'] ) : '',
				'site_section'  => isset( $row['site_section'] ) ? ATI_Project_Classification::sanitize_slug( $row['site_section'] ) : '',
				'enabled'       => ! empty( $row['enabled'] ) ? 1 : 0,
			);
		}
		return $out;
	}

	/**
	 * Sanitizza l'API secret senza mai cancellarlo per un campo vuoto.
	 * - Campo vuoto o mascherato: conserva il valore esistente.
	 * - Checkbox "rimuovi": cancella esplicitamente.
	 * - Se il segreto è definito da costante: l'opzione non viene toccata.
	 *
	 * @param string $value Valore inviato.
	 * @return string
	 */
	public static function sanitize_api_secret( $value ) {
		$existing = (string) get_option( 'ati_ga4_api_secret', '' );

		// Rimozione esplicita.
		if ( ! empty( $_POST['ati_ga4_remove_secret'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return '';
		}

		$value = trim( (string) $value );

		// Vuoto o mascherato: mantieni il valore esistente.
		if ( '' === $value || false !== strpos( $value, '•' ) || '********' === $value ) {
			return $existing;
		}

		return sanitize_text_field( $value );
	}

	/**
	 * Raccoglie lo stato di versione/deploy per l'indicatore diagnostico.
	 *
	 * @return array
	 */
	public static function version_status() {
		global $wpdb;

		$header_version = '';
		if ( function_exists( 'get_plugin_data' ) ) {
			// Non sempre disponibile fuori dalle pagine plugin; caricamento difensivo.
			$main = dirname( __DIR__, 2 ) . '/plugin.php';
			if ( is_readable( $main ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
				$data           = get_plugin_data( $main, false, false );
				$header_version = isset( $data['Version'] ) ? $data['Version'] : '';
			}
		}

		// Colonna reason_code presente? (prova che lo schema v2 è applicato).
		$table    = ATI_Event_Queue::table();
		$has_col  = false;
		$col_name = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM ' . $table . ' LIKE %s', 'reason_code' ) ); // phpcs:ignore
		$has_col  = ( 'reason_code' === $col_name );

		// Firma del bridge JS su disco (per smascherare cache/minify che servono un file diverso).
		$bridge_path = dirname( __DIR__, 2 ) . '/assets/js/ga4-bridge.js';
		$bridge_ok   = is_readable( $bridge_path );
		$bridge_sig  = $bridge_ok ? substr( md5_file( $bridge_path ), 0, 10 ) : '';
		$bridge_mt   = $bridge_ok ? gmdate( 'Y-m-d H:i', (int) filemtime( $bridge_path ) ) . ' UTC' : '';

		return array(
			'plugin_version'   => defined( 'ATI_PLUGIN_VERSION' ) ? ATI_PLUGIN_VERSION : 'n/d',
			'header_version'   => '' !== $header_version ? $header_version : 'n/d',
			'schema_installed' => (string) get_option( ATI_GA4_Migration::VERSION_OPTION, '0' ),
			'schema_expected'  => ATI_GA4_Migration::SCHEMA_VERSION,
			'has_reason_code'  => $has_col,
			'bridge_exists'    => $bridge_ok,
			'bridge_sig'       => $bridge_sig,
			'bridge_mtime'     => $bridge_mt,
			'confirmed_enabled' => ATI_GA4_Config::confirmed_enabled(),
			'is_ready'         => ATI_GA4_Config::is_ready(),
		);
	}

	/**
	 * Rende la pagina admin.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$counts     = ATI_Event_Queue::counts();
		$discarded_reasons = ATI_Event_Queue::discarded_reasons();
		$consent_diag = ATI_Consent_Service::diagnostics();
		$secret_set = ATI_GA4_Config::has_secret();
		$secret_const = ATI_GA4_Config::secret_is_constant();
		$map        = get_option( 'ati_ga4_form_map', array() );
		$map        = is_array( $map ) ? $map : array();
		// Riga vuota per aggiungere un nuovo mapping.
		$map[]      = array();
		$ver = self::version_status();
		?>
		<div class="wrap">
			<h1>GA4 Server-Side</h1>

			<h2>Stato / versione attiva</h2>
			<p class="description">Utile per verificare che il sito stia eseguendo il codice aggiornato (e non una versione in cache/OPcache).</p>
			<table class="widefat" style="max-width:760px">
				<tr>
					<td>Versione plugin (runtime)</td>
					<td><strong><?php echo esc_html( $ver['plugin_version'] ); ?></strong> <span class="description">(header: <?php echo esc_html( $ver['header_version'] ); ?>)</span></td>
				</tr>
				<tr>
					<td>Schema DB</td>
					<td>
						installato <code><?php echo esc_html( $ver['schema_installed'] ); ?></code> / atteso <code><?php echo esc_html( $ver['schema_expected'] ); ?></code>
						<?php if ( $ver['schema_installed'] === $ver['schema_expected'] ) : ?>
							<span style="color:green">✅ allineato</span>
						<?php else : ?>
							<span style="color:#b32d2e">⚠️ migrazione non applicata (codice in cache o migrazione da eseguire)</span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td>Colonna coda <code>reason_code</code></td>
					<td><?php echo $ver['has_reason_code'] ? '<span style="color:green">✅ presente (schema v2)</span>' : '<span style="color:#b32d2e">❌ assente</span>'; ?></td>
				</tr>
				<tr>
					<td>Bridge JS su disco</td>
					<td>
						<?php if ( $ver['bridge_exists'] ) : ?>
							firma <code><?php echo esc_html( $ver['bridge_sig'] ); ?></code> · modificato <code><?php echo esc_html( $ver['bridge_mtime'] ); ?></code>
						<?php else : ?>
							<span style="color:#b32d2e">❌ file non trovato</span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td>Pipeline "Eventi confermati"</td>
					<td><?php echo $ver['confirmed_enabled'] ? '✅ attiva' : '⏸️ disattivata'; ?> · configurazione <?php echo $ver['is_ready'] ? '✅ pronta' : '⚠️ incompleta (Measurement ID / API Secret)'; ?></td>
				</tr>
				<tr>
					<td>Provider form attivi</td>
					<td><code><?php echo esc_html( implode( ', ', ATI_Form_Provider_Registry::active_ids() ) ?: 'nessuno' ); ?></code></td>
				</tr>
			</table>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<h2>Configurazione GA4 Measurement Protocol</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ati_ga4_server_id">Measurement ID (server)</label></th>
						<td>
							<input name="ati_ga4_server_id" type="text" id="ati_ga4_server_id" value="<?php echo esc_attr( get_option( 'ati_ga4_server_id', '' ) ); ?>" class="regular-text" placeholder="G-XXXXXXXXXX" />
							<p class="description">Normalmente lo stesso ID del Google Tag web. Se vuoto, viene usato l'ID client (<code><?php echo esc_html( get_option( 'ati_ga4_id', '(non impostato)' ) ); ?></code>).</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ati_ga4_api_secret">API Secret</label></th>
						<td>
							<?php if ( $secret_const ) : ?>
								<p><span class="dashicons dashicons-lock"></span> Definito tramite costante <code>ATI_GA4_API_SECRET</code> (gestito fuori dal database).</p>
							<?php else : ?>
								<input name="ati_ga4_api_secret" type="password" id="ati_ga4_api_secret" value="" autocomplete="new-password" class="regular-text" placeholder="<?php echo $secret_set ? '••••••••  (lascia vuoto per non modificare)' : 'incolla il secret'; ?>" />
								<p class="description">
									Stato: <?php echo $secret_set ? '<strong>impostato</strong>' : '<strong>non impostato</strong>'; ?>.
									Il valore non viene mai mostrato né inviato al browser. Lascia vuoto per conservarlo.
								</p>
								<?php if ( $secret_set ) : ?>
									<label><input type="checkbox" name="ati_ga4_remove_secret" value="1" /> Rimuovi il secret salvato</label>
								<?php endif; ?>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ati_ga4_region">Regione endpoint</label></th>
						<td>
							<select name="ati_ga4_region" id="ati_ga4_region">
								<option value="eu" <?php selected( get_option( 'ati_ga4_region', 'eu' ), 'eu' ); ?>>EU (region1) — consigliata</option>
								<option value="global" <?php selected( get_option( 'ati_ga4_region', 'eu' ), 'global' ); ?>>Global</option>
							</select>
						</td>
					</tr>
				</table>

				<h2>Attivazione pipeline server-confirmed</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Eventi confermati</th>
						<td>
							<label><input type="checkbox" name="ati_ga4_confirmed_enabled" value="1" <?php checked( get_option( 'ati_ga4_confirmed_enabled', '0' ), '1' ); ?> /> Abilita l'invio server-side di <code>generate_lead</code> confermati</label>
							<p class="description">Abilitare solo dopo: Measurement ID, API Secret, consenso analytics, almeno un form configurato e test di validazione superato.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">PageView server-side (avanzato)</th>
						<td>
							<label><input type="checkbox" name="ati_ga4_server_pageview" value="1" <?php checked( get_option( 'ati_ga4_server_pageview', '0' ), '1' ); ?> /> Invia anche <code>page_view</code> dal server</label>
							<div class="notice notice-warning inline"><p><strong>Rischio di duplicazione:</strong> il Google Tag invia già <code>page_view</code> dal browser. Lasciare disattivato salvo esigenze specifiche.</p></div>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ati_ga4_lead_trigger">Trigger del lead</label></th>
						<td>
							<select name="ati_ga4_lead_trigger" id="ati_ga4_lead_trigger">
								<option value="server" <?php selected( get_option( 'ati_ga4_lead_trigger', 'server' ), 'server' ); ?>>Provider server-side (hook ufficiali: Elementor, Fluent recenti)</option>
								<option value="submit" <?php selected( get_option( 'ati_ga4_lead_trigger', 'server' ), 'submit' ); ?>>Invio form client-side (funziona con tutti i form)</option>
							</select>
							<p class="description">
								<strong>Provider server-side</strong>: massima affidabilità, ma richiede un hook PHP supportato dal plugin form.<br>
								<strong>Invio form client-side</strong>: il bridge invia <code>generate_lead</code> all'invio di un qualsiasi form (utile con form/versioni non supportati). Legge comunque client_id/session_id reali e rispetta consenso e deduplica. Possibili falsi positivi su invii non riusciti. In questa modalità i provider server-side non emettono il lead (niente doppioni).
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Tentativi di submit</th>
						<td>
							<label><input type="checkbox" name="ati_ga4_enable_submit_attempt" value="1" <?php checked( get_option( 'ati_ga4_enable_submit_attempt', '0' ), '1' ); ?> /> Abilita <code>form_submit_attempt</code> (NON è una conversione)</label>
							<p class="description">Rappresenta un tentativo di invio, non un lead confermato. Non marcarlo come key event in GA4.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Debug DebugView</th>
						<td>
							<label><input type="checkbox" name="ati_ga4_debug_mode" value="1" <?php checked( get_option( 'ati_ga4_debug_mode', '0' ), '1' ); ?> /> Invia gli eventi con <code>debug_mode</code> (visibili in GA4 <strong>DebugView</strong>)</label>
							<div class="notice notice-warning inline"><p><strong>Solo per test.</strong> Rende gli eventi server-side visibili in DebugView. Disattiva dopo la verifica: in produzione altera i dati di debug. Non ha effetto sull'anteprima di Google Tag Manager (che mostra solo gli eventi del browser).</p></div>
						</td>
					</tr>
					<tr>
						<th scope="row">Legacy GA4 server</th>
						<td>
							<label><input type="checkbox" name="ati_enable_ga4_server" value="1" <?php checked( get_option( 'ati_enable_ga4_server', false ), '1' ); ?> /> Mantieni il vecchio invio GA4 server-side</label>
							<p class="description">Compatibilità. Quando la pipeline "Eventi confermati" è attiva, il vecchio invio di <code>generate_lead</code> sul submit generico viene disattivato automaticamente.</p>
						</td>
					</tr>
				</table>

				<h2>Consenso &amp; attribuzione</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ati_ga4_analytics_consent_mode">Consenso analytics</label></th>
						<td>
							<select name="ati_ga4_analytics_consent_mode" id="ati_ga4_analytics_consent_mode">
								<option value="auto" <?php selected( get_option( 'ati_ga4_analytics_consent_mode', 'auto' ), 'auto' ); ?>>Auto (rileva categoria statistiche dal CMP)</option>
								<option value="always" <?php selected( get_option( 'ati_ga4_analytics_consent_mode', 'auto' ), 'always' ); ?>>Sempre concesso (sconsigliato)</option>
							</select>
							<p class="description">Il consenso analytics è distinto dal marketing. Non viene mai dedotto dal consenso marketing.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ati_analytics_cookie_name">Cookie analytics custom</label></th>
						<td><input name="ati_analytics_cookie_name" type="text" id="ati_analytics_cookie_name" value="<?php echo esc_attr( get_option( 'ati_analytics_cookie_name', '' ) ); ?>" class="regular-text" placeholder="opzionale (valore atteso: allow)" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="ati_ga4_missing_cid_policy">client_id assente</label></th>
						<td>
							<select name="ati_ga4_missing_cid_policy" id="ati_ga4_missing_cid_policy">
								<option value="queue" <?php selected( get_option( 'ati_ga4_missing_cid_policy', 'queue' ), 'queue' ); ?>>Accoda (attribuzione degradata, nessun UUID casuale)</option>
								<option value="discard" <?php selected( get_option( 'ati_ga4_missing_cid_policy', 'queue' ), 'discard' ); ?>>Scarta l'evento</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ati_ga4_dedup_ttl">TTL deduplica (sec)</label></th>
						<td><input name="ati_ga4_dedup_ttl" type="number" min="60" id="ati_ga4_dedup_ttl" value="<?php echo esc_attr( (int) get_option( 'ati_ga4_dedup_ttl', DAY_IN_SECONDS ) ); ?>" class="small-text" /> <span class="description">default 86400 (24h)</span></td>
					</tr>
				</table>

				<h2>Classificazione del progetto</h2>
				<p class="description">Valori globali (slug). Priorità: mapping form &rarr; filtri &rarr; globale &rarr; fallback (<code>not_set</code>).</p>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="ati_class_business_area">business_area</label></th><td><input name="ati_class_business_area" id="ati_class_business_area" type="text" value="<?php echo esc_attr( get_option( 'ati_class_business_area', '' ) ); ?>" class="regular-text" placeholder="es: acquisizione_immobili" /></td></tr>
					<tr><th scope="row"><label for="ati_class_service_type">service_type</label></th><td><input name="ati_class_service_type" id="ati_class_service_type" type="text" value="<?php echo esc_attr( get_option( 'ati_class_service_type', '' ) ); ?>" class="regular-text" placeholder="es: nuda_proprieta" /></td></tr>
					<tr><th scope="row"><label for="ati_class_audience_type">audience_type</label></th><td><input name="ati_class_audience_type" id="ati_class_audience_type" type="text" value="<?php echo esc_attr( get_option( 'ati_class_audience_type', '' ) ); ?>" class="regular-text" placeholder="es: privato" /></td></tr>
					<tr><th scope="row"><label for="ati_class_site_section">site_section</label></th><td><input name="ati_class_site_section" id="ati_class_site_section" type="text" value="<?php echo esc_attr( get_option( 'ati_class_site_section', '' ) ); ?>" class="regular-text" placeholder="es: landing_nuda_proprieta" /></td></tr>
				</table>

				<h2>Mapping form</h2>
				<table class="widefat" style="max-width:1100px">
					<thead><tr><th>Attivo</th><th>provider</th><th>form_id</th><th>form_name</th><th>business_area</th><th>service_type</th><th>audience_type</th><th>site_section</th></tr></thead>
					<tbody>
					<?php foreach ( $map as $i => $row ) : $row = is_array( $row ) ? $row : array(); ?>
						<tr>
							<td><input type="checkbox" name="ati_ga4_form_map[<?php echo (int) $i; ?>][enabled]" value="1" <?php checked( ! empty( $row['enabled'] ) ); ?> /></td>
							<td><input type="text" name="ati_ga4_form_map[<?php echo (int) $i; ?>][provider]" value="<?php echo esc_attr( isset( $row['provider'] ) ? $row['provider'] : '' ); ?>" placeholder="elementor" /></td>
							<td><input type="text" name="ati_ga4_form_map[<?php echo (int) $i; ?>][form_id]" value="<?php echo esc_attr( isset( $row['form_id'] ) ? $row['form_id'] : '' ); ?>" /></td>
							<td><input type="text" name="ati_ga4_form_map[<?php echo (int) $i; ?>][form_name]" value="<?php echo esc_attr( isset( $row['form_name'] ) ? $row['form_name'] : '' ); ?>" /></td>
							<td><input type="text" name="ati_ga4_form_map[<?php echo (int) $i; ?>][business_area]" value="<?php echo esc_attr( isset( $row['business_area'] ) ? $row['business_area'] : '' ); ?>" /></td>
							<td><input type="text" name="ati_ga4_form_map[<?php echo (int) $i; ?>][service_type]" value="<?php echo esc_attr( isset( $row['service_type'] ) ? $row['service_type'] : '' ); ?>" /></td>
							<td><input type="text" name="ati_ga4_form_map[<?php echo (int) $i; ?>][audience_type]" value="<?php echo esc_attr( isset( $row['audience_type'] ) ? $row['audience_type'] : '' ); ?>" /></td>
							<td><input type="text" name="ati_ga4_form_map[<?php echo (int) $i; ?>][site_section]" value="<?php echo esc_attr( isset( $row['site_section'] ) ? $row['site_section'] : '' ); ?>" /></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description">Provider form attivi rilevati: <code><?php echo esc_html( implode( ', ', ATI_Form_Provider_Registry::active_ids() ) ?: 'nessuno' ); ?></code></p>

				<?php submit_button(); ?>
			</form>

			<hr />
			<h2>Diagnostica consenso</h2>
			<p class="description">Basata sui cookie della richiesta corrente (admin). Nessun contenuto di cookie viene mostrato.</p>
			<table class="widefat" style="max-width:600px">
				<tr><td>Provider consenso rilevati</td><td><strong><?php echo esc_html( implode( ', ', $consent_diag['providers'] ) ?: 'nessuno' ); ?></strong></td></tr>
				<tr><td>Stato analytics rilevato</td><td><strong><?php echo $consent_diag['analytics'] ? '✅ concesso' : '⚠️ non rilevato'; ?></strong></td></tr>
				<tr><td>Modalità consenso analytics</td><td><code><?php echo esc_html( $consent_diag['mode'] ); ?></code></td></tr>
				<tr><td>Purpose iubenda (analytics) configurato</td><td><code><?php echo (int) $consent_diag['iubenda_purpose']; ?></code> <span class="description">(filtro <code>ati_iubenda_analytics_purpose</code>)</span></td></tr>
			</table>

			<hr />
			<h2>Testa configurazione GA4</h2>
			<p class="description">Usa l'endpoint di <strong>validazione/debug</strong> (non crea conversioni, non invia PII, non mostra il secret).</p>
			<button type="button" class="button button-secondary" id="ati-ga4-test-btn">Testa configurazione GA4</button>
			<span id="ati-ga4-test-spinner" class="spinner" style="float:none"></span>
			<div id="ati-ga4-test-result" style="margin-top:12px"></div>

			<hr />
			<h2>Coda eventi GA4</h2>
			<table class="widefat" style="max-width:600px">
				<tr><td>pending</td><td><strong><?php echo (int) $counts['pending']; ?></strong></td></tr>
				<tr><td>processing</td><td><strong><?php echo (int) $counts['processing']; ?></strong></td></tr>
				<tr><td>sent</td><td><strong><?php echo (int) $counts['sent']; ?></strong></td></tr>
				<tr><td>failed</td><td><strong><?php echo (int) $counts['failed']; ?></strong></td></tr>
				<tr><td>discarded</td><td><strong><?php echo (int) $counts['discarded']; ?></strong></td></tr>
			</table>
			<?php if ( ! empty( $discarded_reasons ) ) : ?>
				<p class="description" style="margin-top:6px"><strong>Scartati per motivo:</strong>
				<?php
				$parts = array();
				foreach ( $discarded_reasons as $reason => $n ) {
					$parts[] = esc_html( $reason ) . ': ' . (int) $n;
				}
				echo esc_html( implode( ' · ', $parts ) );
				?>
				</p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:10px">
				<input type="hidden" name="action" value="ati_ga4_queue_action" />
				<?php wp_nonce_field( self::NONCE ); ?>
				<button type="submit" name="ati_queue_op" value="retry" class="button">Riprova falliti</button>
				<button type="submit" name="ati_queue_op" value="delete" class="button">Elimina falliti</button>
				<button type="submit" name="ati_queue_op" value="process" class="button">Processa ora</button>
			</form>

			<script>
			(function(){
				var btn = document.getElementById('ati-ga4-test-btn');
				var out = document.getElementById('ati-ga4-test-result');
				var sp  = document.getElementById('ati-ga4-test-spinner');
				if(!btn) return;
				btn.addEventListener('click', function(){
					sp.classList.add('is-active'); out.innerHTML='';
					var body = new URLSearchParams({ action:'ati_ga4_test_config', _wpnonce:'<?php echo esc_js( wp_create_nonce( self::NONCE ) ); ?>' });
					fetch(ajaxurl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body})
					.then(function(r){return r.json();})
					.then(function(d){
						sp.classList.remove('is-active');
						var html = '<div class="notice notice-'+(d.success?'success':'error')+' inline" style="padding:8px"><p><strong>'+(d.data && d.data.summary ? d.data.summary : (d.success?'OK':'Errore'))+'</strong></p>';
						if(d.data && d.data.messages && d.data.messages.length){
							html += '<ul style="list-style:disc;margin-left:20px">';
							d.data.messages.forEach(function(m){ html += '<li>'+ (m.severity? '['+m.severity+'] ':'') + (m.description||'') +'</li>'; });
							html += '</ul>';
						}
						html += '</div>';
						out.innerHTML = html;
					})
					.catch(function(){ sp.classList.remove('is-active'); out.innerHTML='<div class="notice notice-error inline" style="padding:8px"><p>Errore di rete durante il test.</p></div>'; });
				});
			})();
			</script>
		</div>
		<?php
	}

	/**
	 * AJAX: test di validazione GA4 (nessuna PII, nessun secret esposto).
	 *
	 * @return void
	 */
	public static function ajax_test_config() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'summary' => 'Permesso negato' ), 403 );
		}
		check_ajax_referer( self::NONCE );

		$errors   = array();
		$warnings = array();

		$measurement_id = ATI_GA4_Config::measurement_id();
		if ( '' === $measurement_id ) {
			$errors[] = array( 'severity' => 'ERROR', 'description' => 'Measurement ID mancante.' );
		}
		if ( ! ATI_GA4_Config::has_secret() ) {
			$errors[] = array( 'severity' => 'ERROR', 'description' => 'API Secret mancante.' );
		}

		if ( ! empty( $errors ) ) {
			wp_send_json_error(
				array(
					'summary'  => 'Configurazione incompleta',
					'messages' => $errors,
				)
			);
		}

		// Evento di prova (dati fittizi, nessuna PII).
		$event = ATI_Event_Normalizer::normalize(
			'generate_lead',
			ATI_Project_Classification::global_defaults(),
			array(
				'client_id'            => '1234567890.1234567890',
				'session_id'           => (string) time(),
				'page_location'        => home_url( '/' ),
				'page_title'           => 'GA4 config test',
				'engagement_time_msec' => 1,
				'event_id'             => 'evt_test_' . wp_generate_password( 8, false ),
				'provider'             => 'admin_test',
			)
		);

		$result = ATI_GA4_Adapter::validate( $event );

		$region = strtoupper( ATI_GA4_Endpoints::region() );
		$summary = sprintf(
			'Regione %s · HTTP %d · %s',
			$region,
			(int) $result['code'],
			$result['ok'] ? 'payload valido' : 'validazione con avvisi/errori'
		);

		$payload = array(
			'summary'  => $summary,
			'messages' => is_array( $result['messages'] ) ? $result['messages'] : array(),
		);

		if ( $result['ok'] ) {
			wp_send_json_success( $payload );
		}
		wp_send_json_error( $payload );
	}

	/**
	 * Azioni manuali sulla coda.
	 *
	 * @return void
	 */
	public static function handle_queue_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Permesso negato', '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE );

		$op = isset( $_POST['ati_queue_op'] ) ? sanitize_key( $_POST['ati_queue_op'] ) : '';
		switch ( $op ) {
			case 'retry':
				ATI_Event_Queue::retry_failed();
				break;
			case 'delete':
				ATI_Event_Queue::delete_failed();
				break;
			case 'process':
				ATI_Event_Queue::process();
				break;
		}

		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'ati_queue' => $op ), admin_url( 'options-general.php' ) ) );
		exit;
	}
}
