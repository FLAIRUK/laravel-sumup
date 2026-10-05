<?php

namespace FLAIRUK\SumUp\Events;

use FLAIRUK\SumUp\Webhooks\Notification;
use SumUp\Types\CheckoutSuccess;

/**
 * An online checkout changed status. $checkout was fetched from the SumUp API
 * after the notification arrived, so its status (PENDING, PAID, FAILED,
 * EXPIRED) can be trusted.
 *
 * SumUp retries notifications, so this can fire more than once for the same
 * checkout: make listeners idempotent.
 */
final class CheckoutStatusChanged
{
    public function __construct(
        public readonly CheckoutSuccess $checkout,
        public readonly Notification $notification,
    ) {}

    public function paid(): bool
    {
        return $this->checkout->status === 'PAID';
    }
}
