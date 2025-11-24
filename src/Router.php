<?php

declare(strict_types=1);

namespace Oct8pus\Snapshot;

use Apix\Log\Logger;
use Clue\Commander\NoRouteFoundException;
use Clue\Commander\Router as CommanderRouter;
use Crwlr\Url\Url;
use Crwlr\Url\Validator;
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
    private array $scannedUrls;
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
            // do not follow redirects
            CURLOPT_FOLLOWLOCATION => false,
            // FIX ME - move to setting somewhere
            CURLOPT_SSL_VERIFYPEER => false,
        ];

        // FIX ME - move to setting somewhere
        $this->client = new Client($options, [
            'nocache' => '',
        ]);
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
            $this->scannedUrls = [];
            $this->stashedUrls = [];

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

            $reference = Url::parse("https://{$this->host}");

            if (isset($args['urls'])) {
                $this->stashedUrls = [];

                foreach ($args['urls'] as $url) {
                    $this->stashedUrls[] = $reference->resolve($url)->toString();
                }
            }

            $count = $this->snapshot->takeSnapshots($this->stashedUrls);

            $this->scannedUrls = array_merge($this->scannedUrls, $this->stashedUrls);

            $this->logger->info("{$count} pages");
        });

        $this->router->add('discover', function () : void {
            if (!isset($this->host)) {
                $this->logger->error('set host first');
                return;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->snapshotDir)
            );

            $candidates = [];

            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'html' || !filesize($file->getPathname())) {
                    continue;
                }

                try {
                    $document = new Document($file->getPathname(), true);

                    $links = $document->find('a[href]');

                    foreach ($links as $link) {
                        $href = $link->getAttribute('href');
                        $candidates[] = urldecode($href);
                    }

                    $links = $document->find('link[rel="alternate"]');

                    foreach ($links as $link) {
                        $href = $link->getAttribute('href');
                        $candidates[] = urldecode($href);
                    }

                    $links = $document->find('link[rel="next"]');

                    foreach ($links as $link) {
                        $href = $link->getAttribute('href');
                        $candidates[] = urldecode($href);
                    }
                } catch (ValueError $error) {
                    $this->logger?->error($error->getMessage());
                }
            }

            $candidates = array_unique($candidates);
            sort($candidates);

            $reference = Url::parse("https://{$this->host}");

            $hidden = [];

            foreach ($candidates as $candidate) {
                if (str_starts_with($candidate, 'javaScript:')) {
                    continue;
                }

                $href = $reference->resolve($candidate);

                if (Validator::url($href->toString()) === null) {
                    $this->logger?->error("invalid url - {$href}");
                    continue;
                }

                // keep only internal links
                if ($href->host() !== $this->host) {
                    continue;
                }

                // ignore extensions
                if (preg_match('/\.\w{3,4}$/', $href->path() ?? '') === 1) {
                    continue;
                }

                // strip fragment
                $href->fragment('');

                // strip nocache if present
                $query = $href->queryString();
                $query->remove('nocache');

                $href = urldecode($href->toString());

                if (in_array($href, $this->scannedUrls, true)) {
                    continue;
                }

                if (in_array($href, $hidden, true)) {
                    continue;
                }

                $hidden[] = $href;
            }

            sort($hidden);

            $this->stashedUrls = $hidden;

            $count = count($hidden);
            $this->logger->info("{$count} links stashed");
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
                if (!$file->isFile() || $file->getExtension() !== 'html' || !filesize($file->getPathname())) {
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

            $content = file_get_contents(__DIR__ . "/../extra/{$args['file']}.txt");

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
                /*
                'clean-w3-total-cache' => [
                    'search' => '~<!--\s*Performance optimized by W3 Total Cache[\s\S]*?-->~',
                    'replace' => '',
                ],
                */
                'clean-cache-enabler' => [
                    'search' => "~<!-- Cache Enabler by KeyCDN @ .*? -->~",
                    'replace' => '<!-- Cache Enabler by KeyCDN ... -->',
                ],
                /*
                'remove-cache-enabler-full' => [
                    'search' => "~<!-- Cache Enabler by KeyCDN @ \w{3}, \d{2} May 202\d{1} \d{2}:\d{2}:\d{2} GMT \(https-index\.html\.gz\) -->~",
                    'replace' => '',
                ],
                */
                /*
                'clean-yoast' => [
                    'search' => "~This site is optimized with the Yoast SEO plugin v\d{2}\.\d{1,2}~",
                    'replace' => "This site is optimized with the Yoast SEO plugin v0.0",
                ],
                'remove-yoast-full-1' => [
                    'search' => '~<!-- This site is optimized with the Yoast SEO plugin v0\.0 - .*? -->~',
                    'replace' => '',
                ],
                'remove-yoast-full-2' => [
                    'search' => '~<!-- / Yoast SEO plugin\. -->~',
                    'replace' => '',
                ],
                */
                'clean-seo-framework' => [
                    'search' => "~<!-- / The SEO Framework by Sybre Waaijer \| \d{1,2}\.\d{1,2}ms meta \| \d{1,2}\.\d{1,2}ms boot -->~",
                    'replace' => "<!-- / The SEO Framework by Sybre Waaijer | 0.0ms meta | 0.0ms boot -->"
                ],
                /*
                'remove-seo-framework-full' => [
                    'search' => '~<!-- /? ?The SEO Framework by Sybre Waaijer .*?-->~',
                    'replace' => '',
                ],
                */
                'clean-wp-postratings' => [
                    'search' => '~data-nonce="(\w{10})"~',
                    'replace' => 'data-nonce="0000000000"',
                ],
                'clean-csfr-token' => [
                    'search' => '~<meta name="csrf-token" content=".*?">~',
                    'replace' => '<meta name="csrf-token" content="token">',
                ],
                /*
                'wordpress-cache-busting' => [
                    'search' => '~\?ver=\d\.\d\.\d~',
                    'replace' => '?ver=0.0.0',
                ],
                'wordpress-cache-busting-2' => [
                    'search' => '~\?ver=\d{10}~',
                    'replace' => '?ver=0000000000',
                ],
                'disqus' => [
                    'search' => '~wordpress 3\.1\.3 \d\.\d\.\d~',
                    'replace' => 'wordpress 3.1.3 0.0.0',
                ],
                'clean-end-of-file' => [
                    'search' => '~</html>\r?\n~',
                    'replace' => '</html>',
                ],
                */
                'clean-gravatar' => [
                    'search' => '~https://secure.gravatar.com/avatar/(\w{32,64})~',
                    'replace' => "https://secure.gravatar.com/avatar/00000000000000000000000000000000",
                ],
                'classicpress-cache-busting' => [
                    'search' => '~\?ver=((cp_[a-z0-9]{8})|(\d{10}))?~',
                    'replace' => '?ver=redacted',
                ],
                /* for classicpress migration
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
                    'search' => "~(<link rel='(?:stylesheet|dns-prefetch)' .*?) ?/>~",
                    'replace' => '$1>',
                ],
                'classicpress-5' => [
                    'search' => '~<script(.*?)src="(.*?)" id="(.*?)"~',
                    'replace' => "<script$1src='$2' id='$3'",
                ],
                'classicpress-5-bis' => [
                    'search' => '~<script id="(.*?)">~',
                    'replace' => "<script id='$1'>",
                ],
                'classicpress-6' => [
                    'search' => '~<img loading="lazy" decoding="async"~',
                    'replace' => '<img decoding="async" loading="lazy"',
                ],
                'classicpress-7' => [
                    'search' => '~<img fetchpriority="high" decoding="async"~',
                    'replace' => '<img decoding="async" fetchpriority="high"',
                ],
                'classicpress-8' => [
                    'search' => '~<img (.*?) ?/>~s',
                    'replace' => '<img $1>',
                ],
                'classicpress-9' => [
                    'search' => '~^\t<style>img:is\(\[sizes="auto" i\], \[sizes\^="auto," i\]\) { contain-intrinsic-size: 3000px 1500px }</style>\r?\n\t~m',
                    'replace' => '',
                ],
                'classicpress-10' => [
                    'search' => "~<style id='global-styles-inline-css'>.*?</style>\r?\n~s",
                    'replace' => '',
                ],
                'classicpress-11' => [
                    'search' => '~<script type="speculationrules">.*?</script>~s',
                    'replace' => '',
                ],
                'classicpress-12' => [
                    'search' => '~sizes="auto, ~',
                    'replace' => 'sizes="',
                ],
                'classicpress-13' => [
                    'search' => "~<meta name='robots' content='(.*?)' />~",
                    'replace' => "<meta name='robots' content='$1'>",
                ],
                'classicpress-14' => [
                    'search' => "~<br />~",
                    'replace' => "<br>",
                ],
                'classicpress-14-bis' => [
                    'search' => "~<hr />~",
                    'replace' => "<hr>",
                ],
                'classicpress-15' => [
                */
                //    'search' => '~/\* <!\[CDATA\[ \*/\r?\n~',
                /*    'replace' => '',
                ],
                'classicpress-15-bis' => [
                */
                //    'search' => '~/\* \]\]> \*/\r?\n~',
                /*    'replace' => '',
                ],
                'classicpress-site' => [
                    'search' => '~<body(.*?) class="(home )?(?:wp-singular )?(.*?) wp-theme-.*?">~',
                    'replace' => '<body$1 class="$2$3">',
                ],
                'classicpress-gravatar' => [
                    'search' => "~height='120' width='120' decoding='async'>~",
                    'replace' => "height='120' width='120' loading='lazy' decoding='async'>",
                ],
                'classicpress-19' => [
                    'search' => "~<style id='core-block-supports-inline-css'>.*?</style>~s",
                    'replace' => '',
                ],
                'classicpress-20' => [
                    'search' => '~<meta itemprop="(.*?)" content="(.*?)" />~s',
                    'replace' => '<meta itemprop="$1" content="$2" >',
                ],
                'classicpress-17' => [
                    'search' => '~</footer>(\r?\n)*\r?\n~',
                    'replace' => '</footer>
',
                ],
                'classicpress-disqus' => [
                    'search' => '~wordpress 3\.1\.3 6\.(8|2)\.(2|6)~',
                    'replace' => 'wordpress 3.1.3 redacted',
                ],
                */
                /*
                'date' => [
                    'search' => '~Publié~',
                    'replace' => 'Dernière mise à jour',
                ],
                'classicpress-21' => [
                    'search' => '~ class="post"~s',
                    'replace' => '',
                ],
                'classicpress-22' => [
                    'search' => '~ rel="noopener ?"~s',
                    'replace' => '',
                ],
                'classicpress-23' => [
                    'search' => '~ rel="noreferrer noopener"~s',
                    'replace' => '',
                ],
                'classicpress-24' => [
                    'search' => '~ rel="noopener noreferrer"~s',
                    'replace' => '',
                ],
                */
                /* temporary rules
                'clean-wp-postratings-temp' => [
                    'search' => '~width="12" height="12" ~',
                    'replace' => '',
                ],
                'clean-clearfix-1' => [
                    'search' => '~<div id="page-header" class="clearfix">~',
                    'replace' => '<div id="page-header">',
                ],
                'clean-clearfix-2' => [
                    'search' => '~<article id="post-\d*?" class="clearfix post-\d*? post type-post status-publish format-standard hentry category-.*? (has_thumb)?">~',
                    'replace' => '<article>',
                ],
                'clean-bootstrap-css' => [
                    'search' => <<<TXT
                    ~<link rel='stylesheet' id='bootstrap-css' rel="preload" as="style" href='https://cdnjs\.cloudflare\.com/ajax/libs/twitter-bootstrap/4\.3\.1/css/bootstrap\.min\.css' media='all' integrity="sha256-YLGeXaapI0/5IgZopewRJcFXomhRMlYYjugPLSyNjTY=" crossorigin="anonymous">~
                    TXT,
                    'replace' => <<<TXT
                    <link rel='stylesheet' id='bootstrap-css' rel="preload" as="style" href='https://cdnjs.cloudflare.com/ajax/libs/bootstrap/4.6.2/css/bootstrap.min.css' media='all' integrity="sha512-rt/SrQ4UNIaGfDyEXZtNcyWvQeOq0QLygHluFQcSjaGB04IxWhal71tKuzP6K8eYXYB6vJV4pHkXcmFGGQ1/0w==" crossorigin="anonymous" referrerpolicy="no-referrer">
                    TXT,
                ],
                'clean-bootstrap-js' => [
                    'search' => <<<TXT
                    ~<script defer src='https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/4.3.1/js/bootstrap.min.js' id='bootstrap-js' integrity="sha256-CjSoeELFOcH0/uxWu6mC/Vlrc1AARqbm/jiiImDGV3s=" crossorigin="anonymous"></script>~
                    TXT,
                    'replace' => <<<TXT
                    <script defer src='https://cdnjs.cloudflare.com/ajax/libs/bootstrap/4.6.2/js/bootstrap.min.js' id='bootstrap-js' integrity="sha512-7rusk8kGPFynZWu26OKbTeI+QPoYchtxsmPeBqkHIEXJxeun4yJ4ISYe7C6sz9wdxeE1Gk3VxsIWgCZTc+vX3g==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
                    TXT,
                ],
                'clean-jquery' => [
                    'search' => <<<TXT
                    ~<script src='https://cdnjs\.cloudflare\.com/ajax/libs/jquery/3\.5\.1/jquery\.min\.js' id='jquery-js' integrity="sha512-bLT0Qm9VnAYZDflyKcBaQ2gg0hSYNQrJ8RilYldYQ1FxQYoCLtUjuuRuZo\+fjqhx/qtq/1itJ0C2ejDxltZVFg==" crossorigin="anonymous"></script>~
                    TXT,
                    'replace' => <<<TXT
                    <script src='https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js' id='jquery-js' integrity="sha512-v2CJ7UaYy4JwqLDIrZUI/4hqeoQieOmAZNXBeQyjo21dadnwR+8ZaIJVT8EE2iyI61OV8e6M8PP2/4hpQINQ/g==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
                    TXT,
                ],
                'clean-popper' => [
                    'search' => <<<TXT
                    ~<script defer src='https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.15.0/umd/popper.min.js' id='popper-js' integrity="sha256-fTuUgtT7O2rqoImwjrhDgbXTKUwyxxujIMRIK7TbuNU=" crossorigin="anonymous"></script>~
                    TXT,
                    'replace' => <<<TXT
                    <script defer src='https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.16.1/umd/popper.min.js' id='popper-js' integrity="sha512-ubuT8Z88WxezgSqf3RLuNi5lmjstiJcyezx34yIU2gAHonIi27Na7atqzUZCOoY4CExaoFumzOsFQ2Ch+I/HCw==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
                    TXT,
                ],
                'clean-fontawesome' => [
                    'search' => <<<TXT
                    ~<link rel='stylesheet' id='font-awesome-css' rel="preload" as="style" href='https://cdnjs\.cloudflare\.com/ajax/libs/font-awesome/5\.15\.3/css/all\.min\.css' media='all' integrity="sha512-iBBXm8fW90\+nuLcSKlbmrPcLa0OT92xO1BIsZ\+ywDWZCvqsWgccV3gFoRBv0z\+8dLJgyAHIhR35VZc2oM/gI1w==" crossorigin="anonymous">~
                    TXT,
                    'replace' => <<<TXT
                    <link rel='stylesheet' id='font-awesome-css' rel="preload" as="style" href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.9.0/css/all.min.css' media='all' integrity="sha512-q3eWabyZPc1XTCmF+8/LuE1ozpg5xxn7iO89yfSOd5/oKvyqLngoNGsx8jq92Y8eXJ/IRxQbEC+FGSYxtk2oiw==" crossorigin="anonymous" referrerpolicy="no-referrer">
                    TXT,
                ],
                'cleanup-fullwidth' => [
                    'search' => '~class="fullwidth ~',
                    'replace' => 'class="',
                ],
                'cleanup-read-article' => [
                    'search' => '~class="read-article text-end">Read article~',
                    'replace' => 'class="read-article text-end"> Read article',
                ],
                'cleanup-back-to-top' => [
                    'search' => '~
<!-- Back to top button -->~',
                    'replace' => '<!-- Back to top button -->',
                ],
                'cleanup-back-to-top-two' => [
                    'search' => '~</a></body>~',
                    'replace' => '</a>
</body>',
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
