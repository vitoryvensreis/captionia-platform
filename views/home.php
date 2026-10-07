<?php
use CaptionIA\Core\View;

$byCode = [];
foreach ($plans as $plan) {
    $byCode[$plan['code']] = $plan;
}
$monthly = $byCode['monthly'] ?? ['price_cents' => 3000];
$lifetime = $byCode['lifetime'] ?? ['price_cents' => 7000];

$monthlyPrice = number_format(((int) $monthly['price_cents']) / 100, 0, ',', '.');
$lifetimePrice = number_format(((int) $lifetime['price_cents']) / 100, 0, ',', '.');
?>
<main class="home-main">
<section class="adobe-inspired-hero sales-hero">
  <div class="hero-backdrop"></div>
  <div class="hero-center">
    <div class="hero-kicker">CAPTION IA · PARA QUEM EDITA NO PREMIERE</div>
    <h1>Menos tarefa repetitiva.<br><span>Mais vídeo entregue.</span></h1>
    <p class="hero-sales-copy">
      Automatize partes cansativas da edição com Legendas IA, Karaoke, AutoEdit, AutoCut, SFX e ferramentas de timeline — tudo dentro do seu fluxo no Premiere.
    </p>

    <div class="hero-buy-actions">
      <a class="button sales-primary" href="/comprar/mensal">Começar por R$ <?= $monthlyPrice ?>/mês</a>
      <a class="button button-ghost sales-secondary" href="/comprar/vitalicio">Vitalício por R$ <?= $lifetimePrice ?></a>
    </div>

    <div class="hero-proof-row">
      <span>✓ Até 2 dispositivos</span>
      <span>✓ Pagamento por Pix</span>
      <span>✓ Login persistente</span>
      <span>✓ Mensal com atualizações</span>
    </div>

    <div class="hero-tabs">
      <a href="#problema" class="active">Por que usar</a>
      <a href="#recursos">Recursos</a>
      <a href="#para-quem">Para quem é</a>
      <a href="#planos">Planos</a>
      <a href="#faq">Dúvidas</a>
    </div>
  </div>
</section>

<section class="sales-section pain-section" id="problema">
  <div class="section-heading">
    <div class="eyebrow">TEMPO É ENTREGA</div>
    <h2>Você não deveria perder horas em tarefas que se repetem todo dia.</h2>
    <p>Quando legendas, cortes, ajustes de ritmo e pequenos processos consomem seu tempo, sobra menos energia para a parte criativa e menos espaço para novas entregas.</p>
  </div>

  <div class="pain-grid">
    <article class="pain-card"><span>01</span><h3>Menos trabalho manual</h3><p>Reduza etapas repetitivas e deixe o processo mais previsível.</p></article>
    <article class="pain-card"><span>02</span><h3>Mais ritmo de produção</h3><p>Ganhe agilidade para avançar a timeline sem ficar preso nas mesmas tarefas.</p></article>
    <article class="pain-card"><span>03</span><h3>Mais foco criativo</h3><p>Use seu tempo para decisão, acabamento e narrativa — não para operação repetitiva.</p></article>
  </div>
</section>

<section class="sales-band">
  <div>
    <div class="eyebrow">FEITO PARA PRODUTIVIDADE</div>
    <h2>O Caption IA entra onde o seu fluxo costuma travar.</h2>
    <p>Você continua no controle da edição. O plugin acelera o que pode ser automatizado para você chegar mais rápido ao resultado final.</p>
  </div>
  <a class="button" href="/comprar/mensal">Quero editar mais rápido</a>
</section>

