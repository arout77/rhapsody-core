<?php
namespace Rhapsody\Core\Middleware;

use App\Entities\User;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Tools\SchemaTool;
use Rhapsody\Core\Request;
use Rhapsody\Core\Response;
use Rhapsody\Core\Routing\Route;

class EnsureSchemaIsMigratedMiddleware extends Middleware
{
    /**
     * @param EntityManager $em
     */
    public function __construct(protected EntityManager $em)
    {}

    /**
     * Intercept request and verify table schema only for AuthController actions.
     * * @param Request $request
     * @param Route|null $route Passed along by the routing pipeline context
     */
    public function handle(Request $request, ?Route $route = null): ?Response
    {
        // 1. Ensure we have a valid route to inspect
        if (! $route) {
            return null;
        }

        $callback = $route->getCallback();

        // 2. Performance Check: Verify if the route structure targets a controller class array
        if (is_array($callback) && count($callback) === 2) {
            $controllerClass = $callback[0]; // e.g., "Rhapsody\Core\Controllers\AuthController"

            // Isolate the base class short name exactly like your end() example does
            $controllerParts = explode('\\', $controllerClass);
            $controllerName  = end($controllerParts);

            // 3. Early return if it is any other controller (Zero database performance overhead elsewhere)
            if ($controllerName !== 'AuthController') {
                return null;
            }

            // 4. Execute creation only for AuthController actions if table does not exist
            $userMetadata  = $this->em->getClassMetadata(User::class);
            $schemaManager = $this->em->getConnection()->createSchemaManager();

            if (! $schemaManager->tablesExist([$userMetadata->getTableName()])) {
                $schemaTool = new SchemaTool($this->em);
                $schemaTool->createSchema([$userMetadata]);
            }
        }

        return null;
    }
}
