<?php

namespace FLAIRUK\SumUp\Http\Controllers;

use FLAIRUK\SumUp\Webhooks\WebhookHandler;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Receives SumUp's notifications at the `sumup.webhook` route.
 *
 * SumUp wants "a valid, empty response with any 2xx status code"; anything else,
 * or a slow reply, is retried after 1 minute, 5 minutes, 20 minutes and 2 hours.
 */
class WebhookController
{
    public function __invoke(Request $request, WebhookHandler $handler): Response
    {
        return $handler->handle($request)
            ? new Response('', 200)
            : new Response('', 400);
    }
}
