<?php use CaptionIA\Core\View;
$money=static fn(int $n):string=>'R$ '.number_format($n/100,2,',','.');
$s=$report['orders'];$events=$report['events'];
$visitors=(int)$report['visitors'];$orders=(int)$s['orders'];$paid=(int)$s['paid_orders'];
$conversion=$orders>0?round($paid*100/$orders,1):0;
$steps=[
 ['name'=>'Visitantes com consentimento','total'=>$visitors],
 ['name'=>'Viram os planos','total'=>(int)($events['pricing_view']['visitors']??0)],
 ['name'=>'Clicaram para comprar','total'=>(int)($events['plan_click']['visitors']??0)],
 ['name'=>'Abriram o checkout','total'=>(int)($events['checkout_view']['visitors']??0)],
 ['name'=>'Geraram um pagamento','total'=>$orders],
 ['name'=>'Compras pagas','total'=>$paid],
];
$depth=[25,50,75,100];
?>
<main class="dashboard-wrap admin-wrap finance-wrap">
<section class="dashboard-head"><div><div class="eyebrow">ADMIN · FINANCEIRO</div>
<h1>Receita e comportamento de compra</h1><p>Recebimentos confirmados, pedidos e etapas em que as visitas param.</p></div>
<div class="action-row"><a class="mini-button" href="/admin/afiliados">Afiliados e saques</a><a class="mini-button" href="/admin">Painel geral</a></div></section>
<div class="finance-toolbar"><div class="action-row">
<a class="mini-button" href="/admin/financeiro?dias=7">7 dias</a>
<a class="mini-button" href="/admin/financeiro?dias=30">30 dias</a>
<a class="mini-button" href="/admin/financeiro?dias=90">90 dias</a>
<a class="mini-button" href="/admin/financeiro?dias=365">1 ano</a></div>
<a class="mini-button" href="/admin/financeiro/exportar">Exportar vendas (CSV)</a></div>
<p class="muted">Indicadores de <?= (int)$report['days'] ?> dias. Visitas e rolagem incluem apenas visitantes que consentiram com métricas; receitas e pedidos vêm diretamente do banco financeiro.</p>
<div class="stats-grid finance-stats">
<article><small>Receita confirmada</small><b><?= $money((int)$s['paid_revenue_cents']) ?></b></article>
<article><small>Vendas pagas</small><b><?= $paid ?></b></article>
<article><small>Pedidos criados</small><b><?= $orders ?></b></article>
<article><small>Conversão pedido → venda</small><b><?= number_format($conversion,1,',','.') ?>%</b></article>
<article><small>Valores aguardando</small><b><?= $money((int)$s['pending_cents']) ?></b></article>
<article><small>Comissões geradas</small><b><?= $money((int)$report['commissions']['commissions_cents']) ?></b></article>
<article><small>Saques solicitados</small><b><?= $money((int)$report['withdrawals']['requested_cents']) ?></b></article>
<article><small>Saques pagos</small><b><?= $money((int)$report['withdrawals']['paid_cents']) ?></b></article>
</div>
<section class="panel"><div class="panel-title-row"><div><h2>Funil de compra</h2>
<p>As etapas de visita são métricas consentidas. Pedidos e vendas são dados financeiros e têm universo diferente.</p></div>
<span class="status"><?= $visitors ?> visitas identificadas</span></div>
<div class="finance-funnel">
<?php $max=max(1,$visitors,$orders); foreach($steps as $step): $pct=min(100,round($step['total']*100/$max,1)); ?>
<div class="funnel-step"><span><?= View::e($step['name']) ?></span>
<div class="funnel-track"><div style="width:<?= $pct ?>%"></div></div>
<strong><?= (int)$step['total'] ?></strong></div><?php endforeach; ?></div>
</section>
<div class="finance-columns">
<section class="panel"><h2>Até onde a página foi vista?</h2><p class="muted">Profundidade máxima da rolagem na página inicial, entre visitas consentidas.</p>
<?php foreach($depth as $d): $total=(int)($events['scroll_'.$d]['visitors']??0); ?>
<div class="funnel-step"><span><?= $d ?>% da página</span><div class="funnel-track">
<div style="width:<?= $visitors>0?min(100,round($total*100/$visitors,1)):0 ?>%"></div></div><b><?= $total ?></b></div>
<?php endforeach; ?></section>
<section class="panel"><h2>Eventos do site</h2>
<div class="finance-mini-metrics">
<div><span>Visualizações consentidas</span><strong><?= (int)($events['page_view']['count']??0) ?></strong></div>
<div><span>Inícios de cadastro</span><strong><?= (int)($events['signup_view']['visitors']??0) ?></strong></div>
<div><span>Cliques em planos</span><strong><?= (int)($events['plan_click']['count']??0) ?></strong></div>
<div><span>Pagamentos abertos</span><strong><?= (int)($events['payment_view']['visitors']??0) ?></strong></div>
<div><span>Pedidos não finalizados</span><strong><?= (int)$s['lost_orders'] ?></strong></div>
</div></section></div>
<section class="panel"><h2>Receita por plano</h2><div class="table-wrap"><table class="responsive-table"><thead>
<tr><th>Plano</th><th>Pedidos</th><th>Pagos</th><th>Receita</th></tr></thead><tbody>
<?php foreach($report['plans'] as $plan): ?><tr><td><?= View::e($plan['plan_name']) ?></td>
<td><?= (int)$plan['orders'] ?></td><td><?= (int)$plan['paid_orders'] ?></td>
<td><?= $money((int)$plan['revenue']) ?></td></tr><?php endforeach; ?>
<?php if(!$report['plans']): ?><tr><td colspan="4">Ainda não há vendas no intervalo.</td></tr><?php endif; ?>
</tbody></table></div></section>
<section class="panel"><h2>Vendas por dia</h2><div class="table-wrap"><table class="responsive-table">
<thead><tr><th>Dia</th><th>Pedidos</th><th>Recebido</th></tr></thead><tbody>
<?php foreach($report['daily'] as $row): ?><tr><td><?= View::e(date('d/m/Y',strtotime($row['day']))) ?></td>
<td><?= (int)$row['orders'] ?></td><td><?= $money((int)$row['revenue']) ?></td></tr><?php endforeach; ?>
<?php if(!$report['daily']): ?><tr><td colspan="3">Sem vendas no período.</td></tr><?php endif; ?>
</tbody></table></div></section>
<section class="panel"><h2>Últimos pedidos</h2><div class="table-wrap"><table class="responsive-table">
<thead><tr><th>Cliente</th><th>Plano</th><th>Valor</th><th>Status</th><th>Criado</th><th>Pago</th></tr></thead><tbody>
<?php foreach($report['latest'] as $o): ?><tr>
<td><b><?= View::e($o['buyer_name']) ?></b><small><?= View::e($o['buyer_email']) ?></small></td>
<td><?= View::e($o['plan_name']) ?></td><td><?= $money((int)$o['amount_cents']) ?></td>
<td><?= View::e($o['status']) ?></td><td><?= View::e(date('d/m/Y H:i',strtotime($o['created_at']))) ?></td>
<td><?= $o['paid_at']?View::e(date('d/m/Y H:i',strtotime($o['paid_at']))):'—' ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
</main>