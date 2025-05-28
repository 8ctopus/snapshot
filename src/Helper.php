<?php

declare(strict_types=1);

namespace Oct8pus\Snapshot;

use HttpSoft\Message\Request;
use Nimbly\Shuttle\Shuttle;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

class Helper
{
    protected readonly LoggerInterface $logger;
    protected readonly string $outputDir;

    private readonly Shuttle $client;
    // REM private array $indices;
    private int $index;

    public function __construct(LoggerInterface $logger, string $outputDir)
    {
        $this->logger = $logger;
        $this->outputDir = rtrim($outputDir, '/');

        $this->client = new Shuttle();
        $this->index = 1;
    }

    /**
     * Remove directory recursively
     *
     * @param string $dir
     *
     * @return void
     */
    public static function removeDirectory(string $dir) : void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);

        foreach ($files as $file) {
            $path = "{$dir}/{$file}";

            is_dir($path) ? self::removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
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
        $path = parse_url($url, PHP_URL_PATH);
        $path = $this->urlToPath($path);

        return "{$this->outputDir}/pages/{$path}.{$extension}";
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
     * Get path from url path
     *
     * @param string $url
     *
     * @return string
     */
    protected function urlToPath(string $url) : string
    {
        $url = trim($url, '/');

        if ($url === '') {
            return 'index';
        }

        return $url;
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
}
