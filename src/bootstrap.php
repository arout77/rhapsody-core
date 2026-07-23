<?php

// bootstrap.php
use App\Providers\EventServiceProvider;
use Rhapsody\Core\Services\NotificationService;
use Composer\InstalledVersions;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Omnipay\Omnipay;
use Predis\Client as RedisClient;
use Rhapsody\Core\Cache;
use Rhapsody\Core\Cache\CacheInterface;
use Rhapsody\Core\Cache\FileCacheDriver;
use Rhapsody\Core\Cache\RedisCacheDriver;
use Rhapsody\Core\FrameworkInfo;
use Rhapsody\Core\Commands\CacheClearCommand;
use Rhapsody\Core\Commands\CacheWarmCommand;
use Rhapsody\Core\Commands\CheckVersionCommand;
use Rhapsody\Core\Commands\EnvSyncCommand;
use Rhapsody\Core\Commands\MakeControllerCommand;
use Rhapsody\Core\Commands\MakeEventCommand;
use Rhapsody\Core\Commands\MakeListenerCommand;
use Rhapsody\Core\Commands\MakeMiddlewareCommand;
use Rhapsody\Core\Commands\MakeMigrationCommand;
use Rhapsody\Core\Commands\MakeModelCommand;
use Rhapsody\Core\Commands\MakeReactCommand;
use Rhapsody\Core\Commands\MigrateCommand;
use Rhapsody\Core\Commands\ReactInstallCommand;
use Rhapsody\Core\Commands\RouteCacheCommand;
use Rhapsody\Core\Commands\RouteClearCommand;
use Rhapsody\Core\Container;
use Rhapsody\Core\Contracts\PaymentGatewayInterface;
use Rhapsody\Core\Events\EventDispatcher;
use Rhapsody\Core\Helpers\OmnipayGateway;
use Rhapsody\Core\Helpers\Path;
use Rhapsody\Core\Mailer;
use Rhapsody\Core\Middleware\DdosMiddleware;
use Rhapsody\Core\Proxy\ContainerDecorator;
use Rhapsody\Core\Proxy\LazyProxyFactory;
use Rhapsody\Core\QueryLogger;
use Rhapsody\Core\Request;
use Rhapsody\Core\Routing\Router;
use Rhapsody\Core\Services\RateLimiter;
use Rhapsody\Core\Session;
use Rhapsody\Core\Storage\Cookie;
use Rhapsody\Core\Validator;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

// =========================================================================
// STEP 1: INITIAL ENVIRONMENT & SYSTEM LAYOUT CONFIGURATION
// =========================================================================

// 1. Establish the explicit runtime application path base directory context Safely
$basePath = Path::root();

