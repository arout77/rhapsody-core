<?php
namespace Rhapsody\Core\Testing;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Rhapsody\Core\Container;
use Rhapsody\Core\Helpers\Path;

abstract class TestCase extends BaseTestCase
{
    protected Container $app;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Boot up the empty container first
        $this->app = new Container();

        // 2. Require bootstrap definitions so services (like 'EntityManager') are registered into the container
        $basePath = Path::root();
        require $basePath . '/bootstrap.php';
    }

    /**
     * Simulate an HTTP request into the application.
     */
    protected function call(string $method, string $uri, array $parameters = []): TestResponse
    {
        // Setup framework's request object...
        $request = \Rhapsody\Core\Http\Request::create($method, $uri, $parameters);

        // Dispatch through router...
        $frameworkResponse = $this->app->get('router')->dispatch($request);

        // Wrap the framework response in our new fluent test assertion class
        return new TestResponse(
            $frameworkResponse->getStatusCode(),
            $frameworkResponse->getContent(),
            $frameworkResponse->getHeaders()
        );
    }

    /**
     * Swap an instance in the container with a custom object or mock.
     */
    protected function swap(string $abstract, object $instance): void
    {
        $this->app->bind($abstract, function () use ($instance) {
            return $instance;
        });
    }

    /**
     * Create a native PHPUnit mock, configure it, and immediately swap it into the container.
     */
    protected function mock(string $abstract, callable $mockDefinition): object
    {
        // Create a standard PHPUnit mock object
        $mock = $this->createMock($abstract);

        // Allow the developer to configure the mock's expectations
        $mockDefinition($mock);

        // Swap it into the DI container
        $this->swap($abstract, $mock);

        return $mock;
    }
}
