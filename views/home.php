<?php
use CaptionIA\Core\View;

$byCode = [];
foreach ($plans as $plan) {
    $byCode[$plan['code']] = $plan;
}
$monthly = $byCode['monthly'] ?? ['price_cents' => 3000];
$lifetime = $byCode['lifetime'] ?? ['price_cents' => 7000];
?>
<main class="home-main">
<section class="adobe-inspired-hero">
  <div class="hero-backdrop"></div>
  <div class="hero-center">
    <div class="hero-kicker">CAPTION IA · PREMIERE PRO</div>
    <h1>Crie. Edite. <span>Entregue mais rápido.</span></h1>

    <div class="hero-command">
      <div class="command-label">O que você quer acelerar hoje?</div>
      <div class="command-row">
        <span>Legendas, AutoEdit, cortes, SFX, karaoke e muito mais.</span>
        <a class="button" href="#planos">Começar</a>
      </div>
    </div>

    <div class="hero-tabs">
      <a href="#recursos" class="active">Destaques</a>
      <a href="#recursos">Legendas</a>
      <a href="#recursos">Automação</a>
      <a href="#recursos">Áudio</a>
      <a href="#planos">Planos</a>
    </div>
  </div>
</section>

<section class="workspace-section" id="recursos">
  <div class="section-heading left">
    <div class="eyebrow">FERRAMENTAS</div>
    <h2>Seu fluxo dentro de um só painel.</h2>
  </div>

  <div class="creative-grid">
    <article class="creative-card"><span class="tool-icon orange">⚡</span><div><b>AutoEdit</b><p>Encadeia etapas e prepara a edição automaticamente.</p></div></article>
    <article class="creative-card"><span class="tool-icon blue">CC</span><div><b>Legendas IA</b><p>Transcreve, segmenta e aplica estilos prontos.</p></div></article>
    <article class="creative-card"><span class="tool-icon violet">✦</span><div><b>Karaoke</b><p>Mantém a frase e destaca a palavra falada em tempo real.</p></div></article>
    <article class="creative-card"><span class="tool-icon pink">♪</span><div><b>SFX Auto</b><p>Insere efeitos sonoros com menos trabalho manual.</p></div></article>
    <article class="creative-card"><span class="tool-icon cyan">✂</span><div><b>AutoCut</b><p>Ajuda a tratar silêncios, retakes e ritmo da timeline.</p></div></article>
    <article class="creative-card"><span class="tool-icon lime">↗</span><div><b>Dinâmica</b><p>Zoom, animações, grids e ferramentas de timeline.</p></div></article>
  </div>
</section>

<section class="pricing-section" id="planos">
  <div class="section-heading">
    <div class="eyebrow">PLANOS</div>
    <h2>Escolha seu acesso ao Caption IA.</h2>
    <p>Os dois planos permitem uso em até 2 dispositivos.</p>
  </div>

  <div class="pricing-grid">
    <article class="price-card featured">
      <div class="plan-badge">ATUALIZAÇÕES INCLUÍDAS</div>
      <h3>Mensal</h3>
      <div class="price">R$ <?= number_format(((int) $monthly['price_cents']) / 100, 0, ',', '.') ?><small>/mês</small></div>
      <p>Licença por 30 dias. Enquanto estiver ativa, recebe as versões mais recentes.</p>
      <ul>
        <li>✓ Todas as funções atuais</li>
        <li>✓ Atualizações incluídas</li>
        <li>✓ Novos recursos e templates</li>
        <li>✓ 2 dispositivos</li>
      </ul>
      <a class="button button-wide" href="/comprar/mensal">Assinar</a>
    </article>

    <article class="price-card">
      <h3>Vitalício</h3>
      <div class="price">R$ <?= number_format(((int) $lifetime['price_cents']) / 100, 0, ',', '.') ?></div>
      <p>Pagamento único. Você mantém a geração principal disponível no momento da compra.</p>
      <ul>
        <li>✓ Uso permanente da versão adquirida</li>
        <li>✓ Correções da mesma geração</li>
        <li>✓ 2 dispositivos</li>
        <li>— Futuras gerações principais não incluídas</li>
      </ul>
      <a class="button button-ghost button-wide" href="/comprar/vitalicio">Comprar</a>
    </article>
  </div>
</section>

<section class="faq">
  <h2>Perguntas rápidas</h2>
  <details><summary>Preciso entrar toda vez que abrir o Premiere?</summary><p>Não. A API usa access token curto e refresh token persistente, permitindo renovar a sessão sem pedir login a cada abertura.</p></details>
  <details><summary>Como funciona o mensal?</summary><p>A licença mensal vale por 30 dias. Enquanto o gateway automático não estiver ativo, a confirmação do Pix direto pode ser feita pelo Admin.</p></details>
  <details><summary>O vitalício recebe futuras versões principais?</summary><p>Não. Ele mantém a geração principal adquirida, com as regras de atualização associadas àquela geração.</p></details>
</section>
</main>
