<?php
declare(strict_types=1);

namespace CaptionIA\Core;

final class App
{
    public function __construct(private readonly string $basePath)
    {
    }

    public function run(): void
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        if ($uri === '/health') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => true,
                'app' => 'Caption IA Platform',
                'time' => gmdate('c'),
            ], JSON_UNESCAPED_SLASHES);
            return;
        }

        header('Content-Type: text/html; charset=utf-8');
        echo $this->landingPage();
    }

    private function landingPage(): string
    {
        return <<<'HTML'
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Caption IA</title>
<style>
:root{color-scheme:dark}
*{box-sizing:border-box}
body{margin:0;font-family:Inter,system-ui,-apple-system,Segoe UI,sans-serif;background:#090b10;color:#f5f7fb}
main{min-height:100vh;display:grid;place-items:center;padding:32px}
.card{max-width:880px;width:100%;padding:48px;border:1px solid #273246;border-radius:24px;background:#101723}
.badge{display:inline-flex;padding:7px 12px;border-radius:999px;background:#ff9f0a;color:#121212;font-weight:800;font-size:13px}
h1{font-size:clamp(42px,8vw,82px);line-height:.95;margin:24px 0 18px}
p{max-width:650px;color:#9fb0c8;font-size:18px;line-height:1.6}
.plans{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-top:32px}
.plan{padding:24px;border:1px solid #25334a;border-radius:18px;background:#0c121c}
.price{font-size:34px;font-weight:900;margin-top:8px}
small{color:#7f91aa}
@media(max-width:650px){.card{padding:28px}.plans{grid-template-columns:1fr}}
</style>
</head>
<body>
<main>
<section class="card">
<span class="badge">CAPTION IA</span>
<h1>Edite mais.<br>Espere menos.</h1>
<p>Plataforma oficial do Caption IA para vendas, licenças, assinaturas e gerenciamento de dispositivos.</p>
<div class="plans">
<div class="plan"><strong>Mensal</strong><div class="price">R$ 30<small>/mês</small></div><p>Inclui atualizações enquanto a assinatura estiver ativa.</p></div>
<div class="plan"><strong>Vitalício</strong><div class="price">R$ 70</div><p>Uso permanente da versão adquirida, sem futuras versões principais.</p></div>
</div>
</section>
</main>
</body>
</html>
HTML;
    }
}
