<?php

namespace FLAIRUK\SumUp\Resources;

use FLAIRUK\SumUp\Money;
use SumUp\Services\CheckoutsListAvailablePaymentMethodsParams;
use SumUp\Services\CheckoutsListParams;
use SumUp\SumUp as Sdk;
use SumUp\Types\Checkout;
use SumUp\Types\CheckoutSuccess;

/**
 * Online payments.
 *
 * @see https://developer.sumup.com/api/checkouts
 */
class Checkouts extends Resource
{
    /**
     * Create a checkout. `merchant_code` defaults to the client's merchant and
     * `currency` to SUMUP_CURRENCY; `amount` may be a Money.
     *
     * Required: checkout_reference (unique per payment attempt) and amount.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Checkout
    {
        if (($attributes['amount'] ?? null) instanceof Money) {
            $attributes['currency'] = $attributes['amount']->currency;
            $attributes['amount'] = $attributes['amount']->amount();
        }

        $attributes += [
            'merchant_code' => $this->merchantCode(),
            'currency' => $this->client->currency(),
        ];

        return $this->call(fn (Sdk $sdk) => $sdk->checkouts()->create($attributes));
    }

    /**
     * Retrieve a checkout. This is how SumUp says to confirm a payment: after a
     * webhook, or when the payer returns from the Payment Widget.
     */
    public function find(string $checkoutId): CheckoutSuccess
    {
        return $this->call(fn (Sdk $sdk) => $sdk->checkouts()->get($checkoutId));
    }

    /**
     * Whether the checkout has been paid, according to SumUp (not the browser).
     */
    public function paid(string $checkoutId): bool
    {
        return $this->find($checkoutId)->status === 'PAID';
    }

    /**
     * List checkouts, optionally only those with the given checkout_reference.
     *
     * @return list<CheckoutSuccess>
     */
    public function list(?string $checkoutReference = null): array
    {
        $params = null;

        if ($checkoutReference !== null) {
            $params = new CheckoutsListParams;
            $params->checkoutReference = $checkoutReference;
        }

        return $this->call(fn (Sdk $sdk) => $sdk->checkouts()->list($params));
    }

    /**
     * Process a checkout from your server: PUT /v0.1/checkouts/{id}.
     *
     * Most integrations should let the Payment Widget do this instead. Sending raw
     * card numbers puts your servers in scope for PCI DSS; paying with a saved card
     * (`payment_type` card, `token` and `customer_id`) does not.
     *
     * Returns SumUp's response as an array, because a 3-D Secure challenge comes
     * back with a `next_step` that the SDK's Checkout type does not have.
     *
     * The SDK (0.1.x) has no method for this endpoint, so it is sent raw.
     *
     * @param  array<string, mixed>  $payment  e.g. ['payment_type' => 'card', 'token' => $token, 'customer_id' => $customerId]
     * @return array<string, mixed>
     */
    public function process(string $checkoutId, array $payment): array
    {
        return $this->client->request('PUT', '/v0.1/checkouts/'.rawurlencode($checkoutId), $payment);
    }

    /**
     * Change a checkout that has not been processed yet.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(string $checkoutId, array $attributes): Checkout
    {
        return $this->call(fn (Sdk $sdk) => $sdk->checkouts()->update($checkoutId, $attributes));
    }

    /**
     * Deactivate a checkout so that it can no longer be paid.
     */
    public function deactivate(string $checkoutId): Checkout
    {
        return $this->call(fn (Sdk $sdk) => $sdk->checkouts()->deactivate($checkoutId));
    }

    /**
     * The payment method ids (card, apple_pay, ideal…) the merchant can accept, optionally for an amount.
     *
     * @return list<string>
     */
    public function paymentMethods(?Money $amount = null): array
    {
        $params = null;

        if ($amount !== null) {
            $params = new CheckoutsListAvailablePaymentMethodsParams;
            $params->amount = $amount->amount();
            $params->currency = $amount->currency;
        }

        $response = $this->call(fn (Sdk $sdk) => $sdk->checkouts()->listAvailablePaymentMethods($this->merchantCode(), $params));

        return array_values(array_map(
            fn ($method) => is_array($method) ? (string) ($method['id'] ?? '') : (string) $method->id,
            $response->availablePaymentMethods ?? [],
        ));
    }
}
