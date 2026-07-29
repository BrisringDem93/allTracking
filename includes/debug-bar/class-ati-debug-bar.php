<?php
/**
 * ATI_Debug_Bar — Widget di diagnostica sul front-end.
 *
 * Pannello a schermo, visibile SOLO a un utente loggato con i permessi di
 * amministrazione e SOLO con `WP_DEBUG` attivo (o forzato da opzione/costante).
 * Mostra in un colpo d'occhio: consenso rilevato (server e browser), cookie
 * presenti con l'esito che hanno con le regole attive, cookie che dovrebbero
 * esserci o non esserci, stato del blocco cookie, dei tag e della pipeline GA4
 * server-side.
 *
 * È un pannello di SOLA LETTURA: non scrive cookie, non invia eventi, non
 * modifica il comportamento del tracking. Non viene mai stampato per i visitatori,
 * quindi non può alterare quello che vede un utente anonimo.
 *
 * Il motore di valutazione non è duplicato: il widget usa `atiCookieGuardApi`,
 * esposto dallo stesso `assets/js/cookie-guard.js` che gira sul front-end (con
 * `mode=off` quando il guard non è attivo su questa richiesta). Così l'esito
 * mostrato non può divergere da quello reale.
 *
 * @package QuickTrackingIntegration\DebugBar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Widget di debug del front-end.
 */
class ATI_Debug_Bar {

	/**
	 * Opzione di visibilità: auto (solo con WP_DEBUG) | always | off.
	 */
	const OPTION_MODE = 'ati_debug_bar_mode';

	/**
	 * Modalità predefinita: il widget compare solo quando WP_DEBUG è attivo.
	 */
	const MODE_DEFAULT = 'auto';

	/**
	 * Aggancia gli hook del front-end.
	 *
	 * @return void
	 */
	public static function boot() {
		// Il widget ha bisogno dell'API di valutazione del guard: quando il guard è
		// attivo su questa richiesta, la si abilita sulla configurazione già stampata
		// invece di stampare un secondo script.
		add_filter( 'ati_cookie_guard_script_config', array( __CLASS__, 'expose_guard_api' ) );

		// Ultimo output della pagina: il widget non deve interferire con nulla.
		add_action( 'wp_footer', array( __CLASS__, 'render' ), 9999 );
	}

	/**
	 * Modalità di visibilità configurata.
	 *
	 * @return string auto|always|off
	 */
	public static function mode() {
		$mode = (string) get_option( self::OPTION_MODE, self::MODE_DEFAULT );
		return in_array( $mode, array( 'auto', 'always', 'off' ), true ) ? $mode : self::MODE_DEFAULT;
	}

	/**
	 * Etichette delle modalità (pagina impostazioni).
	 *
	 * @return array<string,string>
	 */
	public static function mode_labels() {
		return array(
			'auto'   => 'Automatico — solo con WP_DEBUG attivo (consigliato)',
			'always' => 'Sempre — anche in produzione, solo per gli amministratori',
			'off'    => 'Mai — widget disattivato',
		);
	}

	/**
	 * Capacità richiesta. Il widget mostra la configurazione del tracking: per
	 * default è riservato a chi può già vederla nel pannello impostazioni.
	 *
	 * Filtro `ati_debug_bar_capability`: usare `'read'` per mostrarlo a qualunque
	 * utente loggato.
	 *
	 * @return string
	 */
	public static function capability() {
		return (string) apply_filters( 'ati_debug_bar_capability', 'manage_options' );
	}

	/**
	 * Il debug è abilitato in questa installazione?
	 *
	 * La costante `ATI_DEBUG_BAR` (wp-config.php) ha la precedenza su tutto: true
	 * forza il widget anche senza WP_DEBUG, false lo spegne in ogni caso.
	 *
	 * @return bool
	 */
	public static function debug_enabled() {
		if ( defined( 'ATI_DEBUG_BAR' ) ) {
			return (bool) ATI_DEBUG_BAR;
		}

		$mode = self::mode();
		if ( 'off' === $mode ) {
			return false;
		}
		if ( 'always' === $mode ) {
			return true;
		}
		return defined( 'WP_DEBUG' ) && WP_DEBUG;
	}

	/**
	 * Il widget va stampato in questa richiesta?
	 *
	 * @return bool
	 */
	public static function is_visible() {
		static $visible = null;
		if ( null !== $visible ) {
			return $visible;
		}

		$visible = self::compute_visibility();
		return $visible;
	}

