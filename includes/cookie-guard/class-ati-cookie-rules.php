<?php
/**
 * ATI_Cookie_Rules — Motore di regole PURO per il blocco/cancellazione dei cookie.
 *
 * Contiene SOLO logica deterministica (nessun DB, nessuna rete, nessun output):
 * sanitizzazione delle regole, allowlist di sicurezza, matching di un nome cookie
 * contro una regola, valutazione dell'esito rispetto allo stato di consenso.
 *
 * La stessa identica logica è implementata in `assets/js/cookie-guard.js` (che agisce
 * nel browser, dove i cookie vengono realmente scritti). Le due implementazioni devono
 * restare allineate: entrambe sono coperte dai test
 * (`tests/cookie-guard-tests.php`, `tests/cookie-guard-tests.js`).
 *
 * @package QuickTrackingIntegration\CookieGuard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Regole di blocco cookie (logica pura, testabile senza WordPress completo).
 */
class ATI_Cookie_Rules {

	/**
	 * Opzione che contiene le regole.
	 */
	const OPTION_RULES = 'ati_cg_rules';

	/**
	 * Opzione che contiene l'allowlist personalizzata (una riga per pattern).
	 */
	const OPTION_ALLOWLIST = 'ati_cg_allowlist';

	/**
	 * Operatori di match disponibili.
	 *
	 * - equals       nome esattamente uguale
	 * - contains     nome che contiene
	 * - starts_with  nome che inizia con
	 * - ends_with    nome che finisce con
	 * - starts_ends  nome che inizia con X E finisce con Y
	 * - wildcard     pattern con * (qualsiasi sequenza) e ? (un carattere)
	 * - regex        espressione regolare
	 * - domain       dominio del cookie (uguale al dominio o suo sottodominio)
	 *
	 * @return array<int,string>
	 */
	public static function match_types() {
		return array( 'equals', 'contains', 'starts_with', 'ends_with', 'starts_ends', 'wildcard', 'regex', 'domain' );
	}

	/**
	 * Etichette leggibili degli operatori.
	 *
	 * @return array<string,string>
	 */
	public static function match_labels() {
		return array(
			'equals'      => 'è esattamente',
			'contains'    => 'contiene',
			'starts_with' => 'inizia con',
			'ends_with'   => 'finisce con',
			'starts_ends' => 'inizia con … e finisce con …',
			'wildcard'    => 'corrisponde al pattern (* e ?)',
			'regex'       => 'corrisponde alla regex',
			'domain'      => 'appartiene al dominio',
		);
	}

	/**
	 * Categorie di consenso gestite.
	 *
	 * `always` non dipende dal consenso: il cookie è sempre bloccato (blacklist).
	 *
	 * @return array<int,string>
	 */
	public static function categories() {
		return array( 'marketing', 'analytics', 'preferences', 'always' );
	}

	/**
	 * Etichette leggibili delle categorie.
	 *
	 * @return array<string,string>
	 */
	public static function category_labels() {
		return array(
			'marketing'   => 'Marketing / pubblicità',
			'analytics'   => 'Statistiche / analytics',
			'preferences' => 'Preferenze / funzionali',
			'always'      => 'Sempre bloccato (indipendente dal consenso)',
		);
	}

	/**
	 * Azioni disponibili.
	 *
	 * @return array<int,string>
	 */
	public static function actions() {
		return array( 'block_delete', 'block', 'delete' );
	}

	/**
	 * Etichette leggibili delle azioni.
	 *
	 * @return array<string,string>
	 */
	public static function action_labels() {
		return array(
			'block_delete' => 'Blocca la scrittura e cancella se presente',
			'block'        => 'Blocca solo la scrittura',
			'delete'       => 'Cancella solo se presente',
		);
	}

