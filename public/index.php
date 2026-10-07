<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use CaptionIA\Core\App;

$app = new App(dirname(__DIR__));
$app->run();
