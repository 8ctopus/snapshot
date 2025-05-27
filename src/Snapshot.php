<?php

declare(strict_types=1);

namespace Oct8pus\Snapshot;

use Exception;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

class Snapshot extends Helper
{
    private array $indices = [];

    public function __construct(string $outputDir, string $timestamp)
    {
        parent::__construct($outputDir, $timestamp);
    }

    /**
     * Take snapshots
     *
     * @param string[] $urls
     *
     * @return array<int, array{url: string, filename?: string, status?: int, request_headers?: array, response_headers?: array, error?: string}>
     */
    public function takeSnapshots(array $urls) : array
    {
        $result = [];
        $this->indices = [];

        foreach ($urls as $url) {
            try {
                $result[] = $this->takeSnapshot($url);
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
     * Take a snapshot
     *
     * @param string $url
     *
     * @return array{url: string, filename: string, status: int, request_headers: array, response_headers: array}
     *
     * @throws RuntimeException if the request fails
     */
    private function takeSnapshot(string $url) : array
    {
        $request = $this->createRequest($url);
        $response = $this->download($request);

        $status = $response->getStatusCode();

        if ($status !== 200) {
            throw new RuntimeException("{$url} - {$status}");
        }

        $filename = $this->getFilename($url);

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
     * @param string $url
     *
     * @return string
     */
    protected function getFilename(string $url) : string
    {
        $parsedUrl = parse_url($url);

        $domain = $parsedUrl['host'];
        $path = $this->getPathName($parsedUrl['path'] ?? '/');
        $key = "{$domain}/{$this->timestamp}";

        if (!isset($this->indices[$key])) {
            $this->indices[$key] = 1;
        }

        $index = str_pad((string) $this->indices[$key], 2, '0', STR_PAD_LEFT);
        ++$this->indices[$key];

        return "{$this->outputDir}/{$domain}/{$this->timestamp}/{$index}-{$path}.json";
    }

    /**
     * Clear all snapshots
     *
     * @return void
     */
    public function clear() : void
    {
        if (!is_dir($this->outputDir)) {
            return;
        }

        $this->removeDirectory($this->outputDir);
    }
}
