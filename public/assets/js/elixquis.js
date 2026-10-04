/* Améliorations progressives de présentation ; prix, stock et actions restent validés par le serveur. */
(() => {
  const catalogue = document.querySelector('[data-catalogue]');
  if (catalogue && catalogue.querySelector('[data-product-grid]')) {
    const tools = catalogue.querySelector('[data-catalogue-tools]');
    const grid = catalogue.querySelector('[data-product-grid]');
    const cards = [...grid.querySelectorAll('[data-product]')];
    const search = catalogue.querySelector('[data-product-search]');
    const sort = catalogue.querySelector('[data-product-sort]');
    const available = catalogue.querySelector('[data-product-available]');
    const count = catalogue.querySelector('[data-product-count]');
    const empty = catalogue.querySelector('[data-product-empty]');
    const normalize = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
    const update = () => {
      const query = normalize(search.value.trim());
      let visible = 0;
      cards.forEach(card => {
        card.hidden = !normalize(card.dataset.name).includes(query) || (available.checked && card.dataset.available !== '1');
        if (!card.hidden) visible++;
      });
      const ordered = [...cards].sort((a, b) => {
        if (sort.value === 'price-asc') return Number(a.dataset.price) - Number(b.dataset.price);
        if (sort.value === 'price-desc') return Number(b.dataset.price) - Number(a.dataset.price);
        return a.dataset.name.localeCompare(b.dataset.name, 'fr');
      });
      ordered.forEach(card => grid.append(card));
      count.textContent = visible + ' produit' + (visible > 1 ? 's' : '') + ' affiché' + (visible > 1 ? 's' : '');
      empty.hidden = visible !== 0;
    };
    tools.hidden = false;
    search.addEventListener('input', update);
    sort.addEventListener('change', update);
    available.addEventListener('change', update);
    catalogue.querySelector('[data-filter-reset]').addEventListener('click', () => {
      search.value = ''; sort.value = 'name'; available.checked = false; update(); search.focus();
    });
  }
  // L’absence de stockage navigateur n’empêche pas l’accès ni la confirmation.
  const modalEl = document.getElementById('ageModal');
  if (modalEl && window.bootstrap) {
    let verified = false;
    try { verified = localStorage.getItem('ageVerified') === 'true'; } catch {}
    if (!verified) {
      const modal = new bootstrap.Modal(modalEl);
      modal.show();
      document.getElementById('btn-age-yes').addEventListener('click', () => {
        try { localStorage.setItem('ageVerified', 'true'); } catch {}
        modal.hide();
      });
      document.getElementById('btn-age-no').addEventListener('click', () => {
        window.location.href = 'https://www.service-public.fr/particuliers/vosdroits/F34463';
      });
    }
  }
  document.querySelectorAll('.js-pwd-toggle, #togglePassword').forEach(button => {
    button.setAttribute('aria-pressed', 'false');
    button.setAttribute('aria-label', 'Afficher le mot de passe');
  });
})();
