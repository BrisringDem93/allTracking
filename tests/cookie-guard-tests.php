<?php
/**
 * Test delle FUNZIONI PURE del blocco cookie (lato PHP).
 *
 * Esegui: php tests/cookie-guard-tests.php
 *
 * Copre: operatori di match, sensibilità alle maiuscole, allowlist di sicurezza,
 * valutazione rispetto al consenso per categoria, categoria "always", azioni
 * blocca/cancella, sanitizzazione di regole e allowlist, regex non valide,
 * varianti di dominio per la cancellazione, rilevamento del consenso "preferenze".
 *
 * La controparte JavaScript (che è ciò che gira nel browser) è in
 * tests/cookie-guard-tests.js: gli stessi casi di matching sono verificati due volte.
 *
 * @package QuickTrackingIntegration\Tests
 */

require __DIR__ . '/wp-stubs.php';

// Definita in includes/tag-inserter.php quando WordPress è caricato; qui serve solo
// perché ATI_Cookie_Consent::state() la interroga.
if ( ! function_exists( 'ati_has_analytics_consent' ) ) {
	$GLOBALS['__ati_analytics_consent'] = false;
	function ati_has_analytics_consent() {
		return (bool) $GLOBALS['__ati_analytics_consent'];
	}
}

require dirname( __DIR__ ) . '/includes/cookie-guard/class-ati-cookie-rules.php';
require dirname( __DIR__ ) . '/includes/cookie-guard/class-ati-cookie-consent.php';
require dirname( __DIR__ ) . '/includes/cookie-guard/class-ati-cookie-guard.php';

$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;

function ok( $cond, $msg ) {
	if ( $cond ) {
		$GLOBALS['__pass']++;
		echo "  PASS  $msg\n";
	} else {
		$GLOBALS['__fail']++;
		echo "  FAIL  $msg\n";
	}
}
function section( $t ) {
	echo "\n== $t ==\n";
}

/**
 * Costruisce una regola completa a partire dai soli campi rilevanti.
 *
 * @param array $overrides Campi da sovrascrivere.
 * @return array
 */
function rule( $overrides = array() ) {
	return array_merge(
		array(
			'enabled'  => 1,
			'label'    => 'test',
			'category' => 'marketing',
			'match'    => 'starts_with',
			'value'    => '',
			'value2'   => '',
			'ci'       => 0,
			'action'   => 'block_delete',
		),
		$overrides
	);
}

$no_consent  = array( 'preferences' => false, 'analytics' => false, 'marketing' => false );
$all_consent = array( 'preferences' => true, 'analytics' => true, 'marketing' => true );

// -------------------------------------------------------------------------
section( 'Operatori di match' );

ok( ATI_Cookie_Rules::matches( rule( array( 'match' => 'equals', 'value' => '_fbp' ) ), '_fbp' ), 'equals: _fbp corrisponde' );
ok( ! ATI_Cookie_Rules::matches( rule( array( 'match' => 'equals', 'value' => '_fbp' ) ), '_fbpx' ), 'equals: _fbpx NON corrisponde' );

ok( ATI_Cookie_Rules::matches( rule( array( 'match' => 'contains', 'value' => 'analytics' ) ), 'my_analytics_id' ), 'contains: sottostringa trovata' );
ok( ! ATI_Cookie_Rules::matches( rule( array( 'match' => 'contains', 'value' => 'analytics' ) ), 'my_stats_id' ), 'contains: sottostringa assente' );

ok( ATI_Cookie_Rules::matches( rule( array( 'match' => 'starts_with', 'value' => '_ga' ) ), '_ga_ABC123' ), 'starts_with: prefisso corrisponde' );
ok( ! ATI_Cookie_Rules::matches( rule( array( 'match' => 'starts_with', 'value' => '_ga' ) ), 'x_ga' ), 'starts_with: prefisso non in testa' );

ok( ATI_Cookie_Rules::matches( rule( array( 'match' => 'ends_with', 'value' => '_id' ) ), 'visitor_id' ), 'ends_with: suffisso corrisponde' );
ok( ! ATI_Cookie_Rules::matches( rule( array( 'match' => 'ends_with', 'value' => '_id' ) ), '_id_visitor' ), 'ends_with: suffisso non in coda' );

