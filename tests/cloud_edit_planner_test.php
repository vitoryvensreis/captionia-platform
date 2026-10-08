<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
use CaptionIA\Services\CloudEditPlanner;
$p = new CloudEditPlanner();
$r = $p->plan(['duration'=>30.0,'fps'=>29.97, 'silences'=>[
 ['start'=>1.0,'end'=>2.2], ['start'=>3.0,'end'=>3.15],
 ['start'=>10.0,'end'=>11.0]
]]);
if (count($r['cuts']) !== 2) throw new RuntimeException('Cortes inesperados.');
if ($r['cuts'][0]['end'] <= $r['cuts'][0]['start']) throw new RuntimeException('Corte inválido.');
if ($r['removed_seconds'] <= 0 || $r['removed_seconds'] >= 19.5) throw new RuntimeException('Quantidade removida inválida.');
if (!$r['requires_user_review']) throw new RuntimeException('Plano não deve se autoexecutar.');
foreach ([
 ['duration'=>0,'fps'=>30,'silences'=>[]],
 ['duration'=>10,'fps'=>0,'silences'=>[]],
 ['duration'=>10,'fps'=>30,'silences'=>[['start'=>4,'end'=>3]]],
 ['duration'=>10,'fps'=>30,'silences'=>[['start'=>9,'end'=>11]]]
] as $invalid) {
 try {$p->plan($invalid);throw new RuntimeException('Plano inválido aceito.');}
 catch (InvalidArgumentException $ok) {}
}
echo "CloudEditPlanner: OK\n";
