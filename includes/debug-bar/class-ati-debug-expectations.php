<?php
/**
 * ATI_Debug_Expectations — Quali cookie DOVREBBERO esserci (e quali no).
 *
 * Logica PURA e deterministica (nessun output, nessuna rete, nessun DB): dato lo
 * stato della configurazione del plugin e il consenso per categoria, produce
 * l'elenco dei cookie attesi con il verdetto atteso:
 *
 * - `yes`   il cookie DEVE esserci (tag attivo + consenso concesso);
 * - `no`    il cookie NON deve esserci (tag spento, consenso assente, tracking
 *           disattivato per l'utente): se è presente c'è un problema;
 * - `maybe` dipende da condizioni non deducibili dalla configurazione (container
 *           GTM, presenza di un `fbclid` nell'URL, primo evento non ancora inviato).
 *
 * Il confronto con i cookie realmente presenti avviene nel browser
 * (`assets/js/debug-bar.js`), che è l'unico posto in cui si vedono i cookie
 * scritti da JavaScript.
 *
 * @package QuickTrackingIntegration\DebugBar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cookie attesi e diagnostica della configurazione.
 */
class ATI_Debug_Expectations {

	/**
	 * Il cookie deve essere presente.
	 */
	const YES = 'yes';

	/**
	 * Il cookie non deve essere presente.
	 */
	const NO = 'no';

	/**
	 * Presente o assente: entrambe le situazioni sono legittime.
	 */
	const MAYBE = 'maybe';

	/**
	 * Configurazione rilevante per le attese, letta dalle opzioni.
	 *
	 * Un tag conta come attivo solo se ha anche il proprio ID: senza ID il plugin
	 * non stampa nulla, quindi i suoi cookie non devono comparire.
	 *
	 * @return array
	 */
	public static function config() {
		$gtm_id = trim( (string) get_option( 'ati_gtm_id', '' ) );
		$ga4_id = trim( (string) get_option( 'ati_ga4_id', '' ) );
		$fb_id  = trim( (string) get_option( 'ati_fb_pixel_id', '' ) );

		return array(
			'gtm'            => array(
				'enabled' => self::flag( 'ati_enable_gtm' ) && '' !== $gtm_id,
				'id'      => $gtm_id,
			),
			'ga4'            => array(
				'enabled' => self::flag( 'ati_enable_ga4' ) && '' !== $ga4_id,
				'id'      => $ga4_id,
			),
			'fb'             => array(
				'enabled' => self::flag( 'ati_enable_fb' ) && '' !== $fb_id,
				'id'      => $fb_id,
			),
			'form_fields'    => '1' === (string) get_option( 'ati_enable_form_fields', '1' ),
			// Tracking spento per l'utente corrente: nessun tag, nessun cookie del plugin.
			'tracking_off'   => self::flag( 'ati_disable_logged_in' ) && is_user_logged_in(),
			'fbclid_in_url'  => isset( $_GET['fbclid'] ) && '' !== trim( (string) $_GET['fbclid'] ), // phpcs:ignore WordPress.Security.NonceVerification
			'custom_cookies' => class_exists( 'ATI_Cookie_Consent' ) ? ATI_Cookie_Consent::custom_cookies() : array(),
		);
	}

	/**
	 * Nome del cookie di sessione GA4 derivato dal Measurement ID.
	 *
	 * `G-ABC1234567` → `_ga_ABC1234567`. Stringa vuota se l'ID non è un
	 * Measurement ID GA4 (es. `UA-…`, campo vuoto, valore incollato male).
	 *
	 * @param string $measurement_id Measurement ID.
	 * @return string
	 */
	public static function ga_session_cookie( $measurement_id ) {
		$id = strtoupper( trim( (string) $measurement_id ) );
		if ( ! preg_match( '/^G-([A-Z0-9]+)$/', $id, $m ) ) {
			return '';
		}
		return '_ga_' . $m[1];
	}

