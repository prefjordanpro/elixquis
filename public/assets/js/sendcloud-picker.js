(() => {
  const checkout = document.querySelector('[data-sendcloud-checkout]');
  checkout?.querySelectorAll('input[name="method"][data-point-required="1"]').forEach(input => {
    input.addEventListener('change', () => {
      if (input.checked) checkout.requestSubmit(checkout.querySelector('button[type="submit"][name="action"]'));
    });
  });
  const root = document.querySelector('[data-service-point-picker]');
  if (!root) return;
  const config = JSON.parse(root.dataset.config), form = root.closest('form');
  const button = root.querySelector('[data-open-picker]'), status = root.querySelector('[data-picker-status]');
  const pointInput = root.querySelector('[name="point"]'), postInput = root.querySelector('[name="post_number"]');
  const submit = form.querySelector('button[type="submit"]');
  let scriptPromise;
  const load = () => {
    if (window.sendcloud?.servicePoints) return Promise.resolve();
    if (!scriptPromise) scriptPromise = new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = 'https://embed.sendcloud.sc/spp/1.0.0/api.min.js';
      script.onload = resolve;
      script.onerror = () => { script.remove(); scriptPromise = null; reject(new Error('picker')); };
      document.head.append(script);
    });
    return scriptPromise;
  };
  button.addEventListener('click', async () => {
    status.textContent = ''; button.disabled = true;
    try {
      await load();
      window.sendcloud.servicePoints.open({ ...config, ...(pointInput.value ? { servicePointId: Number(pointInput.value) } : {}) }, async (servicePoint, postNumber) => {
        status.textContent = 'Vérification du point relais…'; submit.disabled = true;
        try {
          const data = new FormData(form);
          data.set('action', 'point'); data.set('point', String(servicePoint.id)); data.set('post_number', postNumber || '');
          const response = await fetch(form.getAttribute('action'), { method: 'POST', body: data, headers: { Accept: 'application/json' }, credentials: 'same-origin' });
          const result = await response.json();
          if (!response.ok || !result.point) throw new Error(result.error || 'Choisissez à nouveau votre livraison.');
          const point = result.point;
          pointInput.value = String(point.id); postInput.value = point.post_number || '';
          root.querySelector('[data-point-name]').textContent = point.name;
          root.querySelector('[data-point-street]').textContent = [point.address.street, point.address.house_number].filter(Boolean).join(' ');
          root.querySelector('[data-point-city]').textContent = `${point.address.postal_code} ${point.address.city}`;
          root.querySelector('[data-point-summary]').hidden = false;
          button.textContent = 'Changer de point relais'; status.textContent = ''; submit.disabled = false;
        } catch (error) { pointInput.value = ''; postInput.value = ''; root.querySelector('[data-point-summary]').hidden = true; status.textContent = error.message; }
        finally { button.disabled = false; button.focus(); }
      }, errors => {
        if (!errors.includes('Closed')) status.textContent = 'La carte est indisponible. Réessayez ou choisissez une autre livraison.';
        button.disabled = false; button.focus();
      });
    } catch { status.textContent = 'La carte est indisponible. Réessayez ou choisissez une autre livraison.'; button.disabled = false; }
  });
  window.addEventListener('pagehide', () => window.sendcloud?.servicePoints?.close());
  window.addEventListener('pageshow', () => { button.disabled = false; });
})();
