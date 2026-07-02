<?php
namespace Rhapsody\Core\Commands;

use Rhapsody\Core\Database;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AuthInstallCommand extends Command
{
    protected static $defaultName = 'auth:install';

    protected function configure(): void
    {
        $this->setName('auth:install')
            ->setDescription('Install authentication tables (users, password_resets, etc.)')
            ->setHelp('This command creates the necessary database tables for the authentication system.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Installing authentication tables...</info>');

        try {
            $db = Database::getInstance()->getConnection();

            // Create users table
            $sql = "
            CREATE TABLE IF NOT EXISTS `users` (
              `user_id` varchar(255) NOT NULL,
              `name` varchar(255) NOT NULL,
              `email` varchar(255) NOT NULL,
              `password` varchar(255) NOT NULL,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`user_id`),
              UNIQUE KEY `email` (`email`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ";

            $db->exec($sql);
            $output->writeln('<info>✓ Users table created successfully.</info>');

            // Optional: Create password_resets table
            $sql2 = "
            CREATE TABLE IF NOT EXISTS `password_resets` (
              `email` varchar(255) NOT NULL,
              `token` varchar(255) NOT NULL,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              KEY `email` (`email`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ";

            $db->exec($sql2);
            $output->writeln('<info>✓ Password resets table created successfully.</info>');

            return Command::SUCCESS;
        } catch (\PDOException $e) {
            $output->writeln('<error>✗ Failed to create tables: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}
