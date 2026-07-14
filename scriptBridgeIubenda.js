<script>
(function () {
  console.log('[IUBENDA BRIDGE] Inizializzazione');
  window._iub = window._iub || {};
  window._iub.csConfiguration = window._iub.csConfiguration || {};
  window._iub.csConfiguration.callback =
    window._iub.csConfiguration.callback || {};

  const callbacks = window._iub.csConfiguration.callback;
  const previousOnPreferenceChange = callbacks.onPreferenceChange;

  callbacks.onPreferenceChange = function (preferences) {
    // Mantiene eventuali callback già presenti.
    if (typeof previousOnPreferenceChange === 'function') {
      previousOnPreferenceChange.apply(this, arguments);
    }

    let currentPreferences = preferences;

    // In caso Iubenda non passi le preferenze alla callback,
    // prova a leggerle tramite la sua API.
    if (
      (!currentPreferences ||
        Object.keys(currentPreferences).length === 0) &&
      window._iub?.cs?.api?.getPreferences
    ) {
      currentPreferences = window._iub.cs.api.getPreferences();
    }

    const purposes = currentPreferences?.purposes || {};

    console.log('[IUBENDA BRIDGE] Preferenze ricevute:', currentPreferences);
    console.log('[IUBENDA BRIDGE] Finalità ricevute:', purposes);

    // In Iubenda la finalità 5 corrisponde normalmente al marketing.
    const marketingAccepted =
      purposes[5] === true ||
      purposes['5'] === true ||
      purposes.adv === true;

    window.marketingConsent = marketingAccepted;

    // Evento unico di cambio preferenze: il consumer deve rileggere API/cookie.
    document.dispatchEvent(
      new CustomEvent('fstIubendaPreferencesChanged', {
        detail: { preferences: currentPreferences }
      })
    );

    if (marketingAccepted) {
      console.log(
        '[IUBENDA BRIDGE] Consenso marketing concesso'
      );

      document.dispatchEvent(
        new CustomEvent('fstMarketingConsentAccepted', {
          detail: {
            marketingConsent: true,
            preferences: currentPreferences
          }
        })
      );
    } else {
      console.log(
        '[IUBENDA BRIDGE] Consenso marketing assente o revocato'
      );

      document.dispatchEvent(
        new CustomEvent('fstMarketingConsentRevoked', {
          detail: {
            marketingConsent: false,
            preferences: currentPreferences
          }
        })
      );
    }
  };
})();
</script>
