<?php

declare(strict_types=1);

namespace Oct8pus\Snapshot;

use HttpSoft\Message\Request;
use Nimbly\Shuttle\Handler\CurlHandler;
use Nimbly\Shuttle\Shuttle;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class Client
{
    private readonly string $cacheBusting;
    private readonly ClientInterface $client;

    public function __construct(array $options, string $cacheBusting)
    {
        $default = [];
        $this->cacheBusting = $cacheBusting;

        $this->client = new Shuttle(new CurlHandler($default + $options));
    }

    public function download(RequestInterface $request) : ResponseInterface
    {
        return $this->client->sendRequest($request);
    }

    public function createRequest(string $url) : RequestInterface
    {
        $url .= $this->cacheBusting;

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
}