$se = rule( array( 'match' => 'starts_ends', 'value' => '_pk_', 'value2' => '.1' ) );
ok( ATI_Cookie_Rules::matches( $se, '_pk_id.1' ), 'starts_ends: inizia con _pk_ e finisce con .1' );
ok( ! ATI_Cookie_Rules::matches( $se, '_pk_id.2' ), 'starts_ends: suffisso diverso -> no match' );
ok( ! ATI_Cookie_Rules::matches( $se, 'x_pk_id.1' ), 'starts_ends: prefisso non in testa -> no match' );
// Prefisso e suffisso non devono potersi sovrapporre sullo stesso testo.
ok( ! ATI_Cookie_Rules::matches( rule( array( 'match' => 'starts_ends', 'value' => 'abc', 'value2' => 'bcd' ) ), 'abcd' ), 'starts_ends: prefisso e suffisso non si sovrappongono' );

ok( ATI_Cookie_Rules::matches( rule( array( 'match' => 'wildcard', 'value' => '_hj*' ) ), '_hjSessionUser' ), 'wildcard: _hj* corrisponde' );
ok( ATI_Cookie_Rules::matches( rule( array( 'match' => 'wildcard', 'value' => '_cl?k' ) ), '_clck' ), 'wildcard: ? sostituisce un carattere' );
ok( ! ATI_Cookie_Rules::matches( rule( array( 'match' => 'wildcard', 'value' => '_cl?k' ) ), '_clsck' ), 'wildcard: ? non sostituisce due caratteri' );
ok( ATI_Cookie_Rules::matches( rule( array( 'match' => 'wildcard', 'value' => '*uet*' ) ), '_uetsid' ), 'wildcard: * su entrambi i lati' );
// Il punto è un carattere letterale, non un metacarattere.
ok( ! ATI_Cookie_Rules::matches( rule( array( 'match' => 'wildcard', 'value' => '_pk.id' ) ), '_pkXid' ), 'wildcard: il punto resta letterale' );

ok( ATI_Cookie_Rules::matches( rule( array( 'match' => 'regex', 'value' => '^_ga(_[A-Z0-9]+)?$' ) ), '_ga_ABC123' ), 'regex: pattern GA corrisponde' );
ok( ! ATI_Cookie_Rules::matches( rule( array( 'match' => 'regex', 'value' => '^_ga(_[A-Z0-9]+)?$' ) ), '_gali' ), 'regex: pattern GA non corrisponde a _gali' );
ok( ! ATI_Cookie_Rules::matches( rule( array( 'match' => 'regex', 'value' => '^[unclosed' ) ), 'qualsiasi' ), 'regex non valida -> nessun match (nessun errore fatale)' );
ok( ! ATI_Cookie_Rules::is_valid( rule( array( 'match' => 'regex', 'value' => '^[unclosed' ) ) ), 'regex non valida segnalata da is_valid()' );
ok( ATI_Cookie_Rules::is_valid( rule( array( 'match' => 'regex', 'value' => '^_ga' ) ) ), 'regex valida accettata da is_valid()' );
// Il delimitatore interno non deve poter rompere la compilazione.
ok( ATI_Cookie_Rules::matches( rule( array( 'match' => 'regex', 'value' => 'a#b' ) ), 'xa#by' ), 'regex contenente il delimitatore # viene gestita' );

// -------------------------------------------------------------------------
section( 'Dominio' );

$dom = rule( array( 'match' => 'domain', 'value' => 'doubleclick.net' ) );
ok( ATI_Cookie_Rules::matches( $dom, 'IDE', 'doubleclick.net' ), 'domain: dominio esatto' );
ok( ATI_Cookie_Rules::matches( $dom, 'IDE', '.doubleclick.net' ), 'domain: punto iniziale ignorato' );
ok( ATI_Cookie_Rules::matches( $dom, 'IDE', 'ad.doubleclick.net' ), 'domain: sottodominio incluso' );
ok( ! ATI_Cookie_Rules::matches( $dom, 'IDE', 'notdoubleclick.net' ), 'domain: suffisso non basta, serve il confine di etichetta' );
ok( ! ATI_Cookie_Rules::matches( $dom, 'IDE', 'example.com' ), 'domain: dominio diverso' );
ok( ! ATI_Cookie_Rules::matches( $dom, 'IDE', '' ), 'domain: dominio ignoto -> nessun match' );

