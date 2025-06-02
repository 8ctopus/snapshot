<?php

declare(strict_types=1);

namespace Oct8pus\Snapshot;

use Apix\Log\Logger;
use Clue\Commander\NoRouteFoundException;
use Clue\Commander\Router as CommanderRouter;
use DiDom\Document;
use HttpSoft\Message\Request;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

class Router
{
    private Logger $logger;
    private string $dir;
    private CommanderRouter $router;
    private $stdin;
    private Client $client;

    private string $host;
    private string $snapshotDir;
    private Snapshot $snapshot;
    private Sitemap $sitemap;
    private array $stashedUrls;
    private array $stashedSitemaps;

    public function __construct(Logger $logger, string $dir)
    {
        $this->logger = $logger;
        $this->dir = $dir;
        $this->router = new CommanderRouter();

        $stdin = fopen('php://stdin', 'r');

        if ($stdin === false) {
            throw new RuntimeException('fopen');
        }

        $this->stdin = $stdin;

        $options = [
            // FIX ME - move to setting somewhere
            CURLOPT_SSL_VERIFYPEER => false,
        ];

        // FIX ME - move to setting somewhere
        $this->client = new Client($options, '?nocache');
    }

    public function setupRoutes() : self
    {
        $this->router->add('help', function () : void {
            $this->logger->info('commands:');

            foreach ($this->router->getRoutes() as $route) {
                $this->logger->info("  {$route}");
            }
        });

        $this->router->add('host <host>', function ($args) : void {
            $this->host = $args['host'];

            $name = $this->input('snapshot name');

            if (empty($name)) {
                $name = date('Y-m-d_H-i');
            }

            $dir = "{$this->dir}/{$this->host}/{$name}";

            if (file_exists($dir)) {
                $this->logger->error('snapshot name already exists');
                return;
            }

            $this->snapshotDir = $dir;
            $this->snapshot = new Snapshot($this->client, $this->logger, $this->snapshotDir);
            $this->sitemap = new Sitemap($this->client, $this->logger, $this->snapshotDir, "https://{$this->host}");
        });

        $this->router->add('robots', function () : void {
            if (!isset($this->host)) {
                $this->logger->error('set host first');
                return;
            }

            $url = "https://{$this->host}/robots.txt";

            $request = (new Request('GET', $url))
                ->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36');

            $response = $this->client->download($request);

            if ($response->getStatusCode() !== 200) {
                $this->logger->error("download robots.txt {$url}");
                return;
            }

            $robotsFile = "{$this->snapshotDir}/robots.txt";

            mkdir(dirname($robotsFile), 0777, true);

            $body = (string) $response->getBody();
            $this->logger->info($body);
            file_put_contents($robotsFile, $body);

            if (preg_match_all('/^sitemap: ?(.*)$/mi', $body, $matches) === false) {
                throw new RuntimeException('unrecognized sitemap');
            }

            $this->stashedSitemaps = $matches[1];
            $count = count($matches[1]);

            $this->logger->info("{$count} sitemaps found");
        });

        $this->router->add('sitemap [<paths>...]', function (array $args) : void {
            if (!isset($this->host)) {
                $this->logger->error('set host first');
                return;
            }

            if (isset($args['paths'])) {
                $this->stashedSitemaps = $args['paths'];
            }

            $this->stashedUrls = $this->sitemap
                ->analyze($this->stashedSitemaps)
                ->links();

            sort($this->stashedUrls);

            $count = count($this->stashedUrls);
            $this->logger->info("{$count} links stashed");
        });

        $this->router->add('snapshot [<urls>...]', function (array $args) : void {
            if (!isset($this->host)) {
                $this->logger->error('set host first');
                return;
            }

            if (isset($args['urls'])) {
                $this->stashedUrls = [];

                foreach ($args['urls'] as $url) {
                    $this->stashedUrls[] = "https://{$this->host}{$url}";
                }
            }

            $count = $this->snapshot->takeSnapshots($this->stashedUrls);

            $this->logger->info("{$count} pages");
        });

        $this->router->add('discover hidden', function () : void {
            if (!isset($this->host)) {
                $this->logger->error('set host first');
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
                        $href = "https://{$this->host}{$href}";
                    }

                    // keep only internal links
                    if (!str_starts_with($href, $this->host) || $href === $this->host) {
                        continue;
                    }

                    if (in_array($href, $this->stashedUrls, true)) {
                        continue;
                    }

                    $hidden[] = $href;
                }

                $links = $document->find('link[rel="alternate"]');

                foreach ($links as $link) {
                    $href = $link->getAttribute('href');

                    if (in_array($href, $this->stashedUrls, true)) {
                        continue;
                    }

                    $hidden[] = $href;
                }
            }

            $hidden = array_unique($hidden);
            sort($hidden);
            $this->stashedUrls = $hidden;

            $count = count($hidden);
            $this->logger->info("{$count} hidden links stashed");
        });

        $this->router->add('list', function () : void {
            foreach ($this->stashedUrls as $link) {
                $this->logger->info($link);
            }
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

                $robotsShort = ($doindex ? 'index' : 'noindex') . ',' . ($dofollow ? 'follow' : 'nofollow');

                $seo[] = [
                    'url' => json_decode(file_get_contents(str_replace('.html', '.json', $file->getPathname())), true)['url'],
                    'title' => $title,
                    'description' => $description,
                    'robots-short' => $robotsShort === 'index,follow' ? '' : $robotsShort,
                    'robots' => $robots,
                    'canonical' => $canonical,
                ];
            }

            $data = '';

            foreach ($seo as $row) {
                $data .= "url: {$row['url']}\n";
                $data .= "canonical: {$row['canonical']}\n";
                $data .= "title: {$row['title']}\n";
                $data .= "description: {$row['description']}\n";
                $data .= "robots: {$row['robots-short']}\n";
                $data .= "robots: {$row['robots']}\n";
                $data .= str_repeat('-', 80) . "\n";
            }

            file_put_contents("{$this->snapshotDir}/seo.txt", $data);
            $this->logger->info('SEO extracted');
        });

        $this->router->add('select <snapshot>', function ($args) : void {
            $name = $args['snapshot'];

            if (empty($name)) {
                $this->logger->error('snapshot dir required');
                return;
            }

            $dir = "{$this->dir}/{$this->host}/{$name}";

            if (!file_exists($dir)) {
                $this->logger->error('snapshot dir does not exist');
                return;
            }

            $this->snapshotDir = $dir;
            $this->snapshot = new Snapshot($this->client, $this->logger, $this->snapshotDir);
            $this->sitemap = new Sitemap($this->client, $this->logger, $this->snapshotDir, $this->host);
        });

        $this->router->add('clean', function () : void {
            $rules = [
                'cache-enabler' => [
                    'search' => "~<!-- Cache Enabler by KeyCDN @ \w{3}, \d{2} May 202\d{1} \d{2}:\d{2}:\d{2} GMT \(https-index\.html\.gz\) -->~",
                    'replace' => '',
                ],
                'yoast' => [
                    'search' => "~This site is optimized with the Yoast SEO plugin v\d{2}\.\d{1,2}~",
                    'replace' => "This site is optimized with the Yoast SEO plugin v0.0",
                ],
            ];

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->snapshotDir)
            );

            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'html') {
                    continue;
                }

                $original = file_get_contents($file->getPathname());
                $updated = $original;

                foreach ($rules as $rule) {
                    $updated = preg_replace($rule['search'], $rule['replace'], $updated, -1);
                }

                if ($updated !== $original) {
                    $backup = $file->getPathname() . '.bak';

                    if (!file_exists($backup)) {
                        copy($file->getPathname(), $backup);
                    }

                    file_put_contents($file->getPathname(), $updated);
                }
            }
        });

        $this->router->add('clear', function () : void {
            if (!is_dir($this->dir)) {
                return;
            }

            Helper::removeDirectory($this->dir, false);
            $this->logger->info('snapshots cleared');
        });

        return $this;
    }

    public function run(array $input) : void
    {
        while (true) {
            $input = $this->input();

            if (in_array($input, ['', 'exit', 'quit', 'q'], true)) {
                break;
            }

            $input = explode(' ', "dummy {$input}");

            try {
                $this->router->handleArgv($input);
            } catch (NoRouteFoundException $exception) {
                $this->logger->error($exception->getMessage());
            }
        }
    }

    private function input(string $message = '') : string
    {
        echo "\n{$message}> ";

        $input = trim(fgets($this->stdin));

        return $input;
    }
}
