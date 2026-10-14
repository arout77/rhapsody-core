<?php
namespace Rhapsody\Core\Commands;

use Rhapsody\Core\Cache;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CacheClearCommand extends Command
{
    // Inject your refactored Cache utility interface or container abstraction
    public function __construct(protected Cache $cache, protected string $basePath)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('cache:clear')->setDescription('Flush the application cache.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->cache->flush();
        $output->writeln('<info>Application cache cleared.</info>');

        $twigCachePath = $this->basePath . '/storage/cache/twig';
        if (is_dir($twigCachePath)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($twigCachePath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                /** @var \SplFileInfo $file */
                if ($file->isDir()) {
                    rmdir($file->getPathname());
                } else {
                    unlink($file->getPathname());
                }
            }
            $output->writeln('<info>Twig template cache cleared.</info>');
        }

        if (function_exists('opcache_reset')) {
            opcache_reset();
            $output->writeln('<info>OPcache invalidated.</info>');
        }

        return Command::SUCCESS;
    }
}
