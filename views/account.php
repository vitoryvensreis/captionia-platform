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
?>
<main class="dashboard-wrap">
<section class="dashboard-head">
  <div>
    <div class="eyebrow">MINHA CONTA</div>
    <h1>Olá, <?= View::e(explode(' ', trim($user['name']))[0]) ?>.</h1>
    <p><?= View::e($user['email']) ?></p>
  </div>
  <?php if ($license): ?>
    <div class="license-badge active"><i class="bi bi-check-circle"></i> Licença ativa</div>
  <?php else: ?>
    <div class="license-badge">Sem licença ativa</div>
  <?php endif; ?>
</section>

<section class="panel" style="margin-bottom:18px;">
  <h2>Instalar ou atualizar o Caption IA</h2>
  <?php if (!empty($release_allowed) && !empty($release)): ?>
    <p>Versão disponível: <strong><?= View::e($release['version']) ?></strong></p>
    <p class="muted">O instalador inclui o plugin e seus recursos. Faça login no Premiere com a mesma conta para ativar a licença. A transcrição por IA usa a GROQ na nuvem, sem chave local.</p>
    <div class="account-actions">
      <a class="button" href="/minha-conta/baixar/<?= View::e(rawurlencode((string)$release['version'])) ?>">
        <i class="bi bi-download"></i> Baixar Caption IA <?= View::e($release['version']) ?> para Windows
      </a>
    </div>
    <p class="muted">Extraia o ZIP, feche o Premiere e execute INSTALAR_CAPTION_IA.bat. A versão não assinada deverá ser homologada antes da distribuição pública.</p>
  <?php elseif (!empty($release) && !empty($license)): ?>
    <p>O download da versão atual não está incluído na sua licença. Consulte sua assinatura.</p>
  <?php else: ?>
    <p class="muted">O instalador será disponibilizado após a publicação de uma versão autorizada.</p>
  <?php endif; ?>
</section>

<div class="dashboard-grid">
  <section class="panel">
    <h2>Licença</h2>
    <?php if ($license): ?>
      <div class="license-plan"><?= View::e($license['plan_name']) ?></div>
      <dl class="data-list">
        <div><dt>Status</dt><dd>Ativa</dd></div>
        <div><dt>Dispositivos</dt><dd><?= count(array_filter($devices, fn($d) => $d['revoked_at'] === null)) ?> / <?= (int) $license['max_devices'] ?></dd></div>
        <div><dt>Atualizações</dt><dd><?= (int) $license['includes_updates'] === 1 ? 'Incluídas' : 'Conforme a geração adquirida' ?></dd></div>
        <div><dt>Validade</dt><dd><?= $license['expires_at'] ? View::e(date('d/m/Y H:i', strtotime($license['expires_at']))) : 'Sem vencimento' ?></dd></div>
      </dl>
    <?php else: ?>
      <p>Você ainda não tem uma licença ativa.</p>
      <?php if ($plans): ?>
        <div class="account-actions">
          <?php foreach ($plans as $plan): ?>
            <a class="button <?= (int) ($plan['featured'] ?? 0) === 1 ? '' : 'button-ghost' ?>" href="<?= View::e($planUrl($plan)) ?>">
              <?= View::e($plan['name']) ?> · R$ <?= number_format(((int) $plan['price_cents']) / 100, 2, ',', '.') ?>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </section>

  <section class="panel">
    <h2>Dispositivos</h2>
    <?php if (!$devices): ?>
      <p class="muted">Nenhum dispositivo ativado pelo plugin ainda.</p>
    <?php else: ?>
      <div class="device-list">
        <?php foreach ($devices as $device): ?>
          <article class="<?= $device['revoked_at'] ? 'revoked' : '' ?>">
            <div><b><?= View::e($device['device_name'] ?: 'Computador') ?></b><small><?= View::e($device['os'] ?: 'Sistema não informado') ?></small></div>
            <span><?= $device['revoked_at'] ? 'Removido' : 'Ativo' ?></span>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</div>

<section class="panel orders-panel">
  <h2>Pagamentos</h2>
  <?php if (!$orders): ?>
    <p class="muted">Nenhum pagamento gerado.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="responsive-table">
        <thead><tr><th>Plano</th><th>Valor</th><th>Status</th><th>Data</th><th>Ação</th></tr></thead>
        <tbody>
          <?php foreach ($orders as $order): ?>
            <tr>
              <td data-label="Plano"><?= View::e($order['plan_name']) ?></td>
              <td data-label="Valor">R$ <?= number_format(((int) $order['amount_cents']) / 100, 2, ',', '.') ?></td>
              <td data-label="Status"><span class="status status-<?= View::e($order['status']) ?>"><?= View::e($order['status']) ?></span></td>
              <td data-label="Data"><?= View::e(date('d/m/Y H:i', strtotime($order['created_at']))) ?></td>
              <td data-label="Ação"><a href="/pagamento/<?= View::e($order['reference_id']) ?>">Ver</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
</main>
