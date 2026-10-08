<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use CaptionIA\Core\Database;
use CaptionIA\Core\Env;
use CaptionIA\Core\View;
use CaptionIA\Services\LicenseService;
use CaptionIA\Services\OrderAdjustmentService;

Env::load(dirname(__DIR__));
date_default_timezone_set('America/Fortaleza');
$pdo=Database::connection();
$check=static function(bool $condition,string $message):void {
    if(!$condition)throw new RuntimeException('Admin paid-order adjustment: '.$message);
};
$unique=bin2hex(random_bytes(5));
$insert=$pdo->prepare(
    "INSERT INTO users (name,email,cpf,phone,password_hash,role,status) VALUES (?,?,?,'0000000000','test-hash',?,'active')"
);
$insert->execute(['Order Admin','admin-'.$unique.'@example.test','90000000001','admin']);
$admin=(int)$pdo->lastInsertId();
$insert->execute(['Order Buyer','buyer-'.$unique.'@example.test','90000000002','customer']);
$buyer=(int)$pdo->lastInsertId();
$insert->execute(['Other Buyer','other-'.$unique.'@example.test','90000000003','customer']);
$other=(int)$pdo->lastInsertId();

$monthly=(int)$pdo->query("SELECT id FROM plans WHERE code='monthly'")->fetchColumn();
$lifetime=(int)$pdo->query("SELECT id FROM plans WHERE code='lifetime'")->fetchColumn();
$check($monthly>0&&$lifetime>0,'required seed plans available');
$licenses=new LicenseService();
$licenses->grantManual($buyer,'monthly');
$licenses->grantManual($other,'monthly');
$fetchLicense=$pdo->prepare("SELECT id,plan_id,expires_at FROM licenses WHERE user_id=? ORDER BY id DESC LIMIT 1");
$fetchLicense->execute([$buyer]);
$license=$fetchLicense->fetch();
$licenseId=(int)$license['id'];
$fetchLicense->execute([$other]);
$otherLicenseId=(int)$fetchLicense->fetchColumn();

$reference='CIA-'.strtoupper(bin2hex(random_bytes(8)));
$insertOrder=$pdo->prepare(
    "INSERT INTO checkout_orders(reference_id,user_id,plan_id,status,amount_cents,
      provider_mode,license_applied,expires_at,paid_at)
     VALUES (?,?,?,'paid',3000,'bacen_static_pix',1,DATE_ADD(NOW(),INTERVAL 1 DAY),NOW())"
);
$insertOrder->execute([$reference,$buyer,$monthly]);
$orderId=(int)$pdo->lastInsertId();
$svc=new OrderAdjustmentService();
$data=$svc->findForAdmin($reference);
$check($data!==null&&$data['order']['buyer_name']==='Order Buyer','lookup owned order');
$check(count($data['licenses'])===1,'own licenses only');

ob_start();
try {
    $_SERVER['REQUEST_METHOD']='GET';
    $_SERVER['REQUEST_URI']='/admin/pagamentos/'.$reference.'/editar';
    View::render('admin/order-edit',[
        '_basePath'=>dirname(__DIR__),
        'title'=>'Ajustar acesso',
        'orderEdit'=>$data,
        'currentUser'=>['name'=>'Order Admin','role'=>'admin'],
        'csrf'=>'smoke-token',
        'flashSuccess'=>null,
        'flashError'=>null,
    ]);
    $html=(string)ob_get_clean();
}catch(Throwable $e){ob_end_clean();throw $e;}
$check(str_contains($html,'Ajustar acesso do cliente'),'page title');
$check(str_contains($html,'R$ 30,00'),'original order displayed');
$check(str_contains($html,'license_id'),'explicit license selector');
$check(str_contains($html,'Justificativa obrigatória'),'reason input');
$check(str_contains($html,$reference),'order reference rendered');
$check(str_contains($html,'history')||str_contains($html,'Histórico'),'history shown');

$change=static function(int $license,int $plan,?string $expiry,string $reason,int $expectedPlan,?string $expectedExpiry)use($svc,$reference,$admin):void{
    $svc->changeAccess($reference,$license,$plan,$expiry,$reason,$admin,$expectedPlan,$expectedExpiry);
};
$originalExpiry=$license['expires_at'];
$change($licenseId,$lifetime,null,'Upgrade manual de teste 3.4.3',$monthly,$originalExpiry);

$getLicense=$pdo->prepare('SELECT plan_id,expires_at,purchased_version,max_major_version FROM licenses WHERE id=?');
$getLicense->execute([$licenseId]);$updated=$getLicense->fetch();
$check((int)$updated['plan_id']===$lifetime && $updated['expires_at']===null,'monthly->lifetime access');
$check($updated['max_major_version']!==null,'lifetime version caps set');

$order=$pdo->query('SELECT plan_id,status,amount_cents,license_applied,provider_mode FROM checkout_orders WHERE id='.$orderId)->fetch();
$check((int)$order['plan_id']===$monthly && $order['status']==='paid'
    && (int)$order['amount_cents']===3000 && (int)$order['license_applied']===1,
    'original payment receipt immutable');

$targetDate=date('Y-m-d',strtotime('+45 days'));
$change($licenseId,$monthly,$targetDate,'Mudança para plano mensal de teste',$lifetime,null);
$getLicense->execute([$licenseId]);$restored=$getLicense->fetch();
$check((int)$restored['plan_id']===$monthly && str_starts_with($restored['expires_at'],$targetDate),'lifetime->monthly expiry');
$check($restored['max_major_version']===null,'subscription includes updates');
$history=$pdo->query('SELECT COUNT(*) FROM license_access_adjustments WHERE order_id='.$orderId)->fetchColumn();
$check((int)$history===2,'two append-only adjustment entries');

$reject=static function(callable $work,string $message)use($check):void{
    try {$work();throw new RuntimeException('unexpected success');}
    catch(RuntimeException $e) {$check(!str_contains($e->getMessage(),'unexpected success'),$message);}
};
$reject(fn()=> $change($otherLicenseId,$lifetime,null,'Altera licença de outra pessoa',$monthly,null),'cannot edit another customer');
$reject(fn()=> $change($licenseId,$lifetime,null,'Formulário antigo teste',$lifetime,null),'stale form rejected');
$reject(fn()=> $change($licenseId,$monthly,$targetDate,'Nenhuma mudança no acesso',$monthly,$restored['expires_at']),'no-op rejected');
$reject(fn()=> $change($licenseId,$monthly,'2030-02-30','Data inválida de teste',$monthly,$restored['expires_at']),'invalid date rejected');
$reject(fn()=> $change($licenseId,$lifetime,null,'short',$monthly,$restored['expires_at']),'short reason rejected');

$pending='CIA-'.strtoupper(bin2hex(random_bytes(8)));
$pdo->prepare(
    "INSERT INTO checkout_orders(reference_id,user_id,plan_id,status,amount_cents,
      provider_mode,license_applied,expires_at)
     VALUES (?,?,?,'waiting',3000,'bacen_static_pix',0,DATE_ADD(NOW(),INTERVAL 1 DAY))"
)->execute([$pending,$buyer,$monthly]);
$svcPending=new OrderAdjustmentService();
$reject(fn()=> $svcPending->changeAccess($pending,$licenseId,$lifetime,null,'Ajuste de pedido pendente',$admin,$monthly,$restored['expires_at']),'unpaid order rejected');

$record=$svc->findForAdmin($reference);
$check(count($record['history'])===2,'history readable on detail screen');
echo "Admin order access PASS: immutable receipt, owner, lifetime/monthly, expiry, audit, stale, unpaid and rendering\n";
