<?php
namespace Rhapsody\Core\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MakeControllerCommand extends Command
{
    protected static $defaultName = 'make:controller';

    public function __construct(protected string $basePath)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('make:controller')
            ->setDescription('Creates a new controller class.')
            ->setHelp('This command allows you to generate a new controller file.')
            ->addArgument('name', InputArgument::REQUIRED, 'The name of the controller.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name           = $input->getArgument('name');
        $controllerName = str_ends_with($name, 'Controller') ? $name : $name . 'Controller';

        $directory = $this->basePath . '/app/Controllers';
        $filepath  = $directory . '/' . $controllerName . '.php';

        if (file_exists($filepath)) {
            $output->writeln("<error>Controller '{$controllerName}' already exists!</error>");
            return Command::FAILURE;
        }

        $stubPath = $this->basePath . '/stubs/controller.stub';
        if (! file_exists($stubPath)) {
            $output->writeln("<error>Stub template file not found at: {$stubPath}</error>");
            return Command::FAILURE;
        }

        $stub = file_get_contents($stubPath);
        $stub = str_replace('{{ classname }}', $controllerName, $stub);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        if (file_put_contents($filepath, $stub) === false) {
            $output->writeln("<error>Failed to create controller file.</error>");
            return Command::FAILURE;
        }

        $output->writeln("<info>Controller '{$controllerName}' created successfully.</info>");
        return Command::SUCCESS;
    }
}
