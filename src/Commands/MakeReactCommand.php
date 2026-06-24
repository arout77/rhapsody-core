<?php

namespace Rhapsody\Core\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * MakeReactCommand
 *
 * Generates a new React component file in resources/js/components/.
 * Supports nested paths via forward-slash notation.
 *
 * Usage:
 *   php rhapsody make:react Dashboard
 *   php rhapsody make:react Users/ProfileCard
 *
 * The second example creates:
 *   resources/js/components/Users/ProfileCard.jsx
 *
 * And the controller would mount it as:
 *   $this->react('Users/ProfileCard', $props)
 */
class MakeReactCommand extends Command
{
    protected static $defaultName = 'make:react';

    public function __construct(protected string $basePath)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('make:react')
            ->setDescription('Scaffolds a new React component in resources/js/components/.')
            ->setHelp(
                "Creates a new .jsx component file using the Rhapsody component stub.\n\n" .
                "Supports nested directories using forward-slash notation:\n" .
                "  <info>php rhapsody make:react Users/ProfileCard</info>\n" .
                "  Creates: resources/js/components/Users/ProfileCard.jsx"
            )
            ->addArgument(
                'name',
                InputArgument::REQUIRED,
                'Component name or path (e.g. Dashboard, Users/ProfileCard)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = trim((string) $input->getArgument('name'), '/\\');

        // Normalise directory separators
        $name = str_replace('\\', '/', $name);

        // Split into optional sub-directory path + the bare component name
        $parts         = explode('/', $name);
        $componentName = array_pop($parts);          // e.g. "ProfileCard"
        $subDir        = implode('/', $parts);        // e.g. "Users" (may be empty)

        // Enforce PascalCase (React component names must start with an uppercase letter)
        if (!preg_match('/^[A-Z][A-Za-z0-9]*$/', $componentName)) {
            $output->writeln(
                "<error>Component names must be PascalCase (e.g. Dashboard, UserProfile).</error>"
            );
            return Command::FAILURE;
        }

        $baseDir  = $this->basePath . '/resources/js/components';
        $dir      = $subDir ? $baseDir . '/' . $subDir : $baseDir;
        $filepath = $dir . '/' . $componentName . '.jsx';

        // Detect the stub
        $stubPath = dirname(__DIR__, 2) . '/resources/stubs/react-component.stub';

        if (!file_exists($stubPath)) {
            $output->writeln('<error>React component stub not found at: ' . $stubPath . '</error>');
            $output->writeln('<comment>Re-run "php rhapsody react:install" to restore framework stubs.</comment>');
            return Command::FAILURE;
        }

        if (file_exists($filepath)) {
            $output->writeln("<error>Component already exists:</error> {$filepath}");
            return Command::FAILURE;
        }

        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            $output->writeln("<error>Could not create directory:</error> {$dir}");
            return Command::FAILURE;
        }

        $stub    = (string) file_get_contents($stubPath);
        $content = str_replace('{{ componentname }}', $componentName, $stub);

        if (file_put_contents($filepath, $content) === false) {
            $output->writeln('<error>Failed to write component file.</error>');
            return Command::FAILURE;
        }

        $relPath = 'resources/js/components/' . ($subDir ? $subDir . '/' : '') . $componentName . '.jsx';

        $output->writeln('');
        $output->writeln("  <info>✔ Component created:</info> {$relPath}");
        $output->writeln('');
        $output->writeln('  Mount it from a controller with:');
        $output->writeln("  <info>\$this->react('{$name}', \$props);</info>");
        $output->writeln('');

        return Command::SUCCESS;
    }
}
