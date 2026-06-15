<?php
namespace Rhapsody\Core\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MakeModelCommand extends Command
{
    protected static $defaultName = 'make:model';

    public function __construct(protected string $basePath)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('make:model')
            ->setDescription('Creates a new model class.')
            ->setHelp('This command allows you to generate a new Eloquent model file.')
            ->addArgument('name', InputArgument::REQUIRED, 'The name of the model (e.g., Post).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name      = $input->getArgument('name');
        $modelName = ucfirst($name);

        $directory = $this->basePath . '/app/Models';
        $filepath  = $directory . '/' . $modelName . '.php';

        if (file_exists($filepath)) {
            $output->writeln("<error>Model '{$modelName}' already exists!</error>");
            return Command::FAILURE;
        }

        // Downstream app override vs framework core vendor stubs directory
        $appStubPath  = $this->basePath . '/stubs/model.stub';
        $coreStubPath = dirname(__DIR__, 2) . '/resources/stubs/model.stub';

        if (file_exists($appStubPath)) {
            $stubPath = $appStubPath;
        } elseif (file_exists($coreStubPath)) {
            $stubPath = $coreStubPath;
        } else {
            $output->writeln("<error>Stub template file not found!</error>");
            return Command::FAILURE;
        }

        $stub = file_get_contents($stubPath);
        $stub = str_replace('{{ classname }}', $modelName, $stub);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        if (file_put_contents($filepath, $stub) === false) {
            $output->writeln("<error>Failed to create model file.</error>");
            return Command::FAILURE;
        }

        $output->writeln("<info>Model '{$modelName}' created successfully at {$filepath}</info>");
        return Command::SUCCESS;
    }
}
