<?php

namespace Rhapsody\Core\Commands;

use Rhapsody\Core\Modules\ModuleRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'module:health',
    description: 'Report modules that failed to boot, plus live dependency checks from modules that implement ModuleHealthCheckInterface. Exits non-zero if anything is unhealthy — safe to use as a deploy/cron gate.',
)]
class ModuleHealthCommand extends Command
{
    public function __construct(
        protected ModuleRegistry $registry,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $unhealthy = false;

        // Boot failures — a module that never booted at all.
        foreach ($this->registry->failures() as $failure) {
            $unhealthy = true;
            $output->writeln(sprintf(
                '<error>%s: failed to boot (%s)</error> — %s',
                $failure->manifest->name,
                $failure->phase,
                $failure->message,
            ));
        }

        // Live checks — modules that DID boot but declare their own
        // runtime dependency checks (Redis, an external API key, etc).
        foreach ($this->registry->checkHealth() as $moduleName => $checks) {
            foreach ($checks as $checkName => $result) {
                if ($result['ok']) {
                    $output->writeln(sprintf('<info>%s (%s): ok</info>%s', $moduleName, $checkName, $result['message'] !== '' ? ' — ' . $result['message'] : ''));
                    continue;
                }
                $unhealthy = true;
                $output->writeln(sprintf('<error>%s (%s): FAILED</error>%s', $moduleName, $checkName, $result['message'] !== '' ? ' — ' . $result['message'] : ''));
            }
        }

        if (! $unhealthy) {
            $output->writeln('<info>All modules healthy.</info>');
        }

        return $unhealthy ? Command::FAILURE : Command::SUCCESS;
    }
}
