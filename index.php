<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Oct8pus\Snapshot\Command;

$command = new Command(__DIR__ . '/snapshots');
$command->run($argv);

