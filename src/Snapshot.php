<?php

declare(strict_types=1);

namespace Oct8pus\Snapshot;

use HttpSoft\Message\Request;
use Nimbly\Shuttle\Shuttle;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

class Snapshot
{
    private Shuttle $client;
    private string $outputDir;
    private array $indices = [];

    public function __construct(string $outputDir)
    {
        $this->client = new Shuttle();
        $this->outputDir = rtrim($outputDir, '/');
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
        $this->indices = [];

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
     * Take a snapshot of a single URL
     *
     * @param string $url       the url to snapshot
     * @param string $timestamp the timestamp for the snapshot
     *
     * @throws RuntimeException if the request fails
     *
     * @return array{url: string, filename: string, status: int, request_headers: array, response_headers: array}
     */
    private function takeSnapshot(string $url, string $timestamp): array
    {
        $request = new Request('GET', $url);

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

        $body = $this->decompressResponse($body, $response);

        $contentType = $response->getHeaderLine('content-type') ?: 'text/plain';
        $extension = $this->getFileExtension($contentType);

        if (!is_dir(dirname($filename))) {
            mkdir(dirname($filename), 0777, true);
        }

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

        $bodyFilename = dirname($filename) . '/' . basename($filename, '.json') . '.' . $extension;
        file_put_contents($bodyFilename, $body);
    }

    /**
     * Decompress response body
     *
     * @param string            $body     The body to decompress
     * @param ResponseInterface $response The response object
     *
     * @return string The decompressed body
     */
    private function decompressResponse(string $body, ResponseInterface $response): string
    {
        $contentEncoding = $response->getHeaderLine('content-encoding');
        if ($contentEncoding) {
            return $this->decompressBody($body, $contentEncoding);
        }

        $transferEncoding = $response->getHeaderLine('transfer-encoding');
        if ($transferEncoding && strpos($transferEncoding, 'gzip') !== false) {
            return \gzdecode($body);
        }

        // check for gzip magic number
        if (substr($body, 0, 2) === "\x1f\x8b") {
            return \gzdecode($body);
        }

        return $body;
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
        $parsedUrl = parse_url($url);

        $domain = $parsedUrl['host'];
        $path = $this->getPathName($parsedUrl['path'] ?? '/');
        $key = "{$domain}/{$timestamp}";

        if (!isset($this->indices[$key])) {
            $this->indices[$key] = 1;
        }

        $index = str_pad((string) $this->indices[$key], 2, '0', STR_PAD_LEFT);
        $this->indices[$key]++;

        return "{$this->outputDir}/{$domain}/{$timestamp}/{$index}-{$path}.json";
    }

    /**
     * Get path name from URL path
     *
     * @param string $path The path to extract name from
     *
     * @return string The extracted path name
     */
    private function getPathName(string $path): string
    {
        $path = trim($path, '/');

        if ($path === '') {
            return 'index';
        }

        return str_replace('/', '_', $path);
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

        $mappings = [
            'text/html' => 'html',
            'application/xhtml+xml' => 'html',
            'application/json' => 'json',
            'text/plain' => 'txt',
            'text/xml' => 'xml',
            'application/xml' => 'xml',
            'text/css' => 'css',
            'application/javascript' => 'js',
            'text/javascript' => 'js',
        ];

        foreach ($mappings as $type => $extension) {
            if (strpos($contentType, $type) !== false) {
                return $extension;
            }
        }

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
        $encodings = array_map('trim', explode(',', $contentEncoding));

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