<section class="workspace-section" id="recursos">
  <div class="section-heading left">
    <div class="eyebrow">RECURSOS</div>
    <h2>Um painel para acelerar várias etapas da sua edição.</h2>
    <p>Mais do que uma coleção de botões: cada recurso foi pensado para reduzir atrito no dia a dia de quem produz conteúdo.</p>
  </div>

  <div class="creative-grid sales-feature-grid">
    <article class="creative-card"><span class="tool-icon orange">⚡</span><div><b>AutoEdit</b><p>Organize etapas automáticas e deixe o fluxo de edição avançar com menos intervenção manual.</p></div></article>
    <article class="creative-card"><span class="tool-icon blue">CC</span><div><b>Legendas IA</b><p>Transcreva, segmente e aplique estilos com muito menos trabalho operacional.</p></div></article>
    <article class="creative-card"><span class="tool-icon violet">✦</span><div><b>Karaoke</b><p>Mantenha a frase visível e destaque a palavra falada para um visual mais dinâmico.</p></div></article>
    <article class="creative-card"><span class="tool-icon pink">♪</span><div><b>SFX Auto</b><p>Agilize a inserção de efeitos sonoros e dê mais ritmo sem procurar tudo manualmente.</p></div></article>
    <article class="creative-card"><span class="tool-icon cyan">✂</span><div><b>AutoCut</b><p>Ajude a tratar silêncios, retakes e ritmo para chegar mais rápido à montagem limpa.</p></div></article>
    <article class="creative-card"><span class="tool-icon lime">↗</span><div><b>Dinâmica</b><p>Use zoom, animações, grids e ferramentas de timeline para conteúdo vertical e social.</p></div></article>
  </div>

  <div class="section-cta">
    <div><b>Menos operação. Mais edição.</b><span>Comece pelo plano mensal e mantenha acesso às atualizações enquanto estiver ativo.</span></div>
    <a class="button" href="/comprar/mensal">Assinar agora</a>
  </div>
</section>

<section class="sales-section audience-section" id="para-quem">
  <div class="section-heading">
    <div class="eyebrow">PARA QUEM É</div>
    <h2>Feito para quem vive de editar e precisa entregar.</h2>
    <p>Se velocidade, consistência e volume fazem diferença no seu trabalho, o Caption IA entra direto no seu dia a dia.</p>
  </div>

  <div class="audience-grid">
    <span>🎬 Editores de vídeo</span>
    <span>📹 Videomakers</span>
    <span>📱 Social Media</span>
    <span>⚡ Criadores de conteúdo</span>
    <span>🏢 Agências</span>
    <span>💻 Freelancers</span>
  </div>
</section>

<section class="sales-section value-section">
  <div class="value-card">
    <div class="value-copy">
      <div class="eyebrow">CUSTO-BENEFÍCIO</div>
      <h2>Se uma tarefa repetitiva deixa de ocupar seu tempo, o plugin já começa a fazer sentido.</h2>
      <p>O Caption IA foi pensado para ser acessível e entrar no fluxo de quem edita com frequência — sem transformar produtividade em um investimento pesado.</p>
    </div>
    <div class="value-numbers">
      <div><strong>R$ <?= $monthlyPrice ?></strong><span>por 30 dias + atualizações</span></div>
      <div><strong>R$ <?= $lifetimePrice ?></strong><span>uma vez para a geração adquirida</span></div>
      <div><strong>2</strong><span>dispositivos por licença</span></div>
    </div>
  </div>
</section>

<section class="pricing-section sales-pricing" id="planos">
  <div class="section-heading">
    <div class="eyebrow">ESCOLHA E COMECE</div>
    <h2>O acesso está a poucos cliques.</h2>
    <p>Escolha o plano, crie sua conta, pague por Pix e acompanhe sua licença pela área do cliente.</p>
  </div>

  <div class="pricing-grid">
    <article class="price-card featured">
      <div class="plan-badge">RECOMENDADO PARA ATUALIZAÇÕES</div>
      <h3>Mensal</h3>
      <div class="price">R$ <?= $monthlyPrice ?><small>/mês</small></div>
      <p>Para quem quer usar o Caption IA continuamente e receber novas versões enquanto a assinatura estiver ativa.</p>
      <ul>
        <li>✓ Acesso às funções atuais</li>
        <li>✓ Atualizações incluídas enquanto ativo</li>
        <li>✓ Novos recursos e melhorias</li>
        <li>✓ Até 2 dispositivos</li>
      </ul>
      <a class="button button-wide plan-cta" href="/comprar/mensal">Quero assinar por R$ <?= $monthlyPrice ?></a>
      <small class="plan-microcopy">Licença válida por 30 dias.</small>
    </article>

    <article class="price-card">
      <div class="plan-badge plan-badge-neutral">PAGAMENTO ÚNICO</div>
      <h3>Vitalício</h3>
      <div class="price">R$ <?= $lifetimePrice ?></div>
      <p>Para quem prefere pagar uma vez e manter para sempre a geração principal disponível no momento da compra.</p>
      <ul>
        <li>✓ Uso permanente da versão adquirida</li>
        <li>✓ Correções da mesma geração</li>
        <li>✓ Até 2 dispositivos</li>
        <li>— Futuras gerações principais não incluídas</li>
      </ul>
      <a class="button button-ghost button-wide plan-cta" href="/comprar/vitalicio">Comprar vitalício por R$ <?= $lifetimePrice ?></a>
      <small class="plan-microcopy">Sem mensalidade para a geração adquirida.</small>
    </article>
  </div>

  <div class="buy-confidence">
    <span>✓ Compra rápida</span>
    <span>✓ Pagamento por Pix</span>
    <span>✓ Licença vinculada à sua conta</span>
    <span>✓ Controle de dispositivos</span>
  </div>
