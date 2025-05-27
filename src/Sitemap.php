<?php

declare(strict_types=1);

namespace Oct8pus\Snapshot;

use DiDom\Document;
use DiDom\Query;
use Exception;
use RuntimeException;

class Sitemap extends Helper
{
    public function __construct(string $outputDir)
    {
        parent::__construct($outputDir);
    }

    /**
     * Run
     *
     * @param  string $url
     *
     * @return void
     */
    public function run(string $url) : void
    {
        if (!str_ends_with($url, '.xml')) {
            throw new Exception('xml');
        }

        $response = $this->download($this->createRequest($url));

        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException("Failed to fetch {$url}: HTTP {$response->getStatusCode()}");
        }

        $body = $this->decompressBody((string) $response->getBody(), $response->getHeaderLine('content-encoding'));
        $links = self::extractLinks($url, $body);

        self::showLinks($links);
        //self::testLinks($links);
    }

    /**
     * Extract sitemap links
     *
     * @param string $url  sitemap link
     * @param string $page sitemap source code
     *
     * @return array
     */
    public function extractLinks(string $url, string $page) : array
    {
        $document = new Document($page, false, 'UTF-8', Document::TYPE_XML);

        // add namespace
        $document->registerNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        // search for sitemaps within sitemap
        $elements = $document->find('/s:sitemapindex/s:sitemap', Query::TYPE_XPATH);

        $urls = [];

        if (!count($elements)) {
            // file itself is sitemap
            $urls[] = $url;
        } else {
            foreach ($elements as $element) {
                // list children sitemaps
                foreach ($element->children() as $child) {
                    $name = $child->getNode()->nodeName;

                    switch ($name) {
                        case 'loc':
                            $urls[] = $child->text();
                            break;

                        default:
                    }
                }
            }
        }

        $links = [];

        // parse sub-sitemaps
        foreach ($urls as $url) {
            $response = $this->download($this->createRequest($url));

            $page = $this->decompressBody((string) $response->getBody(), $response->getHeaderLine('content-encoding'));

            $document = new Document($page, false, 'UTF-8', Document::TYPE_XML);

            // add namespace
            $document->registerNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');

            // get url nodes
            $elements = $document->find('/s:urlset/s:url', Query::TYPE_XPATH);

            // get urls
            foreach ($elements as $element) {
                $link = [];

                // list url children nodes
                foreach ($element->children() as $child) {
                    $name = $child->getNode()->nodeName;

                    switch ($name) {
                        case 'loc':
                            $link[$name] = urldecode($child->text());
                            break;

                        case 'lastmod':
                            $lastmod = $child->text();
                            $link[$name] = strtotime($lastmod);
                            break;

                        default:
                    }
                }

                $links[] = $link;
            }
        }

        // sort sitemap by date
        usort($links, static function (array $a, array $b) {
            if (isset($a['lastmod'], $b['lastmod'])) {
                return -($a['lastmod'] - $b['lastmod']);
            }

            return 0;
        });

        return $links;
    }

    /**
     * Show sitemap links
     *
     * @param array $links
     */
    public function showLinks(array $links) : void
    {
        $count = count($links);
        echo("sitemap ({$count})\n");

        foreach ($links as $link) {
            $lastmod = $link['lastmod'] ?? '';

            if (!empty($lastmod)) {
                $lastmod = date('F j, Y', $lastmod);
            }

            $lastmod = str_pad($lastmod, 18, ' ', STR_PAD_RIGHT);

            echo("{$lastmod}  {$link['loc']}\n");
        }
    }

    /**
     * Test sitemap links
     *
     * @param array $links
     */
    /*
    public function testLinks(array $links) : void
    {
        Logger::title('test links');

        // test sitemap links
        $count = count($links);

        foreach ($links as $index => $link) {
            $url = $link['loc'];
            $page = '';
            $info = [];

            self::updateConsole("test {$index}/{$count} {$url}");

            if (!Curl::download($url, $page, $info, false)) {
                $error = Curl::error($info);
                Logger::error("download url - {$error}");
                continue;
            }

            if ($info['http_code'] !== 200) {
                Helper::logResponseInfo($info, false);
                Logger::warning('Page in sitemap but redirected to a new page.');
                continue;
            }

            $parser = new Parser($url, $page);

            $successes = [];
            $warnings = [];
            $errors = [];

            // FIX ME test not https
            // FIX ME test canonical

            $robots = $parser->robots();

            // check for noindex
            if (count($robots) && strpos($robots[0], 'noindex') !== false) {
                $errors[] = 'Page marked as `noindex` but included in sitemap.';
            }

            // check analytics
            $analytics = $parser->analytics();

            if (in_array('yandex', $analytics, true)) {
                $successes[] = 'Page has Yandex analytics tracker.';
            }

            if (!in_array('google', $analytics, true)) {
                $warnings[] = 'Page does not have Google analytics tracker.';
            }

            if (count($errors) + count($warnings) + count($successes) === 0) {
                continue;
            }

            Helper::logResponseInfo($info, false);

            foreach ($errors as $error) {
                Logger::error($error);
            }

            foreach ($warnings as $warning) {
                Logger::warning($warning);
            }

            foreach ($successes as $success) {
                Logger::note($success);
            }
        }
    }
    */
}
