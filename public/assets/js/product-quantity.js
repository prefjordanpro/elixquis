document.querySelectorAll('[data-product-quantity]').forEach(control => {
  const input = control.querySelector('input');
  const minus = control.querySelector('[data-quantity-step="-1"]');
  const plus = control.querySelector('[data-quantity-step="1"]');
  const maximum = Number(input.max);
  const update = () => {
    if (input.disabled) return;
    const value = Number(input.value);
    minus.disabled = !Number.isInteger(value) || value <= 1;
    plus.disabled = !Number.isInteger(value) || value >= maximum;
  };
  const normalize = () => {
    if (input.disabled) return;
    input.value = String(Math.min(maximum, Math.max(1, Math.trunc(Number(input.value)) || 1)));
    update();
  };
  control.querySelectorAll('[data-quantity-step]').forEach(button => {
    button.addEventListener('click', () => {
      normalize();
      input.value = String(Math.min(maximum, Math.max(1, Number(input.value) + Number(button.dataset.quantityStep))));
      input.dispatchEvent(new Event('input', { bubbles: true }));
    });
  });
  input.addEventListener('input', update);
  input.addEventListener('change', normalize);
  update();
});