// -------------------------------------------------------------------------
section( 'Maiuscole/minuscole' );

ok( ! ATI_Cookie_Rules::matches( rule( array( 'match' => 'equals', 'value' => 'ide' ) ), 'IDE' ), 'default case-sensitive: ide != IDE' );
ok( ATI_Cookie_Rules::matches( rule( array( 'match' => 'equals', 'value' => 'ide', 'ci' => 1 ) ), 'IDE' ), 'ci=1: ide == IDE' );
ok( ATI_Cookie_Rules::matches( rule( array( 'match' => 'wildcard', 'value' => 'ide*', 'ci' => 1 ) ), 'IDEXX' ), 'ci=1 con wildcard' );
ok( ATI_Cookie_Rules::matches( rule( array( 'match' => 'regex', 'value' => '^ide$', 'ci' => 1 ) ), 'IDE' ), 'ci=1 con regex' );

// -------------------------------------------------------------------------
section( 'Allowlist di sicurezza' );

$GLOBALS['__ati_opts'][ ATI_Cookie_Rules::OPTION_ALLOWLIST ] = '';
ok( ATI_Cookie_Rules::is_allowlisted( 'wordpress_logged_in_abc123' ), 'sessione WordPress protetta' );
ok( ATI_Cookie_Rules::is_allowlisted( 'wp-settings-time-1' ), 'preferenze admin WordPress protette' );
ok( ATI_Cookie_Rules::is_allowlisted( 'PHPSESSID' ), 'sessione PHP protetta' );
ok( ATI_Cookie_Rules::is_allowlisted( 'phpsessid' ), 'allowlist case-insensitive' );
ok( ATI_Cookie_Rules::is_allowlisted( 'woocommerce_cart_hash' ), 'carrello WooCommerce protetto' );
ok( ATI_Cookie_Rules::is_allowlisted( 'cmplz_marketing' ), 'cookie del CMP Complianz protetto' );
ok( ATI_Cookie_Rules::is_allowlisted( '_iub_cs-s4597678' ), 'cookie del CMP iubenda protetto' );
ok( ATI_Cookie_Rules::is_allowlisted( 'OptanonConsent' ), 'cookie del CMP OneTrust protetto' );
ok( ATI_Cookie_Rules::is_allowlisted( 'fst_uid' ), 'cookie del plugin protetto' );
ok( ! ATI_Cookie_Rules::is_allowlisted( '_ga' ), '_ga non è in allowlist' );

$GLOBALS['__ati_opts'][ ATI_Cookie_Rules::OPTION_ALLOWLIST ] = "mio_cookie_*\naltro_esatto";
ok( ATI_Cookie_Rules::is_allowlisted( 'mio_cookie_abc' ), 'allowlist personalizzata con wildcard' );
ok( ATI_Cookie_Rules::is_allowlisted( 'altro_esatto' ), 'allowlist personalizzata esatta' );
ok( ! ATI_Cookie_Rules::is_allowlisted( 'altro_esatto_no' ), 'allowlist personalizzata è ancorata' );
$GLOBALS['__ati_opts'][ ATI_Cookie_Rules::OPTION_ALLOWLIST ] = '';

// L'allowlist vince su qualunque regola, anche "always".
$evaluation = ATI_Cookie_Rules::evaluate(
	'wordpress_logged_in_abc',
	'',
	$no_consent,
	array( rule( array( 'match' => 'contains', 'value' => 'wordpress', 'category' => 'always' ) ) )
);
ok( ! $evaluation['blocked'] && 'allowlist' === $evaluation['reason'], 'allowlist ha la precedenza sulla categoria "always"' );

// -------------------------------------------------------------------------
section( 'Valutazione rispetto al consenso per categoria' );