	/**
	 * Calcolo effettivo della visibilità.
	 *
	 * @return bool
	 */
	protected static function compute_visibility() {
		if ( ! self::debug_enabled() ) {
			return false;
		}
		if ( ! is_user_logged_in() || ! current_user_can( self::capability() ) ) {
			return false;
		}
		// Solo pagine HTML del front-end: mai in admin, AJAX, REST, cron, feed.
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return false;
		}
		if ( function_exists( 'is_feed' ) && is_feed() ) {
			return false;
		}
		if ( function_exists( 'is_embed' ) && is_embed() ) {
			return false;
		}

		/**
		 * Consente di nascondere il widget di debug su specifiche richieste.
		 *
		 * @param bool $visible Stato calcolato.
		 */
		return (bool) apply_filters( 'ati_debug_bar_visible', true );
	}

	/**
	 * Abilita l'API di sola lettura del guard quando il widget è visibile.
	 *
	 * @param array $config Configurazione del guard.
	 * @return array
	 */
	public static function expose_guard_api( $config ) {
		if ( is_array( $config ) && self::is_visible() ) {
			$config['expose'] = true;
		}
		return $config;
	}

	/**
	 * Il guard (e quindi `atiCookieGuardApi`) è già stampato in questa pagina?
	 *
	 * @return bool
	 */
	protected static function guard_already_printed() {
		if ( ! class_exists( 'ATI_Cookie_Guard' ) ) {
			return false;
		}
		return ATI_Cookie_Guard::is_active() && '' !== ATI_Cookie_Guard::script_source();
	}

	/**
	 * Sorgente dello script del widget.
	 *
	 * @return string Codice JS, stringa vuota se il file non è leggibile.
	 */
	public static function script_source() {
		$path = dirname( __DIR__, 2 ) . '/assets/js/debug-bar.js';
		return is_readable( $path ) ? (string) file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * Consenso per categoria lato server.
	 *
	 * Delega a ATI_Cookie_Consent (unica fonte di verità); se il modulo blocco
	 * cookie non è disponibile ricade sulle funzioni storiche del plugin.
	 *
	 * @return array<string,bool>
	 */
	protected static function consent_state() {
		if ( class_exists( 'ATI_Cookie_Consent' ) ) {
			return ATI_Cookie_Consent::state();
		}
		return array(
			'marketing'   => function_exists( 'ati_has_marketing_consent' ) ? (bool) ati_has_marketing_consent() : false,
			'analytics'   => function_exists( 'ati_has_analytics_consent' ) ? (bool) ati_has_analytics_consent() : false,
			'preferences' => false,
		);
	}

	/**
	 * Stato del blocco cookie.
	 *
	 * @return array
	 */
	protected static function guard_snapshot() {
		if ( ! class_exists( 'ATI_Cookie_Guard' ) ) {
			// Modulo blocco cookie non caricato: il widget resta utilizzabile per
			// consenso, cookie e tag, e dichiara che non c'è nessun blocco.
			return array(
				'available'      => false,
				'mode'           => 'off',
				'mode_label'     => 'Modulo blocco cookie non caricato',
				'active'         => false,
				'rules'          => 0,
				'rules_total'    => 0,
				'defaults'       => false,
				'skip_logged_in' => false,
				'server_cleanup' => false,
				'sweep_interval' => 0,
				'sweep_duration' => 0,
				'cmp'            => 'auto',
				'providers'      => array(),
				'custom_cookies' => array(),
			);
		}

		return array(
			'available'      => true,
			'mode'           => ATI_Cookie_Guard::mode(),
			'mode_label'     => ATI_Cookie_Guard::mode_labels()[ ATI_Cookie_Guard::mode() ],
			'active'         => ATI_Cookie_Guard::is_active(),
			'rules'          => count( ATI_Cookie_Rules::active_rules() ),
			'rules_total'    => count( ATI_Cookie_Rules::rules() ),
			'defaults'       => ATI_Cookie_Rules::is_using_defaults(),
			'skip_logged_in' => '1' === (string) get_option( 'ati_cg_skip_logged_in', '1' ),
			'server_cleanup' => '1' === (string) get_option( 'ati_cg_server_cleanup', '0' ),
			'sweep_interval' => max( 250, (int) get_option( 'ati_cg_sweep_interval', 2000 ) ),
			'sweep_duration' => max( 0, (int) get_option( 'ati_cg_sweep_duration', 60000 ) ),
			'cmp'            => ATI_Cookie_Consent::configured_provider(),
			'providers'      => ATI_Cookie_Consent::detected_providers(),
			'custom_cookies' => ATI_Cookie_Consent::custom_cookies(),
		);
	}

	/**
	 * Cookie ricevuti dal server in questa richiesta, con il verdetto delle regole.
	 *
	 * Include gli `HttpOnly` e i cookie di terze parti inviati al server, che
	 * JavaScript non può vedere. I valori NON vengono mai esposti.
	 *
	 * @param array $consent Consenso per categoria.
	 * @return array<int,array>
	 */
	protected static function server_cookies( array $consent ) {
		$names = array_map( 'strval', array_keys( (array) $_COOKIE ) );
		sort( $names );

		if ( ! class_exists( 'ATI_Cookie_Rules' ) ) {
			return array_map(
				function ( $name ) {
					return array(
						'name'    => $name,
						'reason'  => 'no_match',
						'blocked' => false,
					);
				},
				$names
			);
		}

		$rules     = ATI_Cookie_Rules::active_rules();
		$allowlist = ATI_Cookie_Rules::allowlist_patterns();
		$out       = array();

		foreach ( $names as $name ) {
			$eval  = ATI_Cookie_Rules::evaluate( $name, '', $consent, $rules, $allowlist );
			$out[] = array(
				'name'     => $name,
				'reason'   => (string) $eval['reason'],
				'blocked'  => (bool) $eval['blocked'],
				'delete'   => ATI_Cookie_Rules::should_delete( $eval ),
				'category' => (string) $eval['category'],
				'label'    => (string) $eval['label'],
				'rule'     => null === $eval['rule'] ? null : (int) $eval['rule'],
			);
		}

		return $out;
	}

	/**
	 * Stato della pipeline GA4 server-side (senza segreti).
	 *
	 * @return array
	 */
	protected static function ga4_snapshot() {
		if ( ! class_exists( 'ATI_GA4_Config' ) ) {
			return array( 'available' => false );
		}

		$snapshot = array(
			'available'       => true,
			'confirmed'       => ATI_GA4_Config::confirmed_enabled(),
			'ready'           => ATI_GA4_Config::is_ready(),
			'measurement_id'  => ATI_GA4_Config::measurement_id(),
			'has_secret'      => ATI_GA4_Config::has_secret(),
			'secret_constant' => ATI_GA4_Config::secret_is_constant(),
			'server_pageview' => ATI_GA4_Config::server_pageview_enabled(),
			'debug_mode'      => ATI_GA4_Config::debug_mode_enabled(),
			'lead_trigger'    => ATI_GA4_Config::lead_trigger(),
			'region'          => (string) get_option( 'ati_ga4_region', 'eu' ),
			'consent_mode'    => (string) get_option( 'ati_ga4_analytics_consent_mode', 'auto' ),
			'queue'           => self::queue_counts(),
			'cron'            => self::cron_snapshot(),
		);

		if ( class_exists( 'ATI_GA4_Client_Context' ) ) {
			$context                = ATI_GA4_Client_Context::from_cookies();
			$snapshot['client_id']  = isset( $context['client_id'] ) ? (string) $context['client_id'] : '';
			$snapshot['session_id'] = isset( $context['session_id'] ) ? (string) $context['session_id'] : '';
		}

		return $snapshot;
	}

	/**
	 * Conteggi della coda GA4, solo se la tabella esiste.
	 *
	 * La verifica evita l'errore SQL (e il suo dump a schermo con WP_DEBUG_DISPLAY)
	 * quando la migrazione non è ancora stata eseguita.
	 *
	 * @return array<string,int>|null
	 */
	protected static function queue_counts() {
		global $wpdb;

		if ( ! class_exists( 'ATI_Event_Queue' ) || ! isset( $wpdb ) ) {
			return null;
		}

		$table  = ATI_Event_Queue::table();
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $exists !== $table ) {
			return null;
		}

		return ATI_Event_Queue::counts();
	}

	/**
	 * Prossima esecuzione dei worker della coda.
	 *
	 * @return array<string,int|null>
	 */
	protected static function cron_snapshot() {
		$hooks = array( 'tick' => 'ati_ga4_cron_tick' );
		if ( class_exists( 'ATI_Event_Queue' ) ) {
			$hooks['queue'] = ATI_Event_Queue::CRON_HOOK;
		}

		$out = array();
		foreach ( $hooks as $key => $hook ) {
			$next        = wp_next_scheduled( $hook );
			$out[ $key ] = $next ? (int) $next - time() : null;
		}
		return $out;
	}

	/**
	 * Stato dei tag e delle integrazioni server-side (nessun segreto, solo presenza).
	 *
	 * @param array $cfg Configurazione (ATI_Debug_Expectations::config()).
	 * @return array
	 */
	protected static function tracking_snapshot( array $cfg ) {
		$endpoint = trim( (string) get_option( 'ati_server_endpoint', '' ) );
		$host     = '';
		if ( '' !== $endpoint ) {
			$parts = wp_parse_url( $endpoint );
			$host  = isset( $parts['host'] ) ? (string) $parts['host'] : '';
		}

		return array(
			'gtm'          => $cfg['gtm'],
			'ga4'          => $cfg['ga4'],
			'fb'           => $cfg['fb'],
			'form_fields'  => (bool) $cfg['form_fields'],
			'tracking_off' => (bool) $cfg['tracking_off'],
			'n8n'          => array(
				// Il path del webhook è di fatto un segreto: si mostra solo l'host.
				'configured' => '' !== $endpoint,
				'host'       => $host,
				'auth'       => '' !== trim( (string) get_option( 'ati_server_auth_key', '' ) ),
			),
			'meta_capi'    => array(
				'dataset'   => trim( (string) get_option( 'ati_meta_dataset_id', '' ) ),
				'has_token' => '' !== trim( (string) get_option( 'ati_meta_capi_token', '' ) ),
			),
		);
	}

	/**
	 * Fotografia completa passata al widget.
	 *
	 * Non contiene MAI segreti (API Secret GA4, token CAPI, header di autenticazione,
	 * path del webhook) né valori di cookie: solo nomi, esiti e flag di presenza.
	 *
	 * @return array
	 */
	public static function snapshot() {
		$cfg     = ATI_Debug_Expectations::config();
		$consent = self::consent_state();
		$guard   = self::guard_snapshot();
		$ga4     = self::ga4_snapshot();
		$user    = wp_get_current_user();

		return array(
			'version'  => defined( 'ATI_PLUGIN_VERSION' ) ? ATI_PLUGIN_VERSION : '',
			'mode'     => self::mode(),
			'forced'   => defined( 'ATI_DEBUG_BAR' ),
			'env'      => array(
				'wp_debug'         => defined( 'WP_DEBUG' ) && WP_DEBUG,
				'wp_debug_log'     => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
				'wp_debug_display' => defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY,
				'script_debug'     => defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG,
				'user'             => $user ? (string) $user->user_login : '',
				'guard_log'        => '1' === (string) get_option( 'ati_cg_debug', '0' ),
			),
			'consent'  => array(
				'marketing'   => ! empty( $consent['marketing'] ),
				'analytics'   => ! empty( $consent['analytics'] ),
				'preferences' => ! empty( $consent['preferences'] ),
			),
			'guard'    => $guard,
			'cookies'  => self::server_cookies( $consent ),
			'expected' => ATI_Debug_Expectations::rows( $cfg, $consent ),
			'notices'  => ATI_Debug_Expectations::notices( $cfg, $consent, $guard, $ga4 ),
			'tracking' => self::tracking_snapshot( $cfg ),
			'ga4'      => $ga4,
			'labels'   => class_exists( 'ATI_Cookie_Rules' ) ? ATI_Cookie_Rules::category_labels() : array(),
			'settings' => admin_url( 'options-general.php?page=ati-settings&tab=cookies' ),
		);
	}

	/**
	 * Stampa il widget nel footer.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! self::is_visible() ) {
			return;
		}

		$source = self::script_source();
		if ( '' === $source ) {
			return;
		}

		echo "\n<!-- Quick Tracking Integration: widget di debug (visibile solo agli amministratori con WP_DEBUG) -->\n";
		echo '<style id="ati-debug-bar-style">' . self::styles() . "</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput

		// Guard non stampato in questa pagina (es. escluso per gli utenti loggati):
		// si carica lo stesso motore in SOLA LETTURA — mode=off non installa nulla.
		if ( ! self::guard_already_printed() && class_exists( 'ATI_Cookie_Guard' ) ) {
			$guard_source = ATI_Cookie_Guard::script_source();
			if ( '' !== $guard_source ) {
				$config = ATI_Cookie_Guard::script_config(
					array(
						'mode'   => ATI_Cookie_Guard::MODE_OFF,
						'expose' => true,
						'rules'  => array_values( ATI_Cookie_Rules::active_rules() ),
					)
				);
				echo '<script id="ati-debug-bar-guard">' . "\n";
				echo 'window.atiCookieGuard = ' . wp_json_encode( $config ) . ";\n";
				echo $guard_source; // phpcs:ignore WordPress.Security.EscapeOutput
				echo "\n</script>\n";
			}
		}

		echo '<script id="ati-debug-bar">' . "\n";
		echo 'window.atiDebugBar = ' . wp_json_encode( self::snapshot() ) . ";\n";
		echo $source; // phpcs:ignore WordPress.Security.EscapeOutput
		echo "\n</script>\n";
	}

	/**
	 * Stili del pannello. Prefissati `#ati-dbg` per non toccare il tema.
	 *
	 * @return string
	 */
	protected static function styles() {
		return '
#ati-dbg{position:fixed;right:16px;bottom:16px;z-index:999999;font:12px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#e6edf3;max-width:min(560px,calc(100vw - 32px));box-sizing:border-box}
#ati-dbg *{box-sizing:border-box}
#ati-dbg button{font:inherit;cursor:pointer;color:inherit;background:transparent;border:0}
#ati-dbg-toggle{display:flex;align-items:center;gap:8px;background:#161b22;border:1px solid #30363d;border-radius:999px;padding:7px 14px;box-shadow:0 6px 20px rgba(0,0,0,.35);color:#e6edf3;font-weight:600}
#ati-dbg-toggle:hover{border-color:#8b949e}
#ati-dbg-toggle .ati-dbg-pill{border-radius:999px;padding:1px 7px;font-size:11px;font-weight:700}
#ati-dbg-panel{display:none;background:#0d1117;border:1px solid #30363d;border-radius:10px;box-shadow:0 12px 40px rgba(0,0,0,.5);overflow:hidden;max-height:min(70vh,640px);flex-direction:column}
#ati-dbg.ati-dbg-open #ati-dbg-panel{display:flex}
#ati-dbg.ati-dbg-open #ati-dbg-toggle{display:none}
#ati-dbg-head{display:flex;align-items:center;gap:8px;padding:9px 12px;background:#161b22;border-bottom:1px solid #30363d}
#ati-dbg-head strong{font-size:12px}
#ati-dbg-head .ati-dbg-ver{color:#8b949e;font-weight:400}
#ati-dbg-head .ati-dbg-actions{margin-left:auto;display:flex;gap:6px}
#ati-dbg-head .ati-dbg-actions button{border:1px solid #30363d;border-radius:6px;padding:3px 8px;color:#c9d1d9}
#ati-dbg-head .ati-dbg-actions button:hover{border-color:#8b949e;color:#fff}
#ati-dbg-tabs{display:flex;flex-wrap:wrap;gap:2px;padding:6px 8px 0;background:#161b22;border-bottom:1px solid #30363d}
#ati-dbg-tabs button{padding:5px 9px;border-radius:6px 6px 0 0;color:#8b949e;border:1px solid transparent;border-bottom:0}
#ati-dbg-tabs button[aria-selected="true"]{background:#0d1117;border-color:#30363d;color:#e6edf3;font-weight:600}
#ati-dbg-body{padding:10px 12px 14px;overflow:auto}
#ati-dbg h4{margin:12px 0 6px;font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:#8b949e}
#ati-dbg h4:first-child{margin-top:0}
#ati-dbg table{width:100%;border-collapse:collapse}
#ati-dbg th,#ati-dbg td{text-align:left;padding:4px 6px;border-bottom:1px solid #21262d;vertical-align:top}
#ati-dbg th{color:#8b949e;font-weight:600;white-space:nowrap}
#ati-dbg code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11px;color:#e6edf3;background:#161b22;border-radius:4px;padding:1px 4px;word-break:break-all}
#ati-dbg .ati-dbg-note{color:#8b949e;margin:6px 0 0}
#ati-dbg .ati-dbg-msg{border-left:3px solid #30363d;padding:6px 8px;margin:0 0 6px;background:#161b22;border-radius:0 6px 6px 0}
#ati-dbg .ati-dbg-msg.error{border-left-color:#f85149}
#ati-dbg .ati-dbg-msg.warn{border-left-color:#d29922}
#ati-dbg .ati-dbg-msg.info{border-left-color:#58a6ff}
#ati-dbg .ok{color:#3fb950}
#ati-dbg .bad{color:#f85149}
#ati-dbg .warn{color:#d29922}
#ati-dbg .muted{color:#8b949e}
#ati-dbg .info{color:#58a6ff}
#ati-dbg .ati-dbg-kv{display:grid;grid-template-columns:auto 1fr;gap:2px 10px}
#ati-dbg .ati-dbg-kv span:nth-child(odd){color:#8b949e;white-space:nowrap}
#ati-dbg .ati-dbg-empty{color:#8b949e;font-style:italic}
@media (max-width:600px){#ati-dbg{left:12px;right:12px;max-width:none}}
';
	}
}
