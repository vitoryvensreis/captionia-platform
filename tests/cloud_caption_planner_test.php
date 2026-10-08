<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use CaptionIA\Services\CloudCaptionPlanner;

$p = new CloudCaptionPlanner();
$assert = static function(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$r = $p->plan([
    'text' => 'Uma legenda extensa precisa continuar dentro da área segura mesmo em vídeo vertical',
    'aspect_ratio' => '9:16', 'max_words' => 4, 'max_chars' => 22
]);
$assert($r['schema_version'] === 2, 'Versão de contrato inválida.');
$assert(count($r['segments']) >= 3, 'Segmentação não gerou blocos suficientes.');
$recovered = [];
foreach ($r['segments'] as $s) {
    $assert(count($s['lines']) <= 2, 'Mais que duas linhas.');
    $assert($s['font_size_hint'] >= 20 && $s['font_size_hint'] <= 120, 'Fonte fora do limite.');
    $assert($s['start_time'] === null && $s['end_time'] === null, 'Timings foram inventados.');
    $recovered[] = $s['text'];
}
$assert(implode(' ', $recovered) === 'Uma legenda extensa precisa continuar dentro da área segura mesmo em vídeo vertical', 'Palavras foram perdidas.');
$timed = $p->plan(['words' => [
    ['text'=>'Olá', 'start'=>0.2, 'end'=>0.5],
    ['text'=>'mundo', 'start'=>0.6, 'end'=>0.9],
    ['text'=>'novo', 'start'=>2.0, 'end'=>2.3]
], 'max_words'=>8]);
$assert(count($timed['segments']) === 2, 'Pausa não foi respeitada.');
$assert(abs($timed['segments'][0]['start_time'] - 0.2) < 0.0001, 'Início foi alterado.');
$assert(abs($timed['segments'][1]['end_time'] - 2.3) < 0.0001, 'Fim foi alterado.');
foreach ([
    ['text'=>''],
    ['text'=>'abc','aspect_ratio'=>'2:3'],
    ['words'=>[['text'=>'a','start'=>5,'end'=>4]]],
    ['words'=>[['text'=>'a','start'=>1,'end'=>2],['text'=>'b','start'=>0,'end'=>1]]],
] as $invalid) {
    try { $p->plan($invalid); throw new RuntimeException('Entrada inválida aceita.'); }
    catch (InvalidArgumentException $expected) {}
}
echo "CloudCaptionPlanner: OK\n";

require __DIR__ . '/cloud_asset_crypto_test.php';
