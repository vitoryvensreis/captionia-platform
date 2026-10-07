<?php
use CaptionIA\Core\View;

$planUrl = static function (array $plan): string {
    $code = (string) ($plan['code'] ?? '');
    $slug = match ($code) {
        'monthly' => 'mensal',
        'lifetime' => 'vitalicio',
        default => $code,
    };
    return '/comprar/' . rawurlencode($slug);
};

$planPrice = static fn(array $plan): string =>
    number_format(((int) ($plan['price_cents'] ?? 0)) / 100, 0, ',', '.');

$byCode = [];
foreach ($plans as $plan) {
    $byCode[$plan['code']] = $plan;
}

$primaryPlan = $byCode['monthly'] ?? ($plans[0] ?? null);
$secondaryPlan = $byCode['lifetime'] ?? ($plans[1] ?? null);
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

    <?php if ($primaryPlan): ?>
      <div class="hero-buy-actions">
        <a class="button sales-primary" href="<?= View::e($planUrl($primaryPlan)) ?>">
          Comprar <?= View::e($primaryPlan['name']) ?> · R$ <?= View::e($planPrice($primaryPlan)) ?><?= ($primaryPlan['billing_interval'] ?? '') === 'month' ? '/mês' : '' ?>
        </a>
        <?php if ($secondaryPlan && (int) $secondaryPlan['id'] !== (int) $primaryPlan['id']): ?>
          <a class="button button-ghost sales-secondary" href="<?= View::e($planUrl($secondaryPlan)) ?>">
            <?= View::e($secondaryPlan['name']) ?> · R$ <?= View::e($planPrice($secondaryPlan)) ?>
          </a>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="hero-proof-row">
      <span><i class="bi bi-check-circle"></i> Até 2 dispositivos nos planos atuais</span>
      <span><i class="bi bi-qr-code"></i> Pagamento por Pix</span>
      <span><i class="bi bi-shield-check"></i> Login persistente</span>
      <span><i class="bi bi-arrow-repeat"></i> Atualizações conforme o plano</span>
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
  <?php if ($primaryPlan): ?>
    <a class="button" href="<?= View::e($planUrl($primaryPlan)) ?>">Quero editar mais rápido</a>
  <?php endif; ?>
</section>

<section class="workspace-section" id="recursos">
  <div class="section-heading left">
    <div class="eyebrow">RECURSOS</div>
    <h2>Um painel para acelerar várias etapas da sua edição.</h2>
    <p>Mais do que uma coleção de botões: cada recurso foi pensado para reduzir atrito no dia a dia de quem produz conteúdo.</p>
  </div>

  <div class="creative-grid sales-feature-grid">
    <article class="creative-card"><span class="tool-icon orange"><i class="bi bi-lightning-charge-fill"></i></span><div><b>AutoEdit</b><p>Organize etapas automáticas e deixe o fluxo de edição avançar com menos intervenção manual.</p></div></article>
    <article class="creative-card"><span class="tool-icon blue"><i class="bi bi-badge-cc-fill"></i></span><div><b>Legendas IA</b><p>Transcreva, segmente e aplique estilos com muito menos trabalho operacional.</p></div></article>
    <article class="creative-card"><span class="tool-icon violet"><i class="bi bi-stars"></i></span><div><b>Karaoke</b><p>Mantenha a frase visível e destaque a palavra falada para um visual mais dinâmico.</p></div></article>
    <article class="creative-card"><span class="tool-icon pink"><i class="bi bi-music-note-beamed"></i></span><div><b>SFX Auto</b><p>Agilize a inserção de efeitos sonoros e dê mais ritmo sem procurar tudo manualmente.</p></div></article>
    <article class="creative-card"><span class="tool-icon cyan"><i class="bi bi-scissors"></i></span><div><b>AutoCut</b><p>Ajude a tratar silêncios, retakes e ritmo para chegar mais rápido à montagem limpa.</p></div></article>
    <article class="creative-card"><span class="tool-icon lime"><i class="bi bi-graph-up-arrow"></i></span><div><b>Dinâmica</b><p>Use zoom, animações, grids e ferramentas de timeline para conteúdo vertical e social.</p></div></article>
  </div>

  <?php if ($primaryPlan): ?>
    <div class="section-cta">
      <div><b>Menos operação. Mais edição.</b><span>Escolha um plano e comece pelo Pix.</span></div>
      <a class="button" href="<?= View::e($planUrl($primaryPlan)) ?>">Comprar agora</a>
    </div>
  <?php endif; ?>
</section>

<section class="sales-section audience-section" id="para-quem">
  <div class="section-heading">
    <div class="eyebrow">PARA QUEM É</div>
    <h2>Feito para quem vive de editar e precisa entregar.</h2>
    <p>Se velocidade, consistência e volume fazem diferença no seu trabalho, o Caption IA entra direto no seu dia a dia.</p>
  </div>

  <div class="audience-grid">
    <span><i class="bi bi-film"></i> Editores de vídeo</span>
    <span><i class="bi bi-camera-reels"></i> Videomakers</span>
    <span><i class="bi bi-phone"></i> Social Media</span>
    <span><i class="bi bi-lightning-charge"></i> Criadores de conteúdo</span>
    <span><i class="bi bi-buildings"></i> Agências</span>
    <span><i class="bi bi-laptop"></i> Freelancers</span>
  </div>
