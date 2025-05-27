<?php

declare(strict_types=1);

use NunoMaduro\Collision\Provider;

require_once __DIR__ . '/vendor/autoload.php';

(new Provider())
    ->register();

