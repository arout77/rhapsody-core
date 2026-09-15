<?php

namespace Rhapsody\Core\Commands;

use Rhapsody\Core\Events\ModuleBootFailed;
use Rhapsody\Core\Modules\ModuleInstallationStore;
use Rhapsody\Core\Modules\ModuleRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'module:list', description: 'List every discovered rhapsody-module package, whether it is installed, and whether it actually booted.')]
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

        // ModuleRegistry::bootAll() already ran during this process's own
        // bootstrap (see bootstrap.php STEP 2.6), before this command's
        // execute() is even reached — so failures() already reflects
        // whether each installed module actually booted just now, not
        // some stale state from a previous request.
        /** @var array<string, ModuleBootFailed> $failuresByName */
        $failuresByName = [];
        foreach ($this->registry->failures() as $failure) {
            $failuresByName[$failure->manifest->name] = $failure;
        }

        $anyFailures = false;

        foreach ($manifests as $manifest) {
            $installed = $this->installs->isInstalled($manifest->name);
            $failure   = $failuresByName[$manifest->name] ?? null;

            if (! $installed) {
                $status = '<comment>not installed</comment>';
            } elseif ($failure !== null) {
                $status      = sprintf('<error>failed to boot (%s)</error>', $failure->phase);
                $anyFailures = true;
            } else {
                $status = '<info>installed</info>';
            }

            $output->writeln(sprintf('  %-30s v%-10s %s', $manifest->name, $manifest->version, $status));

            if ($failure !== null) {
                $output->writeln(sprintf('      <error>%s</error>', $failure->message));
            }
        }

        if ($anyFailures) {
            $output->writeln('');
            $output->writeln('<error>One or more installed modules failed to boot — see above.</error>');
        }

        return $anyFailures ? Command::FAILURE : Command::SUCCESS;
    }
}