</section>

<?php if ($plans): ?>
<section class="sales-section value-section">
  <div class="value-card">
    <div class="value-copy">
      <div class="eyebrow">CUSTO-BENEFÍCIO</div>
      <h2>Escolha o acesso que faz sentido para o seu ritmo de trabalho.</h2>
      <p>Os planos são gerenciados pelo próprio Caption IA. Valores, dispositivos e regras de atualização podem variar conforme a oferta ativa.</p>
    </div>
    <div class="value-numbers">
      <?php foreach (array_slice($plans, 0, 3) as $plan): ?>
        <div>
          <strong>R$ <?= View::e($planPrice($plan)) ?></strong>
          <span><?= View::e($plan['name']) ?> · até <?= (int) $plan['max_devices'] ?> dispositivo(s)</span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="pricing-section sales-pricing" id="planos">
  <div class="section-heading">
    <div class="eyebrow">ESCOLHA E COMECE</div>
    <h2>O acesso está a poucos cliques.</h2>
    <p>Escolha um plano ativo, crie sua conta, pague por Pix e acompanhe sua licença pela área do cliente.</p>
  </div>

  <?php if (!$plans): ?>
    <div class="empty-state">Nenhum plano está disponível neste momento.</div>
  <?php else: ?>
    <div class="pricing-grid dynamic-pricing-grid">
      <?php foreach ($plans as $plan): ?>
        <?php
          $isFeatured = (int) ($plan['featured'] ?? 0) === 1;
          $intervalLabel = match ($plan['billing_interval'] ?? '') {
              'month' => '/mês',
              'year' => '/ano',
              default => '',
          };
          $description = trim((string) ($plan['description'] ?? ''));
          if ($description === '') {
              $description = ($plan['type'] ?? '') === 'lifetime'
                  ? 'Pagamento único para uso permanente da geração principal adquirida.'
                  : 'Acesso por assinatura ao Caption IA.';
          }
        ?>
        <article class="price-card <?= $isFeatured ? 'featured' : '' ?>">
          <?php if ($isFeatured): ?><div class="plan-badge">DESTAQUE</div><?php endif; ?>
          <h3><?= View::e($plan['name']) ?></h3>
          <div class="price">R$ <?= View::e($planPrice($plan)) ?><small><?= View::e($intervalLabel) ?></small></div>
          <p><?= View::e($description) ?></p>
          <ul>
            <li><i class="bi bi-check-lg"></i> Até <?= (int) $plan['max_devices'] ?> dispositivo(s)</li>
            <li><i class="bi bi-check-lg"></i> <?= (int) $plan['includes_updates'] === 1 ? 'Atualizações incluídas' : 'Atualizações conforme a geração adquirida' ?></li>
            <li><i class="bi bi-check-lg"></i> Acesso ao Caption IA conforme a licença</li>
            <li><i class="bi bi-check-lg"></i> Gestão pela sua conta</li>
          </ul>
          <a class="button <?= $isFeatured ? '' : 'button-ghost' ?> button-wide plan-cta" href="<?= View::e($planUrl($plan)) ?>">
            Comprar por R$ <?= View::e($planPrice($plan)) ?>
          </a>
          <small class="plan-microcopy">
            <?= ($plan['type'] ?? '') === 'lifetime'
              ? 'Pagamento único para a geração adquirida.'
              : (($plan['billing_interval'] ?? '') === 'year' ? 'Licença anual.' : 'Licença por 30 dias.') ?>
          </small>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="buy-confidence">
    <span><i class="bi bi-check-circle"></i> Compra rápida</span>
    <span><i class="bi bi-qr-code"></i> Pagamento por Pix</span>
    <span><i class="bi bi-person-check"></i> Licença vinculada à sua conta</span>
    <span><i class="bi bi-display"></i> Controle de dispositivos</span>
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
  <details><summary>Posso usar em mais de um computador?</summary><p>O limite depende do plano escolhido e é mostrado antes da compra.</p></details>
  <details><summary>O plano recebe atualizações?</summary><p>Depende da configuração do plano. Essa informação aparece no card de compra.</p></details>
  <details><summary>Quando minha licença fica disponível?</summary><p>No pagamento automático, após a confirmação do gateway. No Pix direto, após a confirmação do recebimento pelo administrador.</p></details>
</section>

<?php if ($primaryPlan): ?>
<section class="final-cta">
  <div>
    <div class="eyebrow">COMECE AGORA</div>
    <h2>Seu próximo vídeo não precisa levar o mesmo tempo que o anterior.</h2>
    <p>Coloque o Caption IA no seu fluxo e deixe as tarefas repetitivas ocuparem menos espaço na sua edição.</p>
  </div>
  <div class="final-cta-actions">
    <a class="button" href="<?= View::e($planUrl($primaryPlan)) ?>">Comprar <?= View::e($primaryPlan['name']) ?></a>
    <?php if ($secondaryPlan && (int) $secondaryPlan['id'] !== (int) $primaryPlan['id']): ?>
      <a class="button button-ghost" href="<?= View::e($planUrl($secondaryPlan)) ?>">Ver <?= View::e($secondaryPlan['name']) ?></a>
    <?php endif; ?>
    <small>Compra por Pix · licença vinculada à sua conta</small>
  </div>
</section>
<?php endif; ?>
</main>
