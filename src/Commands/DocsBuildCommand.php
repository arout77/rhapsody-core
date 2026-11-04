<?php

declare (strict_types = 1);

namespace Rhapsody\Core\Commands;

use Rhapsody\Core\Ai\Docs\DocsIndexBuilder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Rebuilds the bundled docs index that `rhapsody ask` searches.
 *
 * For framework maintainers: the index is committed to core and shipped in
 * the package, so applications never need to run this. Use --check in CI to
 * fail when the docs changed without a rebuild.
 */
class DocsBuildCommand extends Command
{
    public function __construct(private readonly string $coreRoot = '')
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('docs:build')
            ->setDescription('Rebuilds the bundled docs search index (framework maintainers).')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Exit non-zero if the committed index is stale; write nothing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $builder = new DocsIndexBuilder($this->coreRoot !== '' ? $this->coreRoot : dirname(__DIR__, 2));

        if (! is_dir($builder->docsDirectory())) {
            $output->writeln('<error>Docs templates not found at ' . $builder->docsDirectory() . '.</error>');

            return Command::FAILURE;
        }

        if ($input->getOption('check') === true) {
            if ($builder->isFresh()) {
                $output->writeln('<info>Docs index is up to date.</info>');

                return Command::SUCCESS;
            }

            $output->writeln('<error>Docs index is stale. Run `rhapsody docs:build` and commit resources/docs-index/chunks.json.</error>');

            return Command::FAILURE;
        }

        $count = $builder->write();
        $output->writeln("<info>Wrote {$count} chunks to {$builder->indexPath()}</info>");

        return Command::SUCCESS;
    }
}
