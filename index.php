<?php

declare(strict_types=1);

use Apix\Log\Format\Minimal;
use Apix\Log\Logger;
use Apix\Log\Logger\Stream;
use Clue\Commander\Router;
use DiDom\Document;
use HttpSoft\Message\Request;
use Nimbly\Shuttle\Shuttle;
use NunoMaduro\Collision\Provider as ExceptionHandler;
use Oct8pus\Snapshot\Helper;
use Oct8pus\Snapshot\Sitemap;
use Oct8pus\Snapshot\Snapshot;

require_once __DIR__ . '/vendor/autoload.php';

(new ExceptionHandler())
    ->register();

$stdout = (new Stream('php://stdout'))
    // intercept logs that are >=
    ->setMinLevel('debug')
    // propagate to other loggers
    ->setCascading(true)
    ->setFormat(new Minimal());

$logger = new Logger([$stdout]);

$host = null;
$dir = __DIR__ . '/snapshots';
// snapshots/host/2025-05-27_12-26
$snapshotDir = '';

$snapshot = null;
$sitemap = null;
$urls = [];

$router = new Router();

$router->add('[--help | -h]', static function () use ($router, $logger) : void {
    $logger->info('Usage:');

    foreach ($router->getRoutes() as $route) {
        $logger->info("  {$route}");
    }
});

$router->add('host <host>', static function ($args) use ($logger, &$host, $dir, &$snapshotDir, &$snapshot, &$sitemap) : void {
    $host = $args['host'];
    //$logger->info("Set host {$host}");

    $timestamp = date('Y-m-d_H-i');
    $snapshotDir = "{$dir}/{$host}/{$timestamp}";

    //$logger->info("snapshot dir - {$snapshotDir}");

    $host = "https://{$host}";

    $snapshot = new Snapshot($snapshotDir);
    $sitemap = new Sitemap($snapshotDir, $host);
});

$router->add('sitemap', static function () use ($logger, &$sitemap, &$urls) : void {
    if ($sitemap === null) {
        $logger->info('Please set host first');
        return;
    }

    $urls = $sitemap
        ->analyze()
        ->links();

    sort($urls);

    $count = count($urls);

    $logger->info("sitemap has {$count} links");
});

$router->add('robots', static function () use ($logger, &$host, &$snapshotDir) : void {
    if ($host === null) {
        $logger->info('Please set host first');
        return;
    }

    $url = $host . '/robots.txt';

    $request = (new Request('GET', $url))
        ->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36');

    $client = new Shuttle();
    $response = $client->sendRequest($request);

    if ($response->getStatusCode() !== 200) {
        $logger->error("Failed to download robots.txt from {$url}");
        return;
    }

    $robotsFile = "{$snapshotDir}/robots.txt";

    mkdir(dirname($robotsFile), 0777, true);

    $body = (string) $response->getBody();
    file_put_contents($robotsFile, $body);

    $logger->info($body);
});

$router->add('snapshot <urls>...', static function (array $args) use ($logger, &$snapshot, &$urls) : void {
    if ($snapshot === null) {
        $logger->info('Please set host first');
        return;
    }

    if ($args['urls'][0] !== 'sitemap') {
        $urls = $args['urls'];
    }

    $results = $snapshot->takeSnapshots($urls);

    foreach ($results as $result) {
        if (isset($result['error'])) {
            $logger->error("{$result['error']} - {$result['url']}");
            continue;
        }
    }
});

$router->add('extract seo', static function () use ($logger, &$snapshotDir) : void {
    $seo = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($snapshotDir)
    );

    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'html') {
            continue;
        }

        $document = new Document(file_get_contents($file->getPathname()));

        $title = $document->first('title')?->text() ?? 'N/A';
        $description = $document->first('meta[name="description"]')?->getAttribute('content') ?? 'N/A';
        $robots = $document->first('meta[name="robots"]')?->getAttribute('content') ?? 'N/A';
        $canonical = $document->first('link[rel="canonical"]')?->getAttribute('href') ?? 'N/A';

        $seo[] = [
            'title' => $title,
            'description' => $description,
            'robots' => $robots,
            'canonical' => $canonical,
        ];
    }

    $data = '';

    foreach ($seo as $row) {
        $data .= "title: {$row['title']}\n";
        $data .= "description: {$row['description']}\n";
        $data .= "robots: {$row['robots']}\n";
        $data .= "canonical: {$row['canonical']}\n";
        $data .= str_repeat('-', 80) . "\n";
    }

    file_put_contents("{$snapshotDir}/seo.txt", $data);
    $logger->info('SEO extracted');
});

$router->add('clear', static function () use ($logger, $dir) : void {
    if (!is_dir($dir)) {
        return;
    }

    Helper::removeDirectory($dir);
    $logger->info('All snapshots cleared');
});

$stdin = fopen('php://stdin', 'r');

if ($stdin === false) {
    throw new RuntimeException('fopen');
}

$input = $argv;

while (true) {
    $router->handleArgv($input);

    echo "\n> ";
    $input = trim(fgets($stdin));

    if (in_array($input, ['', 'exit', 'quit', 'q'], true)) {
        break;
    }

    $input = explode(' ', "dummy {$input}");
}