$rules = array(
	rule( array( 'label' => 'GA', 'category' => 'analytics', 'match' => 'wildcard', 'value' => '_ga*' ) ),
	rule( array( 'label' => 'Meta', 'category' => 'marketing', 'match' => 'equals', 'value' => '_fbp' ) ),
	rule( array( 'label' => 'Tema', 'category' => 'preferences', 'match' => 'starts_with', 'value' => 'pref_' ) ),
);

$ev = ATI_Cookie_Rules::evaluate( '_ga_ABC', '', $no_consent, $rules );
ok( $ev['blocked'] && 'analytics' === $ev['category'], 'senza consenso analytics -> _ga_ABC bloccato' );

$ev = ATI_Cookie_Rules::evaluate( '_ga_ABC', '', array( 'analytics' => true ), $rules );
ok( ! $ev['blocked'] && 'consent_granted' === $ev['reason'], 'con consenso analytics -> _ga_ABC consentito' );

// Consensi separati: il marketing non sblocca l'analytics e viceversa.
$ev = ATI_Cookie_Rules::evaluate( '_ga_ABC', '', array( 'marketing' => true ), $rules );
ok( $ev['blocked'], 'il consenso marketing NON sblocca un cookie analytics' );
$ev = ATI_Cookie_Rules::evaluate( '_fbp', '', array( 'analytics' => true ), $rules );
ok( $ev['blocked'], 'il consenso analytics NON sblocca un cookie marketing' );
$ev = ATI_Cookie_Rules::evaluate( '_fbp', '', array( 'marketing' => true ), $rules );
ok( ! $ev['blocked'], 'con consenso marketing -> _fbp consentito' );
$ev = ATI_Cookie_Rules::evaluate( 'pref_theme', '', array( 'preferences' => true ), $rules );
ok( ! $ev['blocked'], 'con consenso preferenze -> pref_theme consentito' );

$ev = ATI_Cookie_Rules::evaluate( 'cookie_ignoto', '', $no_consent, $rules );
ok( ! $ev['blocked'] && 'no_match' === $ev['reason'], 'nessuna regola -> cookie consentito' );

// Categoria "always": blacklist indipendente dal consenso.
$always = array( rule( array( 'category' => 'always', 'match' => 'equals', 'value' => 'spia' ) ) );
ok( ATI_Cookie_Rules::evaluate( 'spia', '', $all_consent, $always )['blocked'], 'categoria always: bloccato anche con tutti i consensi' );

// Una regola con consenso non impedisce a una regola successiva di bloccare.
$mixed = array(
	rule( array( 'label' => 'ok', 'category' => 'analytics', 'match' => 'starts_with', 'value' => '_x' ) ),
	rule( array( 'label' => 'ban', 'category' => 'always', 'match' => 'equals', 'value' => '_x1' ) ),
);
ok( ATI_Cookie_Rules::evaluate( '_x1', '', array( 'analytics' => true ), $mixed )['blocked'], 'una regola successiva "always" blocca comunque' );

// Le regole disattivate non hanno effetto.
$off = array( rule( array( 'enabled' => 0, 'match' => 'equals', 'value' => '_fbp' ) ) );
ok( ! ATI_Cookie_Rules::evaluate( '_fbp', '', $no_consent, $off )['blocked'], 'regola disattivata -> nessun blocco' );

ok( 'empty_name' === ATI_Cookie_Rules::evaluate( '', '', $no_consent, $rules )['reason'], 'nome vuoto -> nessuna azione' );

// -------------------------------------------------------------------------
section( 'Azioni: blocca / cancella' );

$block_only  = ATI_Cookie_Rules::evaluate( '_fbp', '', $no_consent, array( rule( array( 'match' => 'equals', 'value' => '_fbp', 'action' => 'block' ) ) ) );
$delete_only = ATI_Cookie_Rules::evaluate( '_fbp', '', $no_consent, array( rule( array( 'match' => 'equals', 'value' => '_fbp', 'action' => 'delete' ) ) ) );
$both        = ATI_Cookie_Rules::evaluate( '_fbp', '', $no_consent, array( rule( array( 'match' => 'equals', 'value' => '_fbp', 'action' => 'block_delete' ) ) ) );

