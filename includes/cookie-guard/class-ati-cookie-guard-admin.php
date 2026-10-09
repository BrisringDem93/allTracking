<?php
/**
 * ATI_Cookie_Guard_Admin — Tab "Blocco Cookie" della pagina settings unificata.
 *
 * Mostra: modalità di intervento, CMP configurato, stato dei consensi lasciati
 * dall'utente (lato server e live nel browser), elenco dei cookie presenti con
 * l'esito che avrebbero, editor delle regole e tester interattivo.
 *
 * Il gruppo di opzioni è `ati_cookie_settings` ed è ESCLUSIVO di questo tab:
 * options.php azzera le opzioni registrate nel gruppo ma assenti dal POST, quindi
 * le opzioni condivise con altri tab (es. `ati_consent_cookie_name`) non vengono
 * mai registrate qui — sono solo mostrate in sola lettura.
 *
 * @package QuickTrackingIntegration\CookieGuard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pagina admin del blocco cookie.
 */
class ATI_Cookie_Guard_Admin {

	const GROUP = 'ati_cookie_settings';
	const NONCE = 'ati_cookie_guard_admin';

	/**
	 * Aggancia gli hook admin.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_ati_cg_action', array( __CLASS__, 'handle_action' ) );
	}

	/**
	 * URL del tab.
	 *
	 * @return string
	 */
	public static function tab_url() {
		return admin_url( 'options-general.php?page=ati-settings&tab=cookies' );
	}

	/**
	 * Registrazione impostazioni.
	 *
	 * @return void
	 */
	public static function register_settings() {
		$text = array( 'sanitize_callback' => 'sanitize_text_field' );

		register_setting( self::GROUP, 'ati_cg_mode', array( 'sanitize_callback' => array( __CLASS__, 'sanitize_mode' ), 'default' => ATI_Cookie_Guard::MODE_DEFAULT ) );
		register_setting( self::GROUP, 'ati_cg_skip_logged_in', $text );
		register_setting( self::GROUP, 'ati_cg_server_cleanup', $text );
		register_setting( self::GROUP, 'ati_cg_debug', $text );
		register_setting( self::GROUP, 'ati_cg_cmp', array( 'sanitize_callback' => array( __CLASS__, 'sanitize_cmp' ), 'default' => 'auto' ) );
		register_setting( self::GROUP, 'ati_cg_preferences_cookie_name', $text );
		register_setting( self::GROUP, 'ati_cg_consent_event', $text );
		register_setting( self::GROUP, 'ati_cg_iub_preferences', array( 'sanitize_callback' => 'absint' ) );
		register_setting( self::GROUP, 'ati_cg_iub_analytics', array( 'sanitize_callback' => 'absint' ) );
		register_setting( self::GROUP, 'ati_cg_iub_marketing', array( 'sanitize_callback' => 'absint' ) );
		register_setting( self::GROUP, 'ati_cg_sweep_interval', array( 'sanitize_callback' => 'absint' ) );
		register_setting( self::GROUP, 'ati_cg_sweep_duration', array( 'sanitize_callback' => 'absint' ) );
		register_setting( self::GROUP, ATI_Cookie_Rules::OPTION_RULES, array( 'sanitize_callback' => array( 'ATI_Cookie_Rules', 'sanitize_rules' ) ) );
		register_setting( self::GROUP, ATI_Cookie_Rules::OPTION_ALLOWLIST, array( 'sanitize_callback' => array( 'ATI_Cookie_Rules', 'sanitize_allowlist' ) ) );
	}

	/**
	 * @param string $v Valore.
	 * @return string
	 */
	public static function sanitize_mode( $v ) {
		$allowed = array( ATI_Cookie_Guard::MODE_OFF, ATI_Cookie_Guard::MODE_MONITOR, ATI_Cookie_Guard::MODE_ENFORCE );
		return in_array( $v, $allowed, true ) ? $v : ATI_Cookie_Guard::MODE_OFF;
	}

