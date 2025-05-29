<?php

declare(strict_types=1);

namespace Oct8pus\Snapshot;

use Apix\Log\Logger;
use Clue\Commander\Router as CommanderRouter;
use DiDom\Document;
use HttpSoft\Message\Request;
use Psr\Http\Client\ClientInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

class Router
{
    private Logger $logger;
    private string $dir;
    private CommanderRouter $router;
    private ClientInterface $client;

    private string $host;
    private string $snapshotDir;
    private Snapshot $snapshot;
    private Sitemap $sitemap;
    private array $stashed;

    public function __construct(Logger $logger, string $dir)
    {
        $this->logger = $logger;
        $this->dir = $dir;
        $this->router = new CommanderRouter();

        $options = [
            // FIX ME
            CURLOPT_SSL_VERIFYPEER => false,
        ];

        $this->client = Client::make($options);
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
            $host = $args['host'];
            $timestamp = date('Y-m-d_H-i');

            $this->snapshotDir = "{$this->dir}/{$host}/{$timestamp}";
            $this->host = "https://{$host}";
            $this->snapshot = new Snapshot($this->client, $this->logger, $this->snapshotDir);
            $this->sitemap = new Sitemap($this->client, $this->logger, $this->snapshotDir, $this->host);
        });

        $this->router->add('sitemap [<path>]', function (array $args) : void {
            if (!isset($this->host)) {
                $this->logger->info('Please set host first');
                return;
            }

            $this->stashed = $this->sitemap
                ->analyze(...(isset($args['path']) ? [$args['path']] : []))
                ->links();

            sort($this->stashed);

            $count = count($this->stashed);
            $this->logger->info("{$count} links stashed");
        });

        $this->router->add('robots', function () : void {
            if (!isset($this->host)) {
                $this->logger->info('Please set host first');
                return;
            }

            $url = $this->host . '/robots.txt';

            $request = (new Request('GET', $url))
                ->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36');

            $response = $this->client->sendRequest($request);

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
            if (!isset($this->host)) {
                $this->logger->info('Please set host first');
                return;
            }

            if ($args['urls'][0] !== 'stashed') {
                $this->stashed = [];

                foreach ($args['urls'] as $url) {
                    $this->stashed[] = $this->host . $url;
                }
            }

            $count = $this->snapshot->takeSnapshots($this->stashed);

            $this->logger->info("{$count} pages");
        });

        $this->router->add('discover hidden', function () : void {
            if (!isset($this->host)) {
                $this->logger->info('Please set host first');
                return;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->snapshotDir)
            );

            $hidden = [];

            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'html') {
                    continue;
                }

                $document = new Document(file_get_contents($file->getPathname()));
                $links = $document->find('a[href]');

                foreach ($links as $link) {
                    $href = $link->getAttribute('href');

                    // remove query string and anchor
                    $href = preg_replace('/#.*$/', '', $href);
                    $href = preg_replace('/\?.*$/', '', $href);

                    if (empty($href)) {
                        continue;
                    }

                    // convert relative URLs to absolute
                    if ($href[0] === '/') {
                        $href = "{$this->host}{$href}";
                    }

                    // keep only internal links
                    if (!str_starts_with($href, $this->host) || $href === $this->host) {
                        continue;
                    }

                    if (in_array($href, $this->stashed, true)) {
                        continue;
                    }

                    $hidden[] = $href;
                }
            }

            $hidden = array_unique($hidden);
            sort($hidden);

            $count = count($hidden);
            $this->logger->info("{$count} hidden links stashed");

            foreach ($hidden as $link) {
                $this->logger->info($link);
            }

            $this->stashed = $hidden;
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
                    'url' => json_decode(file_get_contents(str_replace('.html', '.json', $file->getPathname())), true)['url'],
                    'title' => $title,
                    'description' => $description,
                    'robots' => $robots === 'index,follow' ? '' : $robots,
                    'canonical' => $canonical,
                ];
            }

            $data = '';

            foreach ($seo as $row) {
                $data .= "url: {$row['url']}\n";
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

            Helper::removeDirectory($this->dir, false);
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
