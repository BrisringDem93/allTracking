<?php
/**
 * Form Provider Adapters — interfaccia modulare e registry.
 *
 * Ordine di affidabilità (dal più affidabile):
 *   1. hook PHP eseguito dopo il salvataggio riuscito (implementato: Elementor, Fluent Forms)
 *   2. callback ufficiale server-side del provider
 *   3. evento JavaScript ufficiale dopo risposta AJAX positiva (bridge)
 *   4. integrazione manuale tramite API pubblica (ati_track_confirmed_event / bridge REST)
 *   5. listener DOM submit soltanto come tentativo (form_submit_attempt, off di default)
 *
 * Ogni adapter produce un evento SOLO dopo il successo reale del provider.
 *
 * @package QuickTrackingIntegration\GA4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interfaccia che ogni provider deve implementare.
 */
interface ATI_Form_Provider_Interface {

	/**
	 * Slug del provider (es. 'elementor').
	 *
	 * @return string
	 */
	public function get_id();

	/**
	 * Il provider è disponibile in questo sito?
	 *
	 * @return bool
	 */
	public function is_available();

	/**
	 * Registra gli hook del provider che notificano un lead confermato.
	 *
	 * @return void
	 */
	public function register();
}

/**
 * Registry dei provider form.
 */
class ATI_Form_Provider_Registry {

	/** @var ATI_Form_Provider_Interface[] */
	protected static $providers = array();

	/**
	 * Inizializza e registra i provider disponibili.
	 *
	 * @return void
	 */
	public static function boot() {
		$providers = array(
			new ATI_Provider_Elementor(),
			new ATI_Provider_Fluent_Forms(),
			// Breakdance: struttura pronta ma NON verificata -> non registrata come funzionante.
			new ATI_Provider_Breakdance(),
		);

		/**
		 * Consente di aggiungere provider form personalizzati.
		 *
		 * @param ATI_Form_Provider_Interface[] $providers Provider.
		 */
		$providers = apply_filters( 'ati_form_providers', $providers );

		$available   = array();
		$unavailable = array();
		foreach ( $providers as $provider ) {
			if ( ! ( $provider instanceof ATI_Form_Provider_Interface ) ) {
				continue;
			}
			if ( $provider->is_available() ) {
				$provider->register();
				self::$providers[ $provider->get_id() ] = $provider;
				$available[] = $provider->get_id();
			} else {
				$unavailable[] = $provider->get_id();
			}
		}

		// Diagnostica: quali provider form sono attivi e quali no (PII-free).
		if ( function_exists( 'ati_ga4_log' ) ) {
			ati_ga4_log(
				'providers_registered',
				array(
					'active'      => implode( ',', $available ) ?: 'none',
					'unavailable' => implode( ',', $unavailable ) ?: 'none',
				)
			);
		}
	}

	/**
	 * Provider attivi.
	 *
	 * @return string[]
	 */
	public static function active_ids() {
		return array_keys( self::$providers );
	}

	/**
	 * Notifica un lead confermato attraverso l'hook pubblico.
	 *
	 * @param string $provider Slug provider.
	 * @param string $form_id  Id form.
	 * @param array  $params   Parametri commerciali (no PII).
	 * @param array  $context  Contesto (client_id/session_id/page_*).
	 * @return void
	 */
	public static function notify_confirmed_lead( $provider, $form_id, array $params = array(), array $context = array() ) {
		if ( function_exists( 'ati_ga4_log' ) ) {
			ati_ga4_log( 'provider_hook_fired', array( 'provider' => (string) $provider, 'form_id' => (string) $form_id ) );
		}

		// In modalità "submit" il lead è generato dal bridge client-side: i provider
		// server-side non emettono il lead per evitare doppioni.
		if ( class_exists( 'ATI_GA4_Config' ) && 'submit' === ATI_GA4_Config::lead_trigger() ) {
			return;
		}
		/**
		 * Hook pubblico: un provider ha confermato un lead.
		 *
		 * @param string $provider Provider.
		 * @param string $form_id  Form id.
		 * @param array  $params   Parametri.
		 * @param array  $context  Contesto.
		 */
		do_action( 'ati_confirmed_lead', $provider, $form_id, $params, $context );
	}
}

/**
 * Adapter Elementor Pro Forms.
 *
 * Hook ufficiale: `elementor_pro/forms/new_record` — eseguito lato server dopo
 * una submission valida. Riferimento: Elementor Pro Developers (Form Actions).
 */
class ATI_Provider_Elementor implements ATI_Form_Provider_Interface {