	/**
	 * Allowlist di sicurezza NON modificabile: cookie di sessione WordPress,
	 * WooCommerce, dei CMP e del plugin stesso. Bloccarli romperebbe il sito o
	 * cancellerebbe la scelta di consenso dell'utente.
	 *
	 * Sono pattern wildcard (vedi `wildcard_to_regex`).
	 *
	 * @return array<int,string>
	 */
	public static function protected_patterns() {
		$patterns = array(
			// WordPress core.
			'wordpress*',
			'wp-*',
			'wp_*',
			'comment_author*',
			'PHPSESSID',
			// WooCommerce / e-commerce.
			'woocommerce_*',
			'wp_woocommerce_session_*',
			// CMP: la scelta di consenso non va mai toccata.
			'cmplz_*',
			'complianz*',
			'_iub_cs-*',
			'CookieConsent*',
			'OptanonConsent',
			'OptanonAlertBoxClosed',
			'eupubconsent*',
			'euconsent-v2',
			'borlabs-cookie',
			'moove_gdpr_popup',
			'cookielawinfo-*',
			'cookieyes*',
			'cky-*',
			'CookieScriptConsent',
			// Cookie del plugin (consenso/attribuzione già gated dal consenso stesso).
			'fst_*',
			'ati_*',
		);

		/**
		 * Filtra l'allowlist di sicurezza dei cookie mai bloccabili.
		 *
		 * @param array<int,string> $patterns Pattern wildcard protetti.
		 */
		return (array) apply_filters( 'ati_cookie_guard_protected_patterns', $patterns );
	}

	/**
	 * Regole predefinite, ATTIVE: sono la configurazione con cui il plugin parte.
	 *
	 * Coprono i cookie dei tracker più diffusi. Ogni regola blocca soltanto quando
	 * manca il consenso della sua categoria, quindi con il consenso concesso il
	 * comportamento del sito è identico a prima.
	 *
	 * Sono modificabili dal backend: si possono disattivare una per una, svuotare
	 * (eliminare) o ripristinare.
	 *
	 * @return array<int,array>
	 */
	public static function presets() {
		$p = array(
			array( 'Google Analytics', 'analytics', 'wildcard', '_ga*', '' ),
			array( 'Google Analytics (legacy)', 'analytics', 'equals', '_gid', '' ),
			array( 'Google Analytics (throttle)', 'analytics', 'wildcard', '_gat*', '' ),
			array( 'Urchin / Universal Analytics', 'analytics', 'starts_with', '__utm', '' ),
			array( 'Google Ads / conversion linker', 'marketing', 'wildcard', '_gcl_*', '' ),
			array( 'Google Ads (session)', 'marketing', 'wildcard', '_gac_*', '' ),
			array( 'DoubleClick', 'marketing', 'equals', 'IDE', '' ),
			array( 'DoubleClick (test)', 'marketing', 'equals', 'test_cookie', '' ),
			array( 'Meta Pixel (browser id)', 'marketing', 'equals', '_fbp', '' ),
			array( 'Meta Pixel (click id)', 'marketing', 'equals', '_fbc', '' ),
			array( 'Hotjar', 'analytics', 'wildcard', '_hj*', '' ),
			array( 'Microsoft Clarity', 'analytics', 'wildcard', '_cl*k', '' ),
			array( 'Matomo / Piwik', 'analytics', 'wildcard', '_pk_*', '' ),
			array( 'Microsoft Advertising (UET)', 'marketing', 'wildcard', '_uet*', '' ),
			array( 'LinkedIn Insight', 'marketing', 'equals', 'li_fat_id', '' ),
			array( 'LinkedIn (browser id)', 'marketing', 'equals', 'bcookie', '' ),
			array( 'LinkedIn (routing)', 'marketing', 'equals', 'lidc', '' ),
			array( 'TikTok Pixel', 'marketing', 'wildcard', '_tt*', '' ),
			array( 'Pinterest', 'marketing', 'wildcard', '_pin_*', '' ),
			array( 'X / Twitter', 'marketing', 'equals', 'personalization_id', '' ),
			array( 'Yandex Metrica', 'analytics', 'wildcard', '_ym_*', '' ),
			array( 'Snapchat', 'marketing', 'wildcard', '_scid*', '' ),
		);

		$out = array();
		foreach ( $p as $row ) {
			$out[] = array(
				'enabled'  => 1,
				'label'    => $row[0],
				'category' => $row[1],
				'match'    => $row[2],
				'value'    => $row[3],
				'value2'   => $row[4],
				'ci'       => 0,
				'action'   => 'block_delete',
			);
		}

		/**
		 * Filtra le regole predefinite di blocco cookie.
		 *
		 * @param array<int,array> $out Regole predefinite.
		 */
		return (array) apply_filters( 'ati_cookie_guard_default_rules', $out );
	}

