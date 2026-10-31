<?php

declare (strict_types = 1);

namespace Rhapsody\Core\Commands;

use Rhapsody\Core\Contracts\QueueInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

#[AsCommand(name: 'queue:flush', description: 'Permanently delete every failed job.')]
final class QueueFlushCommand extends Command
{
    public function __construct(private readonly QueueInterface $queue)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Delete without asking for confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('force') !== true) {
            $helper = new QuestionHelper();
            $sure   = $helper->ask($input, $output, new ConfirmationQuestion('Delete ALL failed jobs permanently? [y/N] ', false));

            if ($sure !== true) {
                $output->writeln('Nothing deleted.');

                return Command::SUCCESS;
            }
        }

        $output->writeln('<info>Deleted ' . $this->queue->flush() . ' failed job(s).</info>');

        return Command::SUCCESS;
    }
}