ok( ATI_Cookie_Rules::should_block( $block_only ) && ! ATI_Cookie_Rules::should_delete( $block_only ), 'action=block: blocca ma non cancella' );
ok( ! ATI_Cookie_Rules::should_block( $delete_only ) && ATI_Cookie_Rules::should_delete( $delete_only ), 'action=delete: cancella ma non blocca' );
ok( ATI_Cookie_Rules::should_block( $both ) && ATI_Cookie_Rules::should_delete( $both ), 'action=block_delete: entrambe' );

$granted = ATI_Cookie_Rules::evaluate( '_fbp', '', array( 'marketing' => true ), array( rule( array( 'match' => 'equals', 'value' => '_fbp' ) ) ) );
ok( ! ATI_Cookie_Rules::should_block( $granted ) && ! ATI_Cookie_Rules::should_delete( $granted ), 'con consenso: né blocco né cancellazione' );

// -------------------------------------------------------------------------
section( 'Sanitizzazione delle regole' );

$sanitized = ATI_Cookie_Rules::sanitize_rules(
	array(
		array( 'enabled' => '1', 'label' => 'GA', 'category' => 'analytics', 'match' => 'wildcard', 'value' => '_ga*', 'action' => 'block' ),
		array( 'label' => '', 'value' => '' ),                                            // riga vuota
		array( 'label' => 'senza valore', 'value' => '', 'match' => 'equals' ),           // inutile
		array( 'value' => 'x', 'category' => 'inesistente', 'match' => 'inesistente', 'action' => 'inesistente' ),
		'non un array',
	)
);
ok( 2 === count( $sanitized ), 'righe vuote/inutili scartate (2 regole conservate)' );
ok( 'analytics' === $sanitized[0]['category'] && 'wildcard' === $sanitized[0]['match'] && 'block' === $sanitized[0]['action'], 'prima regola conservata integra' );
ok( 1 === $sanitized[0]['enabled'], 'enabled normalizzato a intero' );
ok( 'marketing' === $sanitized[1]['category'], 'categoria non valida -> marketing' );
ok( 'starts_with' === $sanitized[1]['match'], 'operatore non valido -> starts_with' );
ok( 'block_delete' === $sanitized[1]['action'], 'azione non valida -> block_delete' );
ok( 0 === $sanitized[1]['enabled'], 'checkbox assente -> disattivata' );
ok( array() === ATI_Cookie_Rules::sanitize_rules( 'non un array' ), 'input non-array -> nessuna regola' );

// Il valore non viene alterato: una regex deve restare identica.
$regex_kept = ATI_Cookie_Rules::sanitize_rules( array( array( 'value' => '^_ga(_[A-Z0-9]+)?$', 'match' => 'regex' ) ) );
ok( '^_ga(_[A-Z0-9]+)?$' === $regex_kept[0]['value'], 'la regex non viene alterata dalla sanitizzazione' );

// -------------------------------------------------------------------------
section( 'Sanitizzazione dell\'allowlist' );

$allow = ATI_Cookie_Rules::sanitize_allowlist( "  mio_*  \n\n mio_*\naltro cookie con commento\n" );
ok( "mio_*\naltro" === $allow, 'righe ripulite, deduplicate e troncate al primo spazio' );
ok( '' === ATI_Cookie_Rules::sanitize_allowlist( "\n \n" ), 'solo righe vuote -> stringa vuota' );

// -------------------------------------------------------------------------
section( 'Regole predefinite (attive out of the box)' );

$presets = ATI_Cookie_Rules::presets();
ok( count( $presets ) > 10, 'preset disponibili (' . count( $presets ) . ')' );
ok( ATI_Cookie_Rules::default_rules() === $presets, 'default_rules() coincide con presets(): una sola lista canonica' );

$all_enabled = true;
foreach ( $presets as $preset ) {
	if ( empty( $preset['enabled'] ) ) {
		$all_enabled = false;
		break;
	}
}
ok( $all_enabled, 'tutte le regole predefinite sono attive' );

