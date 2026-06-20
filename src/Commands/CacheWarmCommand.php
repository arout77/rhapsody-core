<?php
namespace Rhapsody\Core\Commands;

use Dotenv\Dotenv;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class CacheWarmCommand extends Command
{
    protected static $defaultName = 'app:cache-warm';

    protected function configure(): void
    {
        $this
            ->setName('app:cache-warm')
            ->setDescription('Warm up the Twig template cache by compiling all templates.')
            ->addOption('method', 'm', InputOption::VALUE_OPTIONAL, 'Only "compile" is supported', 'compile')
            ->addOption('base-url', 'b', InputOption::VALUE_OPTIONAL, 'Not used for compile method');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Starting cache warm... (this may take a moment)</info>');

        try {
            $rootPath = $this->getProjectRoot($output);
        } catch (\RuntimeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $output->writeln("<comment>Project root: $rootPath</comment>");

        if (! file_exists($rootPath . '/.env')) {
            $output->writeln('<comment>.env file not found – using existing environment.</comment>');
        } else {
            $dotenv = Dotenv::createImmutable($rootPath);
            $dotenv->load();
            $output->writeln('<comment>.env file loaded.</comment>');
        }

        $method = $input->getOption('method');
        if ($method !== 'compile') {
            $output->writeln('<error>Only "compile" method is currently supported.</error>');
            return Command::FAILURE;
        }

        return $this->warmViaCompilation($output);
    }

    private function warmViaCompilation(OutputInterface $output): int
    {
        $twig = $this->getTwigFromContainer($output);
        if (! $twig) {
            $output->writeln('<error>Could not resolve Twig environment from container.</error>');
            return Command::FAILURE;
        }

        $loader = $twig->getLoader();
        if (! $loader instanceof FilesystemLoader) {
            $output->writeln('<error>Twig loader is not a FilesystemLoader. Cannot add paths.</error>');
            return Command::FAILURE;
        }

        $rootPath    = $this->getProjectRoot($output);
        $activeTheme = $this->getConfig('theme', 'default', $rootPath);

        // YOUR VIEW PATHS (preserved)
        $viewPaths = [
            $rootPath . '/views/themes/' . $activeTheme,
            $rootPath . '/views/themes/default',
            $rootPath . '/vendor/arout/rhapsody-core/resources/views/themes/default',
        ];

        // Add each existing path to the Twig loader
        foreach ($viewPaths as $path) {
            if (is_dir($path)) {
                $loader->addPath($path);
                $output->writeln("<comment>Added path to loader: $path</comment>");
            } else {
                $output->writeln("<comment>Skipping non-existent path: $path</comment>");
            }
        }

        // Collect templates
        $templates = [];
        foreach ($viewPaths as $basePath) {
            if (! is_dir($basePath)) {
                continue;
            }
            $files = $this->findTwigFiles($basePath);
            foreach ($files as $file) {
                $normalizedFile       = str_replace('\\', '/', $file);
                $normalizedBase       = str_replace('\\', '/', $basePath);
                $relative             = str_replace($normalizedBase . '/', '', $normalizedFile);
                $templates[$relative] = true;
            }
        }

        $templates = array_keys($templates);
        $output->writeln("<comment>Found " . count($templates) . " Twig templates to compile.</comment>");

        if (empty($templates)) {
            $output->writeln('<error>No templates found. Check your theme paths.</error>');
            return Command::FAILURE;
        }

        $success = 0;
        foreach ($templates as $template) {
            try {
                $twig->load($template);
                $output->writeln("  <info>✓</info> $template");
                $success++;
            } catch (\Exception $e) {
                $output->writeln("  <error>✗</error> $template - " . $e->getMessage());
            }
        }

        $output->writeln("<info>Compiled $success / " . count($templates) . " templates.</info>");
        return Command::SUCCESS;
    }

    private function findTwigFiles(string $dir): array
    {
        $files    = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'twig') {
                $files[] = $file->getRealPath();
            }
        }
        return $files;
    }

    private function getTwigFromContainer(OutputInterface $output): ?Environment
    {
        if (isset($GLOBALS['container']) && $GLOBALS['container']->has(Environment::class)) {
            return $GLOBALS['container']->resolve(Environment::class);
        }

        $rootPath = $this->getProjectRoot($output);
        if (file_exists($rootPath . '/bootstrap.php')) {
            $container = require $rootPath . '/bootstrap.php';
            if ($container->has(Environment::class)) {
                return $container->resolve(Environment::class);
            }
        }
        return null;
    }

    private function getConfig(string $key, $default, string $rootPath)
    {
        $configFile = $rootPath . '/config/config.php'; // YOUR FIX – config inside config/
        if (! file_exists($configFile)) {
            return $default;
        }
        $config = require $configFile;
        return $config[$key] ?? $default;
    }

    private function getProjectRoot(OutputInterface $output): string
    {
        $attempts = [];

        // 1. Try current working directory
        $cwd = getcwd();
        if ($cwd) {
            $attempts[] = "cwd: $cwd";
            if (file_exists($cwd . '/config/config.php')) { // YOUR FIX
                return $cwd;
            }
        }

        // 2. Try walking up from the command's __DIR__
        $dir           = __DIR__;
        $maxIterations = 20;
        while ($maxIterations-- > 0) {
            $attempts[] = "walking: $dir";
            if (file_exists($dir . '/config/config.php')) { // YOUR FIX
                return $dir;
            }
            $parent = dirname($dir);
            if ($parent === $dir || in_array($parent, ['/', '\\', 'C:\\', 'D:\\'])) {
                break;
            }
            $dir = $parent;
        }

        throw new \RuntimeException(
            "Could not locate project root (config.php not found).\nTried:\n  " . implode("\n  ", $attempts)
        );
    }
}
