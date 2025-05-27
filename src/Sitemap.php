<?php

declare(strict_types=1);

namespace Oct8pus\Snapshot;

use DiDom\Document;
use DiDom\Query;
use RuntimeException;

class Sitemap extends Helper
{
    private readonly string $url;
    private readonly array $links;

    public function __construct(string $url, string $outputDir, string $timestamp)
    {
        $this->url = $url;

        parent::__construct($outputDir, $timestamp);
    }

    /**
     * Analyze sitemap
     *
     * @return self
     */
    public function analyze() : self
    {
        if (!str_ends_with($this->url, '.xml')) {
            throw new RuntimeException('sitemap must have xml extension');
        }

        $response = $this->download($this->createRequest($this->url));
        $status = $response->getStatusCode();

        if ($status !== 200) {
            throw new RuntimeException("{$this->url} - {$status}");
        }

        $body = $this->decompressResponse($response);

        $filename = $this->getFilename($this->url, 'xml');
        $dir = dirname($filename);

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($filename, $body);

        $document = new Document($body, false, 'UTF-8', Document::TYPE_XML);

        // add namespace
        $document->registerNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        // search for sitemaps within sitemap
        $elements = $document->find('/s:sitemapindex/s:sitemap', Query::TYPE_XPATH);

        $urls = [];

        if (!count($elements)) {
            // file itself is sitemap
            $urls[] = $this->url;
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
            $page = $this->decompressResponse($response);

            // save sub-sitemap
            $filename = $this->getFilename($url, 'xml');
            $dir = dirname($filename);

            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }

            file_put_contents($filename, $page);

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

        $this->links = $links;
        return $this;
    }

    /**
     * Show sitemap links
     *
     * @param bool $lastUpdated
     */
    public function show(bool $lastUpdated) : self
    {
        $count = count($this->links);
        echo("sitemap ({$count})\n");

        $links = $this->links;

        if (!$lastUpdated) {
            foreach ($links as $link) {
                echo("{$link['loc']}\n");
            }

            return $this;
        }

        // sort sitemap by date
        usort($links, static function (array $a, array $b) {
            if (isset($a['lastmod'], $b['lastmod'])) {
                return -($a['lastmod'] - $b['lastmod']);
            }

            return 0;
        });

        foreach ($links as $link) {
            $lastmod = $link['lastmod'] ?? '';

            if (!empty($lastmod)) {
                $lastmod = date('F j, Y', $lastmod);
            }

            $lastmod = str_pad($lastmod, 18, ' ', STR_PAD_RIGHT);

            echo("{$lastmod} {$link['loc']}\n");
        }

        return $this;
    }

    public function links() : array
    {
        return $this->links;
    }
}
