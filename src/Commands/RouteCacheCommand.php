<?php
namespace Rhapsody\Core\Commands;

use Rhapsody\Core\Routing\Router;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RouteCacheCommand extends Command
{
    protected static $defaultName = 'route:cache';

    public function __construct(protected string $basePath)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('route:cache')
            ->setDescription('Compile all application routes into a cached file for performance.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<comment>Caching routes...</comment>');

        $webRoutes = $this->basePath . '/routes/web.php';
        $apiRoutes = $this->basePath . '/routes/api.php';

        if (file_exists($webRoutes)) {
            require_once $webRoutes;
        }
        if (file_exists($apiRoutes)) {
            require_once $apiRoutes;
        }

        // Assuming Router exposes routes collection via package implementation
        $routes = Router::getRoutes();

        $cacheDirectory = $this->basePath . '/storage/cache/routes';
        if (! is_dir($cacheDirectory)) {
            mkdir($cacheDirectory, 0755, true);
        }

        $cachePath = $cacheDirectory . '/routes.php';
        $content   = '<?php return ' . var_export($routes, true) . ';';

        if (file_put_contents($cachePath, $content) === false) {
            $output->writeln('<error>Failed to write route cache file.</error>');
            return Command::FAILURE;
        }

        $output->writeln('<info>Routes cached successfully!</info>');
        return Command::SUCCESS;
    }
}
