<?php

namespace Rhapsody\Core\Commands;

use Rhapsody\Core\Modules\ModuleInstallationStore;
use Rhapsody\Core\Modules\ModuleRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'module:list', description: 'List every discovered rhapsody-module package and whether it is installed.')]
class ModuleListCommand extends Command
{
    public function __construct(
        protected ModuleRegistry $registry,
        protected ModuleInstallationStore $installs,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $manifests = $this->registry->discover();

        if (empty($manifests)) {
            $output->writeln('<comment>No rhapsody-module packages found. Run "composer require" to add one.</comment>');
            return Command::SUCCESS;
        }

        foreach ($manifests as $manifest) {
            $status = $this->installs->isInstalled($manifest->name)
                ? '<info>installed</info>'
                : '<comment>not installed</comment>';

            $output->writeln(sprintf('  %-30s v%-10s %s', $manifest->name, $manifest->version, $status));
        }

        return Command::SUCCESS;
    }
}
