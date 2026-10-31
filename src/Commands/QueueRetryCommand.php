<?php

declare (strict_types = 1);

namespace Rhapsody\Core\Commands;

use Rhapsody\Core\Commands\Concerns\ReadsInput;
use Rhapsody\Core\Contracts\QueueInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'queue:retry', description: 'Put a failed job (or "all") back on the queue.')]
final class QueueRetryCommand extends Command
{
    use ReadsInput;

    public function __construct(private readonly QueueInterface $queue)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Failed job id, or "all"');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $target = $this->stringArgument($input, 'id');

        if ($target === 'all') {
            $count = 0;
            foreach ($this->queue->failedJobs() as $job) {
                if ($this->queue->retry($job->id)) {
                    $count++;
                }
            }
            $output->writeln("<info>Queued {$count} failed job(s) for retry.</info>");

            return Command::SUCCESS;
        }

        if (preg_match('/^\d+$/', $target) !== 1) {
            $output->writeln('<error>The id must be a failed job number, or "all".</error>');

            return Command::INVALID;
        }

        if (! $this->queue->retry((int) $target)) {
            $output->writeln("<error>No failed job with id {$target}.</error>");

            return Command::FAILURE;
        }

        $output->writeln("<info>Failed job {$target} is back on the queue.</info>");

        return Command::SUCCESS;
    }
}
