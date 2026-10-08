<?php use CaptionIA\Core\View; ?>
<main class="dashboard-wrap"><section class="dashboard-head"><div>
<div class="eyebrow">TRANSPARÊNCIA</div><h1>Privacidade e métricas</h1>
<p>Como medimos o uso do site Caption IA.</p></div></section>
<section class="panel"><h2>Métricas opcionais</h2>
<p>Quando você aceita, registramos as etapas visitadas no site, cliques em planos, abertura do checkout e profundidade da rolagem da página inicial. Essas informações ajudam a melhorar a apresentação e a experiência de compra.</p>
<p>Não gravamos o texto digitado em formulários, senhas, CPF, dados financeiros ou detalhes do Pix nessas métricas. As visitas usam um identificador aleatório por sessão; eventos de usuários autenticados podem ser associados à conta para análise de conversão.</p>
<h2>Como mudar a escolha</h2><p>Use o botão abaixo para redefinir seu consentimento neste navegador. Novas métricas só serão enviadas após nova autorização.</p>
<button type="button" class="button" data-reset-metrics>Redefinir minha preferência</button>
<p class="muted">O link de afiliado usa um cookie de atribuição, com validade de 30 dias, necessário para reconhecer indicações. O acompanhamento de rolagem é independente e opcional.</p>
<p class="muted">Pedidos, pagamentos e solicitações de saque são tratados para a prestação do serviço e a gestão financeira. A chave Pix informada ao solicitar saque é armazenada com criptografia e acessível ao financeiro autorizado.</p>
<a class="mini-button" href="/">Voltar ao início</a>
</section></main>
<script>document.querySelector('[data-reset-metrics]')?.addEventListener('click',function(){localStorage.removeItem('captionia_metrics_consent_v1');alert('Preferência apagada. Recarregue a página para escolher novamente.');});</script>