// I cookie segnalati dall'utente devono essere coperti dalla configurazione di partenza.
$defaults = ATI_Cookie_Rules::default_rules();
$covered  = array(
	'_fbp'                       => 'marketing',
	'_fbc'                       => 'marketing',
	'_ga'                        => 'analytics',
	'_ga_0RVDVFM24W'             => 'analytics',
	'_ga_MX6Z1X7L8K'             => 'analytics',
	'_gid'                       => 'analytics',
	'_gat_gtag_UA_1'             => 'analytics',
	'_gcl_au'                    => 'marketing',
	'_hjSessionUser_123'         => 'analytics',
	'_clck'                      => 'analytics',
	'_uetsid'                    => 'marketing',
);
foreach ( $covered as $cookie => $expected_category ) {
	$ev = ATI_Cookie_Rules::evaluate( $cookie, '', $no_consent, $defaults );
	ok( $ev['blocked'] && $expected_category === $ev['category'], "predefinite: $cookie bloccato senza consenso ($expected_category)" );
}
// Con il consenso della categoria, gli stessi cookie passano.
foreach ( $covered as $cookie => $expected_category ) {
	$ev = ATI_Cookie_Rules::evaluate( $cookie, '', array( $expected_category => true ), $defaults );
	if ( $ev['blocked'] ) {
		ok( false, "predefinite: $cookie dovrebbe passare con consenso $expected_category" );
	}
}
ok( true, 'predefinite: con il consenso della categoria tutti i cookie coperti passano' );

// Nessuna regola predefinita deve toccare i cookie di sessione o del CMP.
$untouched = array( 'wordpress_logged_in_x', 'wp-settings-1', 'PHPSESSID', 'woocommerce_cart_hash', 'cmplz_marketing', '_iub_cs-s123', 'CookieConsent', 'OptanonConsent', 'fst_uid', 'fst_clid' );
$hit       = '';
foreach ( $untouched as $cookie ) {
	if ( ATI_Cookie_Rules::evaluate( $cookie, '', $no_consent, $defaults )['blocked'] ) {
		$hit = $cookie;
		break;
	}
}
ok( '' === $hit, 'predefinite: nessun cookie di sistema/CMP bloccato' . ( '' !== $hit ? " ($hit)" : '' ) );
$valid = true;
foreach ( $presets as $preset ) {
	if ( null === ATI_Cookie_Rules::sanitize_rule( $preset ) || ! ATI_Cookie_Rules::is_valid( $preset ) ) {
		$valid = false;
		break;
	}
}
ok( $valid, 'tutti i preset sono regole valide' );
// Nessun preset deve colpire un cookie protetto.
$collision = '';
foreach ( $presets as $preset ) {
	foreach ( array( 'wordpress_logged_in_x', 'PHPSESSID', 'cmplz_marketing', 'woocommerce_cart_hash', 'fst_uid' ) as $protected ) {
		if ( ATI_Cookie_Rules::matches( $preset, $protected ) ) {
			$collision = $preset['value'] . ' -> ' . $protected;
		}
	}
}
ok( '' === $collision, 'nessun preset colpisce cookie di sistema' . ( '' !== $collision ? " ($collision)" : '' ) );

// -------------------------------------------------------------------------
section( 'Varianti di dominio per la cancellazione lato server' );

$_SERVER['HTTP_HOST'] = 'www.example.com:8080';
$domains              = ATI_Cookie_Guard::cookie_domains();
ok( in_array( '', $domains, true ), 'inclusa la variante host-only' );
ok( in_array( '.www.example.com', $domains, true ), 'incluso il dominio completo' );
ok( in_array( '.example.com', $domains, true ), 'incluso il dominio registrabile' );
ok( ! in_array( '.com', $domains, true ), 'escluso il TLD nudo' );

$_SERVER['HTTP_HOST'] = '127.0.0.1';
ok( array( '' ) === ATI_Cookie_Guard::cookie_domains(), 'host IP -> solo host-only' );
$_SERVER['HTTP_HOST'] = 'example.com';

// -------------------------------------------------------------------------
section( 'Rilevamento consenso "preferenze"' );

