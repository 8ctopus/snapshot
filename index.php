<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Oct8pus\Snapshot\Snapshot;

$outputDir = __DIR__ . '/snapshots';
$snapshot = new Snapshot($outputDir);

// if no arguments provided, show help
if (count($argv) <= 1) {
    echo "Usage:\n";
    echo "  php index.php snapshot <url>...\n";
    exit(0);
}

// handle snapshot command
if ($argv[1] === 'snapshot') {
    if (count($argv) <= 2) {
        echo "Error: No URLs provided\n";
        exit(1);
    }

    $timestamp = date('Y-m-d_H-i');
    $urls = array_slice($argv, 2);

    $results = $snapshot->takeSnapshots($urls, $timestamp);

    foreach ($results as $result) {
        if (!isset($result['error'])) {
            echo "Snapshot taken for {$result['url']}\n";
            continue;
        }

        echo "Error for {$result['url']}: {$result['error']}\n";
    }

    exit(0);
}

// unknown command
echo "Error: Unknown command\n";
echo "Use --help to see available commands.\n";
exit(1);

