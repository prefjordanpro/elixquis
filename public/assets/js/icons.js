/* Icônes linéaires locales : même API légère que celle utilisée par les templates. */
(() => {
  const paths = {
    user: '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    'shopping-cart': '<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l3 15h12l3-10H6"/>',
    'shopping-bag': '<path d="M6 3h12l3 5v13H3V8z"/><path d="M3 8h18M8 8a4 4 0 0 0 8 0"/>',
    mail: '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m2 5 10 8L22 5"/>',
    lock: '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
    eye: '<path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7"/><circle cx="12" cy="12" r="3"/>',
    'eye-off': '<path d="m3 3 18 18M9 5a13 13 0 0 1 3 0c7 0 11 7 11 7a18 18 0 0 1-3 4M6 6a20 20 0 0 0-5 6s4 7 11 7a13 13 0 0 0 6-2"/>',
    'map-pin': '<path d="M21 10c0 7-9 12-9 12S3 17 3 10a9 9 0 0 1 18 0"/><circle cx="12" cy="10" r="3"/>',
    truck: '<path d="M1 3h14v14H1zM15 8h4l3 4v5h-7"/><circle cx="5" cy="19" r="2"/><circle cx="18" cy="19" r="2"/>',
    'log-out': '<path d="M9 21H5V3h4M12 12h10m-5-5 5 5-5 5"/>',
    'check-circle': '<path d="M22 11v1a10 10 0 1 1-6-9M22 4 12 14l-3-3"/>',
    'arrow-right': '<path d="M5 12h14m-6-6 6 6-6 6"/>',
    shield: '<path d="M12 22s9-4 9-11V5l-9-3-9 3v6c0 7 9 11 9 11"/><path d="m8 12 3 3 5-5"/>'
  };
  const icons = Object.fromEntries(Object.entries(paths).map(([name, path]) => [name, {
    toSvg: () => '<svg class="feather feather-' + name + '" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' + path + '</svg>'
  }]));
  window.feather = { icons, replace: () => document.querySelectorAll('[data-feather]').forEach(element => {
    const icon = icons[element.dataset.feather];
    if (!icon) return;
    const holder = document.createElement('span');
    holder.innerHTML = icon.toSvg();
    const svg = holder.firstChild;
    const inherited = element.getAttribute('class');
    if (inherited) svg.classList.add(...inherited.split(/\s+/).filter(Boolean));
    element.replaceWith(svg);
  }) };
})();
