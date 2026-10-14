<?php
namespace Rhapsody\Core\Commands;

use Rhapsody\Core\Container;
use Rhapsody\Core\Proxy\LazyProxyFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command to pre-generate all lazy proxy classes.
 */
class BuildProxiesCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('build:proxies')
            ->setDescription('Pre-generates lazy proxy classes for all container services.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        global $container;
        if (! $container instanceof Container) {
            $output->writeln('<error>Container not found. Ensure bootstrap.php has been loaded.</error>');
            return Command::FAILURE;
        }

        $output->writeln('<comment>Building lazy proxies...</comment>');

        $basePath      = defined('ROOT_DIR') ? ROOT_DIR : getcwd();
        $proxyCacheDir = $basePath . '/storage/cache/proxies';
        if (! is_dir($proxyCacheDir)) {
            mkdir($proxyCacheDir, 0755, true);
        }

        $factory = new LazyProxyFactory($container, $proxyCacheDir);

        $count = 0;
        foreach ($container->boundAbstracts() as $abstract) {
            if (! class_exists($abstract) && ! interface_exists($abstract)) {
                continue;
            }

            try {
                $factory->create($abstract);
                $output->writeln("  <info>Generated proxy for:</info> $abstract");
                $count++;
            } catch (\Exception $e) {
                $output->writeln("  <error>Failed for $abstract:</error> " . $e->getMessage());
            }
        }

        $output->writeln("<info>Done. Generated $count proxies.</info>");
        return Command::SUCCESS;
    }
}
