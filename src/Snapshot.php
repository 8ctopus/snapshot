<?php

declare(strict_types=1);

namespace Oct8pus\Snapshot;

use Exception;
use HttpSoft\Message\Request;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

class Snapshot extends Helper
{
    private array $indices = [];

    public function __construct(string $outputDir)
    {
        parent::__construct($outputDir);
    }

    /**
     * Take snapshots of multiple URLs
     *
     * @param string[] $urls      the urls to snapshot
     * @param string   $timestamp the timestamp for the snapshots
     *
     * @return array<int, array{url: string, filename?: string, status?: int, request_headers?: array, response_headers?: array, error?: string}>
     */
    public function takeSnapshots(array $urls, string $timestamp) : array
    {
        $result = [];
        $this->indices = [];

        foreach ($urls as $url) {
            try {
                $result[] = $this->takeSnapshot($url, $timestamp);
            } catch (Exception $e) {
                $result[] = [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $result;
    }

    /**
     * Take a snapshot of a single URL
     *
     * @param string $url       the url to snapshot
     * @param string $timestamp the timestamp for the snapshot
     *
     * @return array{url: string, filename: string, status: int, request_headers: array, response_headers: array}
     *
     * @throws RuntimeException if the request fails
     */
    private function takeSnapshot(string $url, string $timestamp) : array
    {
        $request = $this->createRequest($url);
        $response = $this->download($request);

        $status = $response->getStatusCode();

        if ($status !== 200) {
            throw new RuntimeException("{$url} - {$status}");
        }

        $filename = $this->getFilename($url, $timestamp);

        $this->saveSnapshot($filename, $request, $response);

        return [
            'url' => $url,
            'filename' => $filename,
            'status' => $status,
            'request_headers' => $request->getHeaders(),
            'response_headers' => $response->getHeaders(),
        ];
    }

    /**
     * Save snapshot to file
     *
     * @param string            $filename
     * @param RequestInterface  $request
     * @param ResponseInterface $response
     */
    private function saveSnapshot(string $filename, RequestInterface $request, ResponseInterface $response) : void
    {
        $dir = dirname($filename);

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $type = $response->getHeaderLine('content-type');
        $extension = $this->getFileExtension($type);
        $contentFile = basename($filename, '.json') . '.' . $extension;

        $headers = [
            'request' => [
                'method' => $request->getMethod(),
                'url' => (string) $request->getUri(),
                'headers' => $request->getHeaders(),
            ],
            'response' => [
                'status' => $response->getStatusCode(),
                'headers' => $response->getHeaders(),
                'contentFile' => $contentFile,
            ],
        ];

        file_put_contents($filename, json_encode($headers, JSON_PRETTY_PRINT));

        file_put_contents("{$dir}/{$contentFile}", $this->decompressResponse($response));
    }

    /**
     * Generate filename
     *
     * @param string $url       The URL to snapshot
     * @param string $timestamp The timestamp for the snapshot
     *
     * @return string The generated filename
     */
    protected function getFilename(string $url, string $timestamp) : string
    {
        $parsedUrl = parse_url($url);

        $domain = $parsedUrl['host'];
        $path = $this->getPathName($parsedUrl['path'] ?? '/');
        $key = "{$domain}/{$timestamp}";

        if (!isset($this->indices[$key])) {
            $this->indices[$key] = 1;
        }

        $index = str_pad((string) $this->indices[$key], 2, '0', STR_PAD_LEFT);
        ++$this->indices[$key];

        return "{$this->outputDir}/{$domain}/{$timestamp}/{$index}-{$path}.json";
    }
}
