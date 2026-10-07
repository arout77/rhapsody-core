<?php

namespace Rhapsody\Core\Security;

use Rhapsody\Core\Contracts\AdminGateInterface;

/**
 * The zero-setup admin gate: admins are the user ids listed in
 * ADMIN_USER_IDS in .env, e.g.
 *
 *     ADMIN_USER_IDS=1,7
 *
 * Used by AdminMiddleware only when the application hasn't bound its own
 * AdminGateInterface. Denies everyone when the variable is missing or empty,
 * so a fresh install is locked rather than open.
 */
final class EnvAdminGate implements AdminGateInterface
{
    /** @param string|null $ids Override for tests; null reads ADMIN_USER_IDS from the environment */
    public function __construct(private readonly ?string $ids = null)
    {
    }

    public function isAdmin(string $userId): bool
    {
        $userId = trim($userId);
        if ($userId === '') {
            return false;
        }

        $raw = $this->ids ?? ($_ENV['ADMIN_USER_IDS'] ?? getenv('ADMIN_USER_IDS'));
        if (! is_string($raw) || trim($raw) === '') {
            return false;
        }

        foreach (explode(',', $raw) as $id) {
            if (trim($id) === $userId) {
                return true;
            }
        }

        return false;
    }
}
