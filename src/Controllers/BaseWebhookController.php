<?php
namespace Rhapsody\Core\Controllers;

use Rhapsody\Core\BaseController;
use Rhapsody\Core\Request;
use Rhapsody\Core\Response;

abstract class BaseWebhookController extends BaseController
{
    /**
     * Handle incoming webhook requests from payment gateways.
     *
     * @param Request $request
     * @return Response
     */
    abstract public function handle(Request $request): Response;

    /**
     * Trigger internal application events when a webhook succeeds.
     * * @param string $event
     * @param array $payload
     * @return void
     */
    protected function triggerEvent(string $event, array $payload): void
    {
        // Framework event dispatcher logic goes here
        // e.g., Event::dispatch($event, $payload);
    }
}
