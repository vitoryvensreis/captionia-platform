(() => {
  'use strict';

  const copy = document.querySelector('[data-copy-pix]');
  if (copy) {
    copy.addEventListener('click', async () => {
      const input = document.getElementById('pix-code');
      if (!input) return;
      try {
        await navigator.clipboard.writeText(input.value);
      } catch (_) {
        input.select();
        document.execCommand('copy');
      }
      const old = copy.textContent;
      copy.textContent = 'PIX copiado ✓';
      setTimeout(() => { copy.textContent = old; }, 1800);
    });
  }

  const payment = document.querySelector('[data-payment-reference]');
  if (payment && payment.dataset.paymentFinal !== '1') {
    const ref = payment.dataset.paymentReference;
    let attempts = 0;
    const check = async () => {
      attempts++;
      try {
        const response = await fetch('/pagamento/' + encodeURIComponent(ref) + '/status', {
          headers: {'Accept': 'application/json'},
          cache: 'no-store'
        });
        const data = await response.json();
        if (data.paid && data.redirect) {
          window.location.href = data.redirect;
          return;
        }
        if (['declined','cancelled','expired','failed'].includes(data.status)) {
          window.location.reload();
          return;
        }
      } catch (_) {}
      if (attempts < 180) setTimeout(check, 4000);
    };
    setTimeout(check, 3500);
  }
})();
