<?php

declare(strict_types=1);

namespace Oct8pus\Snapshot;

use Nimbly\Shuttle\Handler\CurlHandler;
use Nimbly\Shuttle\Shuttle;
use Psr\Http\Client\ClientInterface;

class Client
{
    public static function make(array $options = []) : ClientInterface
    {
        $default = [];

        return new Shuttle(new CurlHandler($default + $options));
    }
}