$GLOBALS['__ati_opts']['ati_cg_cmp']                     = 'auto';
$GLOBALS['__ati_opts']['ati_cg_preferences_cookie_name'] = '';
$detect = new ReflectionMethod( 'ATI_Cookie_Consent', 'detect_preferences' );
$detect->setAccessible( true );

$_COOKIE = array();
ok( false === $detect->invoke( null ), 'nessun cookie -> preferenze non concesse' );

$_COOKIE = array( 'cmplz_preferences' => 'allow' );
ok( true === $detect->invoke( null ), 'Complianz cmplz_preferences=allow' );

$_COOKIE = array( 'cmplz_preferences' => 'deny', 'cmplz_marketing' => 'allow' );
ok( false === $detect->invoke( null ), 'Complianz: marketing non concede le preferenze' );

$_COOKIE = array( 'CookieConsent' => 'stamp:x,necessary:true,preferences:true,statistics:false,marketing:false' );
ok( true === $detect->invoke( null ), 'Cookiebot preferences:true' );
$_COOKIE = array( 'CookieConsent' => 'stamp:x,preferences:false,marketing:true' );
ok( false === $detect->invoke( null ), 'Cookiebot preferences:false' );

$_COOKIE = array( 'OptanonConsent' => 'groups=C0001:1,C0003:1,C0004:0' );
ok( true === $detect->invoke( null ), 'OneTrust C0003:1 concede le preferenze' );
$_COOKIE = array( 'OptanonConsent' => 'groups=C0001:1,C0003:0,C0004:1' );
ok( false === $detect->invoke( null ), 'OneTrust C0003:0 -> preferenze non concesse' );

// iubenda con magic-quotes WordPress (virgolette escapate).
$_COOKIE = array( '_iub_cs-s4597678' => '{\"purposes\":{\"1\":true,\"3\":true,\"4\":false}}' );
ok( true === $detect->invoke( null ), 'iubenda purpose 3 -> preferenze concesse' );
$_COOKIE = array( '_iub_cs-99' => '{\"purposes\":{\"1\":true,\"5\":true}}' );
ok( false === $detect->invoke( null ), 'iubenda senza purpose 3 -> preferenze non concesse' );
$_COOKIE = array( '_iub_cs-99' => '{\"consent\":true}' );
ok( true === $detect->invoke( null ), 'iubenda consent globale -> preferenze concesse' );

// Cookie personalizzato.
$GLOBALS['__ati_opts']['ati_cg_preferences_cookie_name'] = 'mie_preferenze';
$_COOKIE = array( 'mie_preferenze' => 'allow' );
ok( true === $detect->invoke( null ), 'cookie personalizzato = allow' );
$_COOKIE = array( 'mie_preferenze' => 'deny' );
ok( false === $detect->invoke( null ), 'cookie personalizzato = deny' );
$GLOBALS['__ati_opts']['ati_cg_preferences_cookie_name'] = '';

// CMP forzato: gli altri provider vengono ignorati.
$GLOBALS['__ati_opts']['ati_cg_cmp'] = 'cookiebot';
$_COOKIE = array( 'cmplz_preferences' => 'allow' );
ok( false === $detect->invoke( null ), 'CMP forzato su Cookiebot: Complianz ignorato' );
$_COOKIE = array( 'CookieConsent' => 'preferences:true' );
ok( true === $detect->invoke( null ), 'CMP forzato su Cookiebot: Cookiebot letto' );
$GLOBALS['__ati_opts']['ati_cg_cmp'] = 'auto';

// Provider rilevati.
$_COOKIE = array( 'CookieConsent' => 'x', 'OptanonConsent' => 'groups=' );
$found   = ATI_Cookie_Consent::detected_providers();
ok( in_array( 'cookiebot', $found, true ) && in_array( 'onetrust', $found, true ), 'rilevamento multiplo dei CMP presenti' );
$_COOKIE = array();
ok( array() === ATI_Cookie_Consent::detected_providers(), 'nessun cookie -> nessun CMP rilevato' );

// -------------------------------------------------------------------------
section( 'Configurazione predefinita: blocco attivo appena installato' );

