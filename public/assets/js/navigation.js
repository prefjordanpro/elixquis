/* Présentation du menu uniquement ; focus et fermeture gérés par Bootstrap. */
(() => {
  const panel = document.getElementById('navbarCollapse');
  const toggle = document.querySelector('[aria-controls="navbarCollapse"]');
  if (!panel || !toggle) return;
  panel.addEventListener('show.bs.offcanvas', () => toggle.setAttribute('aria-expanded', 'true'));
  panel.addEventListener('hidden.bs.offcanvas', () => {
    toggle.setAttribute('aria-expanded', 'false');
    panel.querySelectorAll('[data-bs-toggle="dropdown"]').forEach(button => {
      bootstrap.Dropdown.getInstance(button)?.hide();
    });
  });
})();
