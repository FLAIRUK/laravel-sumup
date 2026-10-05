<?php

namespace FLAIRUK\SumUp\Events;

use FLAIRUK\SumUp\Webhooks\Notification;
use SumUp\Types\TransactionFull;

/**
 * A Cloud API reader checkout finished. $transaction was fetched from the SumUp
 * API by the notification's client_transaction_id, so its status (SUCCESSFUL,
 * FAILED, CANCELLED…) can be trusted.
 *
 * SumUp retries notifications, so this can fire more than once for the same
 * transaction: make listeners idempotent.
 */
final class ReaderCheckoutStatusChanged
{
    public function __construct(
        public readonly TransactionFull $transaction,
        public readonly Notification $notification,
    ) {}

    public function successful(): bool
    {
        return $this->transaction->status === 'SUCCESSFUL';
    }
}
