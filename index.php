<?php

declare(strict_types=1);

use Apix\Log\Format\MinimalColors;
use Apix\Log\Logger;
use Apix\Log\Logger\Stream;
use NunoMaduro\Collision\Provider as ExceptionHandler;
use Oct8pus\Snapshot\Router;

require_once __DIR__ . '/vendor/autoload.php';

(new ExceptionHandler())
    ->register();

$stdout = (new Stream('php://stdout'))
    // intercept logs that are >=
    ->setMinLevel('debug')
    // propagate to other loggers
    ->setCascading(true)
    ->setFormat(new MinimalColors());

$logger = new Logger([$stdout]);

$router = (new Router($logger, __DIR__ . '/snapshots'))
    ->setupRoutes()
    ->run($argv);