	/**
	 * Configurazione di partenza usata finché l'amministratore non salva le proprie
	 * regole. Alias di presets(): la lista predefinita è una sola.
	 *
	 * @return array<int,array>
	 */
	public static function default_rules() {
		return self::presets();
	}

	/**
	 * Il sito sta usando le regole predefinite (nessun salvataggio dal backend)?
	 *
	 * @return bool
	 */
	public static function is_using_defaults() {
		return null === get_option( self::OPTION_RULES, null );
	}

	/**
	 * Struttura di una regola vuota.
	 *
	 * @return array
	 */
	public static function empty_rule() {
		return array(
			'enabled'  => 0,
			'label'    => '',
			'category' => 'marketing',
			'match'    => 'starts_with',
			'value'    => '',
			'value2'   => '',
			'ci'       => 0,
			'action'   => 'block_delete',
		);
	}

	/**
	 * Normalizza una singola regola (valori sempre entro i domini ammessi).
	 *
	 * @param mixed $row Riga grezza.
	 * @return array|null Regola normalizzata, o null se vuota/non valida.
	 */
	public static function sanitize_rule( $row ) {
		if ( ! is_array( $row ) ) {
			return null;
		}

		$match = isset( $row['match'] ) ? (string) $row['match'] : 'starts_with';
		if ( ! in_array( $match, self::match_types(), true ) ) {
			$match = 'starts_with';
		}

		$category = isset( $row['category'] ) ? (string) $row['category'] : 'marketing';
		if ( ! in_array( $category, self::categories(), true ) ) {
			$category = 'marketing';
		}

		$action = isset( $row['action'] ) ? (string) $row['action'] : 'block_delete';
		if ( ! in_array( $action, self::actions(), true ) ) {
			$action = 'block_delete';
		}

		// I nomi dei cookie non contengono spazi o separatori: trim conservativo,
		// nessuna alterazione del contenuto (una regex deve restare intatta).
		$value  = isset( $row['value'] ) ? trim( (string) $row['value'] ) : '';
		$value2 = isset( $row['value2'] ) ? trim( (string) $row['value2'] ) : '';

		// Riga completamente vuota: scartata (è la riga "aggiungi nuova").
		if ( '' === $value && '' === $value2 && '' === trim( (string) ( isset( $row['label'] ) ? $row['label'] : '' ) ) ) {
			return null;
		}
		// Un operatore senza valore non può mai matchare: regola inutile.
		if ( '' === $value ) {
			return null;
		}

		return array(
			'enabled'  => empty( $row['enabled'] ) ? 0 : 1,
			'label'    => function_exists( 'sanitize_text_field' ) ? sanitize_text_field( isset( $row['label'] ) ? $row['label'] : '' ) : trim( (string) ( isset( $row['label'] ) ? $row['label'] : '' ) ),
			'category' => $category,
			'match'    => $match,
			'value'    => $value,
			'value2'   => $value2,
			'ci'       => empty( $row['ci'] ) ? 0 : 1,
			'action'   => $action,
		);
	}

