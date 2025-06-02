<?php

declare(strict_types=1);

namespace Oct8pus\Snapshot;

use DiDom\Document;
use DiDom\Query;
use Psr\Log\LoggerInterface;
use RuntimeException;

class Sitemap extends Helper
{
    private readonly string $host;
    private array $links;

    public function __construct(Client $client, LoggerInterface $logger, string $outputDir, string $host)
    {
        parent::__construct($client, $logger, $outputDir);

        $this->host = $host;
        $this->links = [];
    }

    /**
     * Analyze sitemap
     *
     * @param array $urls
     *
     * @return self
     */
    public function analyze(array $urls) : self
    {
        foreach ($urls as $url) {
            if (!str_ends_with($url, '.xml')) {
                throw new RuntimeException('sitemap must have xml extension');
            }

            if (!str_starts_with($url, 'http')) {
                $url = "{$this->host}/{$url}";
            }

            $request = $this->client->createRequest($url);
            $response = $this->client->download($request);
            $status = $response->getStatusCode();

            if ($status !== 200) {
                throw new RuntimeException("download xml - {$status} - {$url}");
            }

            $body = $this->decompressResponse($response);

            $filename = $this->getFilename($url, 'xml');
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
                $response = $this->client->download($this->client->createRequest($url));
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

            $this->links = array_merge($this->links, $links);
        }

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
        echo "sitemap ({$count})\n";

        $links = $this->links;

        if (!$lastUpdated) {
            foreach ($links as $link) {
                echo "{$link['loc']}\n";
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

            echo "{$lastmod} {$link['loc']}\n";
        }

        return $this;
    }

    public function links() : array
    {
        return array_column($this->links, 'loc');
    }
}
