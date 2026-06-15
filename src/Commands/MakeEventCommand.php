<?php
namespace Rhapsody\Core\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MakeEventCommand extends Command
{
    protected static $defaultName = 'make:event';

    public function __construct(protected string $basePath)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('make:event')
            ->setDescription('Creates a new event class.')
            ->addArgument('name', InputArgument::REQUIRED, 'The name of the event (e.g., OrderShipped).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name      = $input->getArgument('name');
        $className = ucfirst($name);

        $directory = $this->basePath . '/app/Events';
        $filepath  = $directory . '/' . $className . '.php';

        if (file_exists($filepath)) {
            $output->writeln("<error>Event '{$className}' already exists!</error>");
            return Command::FAILURE;
        }

        $appStubPath  = $this->basePath . '/stubs/event.stub';
        $coreStubPath = dirname(__DIR__, 2) . '/resources/stubs/event.stub';

        if (file_exists($appStubPath)) {
            $stubPath = $appStubPath;
        } elseif (file_exists($coreStubPath)) {
            $stubPath = $coreStubPath;
        } else {
            $output->writeln("<error>Stub template file not found!</error>");
            return Command::FAILURE;
        }

        $stub = file_get_contents($stubPath);
        $stub = str_replace('{{ classname }}', $className, $stub);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        if (file_put_contents($filepath, $stub) === false) {
            $output->writeln("<error>Failed to create event file.</error>");
            return Command::FAILURE;
        }

        $output->writeln("<info>Event '{$className}' created successfully at {$filepath}</info>");
        return Command::SUCCESS;
    }
}
