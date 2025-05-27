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

    $urls = $sitemap->links();

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

$router->add('extract seo', static function () use ($output, $timestamp) : void {
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

        $document = new DiDom\Document($html);
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
        echo "No HTML files found in current snapshot\n";
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
    echo "SEO data saved to {$seoFile}\n";
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

    if (in_array($input, ['', 'exit', 'quit', 'q'])) {
        break;
    }

    $input = explode(' ', "dummy {$input}");
}