	/**
	 * Sanitizza l'intero set di regole (callback di register_setting).
	 *
	 * @param mixed $rows Righe dal form.
	 * @return array<int,array>
	 */
	public static function sanitize_rules( $rows ) {
		$out = array();
		if ( ! is_array( $rows ) ) {
			return $out;
		}
		foreach ( $rows as $row ) {
			$rule = self::sanitize_rule( $row );
			if ( null !== $rule ) {
				$out[] = $rule;
			}
		}
		return $out;
	}

	/**
	 * Sanitizza l'allowlist personalizzata (testo, un pattern per riga).
	 *
	 * @param mixed $text Testo grezzo.
	 * @return string
	 */
	public static function sanitize_allowlist( $text ) {
		$text  = is_scalar( $text ) ? (string) $text : '';
		$lines = preg_split( '/[\r\n]+/', $text );
		$out   = array();
		foreach ( (array) $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			// Un pattern di cookie non contiene spazi: taglia eventuali commenti.
			$line = preg_replace( '/\s.*$/', '', $line );
			if ( '' !== $line && ! in_array( $line, $out, true ) ) {
				$out[] = $line;
			}
		}
		return implode( "\n", $out );
	}

	/**
	 * Regole in vigore: quelle salvate dall'amministratore, oppure le predefinite
	 * finché non è mai stato fatto un salvataggio.
	 *
	 * L'opzione assente (null) e l'opzione salvata vuota (array()) sono due stati
	 * DIVERSI: chi elimina tutte le regole e salva non se le vede riapparire.
	 *
	 * @return array<int,array>
	 */
	public static function rules() {
		$rules = get_option( self::OPTION_RULES, null );
		if ( null === $rules ) {
			return self::default_rules();
		}
		return is_array( $rules ) ? $rules : array();
	}

	/**
	 * Solo le regole attive.
	 *
	 * @return array<int,array>
	 */
	public static function active_rules() {
		$out = array();
		foreach ( self::rules() as $rule ) {
			if ( is_array( $rule ) && ! empty( $rule['enabled'] ) ) {
				$out[] = $rule;
			}
		}
		return $out;
	}

	/**
	 * Allowlist completa: pattern protetti + pattern personalizzati.
	 *
	 * @return array<int,string>
	 */
	public static function allowlist_patterns() {
		$custom = (string) get_option( self::OPTION_ALLOWLIST, '' );
		$custom = '' === trim( $custom ) ? array() : preg_split( '/[\r\n]+/', trim( $custom ) );
		$custom = array_filter( array_map( 'trim', (array) $custom ) );

		return array_values( array_unique( array_merge( self::protected_patterns(), $custom ) ) );
	}

	/**
	 * Converte un pattern wildcard (* e ?) nella corrispondente regex ancorata.
	 *
	 * @param string $pattern Pattern.
	 * @return string Regex COMPLETA (delimitatori inclusi), senza modificatori.
	 */
	public static function wildcard_to_regex( $pattern ) {
		$quoted = preg_quote( (string) $pattern, '#' );
		// preg_quote ha già escapato * e ?: li riporta a metacaratteri.
		$quoted = str_replace( array( '\*', '\?' ), array( '.*', '.' ), $quoted );
		return '#^' . $quoted . '$#';
	}