$GLOBALS['__ati_opts'] = array();
ok( 'enforce' === ATI_Cookie_Guard::mode(), 'modalità di default: enforce' );
ok( true === ATI_Cookie_Rules::is_using_defaults(), 'senza salvataggi si usano le regole predefinite' );
ok( ATI_Cookie_Rules::rules() === ATI_Cookie_Rules::default_rules(), 'rules() restituisce le predefinite' );
ok( count( ATI_Cookie_Rules::active_rules() ) === count( ATI_Cookie_Rules::default_rules() ), 'tutte le predefinite sono attive' );
ok( true === ATI_Cookie_Guard::is_active(), 'il guard è attivo appena installato' );

// L'amministratore può disattivare tutto senza perdere le regole.
$GLOBALS['__ati_opts']['ati_cg_mode'] = 'off';
ok( false === ATI_Cookie_Guard::is_active(), 'modalità off -> guard inattivo (regole conservate)' );
ok( count( ATI_Cookie_Rules::rules() ) > 0, 'le regole restano configurate anche con il guard spento' );
$GLOBALS['__ati_opts']['ati_cg_mode'] = 'valore_strano';
ok( 'enforce' === ATI_Cookie_Guard::mode(), 'modalità non valida -> default (enforce)' );
unset( $GLOBALS['__ati_opts']['ati_cg_mode'] );

// Salvataggio vuoto (l'amministratore elimina tutte le regole): le predefinite NON tornano.
$GLOBALS['__ati_opts'][ ATI_Cookie_Rules::OPTION_RULES ] = array();
ok( false === ATI_Cookie_Rules::is_using_defaults(), 'opzione salvata vuota != opzione mai salvata' );
ok( array() === ATI_Cookie_Rules::rules(), 'regole eliminate dal backend: nessuna regola, le predefinite non tornano' );
ok( false === ATI_Cookie_Guard::is_active(), 'nessuna regola attiva -> guard inattivo' );

// Salvataggio con regole personalizzate: quelle vincono sulle predefinite.
$GLOBALS['__ati_opts'][ ATI_Cookie_Rules::OPTION_RULES ] = array( rule( array( 'match' => 'equals', 'value' => '_fbp' ) ) );
ok( 1 === count( ATI_Cookie_Rules::rules() ), 'le regole salvate sostituiscono le predefinite' );
ok( true === ATI_Cookie_Guard::is_active(), 'regola personalizzata attiva -> guard attivo' );

// Disattivazione di una singola regola predefinita.
$one_off = ATI_Cookie_Rules::default_rules();
foreach ( $one_off as $i => $r ) {
	if ( '_fbp' === $r['value'] ) {
		$one_off[ $i ]['enabled'] = 0;
	}
}
$GLOBALS['__ati_opts'][ ATI_Cookie_Rules::OPTION_RULES ] = $one_off;
ok( ! ATI_Cookie_Rules::evaluate( '_fbp', '', $no_consent, ATI_Cookie_Rules::active_rules() )['blocked'], 'regola predefinita disattivata dal backend -> _fbp non più bloccato' );
ok( ATI_Cookie_Rules::evaluate( '_ga', '', $no_consent, ATI_Cookie_Rules::active_rules() )['blocked'], 'le altre regole predefinite restano attive' );
$GLOBALS['__ati_opts'] = array();

// L'utente loggato è escluso per default.
$GLOBALS['__ati_logged_in'] = true;
ok( false === ATI_Cookie_Guard::is_active(), 'utente loggato escluso per default' );
$GLOBALS['__ati_opts']['ati_cg_skip_logged_in'] = '0';
ok( true === ATI_Cookie_Guard::is_active(), 'esclusione utenti loggati disattivabile' );
$GLOBALS['__ati_logged_in'] = false;
$GLOBALS['__ati_opts']      = array();

// -------------------------------------------------------------------------
echo "\n---------------------------------------\n";
echo "RISULTATO: {$GLOBALS['__pass']} PASS / {$GLOBALS['__fail']} FAIL\n";
exit( $GLOBALS['__fail'] > 0 ? 1 : 0 );
