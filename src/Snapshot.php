<?php

declare(strict_types=1);

namespace Oct8pus\Snapshot;

use HttpSoft\Message\Request;
use Nimbly\Shuttle\Shuttle;
use Psr\Http\Message\ResponseInterface;

class Snapshot
{
    private Shuttle $client;
    private string $outputDir;

    public function __construct(string $outputDir)
    {
        $this->client = new Shuttle();
        $this->outputDir = rtrim($outputDir, '/');
    }

    /**
     * Take a snapshot of a single URL
     */
    public function takeSnapshot(string $url, string $timestamp): array
    {
        $request = new Request('GET', $url);

        // Add browser-like headers
        $request = $request
            ->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36')
            ->withHeader('Accept', 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8')
            ->withHeader('Accept-Language', 'en-US,en;q=0.9')
            ->withHeader('Accept-Encoding', 'gzip, deflate, br')
            ->withHeader('Connection', 'keep-alive')
            ->withHeader('Upgrade-Insecure-Requests', '1')
            ->withHeader('Sec-Fetch-Dest', 'document')
            ->withHeader('Sec-Fetch-Mode', 'navigate')
            ->withHeader('Sec-Fetch-Site', 'none')
            ->withHeader('Sec-Fetch-User', '?1')
            ->withHeader('Cache-Control', 'max-age=0');

        $response = $this->client->sendRequest($request);

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException("Failed to fetch {$url}: HTTP {$response->getStatusCode()}");
        }

        $filename = $this->getFilename($url, $timestamp);
        $this->saveSnapshot($filename, $request, $response);

        return [
            'url' => $url,
            'filename' => $filename,
            'status' => $response->getStatusCode(),
            'request_headers' => $request->getHeaders(),
            'response_headers' => $response->getHeaders(),
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
    private function saveSnapshot(string $filename, Request $request, ResponseInterface $response): void
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
            'request' => [
                'method' => $request->getMethod(),
                'url' => (string) $request->getUri(),
                'headers' => $request->getHeaders(),
            ],
            'response' => [
                'status' => $response->getStatusCode(),
                'headers' => $headers,
                'body_file' => basename($filename, '.json') . '.' . $extension
            ]
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