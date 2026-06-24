<?php
namespace Rhapsody\Core\Events;

use Rhapsody\Core\Entities\User;
use Rhapsody\Core\Event;

/**
 * This event is dispatched when a new user successfully registers.
 */
class UserRegistered extends Event
{
    /**
     * @param User $user The newly created user entity.
     */
    public function __construct(public readonly User $user)
    {
    }
}