</section>

<section class="sales-section objection-section">
  <div class="section-heading">
    <div class="eyebrow">POR QUE VALE A PENA</div>
    <h2>Você continua editando do seu jeito — só elimina parte do trabalho que atrasa.</h2>
  </div>

  <div class="objection-grid">
    <article><b>Economize tempo operacional</b><p>Automatize etapas repetitivas para dedicar mais atenção ao acabamento da edição.</p></article>
    <article><b>Produza com mais consistência</b><p>Use o mesmo fluxo, estilos e ferramentas sem recomeçar processos a cada projeto.</p></article>
    <article><b>Aumente sua capacidade de entrega</b><p>Quando o processo anda mais rápido, você ganha mais espaço para atender novas demandas.</p></article>
    <article><b>Comece sem complicação</b><p>Escolha o plano, crie sua conta e acompanhe sua licença e dispositivos pelo próprio sistema.</p></article>
  </div>
</section>

<section class="faq sales-faq" id="faq">
  <div class="section-heading left">
    <div class="eyebrow">SEM PEGADINHA</div>
    <h2>Perguntas antes de comprar.</h2>
  </div>

  <details><summary>Preciso entrar toda vez que abrir o Premiere?</summary><p>Não. O sistema foi preparado para manter a sessão e renovar o acesso sem pedir login a cada abertura, enquanto a sessão continuar válida.</p></details>
  <details><summary>Posso usar em mais de um computador?</summary><p>Sim. Cada licença permite uso em até 2 dispositivos.</p></details>
  <details><summary>O mensal recebe atualizações?</summary><p>Sim. Enquanto a licença mensal estiver ativa, ela inclui acesso às atualizações disponibilizadas para o plano.</p></details>
  <details><summary>O vitalício recebe futuras versões principais?</summary><p>Não. O vitalício mantém a geração principal adquirida e as correções compatíveis com essa geração.</p></details>
  <details><summary>Como funciona o pagamento?</summary><p>O sistema aceita Pix. O método exibido na compra depende da configuração ativa no painel administrativo.</p></details>
  <details><summary>Quando minha licença fica disponível?</summary><p>No pagamento automático, após a confirmação do gateway. No Pix direto, após a confirmação do recebimento pelo administrador.</p></details>
</section>

<section class="final-cta">
  <div>
    <div class="eyebrow">COMECE AGORA</div>
    <h2>Seu próximo vídeo não precisa levar o mesmo tempo que o anterior.</h2>
    <p>Coloque o Caption IA no seu fluxo e deixe as tarefas repetitivas ocuparem menos espaço na sua edição.</p>
  </div>
  <div class="final-cta-actions">
    <a class="button" href="/comprar/mensal">Assinar por R$ <?= $monthlyPrice ?>/mês</a>
    <a class="button button-ghost" href="/comprar/vitalicio">Vitalício por R$ <?= $lifetimePrice ?></a>
    <small>Compra por Pix · até 2 dispositivos</small>
  </div>
</section>
</main>