$envFile = $basePath . '/.env';
if (file_exists($basePath . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable($basePath);
    $dotenv->load();
}
// 2. Create a new Service Container instance and assign it to global scope
global $container;
$container  = new Container();
$configPath = $basePath . '/config/config.php';

if (! file_exists($configPath)) {
    throw new \Exception("Configuration file missing at expected target: " . $configPath);
}

// Expecting config.php to return its configuration array
$config = require $configPath;
$container->bind('config', function () use ($config) {
    return $config;
});

// The framework version shown in the debug toolbar and available to CLI
// commands via $config['app_version'] — same source as the update-check
// banner (see NotificationService), so both stay in sync automatically.
$config['app_version'] = FrameworkInfo::getVersion();

// =========================================================================
// STEP 2: SERVICE REGISTRATION (Register bindings into container memory)
// =========================================================================

// --- DDOS Middleware ---
// Bind RateLimiter as a singleton (manual caching)
$container->bind(RateLimiter::class, function ($container) use ($config) {
    static $instance = null;
    if ($instance === null) {
        $instance = new RateLimiter(Cache::getInstance(), $config);
    }
    return $instance;
});

// Bind DdosMiddleware with its dependencies
$container->bind(DdosMiddleware::class, function ($container) use ($config) {
    return new DdosMiddleware(
        $container->resolve(RateLimiter::class),
        $config
    );
});

// --- EVENT DISPATCHER BINDING ---
$container->bind(EventDispatcher::class, function (Container $c) {
    $eventServiceProvider = new EventServiceProvider();
    return new EventDispatcher($c, $eventServiceProvider->getListeners());
});

// --- QUERY LOGGER BINDING (SINGLETON) ---
// $container->bind(QueryLogger::class, function () {
//     static $instance;
//     if ($instance === null) {
//         $instance = new QueryLogger();
//     }
//     return $instance;
// });

// --- DOCTRINE ENTITY MANAGER BINDING ---
$container->bind(EntityManager::class, function ($container) use ($config, $basePath) {
    $paths     = [$basePath . '/app/Entities'];
    $isDevMode = ($config['app_env'] ?? 'production') === 'development';

    // Retrieve the same logger instance (singleton)
    $sqlLogger = $container->resolve(QueryLogger::class);

    $cache          = $isDevMode ? new ArrayAdapter() : new FilesystemAdapter('', 0, $basePath . '/storage/cache/doctrine');
    $sqlLogger      = \Rhapsody\Core\QueryLogger::getInstance();
    $doctrineConfig = ORMSetup::createAttributeMetadataConfiguration($paths, $isDevMode, null, $cache);
    $doctrineConfig->setSQLLogger($sqlLogger);

    $dbParams = [
        'driver'   => 'pdo_mysql',
        'host'     => $_ENV['DB_HOST'],
        'user'     => $_ENV['DB_USER'],
        'password' => $_ENV['DB_PASS'],
        'dbname'   => $_ENV['DB_NAME'],
        'charset'  => 'utf8mb4',
    ];

    $connection = DriverManager::getConnection($dbParams, $doctrineConfig);
    return new EntityManager($connection, $doctrineConfig);
});

// --- CACHE SYSTEM BINDING ---
$container->bind(CacheInterface::class, function () use ($config, $basePath) {
    if ($config['cache']['driver'] === 'redis') {
        $redisClient = new RedisClient([
            'scheme'   => 'tcp',
            'host'     => $config['redis']['host'],
            'port'     => $config['redis']['port'],
            'password' => $config['redis']['password'] ?: null,
        ]);
        return new RedisCacheDriver($redisClient);
    }
    // Inject the decoupled runtime directory path into your File Cache Driver
    return new FileCacheDriver($basePath . '/storage/cache/app');
});

$container->bind(Cache::class, function (Container $c) {
    return new Cache($c->resolve(CacheInterface::class));
});

// Make Cache statically accessible (same pattern as Database::getInstance())
Cache::setInstance($container->resolve(Cache::class));

// --- CORE PACKAGE DATABASE SINGLETON BINDING ---
$container->bind(\Rhapsody\Core\Database::class, function () use ($config) {
    if (empty($config)) {
        throw new \Exception("The global \$config array is empty during Container service compilation.");
    }

    // Securely forward the configurations down to your core package class initialization method
    return \Rhapsody\Core\Database::getInstance($config);
});

// --- TWIG BINDING ---
$container->bind(Environment::class, function (Container $c) use ($config, $basePath) {
    $activeTheme = $config['theme'] ?? 'default';
    $paths       = [];

    // The active theme path is always the first priority.
    $activeThemePath = $basePath . '/views/themes/' . $activeTheme;
    if (is_dir($activeThemePath)) {
        $paths[] = $activeThemePath;
    }

    // If the active theme is not the default, add the default theme as a fallback.
    $defaultThemePath = $basePath . '/views/themes/default';
    if ($activeTheme !== 'default' && is_dir($defaultThemePath)) {
        $paths[] = $defaultThemePath;
    }

    // If for some reason no paths were added (e.g., bad config), fallback to default.
    if (empty($paths) && is_dir($defaultThemePath)) {
        $paths[] = $defaultThemePath;
    }

    if (empty($paths)) {
        throw new \Exception("No valid theme directory found. Please check your configuration.");
    }

    $loader = new FilesystemLoader($paths);
    // App views take priority
    $loader->addPath($basePath . '/views');

    // Register core views under a specific namespace safely
    $coreViewsPath = $basePath . '/vendor/arout/rhapsody-core/resources/views/themes/default';
    if (! is_dir($coreViewsPath)) {
        $coreViewsPath = $basePath . '/vendor/arout/rhapsody-core/views/themes/default';
    }

    if (is_dir($coreViewsPath)) {
        // Add to the 'core' namespace for @core/... references
        $loader->addPath($coreViewsPath, 'core');
        // Also add to the default namespace as a fallback for non-namespaced templates
        $loader->addPath($coreViewsPath);
    };

    // --- TWIG CACHING ENABLED ---
    $isDevelopment = ($config['app_env'] === 'development');
    $twigOptions   = [
        'debug'       => $isDevelopment,
        'cache'       => $basePath . '/storage/cache/twig',
        'auto_reload' => $isDevelopment,
    ];

    $twig = new Environment($loader, $twigOptions);

    if (! empty($_ENV['APP_KEY'])) {
        Cookie::setEncryptionKey($_ENV['APP_KEY']);
    } elseif (($_ENV['APP_ENV'] ?? 'production') === 'production') {
        throw new \RuntimeException(
            'APP_KEY is not set. Generate one with: php -r "echo bin2hex(random_bytes(16));" ' .
            'and add it to your .env file. The application cannot run in production without it, ' .
            'since it is used to encrypt cookies.'
        );
    }

    $twig->addGlobal('app_url', $_ENV['APP_URL'] ?? '');
    $twig->addGlobal('app_env', $_ENV['APP_ENV'] ?? 'production');

    // $twig->addExtension(new \Rhapsody\Core\React\ReactIslandExtension());

    // Register a smart vite_assets function
    // the ['is_safe'] arg is crucial here; Twig will escape the output
    // and just print the HTML to screen without it.
    $twig->addFunction(new \Twig\TwigFunction('vite_assets', function ($entry) {
        return \Rhapsody\Core\React\ViteManifest::tags($entry);
    }, ['is_safe' => ['html']]));

    // Auth lazy object
    $auth = new class($c)
    {
        public function __construct(private Container $container)
        {}

        public function __get(string $name): mixed
        {
            return match ($name) {
                'check' => Session::has('user_id'),
                'user'  => Session::has('user_id')
                    ? $this->container->resolve(\App\Models\User::class)->getUserById(Session::get('user_id'))
                    : null,
                default => null,
            };
        }

        public function __isset(string $name): bool
        {
            return in_array($name, ['check', 'user']);
        }
    };
    $twig->addGlobal('auth', $auth);
    $twig->addGlobal('base_url', $_ENV['APP_URL'] . $_ENV['APP_BASE_URL']);
    $twig->addGlobal('APP_THEME', $_ENV['APP_THEME']);
    $twig->addGlobal('app_theme', $_ENV['APP_THEME']);
    $twig->addExtension(new \Rhapsody\Core\Twig\StorageExtension());

    // Lazy‑loaded flash messages
    $flash = new class {
        public function __get($name)
        {
            return Session::getFlash($name);
        }
        public function __isset($name)
        {
            return Session::hasFlash($name);
        }
    };;;;;;;;;;;;

    $twig->addGlobal('flash', $flash);

    $twig->addFunction(new \Twig\TwigFunction('csrf_field', function () {
        $token = Session::csrfToken();
        return new \Twig\Markup('<input type="hidden" name="_token" value="' . $token . '">', 'UTF-8');
    }));

    return $twig;
});

// --- OTHER CORE SERVICES ---
$container->bind(\Rhapsody\Core\Contracts\AuthenticatableInterface::class, \App\Models\User::class);
$container->bind(PaymentGatewayInterface::class, function () use ($config) {
    $gatewayType = $config['payment']['gateway'] ?? 'Stripe';
    $currency    = $config['payment']['currency'] ?? 'USD';

    $gateway = Omnipay::create($gatewayType);
    if (! $gateway) {
        throw new \RuntimeException("Unsupported payment gateway: \"{$gatewayType}\". Check the PAYMENT_GATEWAY env variable.");
    }

    // Configure API keys based on the driver
    if ($gatewayType === 'Stripe') {
        $gateway->setApiKey($_ENV['STRIPE_SECRET_KEY'] ?? '');
    } elseif ($gatewayType === 'PayPal_Rest') {
        $gateway->setClientId($_ENV['PAYPAL_CLIENT_ID'] ?? '');
        $gateway->setSecret($_ENV['PAYPAL_SECRET'] ?? '');
        $gateway->setTestMode(filter_var($_ENV['PAYPAL_TEST_MODE'] ?? true, FILTER_VALIDATE_BOOLEAN));
    }

    return new OmnipayGateway($gateway, $currency);
});

$container->bind(Rhapsody\Core\Mailer::class, function ($c) use ($config) {
    return new \Rhapsody\Core\Mailer($config['mailer'] ?? []);
});
$container->bind(Validator::class, function (Container $c) {
    return new Validator($c->resolve(EntityManager::class));
});
$container->bind(Request::class, fn() => new Request());
$container->bind(NotificationService::class, function (Container $c) {
    return new NotificationService($c->resolve(Cache::class));
});

// --- COMMAND BINDINGS (Refactored to inject context-aware path mappings) ---
$container->bind(\Rhapsody\Core\Commands\AuthInstallCommand::class, function ($c) {
    return new \Rhapsody\Core\Commands\AuthInstallCommand();
});

$container->bind(CheckVersionCommand::class, function ($c) use ($config) {
    return new CheckVersionCommand(
        $config,
        $c->resolve(Mailer::class),
        $c->resolve(Cache::class),
        $c->resolve(NotificationService::class)
    );
});

$container->bind(CacheClearCommand::class, function ($c) use ($basePath) {
    return new CacheClearCommand($c->resolve(Cache::class), $basePath);
});

$container->bind(CacheWarmCommand::class, function () use ($basePath) {
    return new CacheWarmCommand($basePath);
});

$container->bind(EnvSyncCommand::class, function () use ($basePath) {
    return new EnvSyncCommand($basePath);
});

$container->bind(MakeControllerCommand::class, function () use ($basePath) {
    return new MakeControllerCommand($basePath);
});

$container->bind(MakeEventCommand::class, function () use ($basePath) {
    return new MakeEventCommand($basePath);
});

$container->bind(MakeListenerCommand::class, function () use ($basePath) {
    return new MakeListenerCommand($basePath);
});

$container->bind(MakeMiddlewareCommand::class, function () use ($basePath) {
    return new MakeMiddlewareCommand($basePath);
});

$container->bind(MakeMigrationCommand::class, function () use ($basePath) {
    return new MakeMigrationCommand($basePath);
});

$container->bind(MakeModelCommand::class, function () use ($basePath) {
    return new MakeModelCommand($basePath);
});

// Fix: Resolved the Database dependency singleton out of the container instance cleanly
$container->bind(MigrateCommand::class, function ($c) use ($basePath) {
    return new MigrateCommand($basePath, $c->resolve(\Rhapsody\Core\Database::class));
});

$container->bind(RouteCacheCommand::class, function () use ($basePath) {
    return new RouteCacheCommand($basePath);
});

$container->bind(RouteClearCommand::class, function () use ($basePath) {
    return new RouteClearCommand($basePath);
});

$container->bind(ReactInstallCommand::class, function () use ($basePath) {
    return new ReactInstallCommand($basePath);
});

$container->bind(MakeReactCommand::class, function () use ($basePath) {
    return new MakeReactCommand($basePath);
});

$container->bind(\Rhapsody\Core\Commands\BuildProxiesCommand::class, function ($c) use ($basePath) {
    return new \Rhapsody\Core\Commands\BuildProxiesCommand;
});

// --------------------------------------------------------------
// STEP 2.5: USER EXTENSION HOOK
// --------------------------------------------------------------
$userBootstrap = $basePath . '/bootstrap.php';
if (file_exists($userBootstrap)) {
    // The container is fully built; pass it to the user file.
    require $userBootstrap;
}

// =========================================================================
// STEP 2.6: LAZY LOADING DECORATOR (web only)
// =========================================================================

// Only apply lazy loading for web requests (not CLI)
if (PHP_SAPI !== 'cli') {
    $lazyEnabled = filter_var(
        $_ENV['LAZY_LOADING_ENABLED'] ?? $config['lazy']['enabled'] ?? true,
        FILTER_VALIDATE_BOOLEAN
    );

    if ($lazyEnabled) {
        $eagerServices = array_merge(
            $config['lazy']['eager'] ?? [],
            [
                \Rhapsody\Core\Container::class,
                \Rhapsody\Core\Routing\Router::class,
                \Rhapsody\Core\Events\EventDispatcher::class,
                \Rhapsody\Core\Request::class,
                \Rhapsody\Core\Response::class,
                \Rhapsody\Core\Cache::class,
                \Rhapsody\Core\Database::class,
                \Rhapsody\Core\Session::class,
                \Twig\Environment::class,
                NotificationService::class,
            ]
        );

        $proxyCacheDir = $basePath . '/storage/cache/proxies';
        $proxyFactory  = new \Rhapsody\Core\Proxy\LazyProxyFactory($container, $proxyCacheDir);

        $container = new \Rhapsody\Core\Proxy\ContainerDecorator(
            $container,
            $proxyFactory,
            $lazyEnabled,
            $eagerServices
        );

        // Update the global reference.
        global $container;
        $container = $container;
    }
}

// =========================================================================
// STEP 3: ROUTING & MIDDLEWARE CONFIGURATION (boot-time, once)
// =========================================================================

// Global Middleware Configuration Setup
$middlewareConfig = $config['middleware'] ?? ['map' => [], 'global' => []];
Router::setMiddlewareConfig($middlewareConfig);

// Load routes exactly once. In production, prefer the compiled route cache
// if it exists; otherwise load the live route files. This used to be split
// across bootstrap.php (which only ever loaded routes/web.php, unconditionally)
// and index.php (which loaded routes/web.php + routes/api.php AGAIN whenever
// not using the cache) — meaning every app route was registered twice on
// every non-cached request.
$routeCachePath = $basePath . '/storage/cache/routes/routes.php';
if (file_exists($routeCachePath) && ($config['app_env'] ?? 'production') === 'production') {
    $routes = require $routeCachePath;
    Router::setRoutes($routes);
} else {
    // 1. Framework-defined routes (login, docs, password reset, social auth, etc.)
    if (file_exists($basePath . '/vendor/arout/rhapsody-core/src/routes.php')) {
        require $basePath . '/vendor/arout/rhapsody-core/src/routes.php';
    }

    // 2. Downstream application routes
    if (file_exists($basePath . '/routes/web.php')) {
        require $basePath . '/routes/web.php';
    }
    if (file_exists($basePath . '/routes/api.php')) {
        require $basePath . '/routes/api.php';
    }
}

// 3. Return the completely compiled and configured dependency injection container.
return $container;
