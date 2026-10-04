/* Récapitulatif visuel uniquement : montants et commande restent calculés par le serveur. */
(() => {
  const estimate = document.querySelector('[data-delivery-estimate]');
  if (!estimate) return;
  const carriers = document.querySelectorAll('input[data-delivery-cents]');
  const format = new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' });
  const update = () => {
    const selected = [...carriers].find(input => input.checked);
    if (!selected || selected.dataset.deliveryCents === '') { estimate.hidden = true; return; }
    const delivery = Number(selected.dataset.deliveryCents);
    const subtotal = Number(estimate.dataset.subtotalCents);
    if (!Number.isFinite(delivery) || !Number.isFinite(subtotal)) { estimate.hidden = true; return; }
    estimate.querySelector('[data-delivery-price]').textContent = format.format(delivery / 100);
    estimate.querySelector('[data-checkout-total]').textContent = format.format((subtotal + delivery) / 100);
    estimate.hidden = false;
  };
  carriers.forEach(input => input.addEventListener('change', update));
  update();
})();