	public function get_id() {
		return 'elementor';
	}

	public function is_available() {
		return defined( 'ELEMENTOR_PRO_VERSION' );
	}

	public function register() {
		add_action( 'elementor_pro/forms/new_record', array( $this, 'handle' ), 10, 2 );
	}

	/**
	 * Gestisce una submission Elementor confermata.
	 *
	 * @param mixed $record  Oggetto record del form Elementor.
	 * @param mixed $handler Handler Ajax.
	 * @return void
	 */
	public function handle( $record, $handler ) {
		$form_id   = '';
		$form_name = '';

		if ( is_object( $record ) && method_exists( $record, 'get_form_settings' ) ) {
			$form_id   = (string) $record->get_form_settings( 'id' );
			$form_name = (string) $record->get_form_settings( 'form_name' );
		}

		$context = array_merge(
			ATI_GA4_Client_Context::from_cookies(),
			array(
				'provider'      => $this->get_id(),
				'form_id'       => $form_id,
				'form_name'     => $form_name,
				'page_location' => isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '',
				'source'        => 'server_confirmed',
			)
		);

		ATI_Form_Provider_Registry::notify_confirmed_lead( $this->get_id(), $form_id, array( 'form_name' => $form_name ), $context );
	}
}

/**
 * Adapter Fluent Forms.
 *
 * Hook ufficiale: `fluentform/submission_inserted` — eseguito dopo il salvataggio
 * della submission. Riferimento: Fluent Forms Developer Docs.
 */
class ATI_Provider_Fluent_Forms implements ATI_Form_Provider_Interface {

	public function get_id() {
		return 'fluentform';
	}

	public function is_available() {
		return defined( 'FLUENTFORM_VERSION' ) || function_exists( 'wpFluentForm' );
	}

	public function register() {
		// Evento reale: submission salvata con successo.
		add_action( 'fluentform/submission_inserted', array( $this, 'handle' ), 10, 3 );
		// Compatibilità con la variante underscore (vecchie installazioni).
		add_action( 'fluentform_submission_inserted', array( $this, 'handle' ), 10, 3 );

		// Diagnostica log-only: verifica se Fluent avvia l'elaborazione della submission.
		if ( function_exists( 'ati_ga4_log' ) ) {
			add_action(
				'fluentform/before_insert_submission',
				function ( $insertData = null, $data = null, $form = null ) {
					$fid = is_object( $form ) && isset( $form->id ) ? (string) $form->id : '';
					ati_ga4_log( 'fluent_before_insert', array( 'form_id' => $fid ) );
				},
				10,
				3
			);
		}
	}

	/**
	 * Gestisce una submission Fluent Forms confermata.
	 *
	 * @param int   $entry_id  ID della submission salvata.
	 * @param array $form_data Dati del form (non usati: potrebbero contenere PII).
	 * @param mixed $form      Oggetto form.
	 * @return void
	 */
	public function handle( $entry_id, $form_data, $form ) {
		$form_id   = is_object( $form ) && isset( $form->id ) ? (string) $form->id : '';
		$form_name = is_object( $form ) && isset( $form->title ) ? (string) $form->title : '';

		$context = array_merge(
			ATI_GA4_Client_Context::from_cookies(),
			array(
				'provider'      => $this->get_id(),
				'form_id'       => $form_id,
				'form_name'     => $form_name,
				// event_id stabile per submission: dedup a prova di doppio scatto dell'hook.
				'event_id'      => 'ff_' . (int) $entry_id,
				'page_location' => isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '',
				'source'        => 'server_confirmed',
			)
		);

		ATI_Form_Provider_Registry::notify_confirmed_lead( $this->get_id(), $form_id, array( 'form_name' => $form_name ), $context );
	}
}

/**
 * Adapter Breakdance (NON VERIFICATO).
 *
 * Al momento non è stato individuato un hook PHP server-side ufficiale e stabile
 * per la conferma della submission dei form Breakdance. La struttura è pronta ma
 * il provider NON viene dichiarato funzionante: `is_available()` ritorna false.
 * Integrazione consigliata: bridge JavaScript dopo la risposta AJAX positiva o
 * API manuale `ati_track_confirmed_event()`.
 */
class ATI_Provider_Breakdance implements ATI_Form_Provider_Interface {

	public function get_id() {
		return 'breakdance';
	}

	public function is_available() {
		// Non verificato: non attivare finché non esiste un hook ufficiale confermato.
		return false;
	}

	public function register() {
		// Intenzionalmente vuoto: nessun hook ufficiale verificato.
	}
}
