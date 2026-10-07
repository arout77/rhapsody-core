<?php

namespace Rhapsody\Core\Middleware;

use Rhapsody\Core\Contracts\AdminGateInterface;
use Rhapsody\Core\Contracts\ContainerInterface;
use Rhapsody\Core\Exceptions\HttpException;
use Rhapsody\Core\RedirectResponse;
use Rhapsody\Core\Request;
use Rhapsody\Core\Response;
use Rhapsody\Core\Routing\Route;
use Rhapsody\Core\Security\EnvAdminGate;
use Rhapsody\Core\Session;

/**
 * The built-in `admin` route middleware: ->middleware('admin').
 *
 * Two distinct failure modes, deliberately:
 *
 *  - Not logged in at all -> redirect to /login.
 *  - Logged in, but not an admin -> 403. They already have a perfectly
 *    valid session; bouncing them to /login would be a confusing loop for
 *    an account that isn't broken, just not authorized for this area.
 *
 * "Is this user an admin?" is answered by AdminGateInterface, which the
 * application binds in the container (core doesn't assume any particular
 * users-table column). With nothing bound it falls back to EnvAdminGate
 * (ADMIN_USER_IDS in .env), which denies everyone when empty — so the
 * default is locked, never open.
 *
 * An application that registers its own `admin` entry in the middleware
 * map keeps using that instead; the router prefers the app's map over this
 * built-in alias.
 */
class AdminMiddleware extends Middleware
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function handle(Request $request, ?Route $route = null): ?Response
    {
        if (! Session::has('user_id')) {
            return new RedirectResponse((string) getenv('APP_BASE_URL') . '/login');
        }

        if (! $this->gate()->isAdmin((string) Session::get('user_id'))) {
            throw new HttpException(403, 'You do not have access to this area.');
        }

        return null;
    }

    private function gate(): AdminGateInterface
    {
        try {
            $gate = $this->container->resolve(AdminGateInterface::class);

            if ($gate instanceof AdminGateInterface) {
                return $gate;
            }
        } catch (\Throwable $e) {
            // Normal on a fresh install (nothing bound). Also what a broken
            // binding looks like, so say why before falling back.
            error_log(
                'AdminMiddleware: no usable AdminGateInterface (' . $e->getMessage() . '); ' .
                'falling back to ADMIN_USER_IDS from .env'
            );
        }

        return new EnvAdminGate();
    }
}
