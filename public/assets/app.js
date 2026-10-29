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
      copy.textContent = 'Pix copiado ✓';
      setTimeout(() => { copy.textContent = old; }, 1800);
    });
  }

  const affiliateCopy = document.querySelector('[data-copy-affiliate]');
  if (affiliateCopy) {
    affiliateCopy.addEventListener('click', async () => {
      const field = document.getElementById('affiliate-link');
      if (!field) return;
      try { await navigator.clipboard.writeText(field.value); }
      catch (_) { field.select(); document.execCommand('copy'); }
      affiliateCopy.textContent = 'Link copiado ✓';
      setTimeout(() => { affiliateCopy.textContent = 'Copiar link'; }, 2000);
    });
  }

  const qrTarget = document.querySelector('[data-pix-qrcode]');
  if (qrTarget && window.QRCode) {
    const payload = qrTarget.dataset.pixQrcode || '';
    if (payload) {
      qrTarget.innerHTML = '';
      new QRCode(qrTarget, {
        text: payload,
        width: 196,
        height: 196,
        colorDark: '#000000',
        colorLight: '#ffffff',
        correctLevel: QRCode.CorrectLevel.M
      });
    }
  }

  document.querySelectorAll('.plan-admin-form').forEach((form) => {
    const type = form.querySelector('[data-plan-type]');
    const interval = form.querySelector('[data-plan-interval]');
    if (!type || !interval) return;

    const syncPlanFields = () => {
      const isLifetime = type.value === 'lifetime';
      if (isLifetime) {
        interval.value = 'once';
      } else if (interval.value === 'once') {
        interval.value = 'month';
      }

      Array.from(interval.options).forEach((option) => {
        option.disabled = isLifetime ? option.value !== 'once' : option.value === 'once';
      });
    };

    type.addEventListener('change', syncPlanFields);
    syncPlanFields();
  });

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

        if (data.payment_url) {
          const invoice = payment.querySelector('[data-asaas-invoice]');
          if (invoice && invoice.href !== data.payment_url) {
            invoice.href = data.payment_url;
            invoice.hidden = false;
          }
        }

        if (data.paid && data.redirect) {
          window.location.href = data.redirect;
          return;
        }

        if (['declined', 'cancelled', 'expired', 'failed'].includes(data.status)) {
          window.location.reload();
          return;
        }
      } catch (_) {}

      if (attempts < 360) {
        setTimeout(check, 5000);
      }
    };

    setTimeout(check, 4000);
  }
})();
