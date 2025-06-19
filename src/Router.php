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
use ValueError;

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
        $this->client = new Client($options, 'nocache');
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

            $dir = dirname($robotsFile);

            if (!file_exists($dir)) {
                mkdir($dir, 0777, true);
            }

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

                $document = new Document($file->getPathname(), true);
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
                    if (!str_starts_with($href, "https://{$this->host}") || $href === "https://{$this->host}") {
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

                try {
                    $document = new Document($file->getPathname(), true);
                } catch (ValueError $exception) {
                    $this->logger?->error($file->getPathname() . ' - ' . $exception->getMessage());
                    continue;
                }

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
                $data .= "robots-short: {$row['robots-short']}\n";
                //$data .= "robots: {$row['robots']}\n";
                $data .= str_repeat('-', 80) . "\n";
            }

            file_put_contents("{$this->snapshotDir}/seo.txt", $data);
            $this->logger->info('SEO extracted');
        });

        $this->router->add('import <file>', function ($args) : void {
            if (!isset($this->host)) {
                $this->logger->error('set host first');
                return;
            }

            $content = file_get_contents($args['file']);

            $files = explode("\n", rtrim($content));

            $this->stashedUrls = [];

            foreach ($files as $url) {
                $this->stashedUrls[] = "https://{$this->host}{$url}";
            }

            $count = count($files);

            $this->logger->info("{$count} stashed");
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
                'seo-framework' => [
                    'search' => "~<!-- / The SEO Framework by Sybre Waaijer \| \d{1,2}\.\d{1,2}ms meta \| \d{1,2}\.\d{1,2}ms boot -->~",
                    'replace' => "<!-- / The SEO Framework by Sybre Waaijer | 0.0ms meta | 0.0ms boot -->"
                ],
                'gravatar' => [
                    'search' => '~https://secure.gravatar.com/avatar/(\w{32,64})~',
                    'replace' => "https://secure.gravatar.com/avatar/00000000000000000000000000000000",
                ],
                'wordpress-version' => [
                    'search' => '~\?ver=\d{10}~',
                    'replace' => '?ver=0000000000',
                ],
                /* REM
                'classicpress-site' => [
                    'search' => '~<body class="(.*?)wp-singular page-template-default page (page-id-\d{1,4}) wp-theme-studio">~',
                    'replace' => '<body class="$1page-template-default page $2">',
                ],
                'classicpress-support' => [
                    'search' => '~<body class="wp-singular post-template-default single single-post postid-(\d{1,4}) single-format-standard wp-theme-support">~',
                    'replace' => '<body class="post-template-default single single-post postid-$1 single-format-standard">',
                ],
                'classicpress-support-2' => [
                    'search' => '~<body class="archive category category-(.*?) category-(\d{1,4}) wp-theme-support">~',
                    'replace' => '<body class="archive category category-$1 category-$2">',
                ],
                'classicpress-2' => [
                    'search' => "~ type='text/css'~",
                    'replace' => '',
                ],
                'classicpress-3' => [
                    'search' => '~ type="text/javascript"~',
                    'replace' => '',
                ],
                'classicpress-4' => [
                    'search' => "~(<link rel='(?:stylesheet|dns-prefetch)' .*?)/>~",
                    'replace' => '$1>',
                ],
                'classicpress-5' => [
                    'search' => '~<script src="(.*?)" id="(.*?)">~',
                    'replace' => "<script src='$1' id='$2'>",
                ],
                'classicpress-7' => [
                    'search' => '~<img loading="lazy" decoding="async"~',
                    'replace' => '<img decoding="async" loading="lazy"',
                ],
                'classicpress-8' => [
                    'search' => '~<img fetchpriority="high" decoding="async"~',
                    'replace' => '<img decoding="async" fetchpriority="high"',
                ],
                'classicpress-9' => [
                    'search' => '~<img (.*?)\/>~',
                    'replace' => '<img $1>',
                ],
                */
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

        $this->router->add('restore backup', function () : void {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->snapshotDir)
            );

            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'bak') {
                    continue;
                }

                $backup = $file->getPathname();
                $restore = str_replace('.bak', '', $backup);

                rename($backup, $restore);
            }

            $this->logger->info('backup restored');
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
