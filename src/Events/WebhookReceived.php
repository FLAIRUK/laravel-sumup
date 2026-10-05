<?php

namespace FLAIRUK\SumUp\Events;

use FLAIRUK\SumUp\Webhooks\Notification;

/**
 * Dispatched for every well-formed notification SumUp posts, including event
 * types this package does not know yet, before anything is verified.
 *
 * Do not fulfil orders from this event: SumUp does not sign notifications, so
 * anyone can send one. Listen for CheckoutStatusChanged or
 * ReaderCheckoutStatusChanged, which carry the state fetched from the API.
 */
final class WebhookReceived
{
    public function __construct(public readonly Notification $notification) {}
}