	/**
	 * Il nome del cookie è in allowlist (mai bloccato/cancellato)?
	 *
	 * Il confronto è case-insensitive: i CMP e WordPress usano nomi con maiuscole
	 * variabili e un errore qui romperebbe la sessione.
	 *
	 * @param string $name      Nome cookie.
	 * @param array  $allowlist Pattern (default: allowlist completa).
	 * @return bool
	 */
	public static function is_allowlisted( $name, $allowlist = null ) {
		$name      = (string) $name;
		$allowlist = ( null === $allowlist ) ? self::allowlist_patterns() : (array) $allowlist;

		foreach ( $allowlist as $pattern ) {
			$pattern = (string) $pattern;
			if ( '' === $pattern ) {
				continue;
			}
			if ( preg_match( self::wildcard_to_regex( $pattern ) . 'i', $name ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Il dominio `$domain` copre `$candidate`?
	 *
	 * `example.com` copre `example.com` e `www.example.com`; il punto iniziale
	 * dei cookie (`.example.com`) è ignorato, come da RFC 6265.
	 *
	 * @param string $candidate Dominio della regola.
	 * @param string $domain    Dominio del cookie.
	 * @return bool
	 */
	public static function domain_matches( $candidate, $domain ) {
		$candidate = strtolower( ltrim( trim( (string) $candidate ), '.' ) );
		$domain    = strtolower( ltrim( trim( (string) $domain ), '.' ) );

		if ( '' === $candidate || '' === $domain ) {
			return false;
		}
		if ( $candidate === $domain ) {
			return true;
		}
		$len = strlen( $candidate );
		return strlen( $domain ) > $len && substr( $domain, -( $len + 1 ) ) === '.' . $candidate;
	}

	/**
	 * La regola matcha il cookie indicato?
	 *
	 * @param array  $rule   Regola normalizzata.
	 * @param string $name   Nome del cookie.
	 * @param string $domain Dominio del cookie (noto solo in scrittura lato browser).
	 * @return bool
	 */
	public static function matches( $rule, $name, $domain = '' ) {
		if ( ! is_array( $rule ) ) {
			return false;
		}
		$type   = isset( $rule['match'] ) ? (string) $rule['match'] : '';
		$value  = isset( $rule['value'] ) ? (string) $rule['value'] : '';
		$value2 = isset( $rule['value2'] ) ? (string) $rule['value2'] : '';
		$ci     = ! empty( $rule['ci'] );
		$name   = (string) $name;

		if ( '' === $value ) {
			return false;
		}

		// L'operatore "domain" lavora sul dominio, non sul nome.
		if ( 'domain' === $type ) {
			return self::domain_matches( $value, $domain );
		}

		$subject = $ci ? self::lower( $name ) : $name;
		$needle  = $ci ? self::lower( $value ) : $value;
		$needle2 = $ci ? self::lower( $value2 ) : $value2;

		switch ( $type ) {
			case 'equals':
				return $subject === $needle;

			case 'contains':
				return '' !== $needle && false !== strpos( $subject, $needle );

			case 'starts_with':
				return 0 === strpos( $subject, $needle );

			case 'ends_with':
				return '' !== $needle && substr( $subject, -strlen( $needle ) ) === $needle;

			case 'starts_ends':
				if ( '' === $needle2 ) {
					return 0 === strpos( $subject, $needle );
				}
				// Prefisso e suffisso non devono sovrapporsi.
				if ( strlen( $subject ) < strlen( $needle ) + strlen( $needle2 ) ) {
					return false;
				}
				return 0 === strpos( $subject, $needle ) && substr( $subject, -strlen( $needle2 ) ) === $needle2;

			case 'wildcard':
				return 1 === preg_match( self::wildcard_to_regex( $value ) . ( $ci ? 'i' : '' ), $name );

			case 'regex':
				$regex = self::compile_regex( $value, $ci );
				if ( null === $regex ) {
					return false;
				}
				return 1 === @preg_match( $regex, $name ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

			default:
				return false;
		}
	}

	/**
	 * Compila una regex fornita dall'amministratore, restituendo null se invalida.
	 *
	 * @param string $pattern Pattern senza delimitatori.
	 * @param bool   $ci      Case-insensitive.
	 * @return string|null Regex utilizzabile o null.
	 */
	public static function compile_regex( $pattern, $ci = false ) {
		$pattern = (string) $pattern;
		if ( '' === $pattern ) {
			return null;
		}
		$regex = '#' . str_replace( '#', '\#', $pattern ) . '#' . ( $ci ? 'i' : '' );
		if ( false === @preg_match( $regex, '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return null;
		}
		return $regex;
	}

	/**
	 * Una regola è sintatticamente valida? (usato per segnalare regex errate)
	 *
	 * @param array $rule Regola.
	 * @return bool
	 */
	public static function is_valid( $rule ) {
		if ( ! is_array( $rule ) || '' === (string) ( isset( $rule['value'] ) ? $rule['value'] : '' ) ) {
			return false;
		}
		if ( isset( $rule['match'] ) && 'regex' === $rule['match'] ) {
			return null !== self::compile_regex( $rule['value'], ! empty( $rule['ci'] ) );
		}
		return true;
	}

	/**
	 * Valuta un cookie contro allowlist + regole + stato di consenso.
	 *
	 * @param string     $name      Nome del cookie.
	 * @param string     $domain    Dominio (opzionale; noto solo in scrittura).
	 * @param array      $consent   array('marketing'=>bool,'analytics'=>bool,'preferences'=>bool).
	 * @param array|null $rules     Regole (default: quelle attive salvate).
	 * @param array|null $allowlist Allowlist (default: quella completa).
	 * @return array{blocked:bool,action:string,reason:string,rule:int|null,label:string,category:string}
	 */
	public static function evaluate( $name, $domain = '', $consent = array(), $rules = null, $allowlist = null ) {
		$result = array(
			'blocked'  => false,
			'action'   => '',
			'reason'   => 'no_match',
			'rule'     => null,
			'label'    => '',
			'category' => '',
		);

		$name = (string) $name;
		if ( '' === $name ) {
			$result['reason'] = 'empty_name';
			return $result;
		}

		if ( self::is_allowlisted( $name, $allowlist ) ) {
			$result['reason'] = 'allowlist';
			return $result;
		}

		$rules   = ( null === $rules ) ? self::active_rules() : (array) $rules;
		$consent = is_array( $consent ) ? $consent : array();

		foreach ( $rules as $i => $rule ) {
			if ( ! is_array( $rule ) || empty( $rule['enabled'] ) ) {
				continue;
			}
			if ( ! self::matches( $rule, $name, $domain ) ) {
				continue;
			}

			$category = isset( $rule['category'] ) ? (string) $rule['category'] : 'marketing';
			$granted  = ( 'always' !== $category ) && ! empty( $consent[ $category ] );

			$result['rule']     = (int) $i;
			$result['label']    = isset( $rule['label'] ) ? (string) $rule['label'] : '';
			$result['category'] = $category;
			$result['action']   = isset( $rule['action'] ) ? (string) $rule['action'] : 'block_delete';

			if ( $granted ) {
				// Regola trovata ma consenso presente: si continua a cercare, perché
				// un'altra regola (es. categoria diversa o "always") può bloccarlo.
				$result['reason']  = 'consent_granted';
				$result['blocked'] = false;
				continue;
			}

			$result['reason']  = 'blocked';
			$result['blocked'] = true;
			return $result;
		}

		return $result;
	}

	/**
	 * Il cookie va bloccato in SCRITTURA?
	 *
	 * @param array $evaluation Esito di evaluate().
	 * @return bool
	 */
	public static function should_block( $evaluation ) {
		return ! empty( $evaluation['blocked'] ) && in_array( $evaluation['action'], array( 'block', 'block_delete' ), true );
	}

	/**
	 * Il cookie va CANCELLATO se già presente?
	 *
	 * @param array $evaluation Esito di evaluate().
	 * @return bool
	 */
	public static function should_delete( $evaluation ) {
		return ! empty( $evaluation['blocked'] ) && in_array( $evaluation['action'], array( 'delete', 'block_delete' ), true );
	}

	/**
	 * strtolower sicuro anche senza mbstring (i nomi dei cookie sono ASCII).
	 *
	 * @param string $s Stringa.
	 * @return string
	 */
	protected static function lower( $s ) {
		return strtolower( (string) $s );
	}
}
