<?php

declare(strict_types=1);

namespace Oct8pus\Snapshot;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

class Snapshot extends Helper
{
    public function __construct(LoggerInterface $logger, string $outputDir)
    {
        parent::__construct($logger, $outputDir);
    }

    /**
     * Take snapshots
     *
     * @param string[] $urls
     *
     * @return int
     */
    public function takeSnapshots(array $urls) : int
    {
        $count = 0;

        foreach ($urls as $url) {
            if ($this->takeSnapshot($url)) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Take a snapshot
     *
     * @param string $url
     *
     * @return bool
     */
    private function takeSnapshot(string $url) : bool
    {
        $request = $this->createRequest($url);
        $response = $this->download($request);

        $status = $response->getStatusCode();

        if ($status !== 200) {
            $this->logger->error("{$status} - {$url}");
            return false;
        }

        $filename = $this->getFilename($url, 'json');

        $this->saveSnapshot($filename, $request, $response);

        return true;
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
}
