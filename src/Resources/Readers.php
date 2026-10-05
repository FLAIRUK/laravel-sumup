<?php

namespace FLAIRUK\SumUp\Resources;

use FLAIRUK\SumUp\Money;
use SumUp\SumUp as Sdk;
use SumUp\Types\CreateReaderCheckoutResponse;
use SumUp\Types\GetReaderCheckoutResponse;
use SumUp\Types\Reader;
use SumUp\Types\StatusResponse;

/**
 * Card-present payments on SumUp Solo readers through the Cloud API.
 *
 * @see https://developer.sumup.com/terminal-payments/cloud-api
 */
class Readers extends Resource
{
    /**
     * @return list<Reader>
     */
    public function list(): array
    {
        return $this->call(fn (Sdk $sdk) => $sdk->readers()->list($this->merchantCode()))->items;
    }

    public function find(string $readerId): Reader
    {
        return $this->call(fn (Sdk $sdk) => $sdk->readers()->get($this->merchantCode(), $readerId));
    }

    /**
     * Pair a reader with the code it shows under Connections › API › Connect (the reader must be logged out).
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public function pair(string $pairingCode, string $name, ?array $metadata = null): Reader
    {
        return $this->call(fn (Sdk $sdk) => $sdk->readers()->create($this->merchantCode(), array_filter([
            'pairing_code' => $pairingCode,
            'name' => $name,
            'metadata' => $metadata,
        ], fn ($value) => $value !== null)));
    }

    /**
     * @param  array{name?: string, metadata?: array<string, mixed>}  $attributes
     */
    public function update(string $readerId, array $attributes): Reader
    {
        return $this->call(fn (Sdk $sdk) => $sdk->readers()->update($this->merchantCode(), $readerId, $attributes));
    }

    /**
     * Unpair and delete a reader.
     */
    public function delete(string $readerId): void
    {
        $this->call(fn (Sdk $sdk) => $sdk->readers()->delete($this->merchantCode(), $readerId));
    }

    /**
     * Battery, connection and what the reader is doing (IDLE, WAITING_FOR_CARD…).
     */
    public function status(string $readerId): StatusResponse
    {
        return $this->call(fn (Sdk $sdk) => $sdk->readers()->getStatus($this->merchantCode(), $readerId));
    }

    /**
     * Start a payment on the reader. The reader must be online; the result arrives
     * at `return_url` (see the webhook route) and with transactions()->findByClientTransactionId().
     *
     * Other attributes: description, return_url, tip_rates, tip_timeout, installments, card_type, affiliate.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function checkout(string $readerId, Money $amount, array $attributes = []): CreateReaderCheckoutResponse
    {
        $attributes['total_amount'] = $amount->toReaderAmount();

        return $this->call(fn (Sdk $sdk) => $sdk->readers()->createCheckout($this->merchantCode(), $readerId, $attributes));
    }

    /**
     * A reader checkout's current state (pending, successful, failed, cancelled).
     */
    public function findCheckout(string $readerId, string $checkoutId): GetReaderCheckoutResponse
    {
        return $this->call(fn (Sdk $sdk) => $sdk->readers()->getCheckout($this->merchantCode(), $readerId, $checkoutId));
    }

    /**
     * Cancel the payment in progress on the reader. The request is asynchronous; the outcome arrives by webhook.
     */
    public function terminate(string $readerId): void
    {
        $this->call(fn (Sdk $sdk) => $sdk->readers()->terminateCheckout($this->merchantCode(), $readerId));
    }
}
