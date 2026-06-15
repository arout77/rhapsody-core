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

        // 1. Check for custom application-level workspace override stub first
        $appStubPath = $this->basePath . '/stubs/controller.stub';

        // 2. Fallback A: Framework Root directory (vendor/arout/rhapsody-core/stubs/)
        $coreRootStubPath = dirname(__DIR__, 2) . '/resources/stubs/controller.stub';

        // 3. Fallback B: Framework Src directory (vendor/arout/rhapsody-core/src/stubs/)
        $coreSrcStubPath = dirname(__DIR__, 1) . '/src/stubs/controller.stub';

        if (file_exists($appStubPath)) {
            $stubPath = $appStubPath;
        } elseif (file_exists($coreRootStubPath)) {
            $stubPath = $coreRootStubPath;
        } elseif (file_exists($coreSrcStubPath)) {
            $stubPath = $coreSrcStubPath;
        } else {
            $output->writeln("<error>Stub template file not found!</error>");
            $output->writeln("<comment>Paths searched:</comment>");
            $output->writeln(" - App:  {$appStubPath}");
            $output->writeln(" - Core: {$coreRootStubPath}");
            $output->writeln(" - Src:  {$coreSrcStubPath}");
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

        $output->writeln("<info>Controller '{$controllerName}' created successfully at {$filepath}</info>");
        return Command::SUCCESS;
    }
}
