<?php
namespace Rhapsody\Core\Commands;

use Doctrine\ORM\EntityManager;
use Rhapsody\Core\Console\Command;
use Rhapsody\Core\Container;

class AuthInstallCommand extends Command
{
    protected string $name        = 'auth:install';
    protected string $description = 'Create the users table for authentication.';

    public function execute(Container $container, array $args = []): int
    {
        /** @var EntityManager $em */
        $em = $container->resolve(EntityManager::class);

        $tool    = new \Doctrine\ORM\Tools\SchemaTool($em);
        $classes = [$em->getClassMetadata(\App\Entities\User::class)];

        try {
            $tool->createSchema($classes);
            $this->output->writeln('<info>Users table created successfully.</info>');
            return 0;
        } catch (\Exception $e) {
            $this->output->writeln('<error>Failed to create users table: ' . $e->getMessage() . '</error>');
            return 1;
        }
    }
}
