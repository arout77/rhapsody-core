<?php

namespace Rhapsody\Core\Commands;

use Rhapsody\Core\Modules\ModuleRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'module:uninstall', description: "Run a module's uninstall() hook and deactivate it.")]
class ModuleUninstallCommand extends Command
{
    public function __construct(protected ModuleRegistry $registry)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Package name, e.g. acme/welcome-bonus')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip the confirmation prompt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');

        if (! $input->getOption('force')) {
            $output->write("This runs \"{$name}\"'s uninstall() hook, which may delete its data. Continue? [y/N] ");
            $answer = strtolower(trim((string) fgets(STDIN)));

            if ($answer !== 'y' && $answer !== 'yes') {
                $output->writeln('Aborted.');
                return Command::SUCCESS;
            }
        }

        try {
            $this->registry->uninstall($name);
        } catch (\Throwable $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");
            return Command::FAILURE;
        }

        $output->writeln("<info>Uninstalled \"{$name}\".</info>");
        return Command::SUCCESS;
    }
}
