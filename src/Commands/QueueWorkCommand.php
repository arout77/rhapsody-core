<?php

declare (strict_types = 1);

namespace Rhapsody\Core\Commands;

use InvalidArgumentException;
use Rhapsody\Core\Queue\JobOutcome;
use Rhapsody\Core\Queue\JobResult;
use Rhapsody\Core\Queue\Worker;
use Rhapsody\Core\Queue\WorkerOptions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'queue:work', description: 'Process queued jobs (a daemon, or use --stop-when-empty from cron).')]
final class QueueWorkCommand extends Command
{
    public function __construct(private readonly Worker $worker)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('queue', 'q', InputOption::VALUE_REQUIRED, 'Queue(s) to process, comma separated, highest priority first', 'default')
            ->addOption('sleep', null, InputOption::VALUE_REQUIRED, 'Seconds to wait when the queues are empty', '3')
            ->addOption('max-jobs', null, InputOption::VALUE_REQUIRED, 'Stop after this many jobs (0 = no limit)', '0')
            ->addOption('max-time', null, InputOption::VALUE_REQUIRED, 'Stop after this many seconds (0 = no limit)', '0')
            ->addOption('memory', null, InputOption::VALUE_REQUIRED, 'Stop when memory use reaches this many MB (0 = no limit)', '128')
            ->addOption('stop-when-empty', null, InputOption::VALUE_NONE, 'Exit once the queues are empty (use this from cron)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $options = new WorkerOptions(
                queues: $this->queues($input),
                sleep: $this->intOption($input, 'sleep'),
                maxJobs: $this->intOption($input, 'max-jobs'),
                maxTime: $this->intOption($input, 'max-time'),
                memoryMb: $this->intOption($input, 'memory'),
                stopWhenEmpty: $input->getOption('stop-when-empty') === true,
            );
        } catch (InvalidArgumentException $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");

            return Command::INVALID;
        }

        $output->writeln('<info>Processing queue "' . implode(',', $options->queues) . '"' . ($options->stopWhenEmpty ? ' until empty' : '') . '.</info>');

        $handled = $this->worker->work($options, function (JobOutcome $outcome) use ($output): void {
            $line = sprintf('[%s] #%d %s', date('H:i:s'), $outcome->id, $outcome->job);

            $output->writeln(match ($outcome->result) {
                JobResult::Processed => "<info>{$line} processed</info>",
                JobResult::Released  => "<comment>{$line} failed, will retry: {$outcome->error}</comment>",
                JobResult::Failed    => "<error>{$line} failed permanently: {$outcome->error}</error>",
            });
        });

        $output->writeln("Worker stopped after {$handled} job(s).");

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function queues(InputInterface $input): array
    {
        $value = $input->getOption('queue');
        $names = is_string($value) ? array_filter(array_map('trim', explode(',', $value)), static fn(string $n): bool => $n !== '') : [];

        return array_values($names);
    }

    private function intOption(InputInterface $input, string $name): int
    {
        $value = $input->getOption($name);
        if (! is_string($value) || preg_match('/^\d+$/', $value) !== 1) {
            throw new InvalidArgumentException("Option --{$name} must be a whole number.");
        }

        return (int) $value;
    }
}
