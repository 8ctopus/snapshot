<?php

declare(strict_types=1);

namespace Oct8pus\Snapshot;

use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

class Helper
{
    protected readonly Client $client;
    protected readonly LoggerInterface $logger;
    protected readonly string $outputDir;

    public function __construct(Client $client, LoggerInterface $logger, string $outputDir)
    {
        $this->client = $client;
        $this->logger = $logger;
        $this->outputDir = rtrim($outputDir, '/');
    }

    /**
     * Remove directory recursively
     *
     * @param string $dir
     * @param bool   $removeRoot
     *
     * @return void
     */
    public static function removeDirectory(string $dir, bool $removeRoot) : void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);

        foreach ($files as $file) {
            $path = "{$dir}/{$file}";

            is_dir($path) ? self::removeDirectory($path, true) : unlink($path);
        }

        if ($removeRoot) {
            rmdir($dir);
        }
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

        if (str_ends_with($path, ".{$extension}")) {
            $path = substr($path, 0, - strlen($extension) -1);
        }

        $query = parse_url($url, PHP_URL_QUERY);

        if (!empty($query)) {
            $query = "[{$query}]";
        }

        return "{$this->outputDir}/pages/{$path}{$query}.{$extension}";
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

                /** @disregard P1010 */
                $body = \brotli_uncompress($body);
                break;

            case '':
                break;

            default:
                throw new RuntimeException("Unsupported content encoding: {$encoding}");
        }

        return $body;
    }
}