	/**
	 * Elenco dei cookie attesi.
	 *
	 * @param array $cfg     Configurazione (vedi config()).
	 * @param array $consent Consenso per categoria: marketing/analytics/preferences.
	 * @return array<int,array{name:string,match:string,source:string,category:string,expected:string,why:string}>
	 */
	public static function rows( array $cfg, array $consent ) {
		$marketing = ! empty( $consent['marketing'] );
		$analytics = ! empty( $consent['analytics'] );

		// Un tag senza il proprio ID non viene stampato: non può scrivere cookie.
		$off    = ! empty( $cfg['tracking_off'] );
		$gtm    = self::tag_active( $cfg, 'gtm' );
		$ga4    = self::tag_active( $cfg, 'ga4' );
		$fb     = self::tag_active( $cfg, 'fb' );
		$ga4_id = self::tag_id( $cfg, 'ga4' );
		$rows   = array();

		// --- Google Analytics 4 (client-side) ---------------------------------
		$ga_state = self::tag_state(
			array(
				'off'      => $off,
				'gtm'      => $gtm,
				'active'   => $ga4,
				'consent'  => $analytics,
				'category' => 'statistiche',
				'inactive' => 'GA4 client-side non attivo in questo plugin: se il cookie è presente lo scrive un altro strumento.',
			)
		);

		$rows[] = array(
			'name'     => '_ga',
			'match'    => 'equals',
			'source'   => 'Google Analytics 4 — client id',
			'category' => 'analytics',
			'expected' => $ga_state['expected'],
			'why'      => $ga_state['why'],
		);

		$session = self::ga_session_cookie( $ga4_id );
		$rows[]  = array(
			'name'     => '' !== $session ? $session : '_ga_',
			'match'    => '' !== $session ? 'equals' : 'prefix',
			'source'   => 'Google Analytics 4 — sessione' . ( '' !== $session ? ' (' . $ga4_id . ')' : '' ),
			'category' => 'analytics',
			'expected' => $ga_state['expected'],
			'why'      => '' !== $session
				? $ga_state['why']
				: $ga_state['why'] . ' Measurement ID non riconosciuto: il nome esatto del cookie non è deducibile.',
		);

		// --- Meta Pixel -------------------------------------------------------
		$fb_state = self::tag_state(
			array(
				'off'      => $off,
				'gtm'      => $gtm,
				'active'   => $fb,
				'consent'  => $marketing,
				'category' => 'marketing',
				'inactive' => 'Meta Pixel non attivo in questo plugin: se il cookie è presente lo scrive un altro strumento.',
			)
		);

		$rows[] = array(
			'name'     => '_fbp',
			'match'    => 'equals',
			'source'   => 'Meta Pixel — browser id',
			'category' => 'marketing',
			'expected' => $fb_state['expected'],
			'why'      => $fb_state['why'],
		);

		// _fbc non dipende dal Pixel: lo scrive il plugin quando arriva un fbclid.
		$rows[] = array_merge(
			array(
				'name'     => '_fbc',
				'match'    => 'equals',
				'source'   => 'Meta — click id (fbclid)',
				'category' => 'marketing',
			),
			self::fbc_state( $off, $marketing, ! empty( $cfg['fbclid_in_url'] ) )
		);

		// --- Cookie del plugin ------------------------------------------------
		$rows[] = array_merge(
			array(
				'name'     => 'fst_uid',
				'match'    => 'equals',
				'source'   => 'Plugin — pseudonimo (external_id)',
				'category' => 'marketing',
			),
			self::plugin_cookie_state( $off, $marketing, true, 'Creato al primo evento tracciato dopo il consenso marketing.' )
		);

		$rows[] = array_merge(
			array(
				'name'     => 'fst_clid',
				'match'    => 'equals',
				'source'   => 'Plugin — click id per i campi hidden',
				'category' => 'marketing',
			),
			self::plugin_cookie_state(
				$off,
				$marketing,
				! empty( $cfg['form_fields'] ),
				'Scritto solo quando la pagina riceve un click id (fbclid, gclid, …) o un parametro utm.',
				'Compilazione automatica dei campi hidden disattivata.'
			)
		);

		// --- Cookie di consenso personalizzati --------------------------------
		$labels = array(
			'marketing'   => 'Consenso marketing (cookie personalizzato)',
			'analytics'   => 'Consenso statistiche (cookie personalizzato)',
			'preferences' => 'Consenso preferenze (cookie personalizzato)',
		);
		foreach ( $labels as $category => $label ) {
			$name = isset( $cfg['custom_cookies'][ $category ] ) ? trim( (string) $cfg['custom_cookies'][ $category ] ) : '';
			if ( '' === $name ) {
				continue;
			}
			$rows[] = array(
				'name'     => $name,
				'match'    => 'equals',
				'source'   => $label,
				'category' => 'necessary',
				'expected' => self::MAYBE,
				'why'      => 'Lo scrive il tuo CMP quando l\'utente esprime una scelta. Valore atteso: allow.',
			);
		}

		/**
		 * Filtra i cookie attesi mostrati dal widget di debug.
		 *
		 * @param array $rows    Righe calcolate.
		 * @param array $cfg     Configurazione.
		 * @param array $consent Consenso per categoria.
		 */
		return (array) apply_filters( 'ati_debug_expected_cookies', $rows, $cfg, $consent );
	}

	/**
	 * Il tag è attivo E ha il proprio ID? Senza ID il plugin non stampa nulla.
	 *
	 * @param array  $cfg Configurazione.
	 * @param string $tag gtm|ga4|fb.
	 * @return bool
	 */
	protected static function tag_active( array $cfg, $tag ) {
		return ! empty( $cfg[ $tag ]['enabled'] ) && '' !== self::tag_id( $cfg, $tag );
	}

