<?php

declare (strict_types = 1);

namespace Rhapsody\Core\Commands;

use Symfony\Component\Process\Process;

class MigrateCommand
{
    public string $signature   = 'migrate';
    public string $description = 'Run pending database migrations (powered by Phinx)';

    /**
     * @param string $basePath Application root directory
     */
    public function __construct(private readonly string $basePath)
    {
    }

    /**
     * Extra arguments are passed straight through to Phinx
     * (e.g. `-e production`, `-t 20260101000000`, `--fake`).
     *
     * @param list<string> $args
     */
    public function execute(array $args): void
    {
        echo "\033[34mRhapsody Migration Engine\033[0m\n";
        echo "----------------------------------------\n";

        $process = new Process(
            [
                PHP_BINARY,
                $this->basePath . '/vendor/bin/phinx',
                'migrate',
                '-c',
                $this->basePath . '/phinx.php',
                ...$args,
            ],
            $this->basePath,
        );
        $process->setTimeout(null);

        $exitCode = $process->run(static function (string $type, string $buffer): void {
            echo $buffer;
        });

        if ($exitCode !== 0) {
            echo "\n[\033[31mFAILED\033[0m] Migration halted. See the output above.\n";
            exit($exitCode);
        }

        echo "\n";
    }
}
