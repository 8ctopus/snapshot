<?php

declare(strict_types=1);

namespace Oct8pus\Snapshot;

use Apix\Log\Logger;
use Clue\Commander\Router as CommanderRouter;
use DiDom\Document;
use HttpSoft\Message\Request;
use Nimbly\Shuttle\Shuttle;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

class Router
{
    private string $host = '';
    private string $dir;
    private string $snapshotDir = '';
    private ?Snapshot $snapshot = null;
    private ?Sitemap $sitemap = null;
    private array $urls = [];
    private CommanderRouter $router;
    private Logger $logger;

    public function __construct(Logger $logger, string $dir)
    {
        $this->logger = $logger;
        $this->dir = $dir;

        $this->router = new CommanderRouter();
    }

    public function setupRoutes() : self
    {
        $this->router->add('[--help | -h]', function () : void {
            $this->logger->info('Usage:');

            foreach ($this->router->getRoutes() as $route) {
                $this->logger->info("  {$route}");
            }
        });

        $this->router->add('host <host>', function ($args) : void {
            $this->host = $args['host'];
            $timestamp = date('Y-m-d_H-i');
            $this->snapshotDir = "{$this->dir}/{$this->host}/{$timestamp}";
            $this->host = "https://{$this->host}";
            $this->snapshot = new Snapshot($this->snapshotDir);
            $this->sitemap = new Sitemap($this->snapshotDir, $this->host);
        });

        $this->router->add('sitemap [<path>]', function (array $args) : void {
            if ($this->sitemap === null) {
                $this->logger->info('Please set host first');
                return;
            }

            $this->urls = $this->sitemap
                ->analyze(...(isset($args['path']) ? [$args['path']] : []))
                ->links();

            sort($this->urls);

            $count = count($this->urls);
            $this->logger->info("{$count} links");
        });

        $this->router->add('robots', function () : void {
            if ($this->host === null) {
                $this->logger->info('Please set host first');
                return;
            }

            $url = $this->host . '/robots.txt';

            $request = (new Request('GET', $url))
                ->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36');

            $client = new Shuttle();
            $response = $client->sendRequest($request);

            if ($response->getStatusCode() !== 200) {
                $this->logger->error("Failed to download robots.txt from {$url}");
                return;
            }

            $robotsFile = "{$this->snapshotDir}/robots.txt";

            mkdir(dirname($robotsFile), 0777, true);

            $body = (string) $response->getBody();
            file_put_contents($robotsFile, $body);

            $this->logger->info($body);
        });

        $this->router->add('snapshot <urls>...', function (array $args) : void {
            if ($this->snapshot === null) {
                $this->logger->info('Please set host first');
                return;
            }

            if ($args['urls'][0] !== 'sitemap') {
                $this->urls = $args['urls'];
            }

            $list = $this->snapshot->takeSnapshots($this->urls);

            foreach ($list as $item) {
                if (isset($item['error'])) {
                    $this->logger->error("{$item['error']} - {$item['url']}");
                    continue;
                }
            }

            $count = count($list);

            $this->logger->info("{$count} pages");
        });

        $this->router->add('extract seo', function () : void {
            $seo = [];

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->snapshotDir)
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

                $doindex = !str_contains($robots, 'noindex');
                $dofollow = !str_contains($robots, 'nofollow');

                $robots = ($doindex ? 'index' : 'noindex') . ',' . ($dofollow ? 'follow' : 'nofollow');

                $seo[] = [
                    'title' => $title,
                    'description' => $description,
                    'robots' => $robots === ',' ? '' : $robots,
                    'canonical' => $canonical,
                ];
            }

            $data = '';

            foreach ($seo as $row) {
                $data .= "canonical: {$row['canonical']}\n";
                $data .= "title: {$row['title']}\n";
                $data .= "description: {$row['description']}\n";
                $data .= "robots: {$row['robots']}\n";
                $data .= str_repeat('-', 80) . "\n";
            }

            file_put_contents("{$this->snapshotDir}/seo.txt", $data);
            $this->logger->info('SEO extracted');
        });

        $this->router->add('clear', function () : void {
            if (!is_dir($this->dir)) {
                return;
            }

            Helper::removeDirectory($this->dir);
            $this->logger->info('All snapshots cleared');
        });

        return $this;
    }

    public function run(array $input) : void
    {
        $stdin = fopen('php://stdin', 'r');

        if ($stdin === false) {
            throw new RuntimeException('fopen');
        }

        while (true) {
            $this->router->handleArgv($input);

            echo "\n> ";
            $input = trim(fgets($stdin));

            if (in_array($input, ['', 'exit', 'quit', 'q'], true)) {
                break;
            }

            $input = explode(' ', "dummy {$input}");
        }
    }
}
