<?php

namespace FLAIRUK\SumUp\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;
use SumUp\Types\Checkout;
use SumUp\Types\CheckoutSuccess;

/**
 * SumUp's Payment Widget (card widget): <x-sumup-card :checkout="$checkout" />.
 *
 * The widget runs in the payer's browser, so its "success" is only a hint:
 * confirm the payment on your server with SumUp::checkouts()->find() or paid().
 *
 * @see https://developer.sumup.com/online-payments/checkouts/card-widget
 */
class CardWidget extends Component
{
    public string $checkoutId;

    /**
     * @param  Checkout|CheckoutSuccess|string  $checkout  the checkout, or its id
     * @param  string|null  $onResponse  name of a global JavaScript function to call with (type, body)
     * @param  string|null  $successUrl  where to send the payer on "success"; checkout_id is appended
     * @param  string|null  $failUrl  where to send the payer on "fail"; checkout_id is appended
     */
    public function __construct(
        Checkout|CheckoutSuccess|string $checkout,
        public string $id = 'sumup-card',
        public ?string $locale = null,
        public ?string $email = null,
        public ?bool $showEmail = null,
        public ?bool $showSubmitButton = null,
        public ?bool $showFooter = null,
        public ?string $amount = null,
        public ?string $currency = null,
        public ?string $country = null,
        public ?string $onResponse = null,
        public ?string $successUrl = null,
        public ?string $failUrl = null,
        public ?string $nonce = null,
    ) {
        $this->checkoutId = is_string($checkout) ? $checkout : (string) $checkout->id;
        $this->locale ??= config('sumup.widget.locale');
    }

    /**
     * The options passed to SumUpCard.mount(), without the callbacks.
     *
     * @return array<string, mixed>
     */
    public function options(): array
    {
        return array_filter([
            'id' => $this->id,
            'checkoutId' => $this->checkoutId,
            'locale' => $this->locale,
            'email' => $this->email,
            'showEmail' => $this->showEmail,
            'showSubmitButton' => $this->showSubmitButton,
            'showFooter' => $this->showFooter,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'country' => $this->country,
        ], fn ($value) => $value !== null);
    }

    public function scriptUrl(): string
    {
        return config('sumup.widget.script_url');
    }

    public function nonceAttribute(): string
    {
        return $this->nonce === null ? '' : ' nonce="'.e($this->nonce).'"';
    }

    public function render(): View
    {
        return view('sumup::components.card-widget');
    }
}
