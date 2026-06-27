<?php
namespace Rhapsody\Core\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * ReactInstallCommand
 *
 * Scaffolds the full React + Vite integration into a Rhapsody application.
 *
 * Usage:
 *   php rhapsody react:install
 *
 * What it creates:
 *   vite.config.js                                 — Vite build configuration
 *   package.json                                   — npm manifest with React + Vite dependencies
 *   resources/js/app.jsx                           — JS entry point (the Rhapsody bridge)
 *   resources/js/components/Welcome.jsx            — Example component
 *   resources/js/components/Toolbar/MemoryProfiler.jsx   — Required by the debug toolbar
 *   resources/js/components/Toolbar/PerformancePanel.jsx — Required by the debug toolbar
 *
 * What it patches:
 *   .env / .env.example  — Appends VITE_DEV_SERVER and VITE_PORT vars
 */
class ReactInstallCommand extends Command
{
    protected static $defaultName = 'react:install';

    public function __construct(protected string $basePath)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('react:install')
            ->setDescription('Scaffolds the React + Vite integration for your Rhapsody application.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('');
        $output->writeln('<info>  Rhapsody React Integration Installer</info>');
        $output->writeln('  <comment>────────────────────────────────────────</comment>');
        $output->writeln('');

        $stubsDir = dirname(__DIR__, 2) . '/resources/stubs';

        // Map: stub filename => destination in the developer's app
        $scaffolds = [
            'vite-config.stub'               => 'vite.config.js',
            'react-package.stub'             => 'package.json',
            'react-entry.stub'               => 'resources/js/app.jsx',
            'react-component.stub'           => 'resources/js/components/Welcome.jsx',
            // Toolbar islands — required for the debug toolbar's React features.
            // Placed in a Toolbar/ subdirectory so they don't clutter the app's
            // own component list and are clearly identifiable as framework-internal.
            'toolbar-memory-profiler.stub'   => 'resources/js/components/Toolbar/MemoryProfiler.jsx',
            'toolbar-performance-panel.stub' => 'resources/js/components/Toolbar/PerformancePanel.jsx',
        ];

        $hasErrors = false;

        foreach ($scaffolds as $stub => $dest) {
            $stubPath = $stubsDir . '/' . $stub;
            $destPath = $this->basePath . '/' . $dest;

            if (! file_exists($stubPath)) {
                $output->writeln("  <error>✗ Stub not found:</error> {$stubPath}");
                $hasErrors = true;
                continue;
            }

            if (file_exists($destPath)) {
                $output->writeln("  <comment>⊘ Skipped (exists):</comment> {$dest}");
                continue;
            }

            $destDir = dirname($destPath);
            if (! is_dir($destDir) && ! mkdir($destDir, 0755, true)) {
                $output->writeln("  <error>✗ Could not create directory:</error> {$destDir}");
                $hasErrors = true;
                continue;
            }

            $content = (string) file_get_contents($stubPath);

            // Replace the generic placeholder in the component stub with "Welcome"
            if ($stub === 'react-component.stub') {
                $content = str_replace('{{ componentname }}', 'Welcome', $content);
            }

            if (file_put_contents($destPath, $content) === false) {
                $output->writeln("  <error>✗ Failed to write:</error> {$dest}");
                $hasErrors = true;
                continue;
            }

            $output->writeln("  <info>✔ Created:</info> {$dest}");
        }

        if ($hasErrors) {
            $output->writeln('');
            $output->writeln('<error>  Installation finished with errors. Fix the issues above and re-run.</error>');
            return Command::FAILURE;
        }

        // Patch .env files with Vite vars
        $this->patchEnvFiles($output);

        $output->writeln('');
        $output->writeln('<info>  ✔ React integration installed successfully!</info>');
        $output->writeln('');
        $output->writeln('  <comment>Next steps:</comment>');
        $output->writeln('');
        $output->writeln('  1. Install JS dependencies:');
        $output->writeln('     <info>npm install</info>');
        $output->writeln('');
        $output->writeln('  2a. Development (with HMR):');
        $output->writeln('      Set <info>VITE_DEV_SERVER=true</info> in your .env, then run:');
        $output->writeln('      <info>npm run dev</info>');
        $output->writeln('');
        $output->writeln('  2b. Production:');
        $output->writeln('      Set <info>VITE_DEV_SERVER=false</info> in your .env, then run:');
        $output->writeln('      <info>npm run build</info>');
        $output->writeln('');
        $output->writeln('  3. Generate new components:');
        $output->writeln('     <info>php rhapsody make:react ComponentName</info>');
        $output->writeln('');
        $output->writeln('  4. Mount React from any controller:');
        $output->writeln("     <info>return \$this->react('Welcome', ['greeting' => 'Hello!']);</info>");
        $output->writeln('');

        return Command::SUCCESS;
    }

    /**
     * Append Vite env variables to .env and .env.example if not already present.
     */
    private function patchEnvFiles(OutputInterface $output): void
    {
        $block = <<<ENV

# ─── Vite / React ─────────────────────────────────────────────────────────────
# Set to true while running "npm run dev" (the Vite HMR dev server).
# Set to false (or remove) in production — assets are served from public/build/.
VITE_DEV_SERVER=false
VITE_PORT=5173
ENV;

        foreach (['.env', '.env.example'] as $file) {
            $path = $this->basePath . '/' . $file;

            if (! file_exists($path)) {
                continue;
            }

            $current = (string) file_get_contents($path);

            if (str_contains($current, 'VITE_DEV_SERVER')) {
                $output->writeln("  <comment>⊘ Skipped (already has Vite vars):</comment> {$file}");
                continue;
            }

            file_put_contents($path, $current . $block . "\n");
            $output->writeln("  <info>✔ Patched:</info> {$file}  (added VITE_DEV_SERVER, VITE_PORT)");
        }
    }
}
