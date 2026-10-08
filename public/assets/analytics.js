(() => {
  'use strict';

  const consentKey = 'captionia_metrics_consent_v1';
  const visitKey = 'captionia_metrics_visit_v1';
  const route = window.location.pathname;
  const page = route === '/' ? 'home' :
    route === '/criar-conta' || route === '/entrar' ? 'signup' :
    route.startsWith('/comprar/') ? 'checkout' :
    route.startsWith('/pagamento/') ? 'payment' :
    route === '/minha-conta' ? 'account' : null;
  if (!page) return;

  const cleanId = () => {
    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);
    return [...bytes].map(n => n.toString(16).padStart(2, '0')).join('');
  };
  let visitId = sessionStorage.getItem(visitKey);
  if (!visitId || !/^[a-f0-9]{32}$/.test(visitId)) {
    visitId = cleanId();
    sessionStorage.setItem(visitKey, visitId);
  }

  const record = (event) => {
    if (localStorage.getItem(consentKey) !== 'yes') return;
    fetch('/metricas/evento', {
      method: 'POST',
      credentials: 'same-origin',
      keepalive: true,
      headers: {'Content-Type':'application/json','Accept':'application/json'},
      body: JSON.stringify({consent: true, visit_id: visitId, event, page})
    }).catch(() => {});
  };
  const begin = () => {
    record('page_view');
    if (page === 'signup') record('signup_view');
    if (page === 'checkout') record('checkout_view');
    if (page === 'payment') record('payment_view');
  };

  const makeBanner = () => {
    const bar = document.createElement('section');
    bar.className = 'metrics-consent';
    bar.setAttribute('aria-label','Preferências de métricas de navegação');
    bar.innerHTML =
      '<div><strong>Podemos analisar sua navegação?</strong>' +
      '<p>Com sua permissão, medimos visitas, cliques em planos e até onde a página foi rolada. ' +
      'Não coletamos dados digitados ou conteúdo de pagamento. ' +
      '<a href="/privacidade">Saiba mais</a>.</p></div>' +
      '<div class="metrics-actions"><button type="button" data-metrics-accept>Aceitar métricas</button>' +
      '<button type="button" data-metrics-decline>Somente essenciais</button></div>';
    document.body.appendChild(bar);
    bar.querySelector('[data-metrics-accept]').addEventListener('click', () => {
      localStorage.setItem(consentKey,'yes');bar.remove();begin();
    });
    bar.querySelector('[data-metrics-decline]').addEventListener('click', () => {
      localStorage.setItem(consentKey,'no');bar.remove();
    });
  };

  if (localStorage.getItem(consentKey) === 'yes') begin();
  else if (localStorage.getItem(consentKey) !== 'no') makeBanner();

  document.addEventListener('click', (event) => {
    const link = event.target.closest('a[href]');
    if (link && page === 'home' && (
      link.getAttribute('href')?.startsWith('/comprar/') ||
      link.getAttribute('href')?.startsWith('/criar-conta?plano=')
    )) record('plan_click');
  });
  if (page === 'checkout') {
    document.querySelectorAll('form[action*="/comprar/"]').forEach((form) => {
      form.addEventListener('submit', () => record('checkout_submit'));
    });
  }
  if (page !== 'home') return;
  const pricing = document.querySelector('#planos');
  if (pricing && 'IntersectionObserver' in window) {
    const observer = new IntersectionObserver(entries => {
      if (entries.some(e => e.isIntersecting)) {
        record('pricing_view');observer.disconnect();
      }
    }, {threshold: .15});
    observer.observe(pricing);
  }
  const seen = new Set();
  let pending = false;
  const onScroll = () => {
    if (pending) return;
    pending = true;
    window.requestAnimationFrame(() => {
      pending = false;
      const doc = document.documentElement;
      const available = Math.max(1, doc.scrollHeight - window.innerHeight);
      const percent = Math.round((window.scrollY / available) * 100);
      [25,50,75,100].forEach(threshold => {
        if (percent >= threshold && !seen.has(threshold)) {
          seen.add(threshold);record('scroll_' + threshold);
        }
      });
    });
  };
  window.addEventListener('scroll',onScroll,{passive:true});
  onScroll();
})();