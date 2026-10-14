<?php
namespace Rhapsody\Core\Testing\Traits;

use Doctrine\ORM\Tools\SchemaTool;

trait RefreshesDatabase
{
    /**
     * Rebuilds the Doctrine Schema for testing.
     * Call this inside your test's setUp() method.
     */
    protected function setUpDatabase(): void
    {
        // Retrieve the EntityManager from your dependency injection container
        $em = $this->app->get('EntityManager');

        $tool    = new SchemaTool($em);
        $classes = $em->getMetadataFactory()->getAllMetadata();

        // Drop all tables and recreate them instantly
        $tool->dropSchema($classes);
        $tool->createSchema($classes);
    }
}
