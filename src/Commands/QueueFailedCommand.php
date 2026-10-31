<?php

declare (strict_types = 1);

namespace Rhapsody\Core\Commands;

use Rhapsody\Core\Contracts\QueueInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'queue:failed', description: 'List jobs that failed permanently.')]
final class QueueFailedCommand extends Command
{
    public function __construct(private readonly QueueInterface $queue)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $failed = $this->queue->failedJobs();

        if ($failed === []) {
            $output->writeln('<info>No failed jobs.</info>');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($failed as $job) {
            $rows[] = [
                $job->id,
                $job->queue,
                date('Y-m-d H:i:s', $job->failedAt),
                strtok($job->exception, "\n") ?: $job->exception,
            ];
        }

        (new Table($output))
            ->setHeaders(['ID', 'Queue', 'Failed at', 'Reason'])
            ->setRows($rows)
            ->render();

        $output->writeln('Retry with "queue:retry <id>" or "queue:retry all".');

        return Command::SUCCESS;
    }
}
