<?php

declare(strict_types=1);

namespace Oct8pus\Snapshot;

use HttpSoft\Message\Request;
use InvalidArgumentException;
use Nimbly\Shuttle\Shuttle;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

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
     *
     * @param string $url       the url to snapshot
     * @param string $timestamp the timestamp for the snapshot
     *
     * @throws RuntimeException if the request fails
     *
     * @return array{url: string, filename: string, status: int, request_headers: array, response_headers: array}
     */
    public function takeSnapshot(string $url, string $timestamp): array
    {
        $request = new Request('GET', $url);

        // add browser-like headers
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
            throw new RuntimeException("Failed to fetch {$url}: HTTP {$response->getStatusCode()}");
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
     * @param string[] $urls      the urls to snapshot
     * @param string   $timestamp the timestamp for the snapshots
     *
     * @return array<int, array{url: string, filename?: string, status?: int, request_headers?: array, response_headers?: array, error?: string}>
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
     *
     * @param string   $filename The filename to save to
     * @param Request  $request  The request object
     * @param ResponseInterface $response The response object
     */
    private function saveSnapshot(string $filename, Request $request, ResponseInterface $response): void
    {
        $headers = $response->getHeaders();
        $body = (string) $response->getBody();

        // check for compression in various headers
        $contentEncoding = $headers['content-encoding'][0] ?? null;
        $transferEncoding = $headers['transfer-encoding'][0] ?? null;

        // handle compression
        if ($contentEncoding) {
            $body = $this->decompressBody($body, $contentEncoding);
        } elseif ($transferEncoding && strpos($transferEncoding, 'gzip') !== false) {
            $body = \gzdecode($body);
        } elseif (substr($body, 0, 2) === "\x1f\x8b") { // check for gzip magic number
            $body = \gzdecode($body);
        }

        // get content type from headers
        $contentType = $headers['content-type'][0] ?? 'text/plain';
        $extension = $this->getFileExtension($contentType);

        // create directory if it doesn't exist
        if (!is_dir(dirname($filename))) {
            mkdir(dirname($filename), 0777, true);
        }

        // save headers to json file
        $headersData = [
            'request' => [
                'method' => $request->getMethod(),
                'url' => (string) $request->getUri(),
                'headers' => $request->getHeaders(),
            ],
            'response' => [
                'status' => $response->getStatusCode(),
                'headers' => $headers,
                'body_file' => basename($filename, '.json') . '.' . $extension,
            ],
        ];
        file_put_contents($filename, json_encode($headersData, JSON_PRETTY_PRINT));

        // save body to separate file
        $bodyFilename = dirname($filename) . '/' . basename($filename, '.json') . '.' . $extension;
        file_put_contents($bodyFilename, $body);
    }

    /**
     * Generate filename for snapshot
     *
     * @param string $url       The URL to snapshot
     * @param string $timestamp The timestamp for the snapshot
     *
     * @return string The generated filename
     */
    private function getFilename(string $url, string $timestamp): string
    {
        $urlHash = md5($url);
        $domain = $this->extractDomain($url);

        return "{$this->outputDir}/{$domain}/{$timestamp}/{$urlHash}.json";
    }

    /**
     * Extract domain from URL
     *
     * @param string $url The URL to extract domain from
     *
     * @throws InvalidArgumentException If the URL is invalid
     *
     * @return string The extracted domain
     */
    private function extractDomain(string $url): string
    {
        $parsedUrl = parse_url($url);
        if (!isset($parsedUrl['host'])) {
            throw new InvalidArgumentException("Invalid URL: {$url}");
        }

        // remove www. prefix if present
        $domain = preg_replace('/^www\./', '', $parsedUrl['host']);

        // replace dots with underscores to avoid filesystem issues
        return str_replace('.', '_', $domain);
    }

    /**
     * Get file extension based on content type
     *
     * @param string $contentType The content type to get extension for
     *
     * @return string The file extension
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

        // default to txt if content type is unknown
        return 'txt';
    }

    /**
     * Decompress response body based on content encoding
     *
     * @param string $body            The body to decompress
     * @param string $contentEncoding The content encoding to use
     *
     * @throws RuntimeException If the content encoding is not supported
     *
     * @return string The decompressed body
     */
    private function decompressBody(string $body, string $contentEncoding): string
    {
        $contentEncoding = strtolower($contentEncoding);

        // handle multiple encodings (e.g., "gzip, deflate")
        $encodings = array_map('trim', explode(',', $contentEncoding));

        // apply decompression in reverse order
        foreach (array_reverse($encodings) as $encoding) {
            switch ($encoding) {
                case 'gzip':
                    $body = \gzdecode($body);
                    break;

                case 'deflate':
                    $body = \gzinflate($body);
                    break;

                case 'br':
                    if (function_exists('\brotli_uncompress')) {
                        $body = \brotli_uncompress($body);
                    } else {
                        throw new RuntimeException('Brotli decompression is not available. Please install the brotli extension.');
                    }
                    break;

                default:
                    throw new RuntimeException("Unsupported content encoding: {$encoding}");
            }
        }

        return $body;
    }
}