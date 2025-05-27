<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Clue\Commander\Router;
use Oct8pus\Snapshot\Sitemap;
use Oct8pus\Snapshot\Snapshot;

$output = __DIR__ . '/snapshots';
$timestamp = date('Y-m-d_H-i');
$snapshot = new Snapshot($output, $timestamp);

$router = new Router();

$router->add('[--help | -h]', static function () use ($router) : void {
    echo "Usage:\n";
    foreach ($router->getRoutes() as $route) {
        echo "  {$route}\n";
    }
});

$router->add('snapshot from sitemap <url>', static function (array $args) use ($snapshot, $output, $timestamp) : void {
    $sitemap = new Sitemap($args['url'], $output, $timestamp);
    $sitemap->analyze();

    $links = $sitemap->links();
    $urls = array_column($links, 'loc');

    if (empty($urls)) {
        echo "No URLs found in sitemap\n";
        return;
    }

    $results = $snapshot->takeSnapshots($urls);

    foreach ($results as $result) {
        if (!isset($result['error'])) {
            echo "Snapshot taken - {$result['url']}\n";
            continue;
        }

        echo "{$result['error']} - {$result['url']} \n";
    }
});

$router->add('snapshot <urls>...', static function (array $args) use ($snapshot) : void {
    $urls = $args['urls'];

    $results = $snapshot->takeSnapshots($urls);

    foreach ($results as $result) {
        if (!isset($result['error'])) {
            echo "Snapshot taken - {$result['url']}\n";
            continue;
        }

        echo "{$result['error']} - {$result['url']} \n";
    }
});

$router->add('sitemap <url>', static function (array $args) use ($output, $timestamp) : void {
    (new Sitemap($args['url'], $output, $timestamp))
        ->analyze()
        ->show(false);
});

$router->add('clear snapshots', static function () use ($snapshot) : void {
    $snapshot->clear();
    echo "All snapshots cleared\n";
});

$router->add('exit', static function () : void {
    exit(0);
});

while (true) {
    echo "\nEnter command (or 'exit' to quit): ";
    $input = trim(fgets(STDIN));

    if (empty($input)) {
        continue;
    }

    try {
        $router->handleArgv(explode(' ', $input));
    } catch (Exception $e) {
        echo "Error: {$e->getMessage()}\n";
    }
}
