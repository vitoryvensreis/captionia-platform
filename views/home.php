<?php
use CaptionIA\Core\View;

$byCode = [];
foreach ($plans as $plan) {
    $byCode[$plan['code']] = $plan;
}
$monthly = $byCode['monthly'] ?? ['price_cents' => 3000];
$lifetime = $byCode['lifetime'] ?? ['price_cents' => 7000];
?>
<main>
<section class="hero">
  <div class="hero-copy">
    <div class="eyebrow">CAPTION IA · PREMIERE PRO</div>
    <h1>Edite mais.<br><span>Espere menos.</span></h1>
    <p>Legendas, cortes, retakes, efeitos, SFX e automações dentro do Premiere. Escolha o plano e comece pelo PIX.</p>
    <div class="hero-actions">
      <a class="button" href="#planos">Ver planos</a>
      <a class="button button-ghost" href="/entrar">Já sou cliente</a>
    </div>
    <div class="trust-row">
      <span>✓ 2 dispositivos</span>
      <span>✓ Login persistente</span>
      <span>✓ Pagamento por PIX</span>
    </div>
  </div>
  <div class="hero-panel">
    <div class="mock-top"><span></span><span></span><span></span><b>Caption IA</b></div>
    <div class="mock-tabs"><em>AutoEdit</em><em>AutoCut</em><em>Dinâmica</em><em>Karaoke</em></div>
    <div class="mock-card">
      <small>AUTOMAÇÃO</small>
      <strong>8 de 8 etapas ligadas</strong>
      <div class="mock-lines"><i></i><i></i><i></i><i></i></div>
      <button>▶ Rodar AutoEdit</button>
    </div>
  </div>
</section>

<section class="features">
  <article><b>AutoEdit</b><p>Organiza etapas automáticas e acelera a montagem.</p></article>
  <article><b>Legendas + Karaoke</b><p>Transcrição e destaque palavra por palavra.</p></article>
  <article><b>AutoCut e Retakes</b><p>Identifica pausas e tentativas repetidas.</p></article>
  <article><b>Templates e SFX</b><p>Aplica modelos, efeitos sonoros e estilos.</p></article>
</section>

<section class="pricing-section" id="planos">
  <div class="section-heading">
    <div class="eyebrow">PLANOS</div>
    <h2>Escolha como quer usar.</h2>
    <p>Os dois planos dão acesso ao Caption IA em até 2 dispositivos.</p>
  </div>

  <div class="pricing-grid">
    <article class="price-card featured">
      <div class="plan-badge">MAIS ATUALIZADO</div>
      <h3>Mensal</h3>
      <div class="price">R$ <?= number_format(((int) $monthly['price_cents']) / 100, 0, ',', '.') ?><small>/mês</small></div>
      <p>Licença por 30 dias, renovada por um novo PIX. Enquanto ativa, recebe as versões mais recentes.</p>
      <ul>
        <li>✓ Todas as funções atuais</li>
        <li>✓ Atualizações incluídas</li>
        <li>✓ Novos recursos e templates</li>
        <li>✓ 2 dispositivos</li>
      </ul>
      <a class="button button-wide" href="/comprar/mensal">Assinar por PIX</a>
    </article>

    <article class="price-card">
      <h3>Vitalício</h3>
      <div class="price">R$ <?= number_format(((int) $lifetime['price_cents']) / 100, 0, ',', '.') ?></div>
      <p>Pagamento único. Você mantém para sempre a geração principal disponível no dia da compra.</p>
      <ul>
        <li>✓ Uso permanente da versão adquirida</li>
        <li>✓ Correções da mesma geração</li>
        <li>✓ 2 dispositivos</li>
        <li>— Futuras gerações não incluídas</li>
      </ul>
      <a class="button button-ghost button-wide" href="/comprar/vitalicio">Comprar por PIX</a>
    </article>
  </div>
</section>

<section class="faq">
  <h2>Perguntas rápidas</h2>
  <details><summary>Preciso entrar toda vez que abrir o Premiere?</summary><p>Não. O sistema foi preparado para sessões persistentes. A integração do plugin usa tokens renováveis e o dispositivo permanece autenticado até você sair ou a sessão ser revogada.</p></details>
  <details><summary>O plano mensal cobra automaticamente?</summary><p>Não. Como a venda é exclusivamente por PIX, cada renovação mensal é feita por um novo PIX. O pagamento aprovado acrescenta 30 dias à licença.</p></details>
  <details><summary>O vitalício recebe atualizações?</summary><p>Ele permanece na geração principal adquirida. Correções dessa geração podem ser disponibilizadas, mas futuras gerações principais não fazem parte da licença.</p></details>
</section>
</main>
