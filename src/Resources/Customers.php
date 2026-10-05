<?php

namespace FLAIRUK\SumUp\Resources;

use SumUp\SumUp as Sdk;
use SumUp\Types\Customer;
use SumUp\Types\PaymentInstrumentResponse;

/**
 * Customers and their saved cards (payment instruments), for recurring payments.
 *
 * @see https://developer.sumup.com/api/customers
 */
class Customers extends Resource
{
    /**
     * @param  array{customer_id: string, personal_details?: array<string, mixed>}  $attributes
     */
    public function create(array $attributes): Customer
    {
        return $this->call(fn (Sdk $sdk) => $sdk->customers()->create($attributes));
    }

    public function find(string $customerId): Customer
    {
        return $this->call(fn (Sdk $sdk) => $sdk->customers()->get($customerId));
    }

    /**
     * @param  array{personal_details?: array<string, mixed>}  $attributes
     */
    public function update(string $customerId, array $attributes): Customer
    {
        return $this->call(fn (Sdk $sdk) => $sdk->customers()->update($customerId, $attributes));
    }

    /**
     * The customer's active saved cards. Use a card's `token` with checkouts()->process().
     *
     * @return list<PaymentInstrumentResponse>
     */
    public function paymentInstruments(string $customerId): array
    {
        return $this->call(fn (Sdk $sdk) => $sdk->customers()->listPaymentInstruments($customerId));
    }

    public function deactivatePaymentInstrument(string $customerId, string $token): void
    {
        $this->call(fn (Sdk $sdk) => $sdk->customers()->deactivatePaymentInstrument($customerId, $token));
    }
}
