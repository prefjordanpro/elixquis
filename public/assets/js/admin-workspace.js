(() => {
  'use strict';
  const init = () => {
    if (location.hash) {
      const field = document.getElementById(location.hash.slice(1));
      const pane = field?.closest('.tab-pane');
      const target = pane ? `#${pane.id}` : location.hash;
      const tab = [...document.querySelectorAll('[data-bs-toggle="tab"]')].find(link => link.getAttribute('href') === target || link.getAttribute('data-bs-target') === target);
      if (tab) { tab.click(); field.scrollIntoView({ block: 'center' }); field.focus({ preventScroll: true }); }
    }
    document.querySelectorAll('[data-image-replacement]').forEach(input => {
      input.addEventListener('change', () => {
        const file = input.files?.[0], row = input.closest('.field-image');
        if (!file || !row || !['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) return;
        let preview = row.querySelector('[data-new-image-preview]');
        if (!preview) { preview = document.createElement('img'); preview.dataset.newImagePreview = ''; preview.alt = 'Aperçu de la nouvelle image'; preview.className = 'content-image-preview img-fluid mt-3'; preview.style.maxHeight = '180px'; row.append(preview); }
        if (preview.dataset.objectUrl) URL.revokeObjectURL(preview.dataset.objectUrl);
        preview.dataset.objectUrl = URL.createObjectURL(file); preview.src = preview.dataset.objectUrl;
      });
    });
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
