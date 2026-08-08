<?php

namespace Rhapsody\Core\Commands;

use Rhapsody\Core\Modules\ModuleRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'module:install', description: "Run a module's install() hook and activate it.")]
class ModuleInstallCommand extends Command
{
    public function __construct(protected ModuleRegistry $registry)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Package name, e.g. acme/welcome-bonus');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');

        try {
            $this->registry->install($name);
        } catch (\Throwable $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");
            return Command::FAILURE;
        }

        $output->writeln("<info>Installed \"{$name}\".</info> It will boot on the next request.");
        return Command::SUCCESS;
    }
}
