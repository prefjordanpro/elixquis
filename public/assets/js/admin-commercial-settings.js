/* Présentation du formulaire uniquement ; validation et règles restent côté serveur. */
(() => {
  const init = () => {
    const mode = document.querySelector('[data-commercial-shipping-mode]');
    const row = document.querySelector('[data-commercial-shipping-threshold]');
    if (!mode || !row) return;
    const input = row.querySelector('input');
    const update = () => {
      const applicable = mode.value === 'threshold';
      row.hidden = !applicable;
      if (input) {
        input.required = applicable;
        // Conserver le seuil enregistré lorsqu'on masque le champ après l'avoir vidé.
        if (!applicable && input.value.trim() === '') input.value = input.defaultValue || '0';
      }
    };
    mode.addEventListener('change', update);
    update();
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
