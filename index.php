<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Clue\Commander\Router;
use Oct8pus\Snapshot\Snapshot;

$snapshot = new Snapshot(__DIR__ . '/snapshots');

$router = new Router();

$router->add('[--help | -h]', function () use ($router) : void {
    echo "Usage:\n";
    foreach ($router->getRoutes() as $route) {
        echo "  {$route}\n";
    }
});

$router->add('snapshot <url>...', function (array $args) use ($snapshot) : void {
    $timestamp = date('Y-m-d_H-i');
    $urls = $args['url'];

    $results = $snapshot->takeSnapshots($urls, $timestamp);

    foreach ($results as $result) {
        if (!isset($result['error'])) {
            echo "Snapshot taken - {$result['url']}\n";
            continue;
        }

        echo "{$result['error']} - {$result['url']} \n";
    }
});

$router->handleArgv($argv);