	/**
	 * ID configurato di un tag.
	 *
	 * @param array  $cfg Configurazione.
	 * @param string $tag gtm|ga4|fb.
	 * @return string
	 */
	protected static function tag_id( array $cfg, $tag ) {
		return isset( $cfg[ $tag ]['id'] ) ? trim( (string) $cfg[ $tag ]['id'] ) : '';
	}

	/**
	 * Esito atteso per un cookie scritto da un tag client-side del plugin.
	 *
	 * @param array $args off/gtm/active/consent/category/inactive.
	 * @return array{expected:string,why:string}
	 */
	protected static function tag_state( array $args ) {
		if ( ! empty( $args['off'] ) ) {
			return array(
				'expected' => self::NO,
				'why'      => 'Tracking disattivato per gli utenti loggati: in questa pagina non viene caricato nessun tag.',
			);
		}
		if ( ! empty( $args['gtm'] ) ) {
			return array(
				'expected' => self::MAYBE,
				'why'      => 'Google Tag Manager è attivo: il plugin non carica questo tag, il cookie dipende dal container (Consent Mode nega tutto per default).',
			);
		}
		if ( empty( $args['active'] ) ) {
			return array(
				'expected' => self::MAYBE,
				'why'      => (string) $args['inactive'],
			);
		}
		if ( empty( $args['consent'] ) ) {
			return array(
				'expected' => self::NO,
				'why'      => 'Consenso ' . $args['category'] . ' assente: il tag non viene caricato e il cookie non deve esistere.',
			);
		}
		return array(
			'expected' => self::YES,
			'why'      => 'Tag attivo e consenso ' . $args['category'] . ' concesso.',
		);
	}

	/**
	 * Esito atteso per `_fbc`: lo scrive il plugin (lato server e lato client) solo
	 * con consenso marketing e solo se un `fbclid` è passato da questa navigazione.
	 *
	 * @param bool $off       Tracking disattivato per l'utente corrente.
	 * @param bool $marketing Consenso marketing.
	 * @param bool $fbclid    `fbclid` presente nell'URL della richiesta.
	 * @return array{expected:string,why:string}
	 */
	protected static function fbc_state( $off, $marketing, $fbclid ) {
		if ( $off ) {
			return array(
				'expected' => self::NO,
				'why'      => 'Tracking disattivato per gli utenti loggati: nessuna scrittura di _fbc.',
			);
		}
		if ( ! $marketing ) {
			return array(
				'expected' => self::NO,
				'why'      => 'Senza consenso marketing il cookie non viene scritto: il valore fbc viene comunque ricostruito in memoria per i campi hidden.',
			);
		}
		if ( $fbclid ) {
			return array(
				'expected' => self::YES,
				'why'      => 'L\'URL contiene un fbclid e il consenso marketing c\'è: il cookie viene scritto prima del primo byte di HTML.',
			);
		}
		return array(
			'expected' => self::MAYBE,
			'why'      => 'Presente solo se l\'utente è arrivato da un annuncio Meta (parametro fbclid) in questa o in una visita precedente.',
		);
	}

	/**
	 * Esito atteso per un cookie scritto dal plugin stesso.
	 *
	 * @param bool   $off       Tracking disattivato per l'utente corrente.
	 * @param bool   $marketing Consenso marketing.
	 * @param bool   $feature   Funzione che scrive il cookie attiva.
	 * @param string $why       Motivazione quando il consenso c'è.
	 * @param string $why_off   Motivazione quando la funzione è disattivata.
	 * @return array{expected:string,why:string}
	 */
	protected static function plugin_cookie_state( $off, $marketing, $feature, $why, $why_off = '' ) {
		if ( $off ) {
			return array(
				'expected' => self::NO,
				'why'      => 'Tracking disattivato per gli utenti loggati: il plugin non scrive nulla.',
			);
		}
		if ( ! $feature ) {
			return array(
				'expected' => self::NO,
				'why'      => '' !== $why_off ? $why_off : 'Funzione disattivata.',
			);
		}
		if ( ! $marketing ) {
			return array(
				'expected' => self::NO,
				'why'      => 'Nessuno storage senza consenso marketing (GDPR): il valore resta solo in memoria.',
			);
		}
		return array(
			'expected' => self::MAYBE,
			'why'      => $why,
		);
	}

