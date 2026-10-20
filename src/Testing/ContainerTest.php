<?php

declare (strict_types = 1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rhapsody\Core\Container;
use Rhapsody\Core\Proxy\ContainerDecorator;
use Rhapsody\Core\Proxy\LazyProxyFactory;

/**
 * Container behavior in isolation: no bootstrap.php, no application. Every
 * test builds its own `new Container()`, so nothing here depends on (or can
 * disturb) the real service bindings.
 */
final class ContainerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Container::resetTrace();
    }

    // ---------------------------------------------------------------------
    // Alias bindings: bind('Abstract', Concrete::class)
    // ---------------------------------------------------------------------

    public function testAliasResolvesTheBoundClass(): void
    {
        $container = new Container();
        $container->bind(ContainerTestGreeter::class, ContainerTestHello::class);

        $this->assertInstanceOf(ContainerTestHello::class, $container->resolve(ContainerTestGreeter::class));
    }

    public function testAliasTargetDependenciesAreAutoWired(): void
    {
        $container = new Container();
        $container->bind(ContainerTestGreeter::class, ContainerTestHello::class);

        $greeter = $container->resolve(ContainerTestGreeter::class);

        $this->assertInstanceOf(ContainerTestHello::class, $greeter);
        $this->assertInstanceOf(ContainerTestClock::class, $greeter->clock);
    }

    public function testBindGivesAFreshInstanceEachTime(): void
    {
        $container = new Container();
        $container->bind(ContainerTestGreeter::class, ContainerTestHello::class);

        $this->assertNotSame(
            $container->resolve(ContainerTestGreeter::class),
            $container->resolve(ContainerTestGreeter::class)
        );
    }

    public function testSingletonAliasIsCachedUnderTheAbstract(): void
    {
        $container = new Container();
        $container->singleton(ContainerTestGreeter::class, ContainerTestHello::class);

        $this->assertFalse($container->resolved(ContainerTestGreeter::class));

        $first = $container->resolve(ContainerTestGreeter::class);

        $this->assertTrue($container->resolved(ContainerTestGreeter::class));
        $this->assertSame($first, $container->resolve(ContainerTestGreeter::class));
    }

    public function testForgetSingletonRebuildsAnAlias(): void
    {
        $container = new Container();
        $container->singleton(ContainerTestGreeter::class, ContainerTestHello::class);

        $first = $container->resolve(ContainerTestGreeter::class);
        $container->forgetSingleton(ContainerTestGreeter::class);

        $this->assertNotSame($first, $container->resolve(ContainerTestGreeter::class));
    }

    public function testAliasesCanChain(): void
    {
        $container = new Container();
        $container->bind(ContainerTestChainOne::class, ContainerTestChainTwo::class);
        $container->bind(ContainerTestChainTwo::class, ContainerTestChainEnd::class);

        $this->assertInstanceOf(ContainerTestChainEnd::class, $container->resolve(ContainerTestChainOne::class));
    }

    public function testAliasTargetsOwnClosureBindingIsHonored(): void
    {
        $marker    = new ContainerTestClock();
        $container = new Container();
        $container->bind(ContainerTestGreeter::class, ContainerTestHello::class);
        $container->bind(ContainerTestHello::class, fn(): ContainerTestHello => new ContainerTestHello($marker));

        $greeter = $container->resolve(ContainerTestGreeter::class);

        $this->assertInstanceOf(ContainerTestHello::class, $greeter);
        $this->assertSame($marker, $greeter->clock);
    }

    public function testAliasTargetRegisteredAsSingletonIsShared(): void
    {
        $container = new Container();
        $container->bind(ContainerTestGreeter::class, ContainerTestHello::class);
        $container->singleton(ContainerTestHello::class);

        $this->assertSame(
            $container->resolve(ContainerTestGreeter::class),
            $container->resolve(ContainerTestGreeter::class)
        );
    }

    public function testCircularAliasesThrowAndStayRepeatable(): void
    {
        $container = new Container();
        $container->bind(ContainerTestLoopA::class, ContainerTestLoopB::class);
        $container->bind(ContainerTestLoopB::class, ContainerTestLoopA::class);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $container->resolve(ContainerTestLoopA::class);
                $this->fail('Expected a circular dependency exception.');
            } catch (\Exception $e) {
                $this->assertStringContainsString('Circular dependency detected', $e->getMessage());
            }
        }
    }

    public function testUnboundInterfaceIsStillNotInstantiable(): void
    {
        $this->expectExceptionMessage('is not instantiable');

        (new Container())->resolve(ContainerTestOrphan::class);
    }

    public function testAliasToAnInterfaceReportsNotInstantiable(): void
    {
        $container = new Container();
        $container->bind(ContainerTestGreeter::class, ContainerTestOrphan::class);

        $this->expectExceptionMessage('is not instantiable');

        $container->resolve(ContainerTestGreeter::class);
    }

    public function testTraceRecordsTheAliasItsTargetAndTheTargetsDependencies(): void
    {
        $container = new Container();
        $container->bind(ContainerTestGreeter::class, ContainerTestHello::class);

        $container->resolve(ContainerTestGreeter::class);

        $resolved = array_column(Container::getTrace(), 'class');
        $this->assertContains(ContainerTestGreeter::class, $resolved);
        $this->assertContains(ContainerTestHello::class, $resolved);
        $this->assertContains(ContainerTestClock::class, $resolved);
    }

    // ---------------------------------------------------------------------
    // Behavior that must not change
    // ---------------------------------------------------------------------

    public function testSelfBindingResolvesTheClassItself(): void
    {
        $container = new Container();
        $container->bind(ContainerTestClock::class);

        $this->assertInstanceOf(ContainerTestClock::class, $container->resolve(ContainerTestClock::class));
    }

    public function testUnboundClassesAreAutoWired(): void
    {
        $hello = (new Container())->resolve(ContainerTestHello::class);

        $this->assertInstanceOf(ContainerTestClock::class, $hello->clock);
    }

    public function testClosureSingletonAndInstanceBindings(): void
    {
        $container = new Container();
        $container->singleton('thing', fn(): ContainerTestClock => new ContainerTestClock());
        $this->assertSame($container->resolve('thing'), $container->resolve('thing'));

        $clock = new ContainerTestClock();
        $container->instance(ContainerTestClock::class, $clock);
        $this->assertSame($clock, $container->resolve(ContainerTestClock::class));
    }

    // ---------------------------------------------------------------------
    // call(): invoking callables with auto-wired parameters
    // ---------------------------------------------------------------------

    public function testCallInjectsServicesIntoAnInstanceMethod(): void
    {
        $container = new Container();
        $container->bind(ContainerTestGreeter::class, ContainerTestHello::class);

        $result = $container->call([new ContainerTestJob(), 'handle']);

        $this->assertSame('job-1:hello:!', $result);
    }

    public function testCallBuildsTheInstanceFromAClassName(): void
    {
        $container = new Container();
        $container->bind(ContainerTestGreeter::class, ContainerTestHello::class);

        $this->assertSame('boot:hello:!', $container->call([ContainerTestBootedJob::class, 'handle']));
    }

    public function testNamedParametersOverrideContainerResolutionAndDefaults(): void
    {
        $container = new Container();
        $container->bind(ContainerTestGreeter::class, ContainerTestHello::class);

        $custom = new class implements ContainerTestGreeter
        {
            public function name(): string
            {
                return 'custom';
            }
        };

        $result = $container->call(
            [new ContainerTestJob(), 'handle'],
            ['greeter' => $custom, 'suffix' => '?']
        );

        $this->assertSame('job-1:custom:?', $result);
    }

    public function testCallRunsClosuresInvokablesAndStaticMethods(): void
    {
        $container = new Container();

        $this->assertInstanceOf(
            ContainerTestClock::class,
            $container->call(fn(ContainerTestClock $clock): ContainerTestClock => $clock)
        );
        $this->assertInstanceOf(ContainerTestClock::class, $container->call(new ContainerTestInvokable()));
        $this->assertSame('static:clock', $container->call([ContainerTestJob::class, 'build']));
        $this->assertSame('static:clock', $container->call('Tests\Unit\ContainerTestJob::build'));
    }

    public function testCallUsesTheContainersBindingsForEachParameter(): void
    {
        $container = new Container();
        $clock     = new ContainerTestClock();
        $container->instance(ContainerTestClock::class, $clock);

        $this->assertSame($clock, $container->call(fn(ContainerTestClock $c): ContainerTestClock => $c));
    }

    public function testCallRejectsNonPublicMethods(): void
    {
        $this->expectExceptionMessage('Cannot call non-public method');

        (new Container())->call([new ContainerTestJob(), 'hidden']);
    }

    public function testCallNamesTheFunctionWhenAParameterCannotBeResolved(): void
    {
        $container = new Container();

        try {
            $container->call([new ContainerTestJob(), 'needsScalar']);
            $this->fail('Expected an unresolvable parameter exception.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('$count', $e->getMessage());
            $this->assertStringContainsString(ContainerTestJob::class, $e->getMessage());
        }

        try {
            $container->call(static fn(int $limit): int => $limit);
            $this->fail('Expected an unresolvable parameter exception.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('$limit', $e->getMessage());
            $this->assertStringContainsString('{closure}', $e->getMessage());
        }
    }

    public function testCallDoesNotSwallowExceptionsFromTheCallable(): void
    {
        $this->expectExceptionMessage('boom');

        (new Container())->call(static function (): never {
            throw new \RuntimeException('boom');
        });
    }

    public function testDecoratorDelegatesCallToTheInnerContainer(): void
    {
        $inner     = new Container();
        $decorator = new ContainerDecorator($inner, new LazyProxyFactory($inner, sys_get_temp_dir()), false);

        $this->assertSame('job-1:clock:!', $decorator->call([new ContainerTestJob(), 'handle'], [
            'greeter' => new ContainerTestClockGreeter(),
        ]));
    }
}

interface ContainerTestGreeter
{
    public function name(): string;
}

final class ContainerTestClock
{
}

final class ContainerTestHello implements ContainerTestGreeter
{
    public function __construct(public readonly ContainerTestClock $clock)
    {
    }

    public function name(): string
    {
        return 'hello';
    }
}

final class ContainerTestClockGreeter implements ContainerTestGreeter
{
    public function name(): string
    {
        return 'clock';
    }
}

interface ContainerTestChainOne
{
}

interface ContainerTestChainTwo
{
}

final class ContainerTestChainEnd implements ContainerTestChainOne, ContainerTestChainTwo
{
}

interface ContainerTestLoopA
{
}

interface ContainerTestLoopB
{
}

interface ContainerTestOrphan
{
}

final class ContainerTestJob
{
    public function __construct(public readonly string $id = 'job-1')
    {
    }

    public function handle(ContainerTestGreeter $greeter, string $suffix = '!'): string
    {
        return $this->id . ':' . $greeter->name() . ':' . $suffix;
    }

    public static function build(ContainerTestClock $clock): string
    {
        return 'static:clock';
    }

    public function needsScalar(int $count): int
    {
        return $count;
    }

    protected function hidden(): string
    {
        return 'hidden';
    }
}

final class ContainerTestBootedJob
{
    public function __construct(public readonly ContainerTestClock $clock)
    {
    }

    public function handle(ContainerTestGreeter $greeter, string $suffix = '!'): string
    {
        return 'boot:' . $greeter->name() . ':' . $suffix;
    }
}

final class ContainerTestInvokable
{
    public function __invoke(ContainerTestClock $clock): ContainerTestClock
    {
        return $clock;
    }
}