	/**
	 * @param string $v Valore.
	 * @return string
	 */
	public static function sanitize_cmp( $v ) {
		return array_key_exists( (string) $v, ATI_Cookie_Consent::providers() ) ? (string) $v : 'auto';
	}

	/**
	 * Azioni dirette sul set di regole (preset, svuota).
	 *
	 * @return void
	 */
	public static function handle_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Permesso negato', '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE );

		$op      = isset( $_POST['ati_cg_op'] ) ? sanitize_key( $_POST['ati_cg_op'] ) : '';
		$notice  = '';
		$current = ATI_Cookie_Rules::rules();

		switch ( $op ) {
			case 'presets':
				// Aggiunge solo i preset non già presenti (match su operatore+valore).
				$existing = array();
				foreach ( $current as $rule ) {
					$existing[] = strtolower( $rule['match'] . '|' . $rule['value'] . '|' . ( isset( $rule['value2'] ) ? $rule['value2'] : '' ) );
				}
				$added = 0;
				foreach ( ATI_Cookie_Rules::presets() as $preset ) {
					$key = strtolower( $preset['match'] . '|' . $preset['value'] . '|' . $preset['value2'] );
					if ( in_array( $key, $existing, true ) ) {
						continue;
					}
					$current[]  = $preset;
					$existing[] = $key;
					$added++;
				}
				update_option( ATI_Cookie_Rules::OPTION_RULES, $current );
				$notice = 'presets_' . $added;
				break;

			case 'restore':
				// Torna alla configurazione predefinita: eliminando l'opzione, rules()
				// riprende a restituire default_rules().
				delete_option( ATI_Cookie_Rules::OPTION_RULES );
				$notice = 'restored';
				break;

			case 'enable_all':
				foreach ( $current as $i => $rule ) {
					$current[ $i ]['enabled'] = 1;
				}
				update_option( ATI_Cookie_Rules::OPTION_RULES, $current );
				$notice = 'enabled';
				break;

			case 'disable_all':
				foreach ( $current as $i => $rule ) {
					$current[ $i ]['enabled'] = 0;
				}
				update_option( ATI_Cookie_Rules::OPTION_RULES, $current );
				$notice = 'disabled';
				break;

			case 'clear':
				update_option( ATI_Cookie_Rules::OPTION_RULES, array() );
				$notice = 'cleared';
				break;
		}

		wp_safe_redirect( add_query_arg( 'ati_cg_notice', rawurlencode( $notice ), self::tab_url() ) );
		exit;
	}

	/**
	 * Carica gli script del pannello (guard in sola lettura + UI admin).
	 *
	 * @return void
	 */
	protected static function enqueue_admin_assets() {
		$ver  = defined( 'ATI_PLUGIN_VERSION' ) ? ATI_PLUGIN_VERSION : '1.0.0';
		$root = dirname( __DIR__, 2 ) . '/plugin.php';

		// Stesso identico motore usato sul front-end, ma in sola lettura: mode=off
		// non installa nulla, expose=true pubblica l'API di valutazione. Così
		// l'anteprima del pannello non può divergere dal comportamento reale.
		wp_register_script( 'ati-cookie-guard', plugins_url( 'assets/js/cookie-guard.js', $root ), array(), $ver, true );
		wp_add_inline_script(
			'ati-cookie-guard',
			'window.atiCookieGuard = ' . wp_json_encode(
				ATI_Cookie_Guard::script_config(
					array(
						'mode'   => ATI_Cookie_Guard::MODE_OFF,
						'expose' => true,
						// Nel pannello si valutano TUTTE le regole salvate, anche quelle
						// disattivate resterebbero invisibili: si usano solo le attive,
						// coerentemente con ciò che accade sul sito.
						'rules'  => array_values( ATI_Cookie_Rules::active_rules() ),
					)
				)
			) . ';',
			'before'
		);
		wp_enqueue_script( 'ati-cookie-guard' );

		wp_register_script( 'ati-cookie-guard-admin', plugins_url( 'assets/js/cookie-guard-admin.js', $root ), array( 'ati-cookie-guard' ), $ver, true );
		wp_add_inline_script(
			'ati-cookie-guard-admin',
			'window.atiCookieGuardAdmin = ' . wp_json_encode(
				array(
					'categoryLabels' => ATI_Cookie_Rules::category_labels(),
					'matchLabels'    => ATI_Cookie_Rules::match_labels(),
				)
			) . ';',
			'before'
		);
		wp_enqueue_script( 'ati-cookie-guard-admin' );
	}

	/**
	 * Avviso dopo un'azione sulle regole.
	 *
	 * @return void
	 */
	protected static function render_notice() {
		$notice = isset( $_GET['ati_cg_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['ati_cg_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' === $notice ) {
			return;
		}
		$message = '';
		if ( 0 === strpos( $notice, 'presets_' ) ) {
			$n       = (int) substr( $notice, 8 );
			$message = $n > 0
				? sprintf( '%d regole predefinite aggiunte e attivate.', $n )
				: 'Nessuna nuova regola da aggiungere: le regole predefinite erano già presenti.';
		} elseif ( 'restored' === $notice ) {
			$message = 'Configurazione predefinita ripristinata: sono di nuovo attive le regole di partenza.';
		} elseif ( 'enabled' === $notice ) {
			$message = 'Tutte le regole sono state attivate.';
		} elseif ( 'disabled' === $notice ) {
			$message = 'Tutte le regole sono state disattivate.';
		} elseif ( 'cleared' === $notice ) {
			$message = 'Tutte le regole sono state eliminate.';
		}
		if ( '' !== $message ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}
	}

	/**
	 * Rende il contenuto del tab (dentro il wrap della pagina settings unificata).
	 *
	 * @return void
	 */
	public static function render_tab() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		self::enqueue_admin_assets();
		self::render_notice();

		$mode      = ATI_Cookie_Guard::mode();
		$consent   = ATI_Cookie_Consent::state();
		$rules     = ATI_Cookie_Rules::rules();
		$rules[]   = ATI_Cookie_Rules::empty_rule(); // Riga vuota per aggiungerne una.
		$custom    = ATI_Cookie_Consent::custom_cookies();
		$active_n  = count( ATI_Cookie_Rules::active_rules() );
		$providers = ATI_Cookie_Consent::detected_providers();
		?>

		<h2>Blocco dei cookie senza consenso</h2>
		<p class="description" style="max-width:900px">
			Impedisce la scrittura dei cookie non consentiti e cancella quelli già presenti, in base a
			<strong>regole granulari per categoria di consenso</strong>. Il controllo agisce nel browser sostituendo il
			setter di <code>document.cookie</code> prima che qualunque pixel possa scrivere.
		</p>

		<?php if ( ATI_Cookie_Rules::is_using_defaults() ) : ?>
			<div class="notice notice-info inline" style="max-width:900px"><p>
				<strong>Configurazione predefinita in uso.</strong> Il plugin parte già con
				<?php echo (int) $active_n; ?> regole attive che bloccano i cookie dei tracker più diffusi
				(<code>_ga</code>, <code>_ga_*</code>, <code>_gid</code>, <code>_fbp</code>, <code>_fbc</code>,
				<code>_gcl_*</code>, Hotjar, Clarity, LinkedIn, TikTok, …) quando manca il consenso della loro categoria.
				Puoi disattivarle singolarmente, eliminarle (svuota il campo «Valore») o spegnere tutto dalla
				<strong>modalità</strong> qui sotto. Al primo salvataggio questa configurazione diventa tua.
			</p></div>
		<?php endif; ?>

		<?php if ( ATI_Cookie_Guard::MODE_ENFORCE === $mode && $active_n > 0 ) : ?>
			<div class="notice notice-warning inline" style="max-width:900px"><p>
				<strong>Blocco attivo</strong> con <?php echo (int) $active_n; ?> regole. Verifica sul sito (in navigazione anonima)
				che il banner del CMP, il login e i form continuino a funzionare.
			</p></div>
			<?php if ( empty( $providers ) ) : ?>
				<div class="notice notice-error inline" style="max-width:900px"><p>
					<strong>Nessun CMP rilevato nei cookie di questa richiesta.</strong> Se il sito non ha un cookie banner
					riconosciuto (Complianz, iubenda, Cookiebot, OneTrust) o un cookie di consenso personalizzato, il consenso
					risulta sempre assente e i cookie di analytics e marketing verranno bloccati <em>sempre</em>, anche per chi
					accetta. Controlla la tabella dei consensi qui sotto: se anche la colonna «Browser» è tutta rossa mentre hai
					accettato il banner, passa alla modalità <strong>Monitoraggio</strong> e verifica il rilevamento del CMP
					prima di riattivare il blocco.
				</p></div>
			<?php endif; ?>
		<?php endif; ?>

		<hr />
		<h2>I tuoi consensi in questo momento</h2>
		<p class="description">
			A sinistra ciò che vede il <strong>server</strong> (cookie inviati con questa richiesta), a destra ciò che vede il
			<strong>browser</strong> in tempo reale. Se differiscono, il consenso è cambiato dopo il caricamento della pagina.
		</p>
		<table class="widefat striped" style="max-width:900px">
			<thead>
				<tr><th style="width:32%">Categoria</th><th style="width:22%">Server (PHP)</th><th style="width:22%">Browser (live)</th><th>Note</th></tr>
			</thead>
			<tbody>
				<tr>
					<td><strong>Necessari</strong></td>
					<td>✅ sempre</td>
					<td>✅ sempre</td>
					<td class="description">Mai bloccati: sono in allowlist.</td>
				</tr>
				<?php
				$rows = array(
					'preferences' => 'Preferenze / funzionali',
					'analytics'   => 'Statistiche / analytics',
					'marketing'   => 'Marketing / pubblicità',
				);
				foreach ( $rows as $key => $label ) :
					?>
					<tr>
						<td><strong><?php echo esc_html( $label ); ?></strong> <code><?php echo esc_html( $key ); ?></code></td>
						<td><?php echo ! empty( $consent[ $key ] ) ? '<span style="color:green">✅ concesso</span>' : '<span style="color:#b32d2e">❌ non concesso</span>'; ?></td>
						<td data-ati-consent="<?php echo esc_attr( $key ); ?>"><em>…</em></td>
						<td class="description">
							<?php
							if ( '' !== $custom[ $key ] ) {
								echo 'Cookie personalizzato: <code>' . esc_html( $custom[ $key ] ) . '</code>';
							} else {
								echo 'Rilevamento dal CMP';
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p>
			<strong>CMP rilevati (server):</strong> <code><?php echo esc_html( implode( ', ', $providers ) ?: 'nessuno' ); ?></code>
			&nbsp;·&nbsp;
			<strong>CMP rilevati (browser):</strong> <code data-ati-providers>…</code>
			&nbsp;·&nbsp;
			<button type="button" class="button button-small" data-ati-refresh>Aggiorna</button>
		</p>
		<p class="description">
			I cookie personalizzati di marketing e analytics si impostano rispettivamente nel tab
			<a href="<?php echo esc_url( admin_url( 'options-general.php?page=ati-settings&tab=general' ) ); ?>">Generale</a> e
			<a href="<?php echo esc_url( admin_url( 'options-general.php?page=ati-settings&tab=ga4' ) ); ?>">GA4 Server-Side</a>:
			il blocco cookie riusa quelle impostazioni, senza duplicarle.
		</p>
		<?php if ( class_exists( 'ATI_Debug_Bar' ) && ATI_Debug_Bar::debug_enabled() ) : ?>
			<p class="description">
				💡 Le stesse informazioni — più i cookie <strong>attesi</strong> e quelli che non dovrebbero esserci — sono disponibili
				<strong>mentre navighi il sito</strong>: il <em>widget di debug</em> in basso a destra sul front-end. Si configura nel tab
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=ati-settings&tab=general' ) ); ?>">Generale</a> e lo vedi
				solo tu, non i visitatori.
			</p>
		<?php endif; ?>

		<hr />
		<h2>Cookie presenti ora</h2>
		<p class="description">
			Elenco letto dal <strong>tuo</strong> browser con l'esito che ciascun cookie avrebbe con le regole attualmente
			<strong>salvate e attive</strong>. Non vengono mostrati i valori. I cookie <code>HttpOnly</code> e quelli di terze parti
			non sono leggibili da JavaScript: quelli ricevuti dal server sono elencati nella tabella successiva.
		</p>
		<div data-ati-cookie-list><p><em>Caricamento…</em></p></div>

		<h3>Cookie ricevuti dal server in questa richiesta</h3>
		<table class="widefat striped" style="max-width:900px">
			<thead><tr><th style="width:40%">Nome</th><th style="width:25%">Esito</th><th>Regola</th></tr></thead>
			<tbody>
			<?php
			$server_names = array_keys( (array) $_COOKIE );
			sort( $server_names );
			$active_rules = ATI_Cookie_Rules::active_rules();
			$allowlist    = ATI_Cookie_Rules::allowlist_patterns();
			if ( empty( $server_names ) ) :
				?>
				<tr><td colspan="3"><em>Nessun cookie ricevuto.</em></td></tr>
			<?php else : ?>
				<?php foreach ( $server_names as $name ) : ?>
					<?php
					$eval  = ATI_Cookie_Rules::evaluate( (string) $name, '', $consent, $active_rules, $allowlist );
					$badge = '<span class="description">consentito</span>';
					if ( 'allowlist' === $eval['reason'] ) {
						$badge = '<span style="color:#2271b1">🛡️ allowlist</span>';
					} elseif ( $eval['blocked'] ) {
						$badge = '<span style="color:#b32d2e">⛔ ' . esc_html( ATI_Cookie_Rules::should_delete( $eval ) ? 'da cancellare' : 'da bloccare' ) . '</span>';
					} elseif ( 'consent_granted' === $eval['reason'] ) {
						$badge = '<span style="color:green">✅ consenso presente</span>';
					}
					?>
					<tr>
						<td><code><?php echo esc_html( $name ); ?></code></td>
						<td><?php echo wp_kses_post( $badge ); ?></td>
						<td><?php echo esc_html( '' !== $eval['label'] ? $eval['label'] : ( null !== $eval['rule'] ? '#' . ( (int) $eval['rule'] + 1 ) : '—' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>

		<hr />
		<h2>Provalo su un nome di cookie</h2>
		<p class="description">Verifica cosa succederebbe a un cookie, con lo stato di consenso attuale del tuo browser.</p>
		<p>
			<input type="text" data-ati-test-name class="regular-text code" placeholder="es: _ga_ABC123" />
			<input type="text" data-ati-test-domain class="regular-text code" placeholder="dominio (opzionale), es: .example.com" />
		</p>
		<div data-ati-test-result></div>

		<hr />
		<form method="post" action="options.php">
			<?php settings_fields( self::GROUP ); ?>

			<h2>Modalità</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="ati_cg_mode">Intervento sui cookie</label></th>
					<td>
						<select name="ati_cg_mode" id="ati_cg_mode">
							<?php foreach ( ATI_Cookie_Guard::mode_labels() as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $mode, $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							Predefinita: <strong>Attivo</strong>. Se qualcosa non torna, passa a <strong>Monitoraggio</strong>:
							il plugin scrive in console del browser cosa bloccherebbe, senza toccare nulla — utile per
							verificare l'elenco prima di riattivare il blocco. <strong>Disattivato</strong> spegne del tutto
							la funzione, senza perdere le regole configurate.
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Sicurezza</th>
					<td>
						<fieldset>
							<label><input type="checkbox" name="ati_cg_skip_logged_in" value="1" <?php checked( get_option( 'ati_cg_skip_logged_in', '1' ), '1' ); ?> /> Non applicare agli utenti loggati (consigliato)</label><br />
							<label><input type="checkbox" name="ati_cg_server_cleanup" value="1" <?php checked( get_option( 'ati_cg_server_cleanup', '0' ), '1' ); ?> /> Cancella anche lato server i cookie non consentiti ricevuti nella richiesta</label>
							<p class="description">
								La pulizia lato server intercetta anche i cookie scritti da header HTTP <code>Set-Cookie</code>, invisibili a JavaScript.
								Il dominio esatto non è noto al server: la cancellazione viene tentata su tutte le varianti del dominio corrente.
							</p>
							<label><input type="checkbox" name="ati_cg_debug" value="1" <?php checked( get_option( 'ati_cg_debug', '0' ), '1' ); ?> /> Log dettagliato in console del browser</label>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row">Passate di pulizia</th>
					<td>
						<label>ogni <input type="number" name="ati_cg_sweep_interval" min="250" step="250" value="<?php echo esc_attr( (int) get_option( 'ati_cg_sweep_interval', 2000 ) ); ?>" class="small-text" /> ms</label>
						&nbsp;
						<label>per <input type="number" name="ati_cg_sweep_duration" min="0" step="1000" value="<?php echo esc_attr( (int) get_option( 'ati_cg_sweep_duration', 60000 ) ); ?>" class="small-text" /> ms</label>
						<p class="description">
							Oltre alla passata iniziale e a quelle sugli eventi di consenso. <code>0</code> disattiva le passate periodiche
							(il blocco in scrittura resta comunque attivo). Serve per i cookie scritti da script caricati in ritardo.
						</p>
					</td>
				</tr>
			</table>

			<h2>Rilevamento del consenso (CMP)</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="ati_cg_cmp">Cookie banner / CMP</label></th>
					<td>
						<select name="ati_cg_cmp" id="ati_cg_cmp">
							<?php foreach ( ATI_Cookie_Consent::providers() as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( ATI_Cookie_Consent::configured_provider(), $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							Con <strong>Rilevamento automatico</strong> vengono letti tutti i CMP supportati (Complianz, iubenda, Cookiebot, OneTrust)
							più gli eventuali cookie personalizzati. Forza un CMP specifico se sul sito ne convivono più di uno.
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ati_cg_preferences_cookie_name">Cookie preferenze personalizzato</label></th>
					<td>
						<input name="ati_cg_preferences_cookie_name" type="text" id="ati_cg_preferences_cookie_name" value="<?php echo esc_attr( get_option( 'ati_cg_preferences_cookie_name', '' ) ); ?>" class="regular-text" placeholder="opzionale (valore atteso: allow)" />
						<p class="description">Solo per la categoria "preferenze". Marketing e analytics riusano i cookie già configurati negli altri tab.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ati_cg_consent_event">Evento JS di cambio consenso</label></th>
					<td>
						<input name="ati_cg_consent_event" type="text" id="ati_cg_consent_event" value="<?php echo esc_attr( get_option( 'ati_cg_consent_event', '' ) ); ?>" class="regular-text" placeholder="es: myConsentUpdated" />
						<p class="description">
							Alla ricezione dell'evento il consenso viene rivalutato subito. Gli eventi dei CMP supportati
							(<code>cmplz_status_change</code>, <code>CookiebotOnAccept</code>, <code>OneTrustGroupsUpdated</code>,
							<code>iubenda_consent_given</code>) sono già gestiti.
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Purpose iubenda</th>
					<td>
						<label>preferenze <input type="number" name="ati_cg_iub_preferences" min="1" max="10" value="<?php echo esc_attr( (int) get_option( 'ati_cg_iub_preferences', 3 ) ); ?>" class="small-text" /></label>
						&nbsp;<label>analytics <input type="number" name="ati_cg_iub_analytics" min="1" max="10" value="<?php echo esc_attr( (int) get_option( 'ati_cg_iub_analytics', 4 ) ); ?>" class="small-text" /></label>
						&nbsp;<label>marketing <input type="number" name="ati_cg_iub_marketing" min="1" max="10" value="<?php echo esc_attr( (int) get_option( 'ati_cg_iub_marketing', 5 ) ); ?>" class="small-text" /></label>
						<p class="description">Indici dei purpose nel cookie <code>_iub_cs-*</code>. I default (3/4/5) coprono la configurazione standard.</p>
					</td>
				</tr>
			</table>

			<h2>Regole di blocco</h2>
			<p class="description" style="max-width:900px">
				Una regola blocca il cookie <strong>solo quando manca il consenso della categoria indicata</strong>.
				Appena l'utente accetta quella categoria, il cookie viene consentito senza ricaricare la pagina.
				Le regole sono valutate in ordine: vince la prima che blocca.
			</p>
			<table class="widefat striped" style="max-width:1200px" data-ati-rules>
				<thead>
					<tr>
						<th style="width:50px">Attiva</th>
						<th style="width:170px">Etichetta</th>
						<th style="width:160px">Categoria</th>
						<th style="width:190px">Il nome del cookie…</th>
						<th>Valore</th>
						<th>…e finisce con</th>
						<th style="width:50px" title="Ignora maiuscole/minuscole">Aa</th>
						<th style="width:200px">Azione</th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $rules as $i => $rule ) : ?>
					<?php
					$rule    = is_array( $rule ) ? array_merge( ATI_Cookie_Rules::empty_rule(), $rule ) : ATI_Cookie_Rules::empty_rule();
					$invalid = '' !== $rule['value'] && ! ATI_Cookie_Rules::is_valid( $rule );
					?>
					<tr<?php echo $invalid ? ' style="background:#fcf0f1"' : ''; ?>>
						<td><input type="checkbox" name="ati_cg_rules[<?php echo (int) $i; ?>][enabled]" value="1" <?php checked( ! empty( $rule['enabled'] ) ); ?> /></td>
						<td><input type="text" name="ati_cg_rules[<?php echo (int) $i; ?>][label]" value="<?php echo esc_attr( $rule['label'] ); ?>" class="regular-text" style="width:100%" placeholder="es: Google Analytics" /></td>
						<td>
							<select name="ati_cg_rules[<?php echo (int) $i; ?>][category]" style="width:100%">
								<?php foreach ( ATI_Cookie_Rules::category_labels() as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $rule['category'], $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
						<td>
							<select name="ati_cg_rules[<?php echo (int) $i; ?>][match]" style="width:100%">
								<?php foreach ( ATI_Cookie_Rules::match_labels() as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $rule['match'], $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
						<td><input type="text" name="ati_cg_rules[<?php echo (int) $i; ?>][value]" value="<?php echo esc_attr( $rule['value'] ); ?>" class="code" style="width:100%" placeholder="_ga" /></td>
						<td><input type="text" name="ati_cg_rules[<?php echo (int) $i; ?>][value2]" value="<?php echo esc_attr( $rule['value2'] ); ?>" class="code" style="width:100%" placeholder="solo per «inizia con … e finisce con …»" /></td>
						<td><input type="checkbox" name="ati_cg_rules[<?php echo (int) $i; ?>][ci]" value="1" <?php checked( ! empty( $rule['ci'] ) ); ?> title="Ignora maiuscole/minuscole" /></td>
						<td>
							<select name="ati_cg_rules[<?php echo (int) $i; ?>][action]" style="width:100%">
								<?php foreach ( ATI_Cookie_Rules::action_labels() as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $rule['action'], $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<?php if ( $invalid ) : ?>
								<span style="color:#b32d2e">⚠️ espressione non valida</span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p>
				<button type="button" class="button" data-ati-add-rule>+ Aggiungi riga</button>
				<span class="description">Per eliminare una regola svuota il campo «Valore» e salva.</span>
			</p>

			<h3>Come si scrivono i valori</h3>
			<table class="widefat" style="max-width:900px">
				<tbody>
					<tr><td style="width:230px"><strong>contiene</strong></td><td>«cookie che contengono <code>analytics</code>» → valore <code>analytics</code></td></tr>
					<tr><td><strong>inizia con</strong></td><td><code>_ga</code> blocca <code>_ga</code>, <code>_ga_ABC123</code>, <code>_gali</code></td></tr>
					<tr><td><strong>finisce con</strong></td><td><code>_id</code> blocca <code>visitor_id</code>, <code>user_id</code></td></tr>
					<tr><td><strong>inizia con … e finisce con …</strong></td><td>valore <code>_pk_</code> + secondo campo <code>.1</code> → blocca <code>_pk_id.1</code></td></tr>
					<tr><td><strong>pattern (* e ?)</strong></td><td><code>_hj*</code>, <code>_cl?k</code>, <code>*_uet*</code></td></tr>
					<tr><td><strong>regex</strong></td><td><code>^_ga(_[A-Z0-9]+)?$</code> (senza delimitatori)</td></tr>
					<tr><td><strong>appartiene al dominio</strong></td><td><code>doubleclick.net</code> blocca i cookie scritti per quel dominio e i suoi sottodomini. Funziona solo <em>in scrittura</em>: nella cancellazione dei cookie già presenti il dominio non è leggibile da JavaScript.</td></tr>
				</tbody>
			</table>

			<h2>Allowlist personalizzata</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="ati_cg_allowlist">Cookie da non toccare mai</label></th>
					<td>
						<textarea name="ati_cg_allowlist" id="ati_cg_allowlist" rows="5" class="large-text code" placeholder="un pattern per riga, con * e ? — es: mio_cookie_*"><?php echo esc_textarea( (string) get_option( ATI_Cookie_Rules::OPTION_ALLOWLIST, '' ) ); ?></textarea>
						<p class="description">
							Ha la precedenza su qualunque regola. Oltre a questi, il plugin protegge <strong>sempre</strong> i cookie di sessione
							WordPress/WooCommerce, quelli dei CMP e i propri:
							<code><?php echo esc_html( implode( '  ', ATI_Cookie_Rules::protected_patterns() ) ); ?></code>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:10px">
			<input type="hidden" name="action" value="ati_cg_action" />
			<?php wp_nonce_field( self::NONCE ); ?>
			<button type="submit" name="ati_cg_op" value="presets" class="button">Aggiungi le regole predefinite mancanti</button>
			<button type="submit" name="ati_cg_op" value="restore" class="button" onclick="return confirm('Ripristinare la configurazione predefinita? Le regole personalizzate verranno perse.');">Ripristina configurazione predefinita</button>
			<button type="submit" name="ati_cg_op" value="enable_all" class="button">Attiva tutte</button>
			<button type="submit" name="ati_cg_op" value="disable_all" class="button">Disattiva tutte</button>
			<button type="submit" name="ati_cg_op" value="clear" class="button" onclick="return confirm('Eliminare tutte le regole di blocco cookie?');">Elimina tutte</button>
			<p class="description">
				Le regole predefinite coprono Google Analytics, Google Ads/DoubleClick, Meta, Hotjar, Clarity, Matomo, UET,
				LinkedIn, TikTok, Pinterest, X, Yandex e Snapchat, e vengono aggiunte <strong>attive</strong>.
				«Elimina tutte» lascia il sito senza alcun blocco e non fa ricomparire le predefinite.
				Queste azioni salvano subito: salva prima le modifiche in sospeso.
			</p>
		</form>

		<hr />
		<h2>Limiti da conoscere</h2>
		<ul style="list-style:disc;margin-left:20px;max-width:900px">
			<li>I cookie impostati da <strong>header HTTP di terze parti</strong> (es. iframe di YouTube, DoubleClick) non sono intercettabili da JavaScript né cancellabili da questo sito: vanno bloccati non caricando lo script/iframe finché manca il consenso.</li>
			<li>I cookie <code>HttpOnly</code> non sono leggibili né scrivibili da JavaScript: solo la pulizia lato server può rimuoverli.</li>
			<li>Il guard agisce <strong>da quando viene eseguito</strong>: uno script inserito prima nell'<code>&lt;head&gt;</code> (o un cookie già sul browser) viene intercettato dalla passata di pulizia, non dal blocco in scrittura.</li>
			<li><code>localStorage</code> e <code>sessionStorage</code> non sono cookie e non sono gestiti da questa funzione.</li>
			<li>Il blocco non sostituisce un CMP: serve a far rispettare le scelte già raccolte dal banner.</li>
		</ul>
		<?php
	}
}
