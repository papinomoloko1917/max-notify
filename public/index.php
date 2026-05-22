<?php

declare(strict_types=1);

use App\App;
use App\Container\Container;

define('APP_PATH', dirname(__DIR__));

require APP_PATH . '/vendor/autoload.php';

date_default_timezone_set('Europe/Moscow');

$app = new App(new Container(APP_PATH));
$app->handle();
