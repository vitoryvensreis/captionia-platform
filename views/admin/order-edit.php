<?php
use CaptionIA\Core\View;
$purchase = $orderEdit['order'];
$isPaid = $purchase['status'] === 'paid' && (int)$purchase['license_applied'] === 1;
$eligible = array_values(array_filter(
    $orderEdit['licenses'],
    static fn($l) => $l['status'] === 'active'
        && ($l['expires_at'] === null || strtotime((string)$l['expires_at']) > time())
));
$money = static fn(int $c): string => 'R$ ' . number_format($c / 100,2,',','.');
?>
<main class="dashboard-wrap admin-wrap order-editor">
<section class="dashboard-head">
  <div><div class="eyebrow">ADMIN · PAGAMENTOS</div>
    <h1>Ajustar acesso do cliente</h1>
    <p>Corrija o plano ou a validade de uma licença sem alterar o valor realmente pago.</p>
  </div>
  <a class="mini-button" href="/admin">Voltar aos pagamentos</a>
</section>
<section class="panel">
  <h2>Registro original da compra — somente leitura</h2>
  <div class="order-purchase-grid">
    <div><small>Cliente</small><strong><?= View::e($purchase['buyer_name']) ?></strong>
      <span><?= View::e($purchase['buyer_email']) ?></span></div>
    <div><small>Pedido</small><strong><?= View::e($purchase['reference_id']) ?></strong></div>
    <div><small>Plano comprado</small><strong><?= View::e($purchase['purchased_plan_name']) ?></strong></div>
    <div><small>Valor registrado</small><strong><?= $money((int)$purchase['amount_cents']) ?></strong></div>
    <div><small>Status financeiro</small><strong><?= View::e($purchase['status']) ?></strong></div>
    <div><small>Pagamento confirmado</small><strong><?= $purchase['paid_at'] ? View::e(date('d/m/Y H:i',strtotime($purchase['paid_at']))) : '—' ?></strong></div>
  </div>
  <p class="muted">O ajuste não altera o valor pago, o plano original do recibo, o gateway, a comissão de afiliados ou uma assinatura externa. O histórico da alteração permanece registrado.</p>
</section>

<?php if (!$isPaid): ?>
<section class="panel"><h2>Pedido ainda não elegível</h2>
<p>Esta compra não possui pagamento confirmado e licença aplicada. Para mudar um pedido pendente, crie um novo checkout com o plano desejado; não altere o valor de uma cobrança Pix ou PagBank já emitida.</p></section>
<?php elseif (!$eligible): ?>
<section class="panel"><h2>Não há licença ativa para ajustar</h2>
<p>O comprador não possui uma licença ativa e válida. Verifique a área Clientes e licenças antes de fazer alterações.</p></section>
<?php else: ?>
<section class="panel">
  <div class="panel-title-row"><div><h2>Alterar plano e validade da licença</h2>
    <p>Selecione qual licença do comprador será ajustada. A alteração vale imediatamente no plugin.</p></div></div>
  <form method="post" action="/admin/pagamentos/<?= View::e($purchase['reference_id']) ?>/ajustar-acesso"
        class="settings-form order-adjust-form"
        onsubmit="return confirm('Confirma a alteração dos direitos de acesso? O registro financeiro original será preservado.');">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
    <label>Licença a modificar
      <select name="license_id" data-order-license required>
        <option value="">Selecione a licença do cliente</option>
        <?php foreach ($eligible as $l): ?>
          <option value="<?= (int)$l['id'] ?>"
                  data-plan="<?= (int)$l['plan_id'] ?>"
                  data-expiry="<?= View::e($l['expires_at']??'') ?>">
            #<?= (int)$l['id'] ?> · <?= View::e($l['plan_name']) ?> · <?= $l['expires_at'] ? View::e(date('d/m/Y',strtotime($l['expires_at']))) : 'Sem vencimento' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <input type="hidden" name="expected_plan_id" value="" data-expected-plan>
    <input type="hidden" name="expected_expiry" value="" data-expected-expiry>
    <div class="form-grid">
      <label>Novo plano de acesso
        <select name="new_plan_id" data-order-plan required>
          <option value="">Selecione o novo plano</option>
          <?php foreach ($orderEdit['plans'] as $plan): ?>
          <option value="<?= (int)$plan['id'] ?>" data-plan-type="<?= View::e($plan['type']) ?>">
            <?= View::e($plan['name']) ?> <?= (int)$plan['active']===0?'(inativo)':'' ?>
          </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Vencimento para assinatura
        <input type="date" name="expiry_date" data-order-expiry min="<?= date('Y-m-d') ?>"
          max="<?= date('Y-m-d',strtotime('+5 years')) ?>">
        <small>Para plano vitalício, o vencimento é removido automaticamente.</small>
      </label>
    </div>
    <label>Justificativa obrigatória (mínimo 10 caracteres)
      <textarea name="reason" rows="3" maxlength="500" minlength="10" required
        placeholder="Ex.: Cliente comprou mensal, mas foi concedido upgrade manual após atendimento."></textarea>
    </label>
    <button type="submit" class="button">Salvar ajuste de acesso</button>
  </form>
