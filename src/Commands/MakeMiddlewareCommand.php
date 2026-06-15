<?php
namespace Rhapsody\Core\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MakeMiddlewareCommand extends Command
{
    protected static $defaultName = 'make:middleware';

    // Inject the application root base path context
    public function __construct(protected string $basePath)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('make:middleware')
            ->setDescription('Creates a new middleware class.')
            ->addArgument('name', InputArgument::REQUIRED, 'The name of the middleware (e.g., CheckAdminRole).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name           = $input->getArgument('name');
        $middlewareName = str_ends_with($name, 'Middleware') ? $name : $name . 'Middleware';

        // Direct target files out into the app workspace directory
        $directory = $this->basePath . '/app/Middleware';
        $filepath  = $directory . '/' . $middlewareName . '.php';

        if (file_exists($filepath)) {
            $output->writeln("<error>Middleware '{$middlewareName}' already exists!</error>");
            return Command::FAILURE;
        }

        // Search downstream app folder vs core engine resource asset directory path layout
        $appStubPath  = $this->basePath . '/stubs/middleware.stub';
        $coreStubPath = dirname(__DIR__, 2) . '/resources/stubs/middleware.stub';

        if (file_exists($appStubPath)) {
            $stubPath = $appStubPath;
        } elseif (file_exists($coreStubPath)) {
            $stubPath = $coreStubPath;
        } else {
            $output->writeln("<error>Stub template file not found!</error>");
            return Command::FAILURE;
        }

        $stub = file_get_contents($stubPath);
        $stub = str_replace('{{ classname }}', $middlewareName, $stub);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        if (file_put_contents($filepath, $stub) === false) {
            $output->writeln("<error>Failed to create middleware file.</error>");
            return Command::FAILURE;
        }

        $output->writeln("<info>Middleware '{$middlewareName}' created successfully at {$filepath}</info>");
        return Command::SUCCESS;
    }
}
