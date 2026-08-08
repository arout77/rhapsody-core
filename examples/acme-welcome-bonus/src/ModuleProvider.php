<?php

namespace Acme\WelcomeBonus;

use Rhapsody\Core\Events\UserRegistered;
use Rhapsody\Core\Modules\Contracts\ModuleServiceProviderInterface;
use Rhapsody\Core\Modules\ModuleContext;
use Rhapsody\Core\Response;

class ModuleProvider implements ModuleServiceProviderInterface
{
    public function boot(ModuleContext $context): void
    {
        // Allowed: UserRegistered is in this module's events.listen.listen whitelist.
        $context->events()->listen(UserRegistered::class, function (UserRegistered $event) use ($context) {
            $amount = $context->settings()->get('bonus_amount', 10);
            $context->storage()->put(
                'bonus-log.txt',
                sprintf("[%s] granted $%s to user #%s\n", date('c'), $amount, $event->user->getUserId())
            );
        });

        // Mounted at /welcome-bonus/balance (prefix from module.json, no
        // forced "/modules/" segment).
        $context->routes()->get('/balance', function () use ($context) {
            $amount = $context->settings()->get('bonus_amount', 10);
            return (new Response())->setContent("Current welcome bonus: \${$amount}");
        });
    }

    public function install(ModuleContext $context): void
    {
        $context->settings()->set('bonus_amount', 10);
    }

    public function uninstall(ModuleContext $context): void
    {
        // No DB tables to clean up in this example. Scoped storage under
        // storage/modules/acme-welcome-bonus/ is left for audit purposes
        // rather than deleted here — a real module would remove anything
        // sensitive.
    }
}
