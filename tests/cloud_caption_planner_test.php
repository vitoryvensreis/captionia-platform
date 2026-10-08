<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use CaptionIA\Services\CloudCaptionPlanner;

$p = new CloudCaptionPlanner();
$r = $p->plan(['text' => 'Um teste com legenda longa para evitar que o texto atravesse toda a tela do vídeo vertical', 'aspect_ratio'=>'9:16', 'max_words'=>5, 'max_chars'=>26]);
if (count($r['segments']) < 2) throw new RuntimeException('Deveria criar segmentos menores.');
foreach ($r['segments'] as $segment) {
    if (count($segment['lines']) > 2) throw new RuntimeException('Mais de duas linhas.');
    if ($segment['font_size_hint'] < 20 || $segment['font_size_hint'] > 120) throw new RuntimeException('Tamanho inválido.');
}
try {
    $p->plan(['text' => '', 'aspect_ratio' => '9:16']);
    throw new RuntimeException('Texto vazio foi aceito.');
} catch (InvalidArgumentException $expected) {}
try {
    $p->plan(['text' => 'Teste', 'aspect_ratio' => '2:3']);
    throw new RuntimeException('Aspect ratio inválido foi aceito.');
} catch (InvalidArgumentException $expected) {}
echo "CloudCaptionPlanner OK\n";