	/**
	 * Diagnostica: cosa può spiegare un comportamento inatteso.
	 *
	 * Ordine: prima ciò che invalida l'osservazione (quello che vedi non è quello
	 * che vede un visitatore), poi gli errori di configurazione.
	 *
	 * @param array $cfg     Configurazione (vedi config()).
	 * @param array $consent Consenso per categoria.
	 * @param array $guard   Stato del blocco cookie: mode/active/rules/skip_logged_in/server_cleanup/providers.
	 * @param array $ga4     Stato GA4 server-side: confirmed/ready/measurement_id/has_secret/queue.
	 * @return array<int,array{level:string,text:string}>
	 */
	public static function notices( array $cfg, array $consent, array $guard, array $ga4 = array() ) {
		$out       = array();
		$logged_in = is_user_logged_in();
		$mode      = isset( $guard['mode'] ) ? (string) $guard['mode'] : 'off';

		// --- L'osservazione non rappresenta un visitatore anonimo -------------
		if ( $logged_in && ! empty( $guard['skip_logged_in'] ) && 'off' !== $mode ) {
			$out[] = array(
				'level' => 'warn',
				'text'  => 'Il blocco cookie è escluso per gli utenti loggati: in questa pagina NON sta bloccando nulla. Gli esiti qui sotto sono una simulazione — verifica in navigazione anonima.',
			);
		}
		if ( ! empty( $cfg['tracking_off'] ) ) {
			$out[] = array(
				'level' => 'warn',
				'text'  => '«Disattiva per utenti loggati» è attivo: in questa pagina non vengono caricati né GTM, né GA4, né il Pixel, e il plugin non scrive cookie.',
			);
		}

		// --- Rilevamento del consenso ----------------------------------------
		$providers = isset( $guard['providers'] ) ? (array) $guard['providers'] : array();
		if ( empty( $providers ) ) {
			$out[] = array(
				'level' => 'error',
				'text'  => 'Nessun CMP rilevato nei cookie di questa richiesta: il consenso risulta sempre assente. Con il blocco attivo, analytics e marketing vengono bloccati anche per chi accetta.',
			);
		}

		// --- Blocco cookie ----------------------------------------------------
		if ( 'enforce' === $mode && empty( $guard['rules'] ) ) {
			$out[] = array(
				'level' => 'warn',
				'text'  => 'Blocco cookie in modalità «Attivo» ma nessuna regola attiva: non viene bloccato nulla.',
			);
		}
		if ( 'off' === $mode ) {
			$out[] = array(
				'level' => 'info',
				'text'  => 'Blocco cookie disattivato: i cookie non consentiti non vengono né bloccati né cancellati.',
			);
		}

		// --- Duplicazione tag -------------------------------------------------
		if ( self::tag_active( $cfg, 'gtm' ) && ( ! empty( $cfg['ga4']['enabled'] ) || ! empty( $cfg['fb']['enabled'] ) ) ) {
			$out[] = array(
				'level' => 'info',
				'text'  => 'GTM è attivo: il plugin non carica GA4 né il Pixel lato client, anche se sono spuntati. Configurali nel container per evitare doppi eventi.',
			);
		}

		// --- GA4 server-side --------------------------------------------------
		if ( ! empty( $ga4['confirmed'] ) && empty( $ga4['ready'] ) ) {
			$missing = array();
			if ( empty( $ga4['measurement_id'] ) ) {
				$missing[] = 'Measurement ID';
			}
			if ( empty( $ga4['has_secret'] ) ) {
				$missing[] = 'API Secret';
			}
			$out[] = array(
				'level' => 'error',
				'text'  => 'Pipeline GA4 server-side attiva ma configurazione incompleta (manca: ' . implode( ', ', $missing ) . '): gli eventi confermati non possono essere inviati.',
			);
		}
		if ( ! empty( $ga4['confirmed'] ) && empty( $consent['analytics'] ) ) {
			$out[] = array(
				'level' => 'info',
				'text'  => 'Consenso statistiche assente: gli eventi GA4 server-side vengono scartati con esito no_consent (nessun invio, nessuna coda).',
			);
		}
		if ( isset( $ga4['queue']['failed'] ) && (int) $ga4['queue']['failed'] > 0 ) {
			$out[] = array(
				'level' => 'warn',
				'text'  => 'Coda GA4: ' . (int) $ga4['queue']['failed'] . ' eventi in stato failed. Controlla il tab «GA4 Server-Side».',
			);
		}

		/**
		 * Filtra le segnalazioni del widget di debug.
		 *
		 * @param array $out     Segnalazioni.
		 * @param array $cfg     Configurazione.
		 * @param array $consent Consenso.
		 * @param array $guard   Stato blocco cookie.
		 */
		return (array) apply_filters( 'ati_debug_notices', $out, $cfg, $consent, $guard );
	}

	/**
	 * Opzione checkbox attiva? (options.php salva '' quando la casella è vuota)
	 *
	 * @param string $option Nome opzione.
	 * @return bool
	 */
	protected static function flag( $option ) {
		return '1' === (string) get_option( $option, '' );
	}
}
