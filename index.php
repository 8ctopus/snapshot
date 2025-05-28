<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Apix\Log\Format\ConsoleColors;
use Apix\Log\Logger;
use Apix\Log\Logger\Stream;
use Clue\Commander\Router;
use DiDom\Document;
use HttpSoft\Message\Request;
use Nimbly\Shuttle\Shuttle;
use Oct8pus\Snapshot\Sitemap;
use Oct8pus\Snapshot\Snapshot;

$stdout = (new Stream('php://stdout'))
    // intercept logs that are >=
    ->setMinLevel('debug')
    // propagate to other loggers
    ->setCascading(true)
    ->setFormat(new ConsoleColors());

$logger = new Logger([$stdout]);

// snapshots/host/2025-05-27_12-26
$output = __DIR__ . '/snapshots';
$host = null;
$timestamp = date('Y-m-d_H-i');
$snapshot = new Snapshot($output, $timestamp);

$router = new Router();

$router->add('[--help | -h]', static function () use ($router, $logger) : void {
    $logger->info("Usage:");

    foreach ($router->getRoutes() as $route) {
        $logger->info("  {$route}");
    }
});

$router->add('host <host>', static function ($args) use ($logger, $host) : void {
    $host = $args['host'];
    $logger->info("Set host {$host}");
});

$router->add('snapshot from sitemap <url>', static function (array $args) use ($logger, $snapshot, $output, $timestamp) : void {
    $sitemap = new Sitemap($args['url'], $output, $timestamp);
    $sitemap->analyze();

    $urls = $sitemap->links();

    $results = $snapshot->takeSnapshots($urls);

    foreach ($results as $result) {
        if (!isset($result['error'])) {
            $logger->info("Snapshot taken - {$result['url']}");
            continue;
        }

        $logger->info("{$result['error']} - {$result['url']}");
    }
});

$router->add('snapshot <urls>...', static function (array $args) use ($logger, $snapshot) : void {
    $urls = $args['urls'];

    $results = $snapshot->takeSnapshots($urls);

    foreach ($results as $result) {
        if (!isset($result['error'])) {
            $logger->info("Snapshot taken - {$result['url']}");
            continue;
        }

        $logger->info("{$result['error']} - {$result['url']}");
    }
});

$router->add('sitemap <url>', static function (array $args) use ($logger, $output, $timestamp) : void {
    (new Sitemap($args['url'], $output, $timestamp))
        ->analyze()
        ->show(false);
});

$router->add('clear snapshots', static function () use ($logger, $snapshot) : void {
    $snapshot->clear();
    $logger->info("All snapshots cleared");
});

$router->add('extract seo', static function () use ($logger, $output, $timestamp) : void {
    $seoData = [];
    $firstFilePath = null;

    // find all html files in current snapshot directory
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($output)
    );

    foreach ($iterator as $file) {
        // only process files from current timestamp directory
        if (!$file->isFile() || $file->getExtension() !== 'html' || !str_contains($file->getPathname(), $timestamp)) {
            continue;
        }

        $html = file_get_contents($file->getPathname());
        if ($html === false) {
            continue;
        }

        $document = new Document($html);
        $filePath = $file->getPathname();

        // save the first file path for directory
        if ($firstFilePath === null) {
            $firstFilePath = $filePath;
        }

        $title = $document->find('title')[0]?->text() ?? '';
        $description = $document->find('meta[name="description"]')[0]?->getAttribute('content') ?? '';
        $robots = $document->find('meta[name="robots"]')[0]?->getAttribute('content') ?? '';
        $canonical = $document->find('link[rel="canonical"]')[0]?->getAttribute('href') ?? '';

        $seoData[] = [
            'url' => $canonical ?: $filePath,
            'title' => $title,
            'description' => $description,
            'robots' => $robots,
        ];
    }

    if (empty($seoData)) {
        $logger->info("No HTML files found in current snapshot");
        return;
    }

    // use the first file's directory for the seo.txt file
    $dir = dirname($firstFilePath);
    $seoFile = "{$dir}/seo.txt";

    $content = '';
    foreach ($seoData as $data) {
        $content .= "URL: {$data['url']}\n";
        $content .= "Title: {$data['title']}\n";
        $content .= "Description: {$data['description']}\n";
        $content .= "Robots: {$data['robots']}\n";
        $content .= str_repeat('-', 80) . "\n";
    }

    file_put_contents($seoFile, $content);
    $logger->info("SEO data saved to {$seoFile}");
});

$router->add('download robots <url>', static function (array $args) use ($output, $timestamp, $logger) : void {
    $url = $args['url'];
    if (!str_ends_with($url, 'robots.txt')) {
        $logger->info("URL must end with robots.txt");
        return;
    }

    $request = (new Request('GET', $url))
        ->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36');

    $client = new Shuttle();
    $response = $client->sendRequest($request);

    if ($response->getStatusCode() !== 200) {
        $logger->info("Failed to download robots.txt from {$url}");
        return;
    }

    // extract domain from URL for directory structure
    $parsedUrl = parse_url($url);
    $domain = $parsedUrl['host'];
    $dir = "{$output}/{$domain}/{$timestamp}";

    if (!is_dir($dir)) {
        $logger->info("No snapshot directory found for {$domain}");
        return;
    }

    $robotsFile = "{$dir}/robots.txt";
    file_put_contents($robotsFile, (string) $response->getBody());
    $logger->info("robots.txt saved to {$robotsFile}");
});

$router->add('exit', static function () : void {
    exit(0);
});

$stdin = fopen('php://stdin', 'r');

if ($stdin === false) {
    throw new Exception('fopen');
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
