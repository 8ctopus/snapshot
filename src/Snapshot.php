<?php

declare(strict_types=1);

namespace Oct8pus\Snapshot;

use Nimbly\Shuttle\Shuttle;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Nimbly\Capsule\Factory\RequestFactory;

class Snapshot
{
    private Shuttle $client;
    private string $outputDir;
    private RequestFactory $requestFactory;

    public function __construct(string $outputDir)
    {
        $this->client = new Shuttle();
        $this->outputDir = rtrim($outputDir, '/');
        $this->requestFactory = new RequestFactory();
    }

    /**
     * Take a snapshot of a single URL
     */
    public function takeSnapshot(string $url, string $timestamp): array
    {
        $request = $this->requestFactory->createRequest('GET', $url);
        $response = $this->client->sendRequest($request);

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException("Failed to fetch {$url}: HTTP {$response->getStatusCode()}");
        }

        $filename = $this->getFilename($url, $timestamp);
        $this->saveSnapshot($filename, $response);

        return [
            'url' => $url,
            'filename' => $filename,
            'status' => $response->getStatusCode(),
            'headers' => $response->getHeaders(),
        ];
    }

    /**
     * Take snapshots of multiple URLs
     *
     * @param string[] $urls
     * @return array
     */
    public function takeSnapshots(array $urls, string $timestamp): array
    {
        $results = [];

        foreach ($urls as $url) {
            try {
                $results[] = $this->takeSnapshot($url, $timestamp);
            } catch (\Exception $e) {
                $results[] = [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Save snapshot to file
     */
    private function saveSnapshot(string $filename, ResponseInterface $response): void
    {
        $headers = $response->getHeaders();
        $body = (string) $response->getBody();

        // Check for compression in various headers
        $contentEncoding = $headers['content-encoding'][0] ?? null;
        $transferEncoding = $headers['transfer-encoding'][0] ?? null;

        // Handle compression
        if ($contentEncoding) {
            $body = $this->decompressBody($body, $contentEncoding);
        } elseif ($transferEncoding && strpos($transferEncoding, 'gzip') !== false) {
            $body = gzdecode($body);
        } elseif (substr($body, 0, 2) === "\x1f\x8b") { // Check for gzip magic number
            $body = gzdecode($body);
        }

        // Get content type from headers
        $contentType = $headers['content-type'][0] ?? 'text/plain';
        $extension = $this->getFileExtension($contentType);

        // Create directory if it doesn't exist
        if (!is_dir(dirname($filename))) {
            mkdir(dirname($filename), 0777, true);
        }

        // Save headers to JSON file
        $headersData = [
            'headers' => $headers,
            'body_file' => basename($filename, '.json') . '.' . $extension
        ];
        file_put_contents($filename, json_encode($headersData, JSON_PRETTY_PRINT));

        // Save body to separate file
        $bodyFilename = dirname($filename) . '/' . basename($filename, '.json') . '.' . $extension;
        file_put_contents($bodyFilename, $body);
    }

    /**
     * Decompress response body based on content encoding
     */
    private function decompressBody(string $body, string $contentEncoding): string
    {
        $contentEncoding = strtolower($contentEncoding);

        // Handle multiple encodings (e.g., "gzip, deflate")
        $encodings = array_map('trim', explode(',', $contentEncoding));

        // Apply decompression in reverse order
        foreach (array_reverse($encodings) as $encoding) {
            switch ($encoding) {
                case 'gzip':
                    $body = gzdecode($body);
                    break;

                case 'deflate':
                    $body = gzinflate($body);
                    break;

                case 'br':
                    if (function_exists('brotli_uncompress')) {
                        $body = brotli_uncompress($body);
                    } else {
                        throw new \RuntimeException('Brotli decompression is not available. Please install the brotli extension.');
                    }
                    break;

                default:
                    throw new \RuntimeException("Unsupported content encoding: {$encoding}");
            }
        }

        return $body;
    }

    /**
     * Get file extension based on content type
     */
    private function getFileExtension(string $contentType): string
    {
        $contentType = strtolower($contentType);

        if (strpos($contentType, 'text/html') !== false) {
            return 'html';
        }

        if (strpos($contentType, 'application/json') !== false) {
            return 'json';
        }

        if (strpos($contentType, 'text/plain') !== false) {
            return 'txt';
        }

        if (strpos($contentType, 'text/xml') !== false || strpos($contentType, 'application/xml') !== false) {
            return 'xml';
        }

        if (strpos($contentType, 'text/css') !== false) {
            return 'css';
        }

        if (strpos($contentType, 'application/javascript') !== false || strpos($contentType, 'text/javascript') !== false) {
            return 'js';
        }

        // Default to txt if content type is unknown
        return 'txt';
    }

    /**
     * Generate filename for snapshot
     */
    private function getFilename(string $url, string $timestamp): string
    {
        $urlHash = md5($url);
        $domain = $this->extractDomain($url);
        return "{$this->outputDir}/{$domain}/{$timestamp}/{$urlHash}.json";
    }

    /**
     * Extract domain from URL
     */
    private function extractDomain(string $url): string
    {
        $parsedUrl = parse_url($url);
        if (!isset($parsedUrl['host'])) {
            throw new \InvalidArgumentException("Invalid URL: {$url}");
        }

        // Remove www. prefix if present
        $domain = preg_replace('/^www\./', '', $parsedUrl['host']);

        // Replace dots with underscores to avoid filesystem issues
        return str_replace('.', '_', $domain);
    }
}