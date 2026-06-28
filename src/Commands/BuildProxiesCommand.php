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
    protected static $defaultName = 'build:proxies';

    private string $basePath;

    protected function configure(): void
    {
        $this
            ->setName('build:proxies')
            ->setDescription('Pre-generates lazy proxy classes for all container services.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        global $container;
        if (! isset($container) || ! $container instanceof Container) {
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

        // Get all bound abstract names from the container's bindings.
        $reflection   = new \ReflectionClass($container);
        $bindingsProp = $reflection->getProperty('bindings');
        $bindingsProp->setAccessible(true);
        $bindings = $bindingsProp->getValue($container);

        $count = 0;
        foreach ($bindings as $abstract => $concrete) {
            if (! is_string($abstract) || ! (class_exists($abstract) || interface_exists($abstract))) {
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

    /**
     * Load the container from the core bootstrap.
     * This mimics the logic in index.php.
     */
    private function loadContainer(): ?Container
    {
        // Define ROOT_DIR if not defined.
        if (! defined('ROOT_DIR')) {
            define('ROOT_DIR', $this->basePath);
        }

        // Load environment variables.
        $rootPath = ROOT_DIR;
        $dotenv   = \Dotenv\Dotenv::createImmutable($rootPath);
        $dotenv->load();

        // Include the core bootstrap and return the container.
        $bootstrapPath = ROOT_DIR . '/vendor/arout/rhapsody-core/src/bootstrap.php';
        if (! file_exists($bootstrapPath)) {
            return null;
        }

        // The bootstrap returns the container.
        $container = require $bootstrapPath;
        if ($container instanceof Container) {
            return $container;
        }

        // Fallback: check global.
        global $container;
        if (isset($container) && $container instanceof Container) {
            return $container;
        }

        return null;
    }
}
