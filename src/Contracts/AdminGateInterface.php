<?php

namespace Rhapsody\Core\Contracts;

/**
 * Decides whether a logged-in user may use admin-only areas.
 *
 * Core can't know how an application stores "who is an admin" — an
 * `is_admin` column on `users`, a separate roles table, a config file — so
 * it asks this contract instead of reading any table itself. The consuming
 * application binds its own implementation in the container; core's
 * AdminMiddleware (the built-in `admin` route middleware) calls it.
 *
 * If no implementation is bound, AdminMiddleware falls back to
 * Rhapsody\Core\Security\EnvAdminGate, which reads a comma-separated list of
 * user ids from ADMIN_USER_IDS in .env and denies everyone when that is empty.
 */
interface AdminGateInterface
{
    /**
     * @param string $userId The id stored in the session under "user_id"
     */
    public function isAdmin(string $userId): bool;
}
