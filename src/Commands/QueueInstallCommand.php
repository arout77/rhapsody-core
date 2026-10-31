<?php

declare (strict_types = 1);

namespace Rhapsody\Core\Commands;

use Rhapsody\Core\Helpers\Path;
use Rhapsody\Core\Support\Fs;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

#[AsCommand(name: 'queue:install', description: 'Publish the queue tables migration into db/migrations.')]
final class QueueInstallCommand extends Command
{
    private const string SUFFIX = '_create_queue_tables.php';

    protected function configure(): void
    {
        $this->addOption('migrate', null, InputOption::VALUE_NONE, 'Run the migrations straight after publishing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $directory = Path::root('db/migrations');
        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            $output->writeln("<error>Could not create {$directory}.</error>");

            return Command::FAILURE;
        }

        $existing = Fs::glob($directory . '/*' . self::SUFFIX);

        if ($existing !== []) {
            $output->writeln('<comment>The queue migration is already published (' . basename($existing[0]) . ').</comment>');
        } else {
            $file = $directory . '/' . date('YmdHis') . self::SUFFIX;
            Fs::write($file, Fs::read(__DIR__ . '/../Queue/stubs/create_queue_tables.php.stub'));
            $output->writeln('<info>Published ' . basename($file) . '.</info>');
        }

        if ($input->getOption('migrate') !== true) {
            $output->writeln('Run "php rhapsody migrate" to create the tables.');

            return Command::SUCCESS;
        }

        $process = new Process(
            [PHP_BINARY, Path::root('vendor/bin/phinx'), 'migrate', '-c', Path::root('phinx.php')],
            Path::root()
        );
        $process->setTimeout(null);

        $exitCode = $process->run(static function (string $type, string $buffer) use ($output): void {
            $output->write($buffer);
        });

        return $exitCode === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
