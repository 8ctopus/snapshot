<?php

declare(strict_types=1);

namespace Oct8pus\Snapshot;

use HttpSoft\Message\Request;
use Nimbly\Shuttle\Shuttle;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

class Helper
{
    protected readonly string $timestamp;
    protected readonly string $outputDir;

    private readonly Shuttle $client;
    private array $indices;

    public function __construct(string $outputDir, string $timestamp)
    {
        $this->outputDir = rtrim($outputDir, '/');
        $this->timestamp = $timestamp;

        $this->client = new Shuttle();
        $this->indices = [];
    }

    protected function createRequest(string $url) : RequestInterface
    {
        return (new Request('GET', $url))
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
    }

    protected function download(RequestInterface $request) : ResponseInterface
    {
        return $this->client->sendRequest($request);
    }

    /**
     * Generate filename
     *
     * @param string $url
     * @param string $extension
     *
     * @return string
     */
    protected function getFilename(string $url, string $extension) : string
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

        return "{$this->outputDir}/{$domain}/{$this->timestamp}/{$index}-{$path}.{$extension}";
    }

    /**
     * Get file extension based on content type
     *
     * @param string $contentType The content type to get extension for
     *
     * @return string The file extension
     */
    protected function getFileExtension(string $contentType) : string
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
     * Get path name from URL path
     *
     * @param string $path The path to extract name from
     *
     * @return string The extracted path name
     */
    protected function getPathName(string $path) : string
    {
        $path = trim($path, '/');

        if ($path === '') {
            return 'index';
        }

        return str_replace('/', '_', $path);
    }

    /**
     * Decompress response body
     *
     * @param ResponseInterface $response The response object
     *
     * @return string The decompressed body
     */
    protected function decompressResponse(ResponseInterface $response) : string
    {
        $body = (string) $response->getBody();
        $encoding = $response->getHeaderLine('content-encoding');

        switch ($encoding) {
            case 'gzip':
                $body = \gzdecode($body);
                break;

            case 'deflate':
                $body = \gzinflate($body);
                break;

            case 'br':
                if (!function_exists('\brotli_uncompress')) {
                    throw new RuntimeException('Brotli decompression is not available. Please install the brotli extension.');
                }

                $body = \brotli_uncompress($body);
                break;

            default:
                throw new RuntimeException("Unsupported content encoding: {$encoding}");
        }

        return $body;
    }

    /**
     * Remove directory recursively
     *
     * @param string $dir
     *
     * @return void
     */
    protected function removeDirectory(string $dir) : void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);

        foreach ($files as $file) {
            $path = "{$dir}/{$file}";

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
