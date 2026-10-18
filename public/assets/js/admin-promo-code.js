/* Aide de saisie uniquement ; type et conversion en centimes sont validés côté serveur. */
(() => {
  const init = () => {
    const type = document.querySelector('[data-promo-code-type]');
    const row = document.querySelector('[data-promo-code-value]');
    if (!type || !row) return;
    const input = row.querySelector('input');
    const label = row.querySelector('label');
    const help = row.querySelector('.form-help, .help-text, [id$="_help"]');
    const update = () => {
      const fixed = type.value === 'fixed';
      if (label) label.textContent = fixed ? 'Valeur (€)' : 'Valeur (%)';
      if (help) help.textContent = fixed ? 'Montant de la remise en euros, par exemple 5,00 €.' : 'Pourcentage entier de réduction, de 1 à 100 %.';
      if (input) {
        if (fixed) input.type = 'text';
        input.inputMode = fixed ? 'decimal' : 'numeric';
        input.placeholder = fixed ? '5,00' : '10';
        input.removeAttribute('max');
        if (!fixed) input.setAttribute('max', '100');
        input.setAttribute('step', fixed ? '0.01' : '1');
      }
      row.querySelectorAll('.input-group-text').forEach(unit => { if (unit.textContent.trim() === '€') unit.hidden = !fixed; });
    };
    type.addEventListener('change', update); update();
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
