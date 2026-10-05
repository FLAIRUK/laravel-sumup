<?php

namespace FLAIRUK\SumUp\Webhooks;

/**
 * A notification SumUp posted to a `return_url`, as received.
 *
 * SumUp does not sign these, so nothing in here is trustworthy until it has been
 * confirmed with the API. The package does that before dispatching
 * CheckoutStatusChanged and ReaderCheckoutStatusChanged.
 */
final readonly class Notification
{
    /** An online checkout changed status: {"event_type": "CHECKOUT_STATUS_CHANGED", "id": "<checkout id>"}. */
    public const CHECKOUT_STATUS_CHANGED = 'CHECKOUT_STATUS_CHANGED';

    /** A Cloud API reader checkout finished: {"event_type": "solo.transaction.updated", "id": "<event id>", "payload": {...}}. */
    public const READER_TRANSACTION_UPDATED = 'solo.transaction.updated';

    /**
     * @param  array<string, mixed>  $payload  the `payload` object (reader notifications only)
     * @param  array<string, mixed>  $raw  the whole JSON body
     */
    public function __construct(
        public string $eventType,
        public string $id,
        public array $payload = [],
        public ?string $timestamp = null,
        public array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $body
     */
    public static function fromArray(array $body): ?self
    {
        $eventType = $body['event_type'] ?? null;
        $id = $body['id'] ?? null;

        if (! is_string($eventType) || $eventType === '' || ! is_scalar($id) || (string) $id === '') {
            return null;
        }

        return new self(
            $eventType,
            (string) $id,
            is_array($body['payload'] ?? null) ? $body['payload'] : [],
            is_string($body['timestamp'] ?? null) ? $body['timestamp'] : null,
            $body,
        );
    }

    public function isCheckoutStatusChange(): bool
    {
        return $this->eventType === self::CHECKOUT_STATUS_CHANGED;
    }

    public function isReaderTransactionUpdate(): bool
    {
        return $this->eventType === self::READER_TRANSACTION_UPDATED;
    }

    /**
     * The checkout id of a CHECKOUT_STATUS_CHANGED notification.
     */
    public function checkoutId(): ?string
    {
        return $this->isCheckoutStatusChange() ? $this->id : null;
    }

    /**
     * The merchant code in a reader notification's payload.
     */
    public function merchantCode(): ?string
    {
        $code = $this->payload['merchant_code'] ?? null;

        return is_string($code) && $code !== '' ? $code : null;
    }

    /**
     * The client_transaction_id in a reader notification's payload (or the deprecated transaction_id).
     */
    public function clientTransactionId(): ?string
    {
        $id = $this->payload['client_transaction_id'] ?? $this->payload['transaction_id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }
}