</section>
<?php endif; ?>
<section class="panel">
  <h2>Histórico de alterações dessa compra</h2>
  <div class="table-wrap"><table class="responsive-table">
    <thead><tr><th>Data</th><th>Admin</th><th>Licença</th><th>Antes</th><th>Depois</th><th>Motivo</th></tr></thead>
    <tbody>
      <?php foreach ($orderEdit['history'] as $change): ?>
      <tr>
        <td data-label="Data"><?= View::e(date('d/m/Y H:i',strtotime($change['created_at']))) ?></td>
        <td data-label="Admin"><?= View::e($change['admin_name']) ?></td>
        <td data-label="Licença">#<?= (int)$change['license_id'] ?></td>
        <td data-label="Antes"><?= View::e($change['old_plan']) ?><small><?= $change['old_expires_at']?View::e(date('d/m/Y',strtotime($change['old_expires_at']))):'Sem vencimento' ?></small></td>
        <td data-label="Depois"><?= View::e($change['new_plan']) ?><small><?= $change['new_expires_at']?View::e(date('d/m/Y',strtotime($change['new_expires_at']))):'Sem vencimento' ?></small></td>
        <td data-label="Motivo"><?= View::e($change['reason']) ?></td>
      </tr><?php endforeach; ?>
      <?php if (!$orderEdit['history']): ?><tr><td colspan="6">Nenhum ajuste de acesso registrado para esta compra.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</section>
</main>
<script>
(() => {
  const license=document.querySelector('[data-order-license]');
  const plan=document.querySelector('[data-order-plan]');
  const expiry=document.querySelector('[data-order-expiry]');
  const originalPlan=document.querySelector('[data-expected-plan]');
  const originalExpiry=document.querySelector('[data-expected-expiry]');
  if(!license||!plan||!expiry)return;
  const syncPlan=()=>{
    const option=plan.selectedOptions[0];
    const recurring=option?.dataset.planType==='subscription';
    expiry.disabled=!recurring;
    expiry.required=recurring;
    if(!recurring)expiry.value='';
  };
  license.addEventListener('change',()=>{
    const selected=license.selectedOptions[0],before=selected?.dataset.plan||'';
    const previousExpiry=selected?.dataset.expiry||'';
    originalPlan.value=before;
    originalExpiry.value=previousExpiry;
    plan.value=before;
    const existing=previousExpiry.slice(0,10);
    expiry.value=existing&&existing>=expiry.min?existing:'';
    syncPlan();
  });
  plan.addEventListener('change',syncPlan);
  if(license.options.length===2){license.selectedIndex=1;license.dispatchEvent(new Event('change'));}
})();
</script>
