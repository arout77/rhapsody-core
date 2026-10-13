<?php
namespace Rhapsody\Core\Testing;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Rhapsody\Core\Container;
use Rhapsody\Core\Helpers\Path;
use Rhapsody\Core\Request;
use Rhapsody\Core\Routing\Router;

abstract class TestCase extends BaseTestCase
{
    protected Container $app;

    protected function setUp(): void
    {
        parent::setUp();

        // Routes live in Router's static collection, so clear it before bootstrap re-registers them.
        Router::setRoutes([]);

        // bootstrap.php builds the container, registers every service, and returns it.
        $container = require Path::root() . '/bootstrap.php';

        if (! $container instanceof Container) {
            throw new \RuntimeException('bootstrap.php must return the application Container.');
        }

        $this->app = $container;
    }

    protected function tearDown(): void
    {
        Container::forgetInstance();
        Router::setRoutes([]);

        parent::tearDown();
    }

    /**
     * Simulate an HTTP request into the application.
     *
     * @param array<string, mixed>  $parameters Query string for GET/HEAD, request body otherwise
     * @param array<string, string> $headers
     */
    protected function call(string $method, string $uri, array $parameters = [], array $headers = []): TestResponse
    {
        $request           = Request::create($method, $uri, $parameters, $headers);
        $frameworkResponse = Router::dispatch($request, $this->app);

        // Wrap the framework response in our fluent test assertion class
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
        // Drop any cached singleton first, otherwise resolve() keeps returning the original.
        $this->app->forgetSingleton($abstract);
        $this->app->bind($abstract, function () use ($instance) {
            return $instance;
        });
    }

    /**
     * Create a native PHPUnit mock, configure it, and immediately swap it into the container.
     *
     * @template T of object
     *
     * @param  class-string<T>              $abstract
     * @param  callable(MockObject&T): void $mockDefinition
     * @return MockObject&T
     */
    protected function mock(string $abstract, callable $mockDefinition): object
    {
        $mock = $this->createMock($abstract);

        // Allow the developer to configure the mock's expectations
        $mockDefinition($mock);

        $this->swap($abstract, $mock);

        return $mock;
    }
}
