<?php

declare(strict_types=1);

namespace Oct8pus\Snapshot;

use Clue\Commander\Router;
use Clue\Commander\NoRouteFoundException;

class Command
{
    private Router $router;
    private Snapshot $snapshot;

    public function __construct(string $outputDir)
    {
        $this->snapshot = new Snapshot($outputDir);
        $this->router = new Router();
        $this->setupCommands();
    }

    /**
     * Setup available commands
     */
    private function setupCommands(): void
    {
        // Add help command
        $this->router->add('[--help | -h]', function () : void {
            echo "Usage:\n";
            foreach ($this->router->getRoutes() as $route) {
                echo "  {$route}\n";
            }
        });

        // Add snapshot command
        $this->router->add('snapshot <url>...', function (array $args) : void {
            $timestamp = date('Y-m-d_H-i');
            $urls = $args['url'];

            $results = $this->snapshot->takeSnapshots($urls, $timestamp);

            foreach ($results as $result) {
                if (!isset($result['error'])) {
                    echo "Snapshot taken for {$result['url']}\n";
                    continue;
                }

                echo "Error for {$result['url']}: {$result['error']}\n";
            }
        });
    }

    /**
     * Run the command
     */
    public function run(array $argv): void
    {
        try {
            // If no arguments provided, show help
            if (count($argv) <= 1) {
                $this->router->handleArgv(['', '--help']);
                return;
            }

            $this->router->handleArgv($argv);
        } catch (NoRouteFoundException $e) {
            echo "Error: " . $e->getMessage() . "\n";
            echo "Use --help to see available commands.\n";
            exit(1);
        }
    }
}