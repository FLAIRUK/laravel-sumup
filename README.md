<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="art/logo-dark.svg">
    <img src="art/logo-light.svg" alt="SumUp for Laravel" width="420">
  </picture>
</p>

<h2 align="center">
  <a href="https://www.php.net/" target="_blank"><img src="https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=flat&logo=php&logoColor=white" alt="PHP 8.2+"></a>&nbsp;
  <a href="https://laravel.com/docs/" target="_blank"><img src="https://img.shields.io/badge/Laravel-12%20%7C%2013-FF2D20?style=flat&logo=laravel&logoColor=white" alt="Laravel 12 or 13"></a>&nbsp;
  <a href="https://github.com/FLAIRUK/laravel-sumup/actions/workflows/tests.yml" target="_blank"><img src="https://img.shields.io/badge/Lint-%E2%9C%93-2EA043?style=flat&logo=githubactions&logoColor=white" alt="Lint"></a>&nbsp;
  <a href="https://github.com/FLAIRUK/laravel-sumup/actions/workflows/tests.yml" target="_blank"><img src="https://img.shields.io/badge/Tests-%E2%9C%93-2EA043?style=flat&logo=githubactions&logoColor=white" alt="Tests"></a>&nbsp;
  <a href="https://packagist.org/packages/flairuk/laravel-sumup" target="_blank"><img src="https://img.shields.io/packagist/dt/flairuk/laravel-sumup?style=flat&logo=packagist&logoColor=white&label=Downloads&color=F28D1A" alt="Downloads on Packagist"></a>&nbsp;
  <a href="https://github.com/FLAIRUK/laravel-sumup/blob/main/LICENSE.md" target="_blank"><img src="https://img.shields.io/github/license/FLAIRUK/laravel-sumup?style=flat&label=License&color=3DA639" alt="MIT licence"></a>&nbsp;
  <a href="https://developer.sumup.com/api" target="_blank"><img src="https://img.shields.io/badge/Client-SumUp%20API-3730A3?style=flat" alt="SumUp API"></a>&nbsp;
  <br>&nbsp;
</h2>

**SumUp for Laravel** — Take card payments online and in person with [SumUp](https://developer.sumup.com) in Laravel 12 and 13, built on SumUp's official PHP SDK.

- **The official SDK, configured.** A singleton [`sumup/sumup-php`](https://github.com/sumup/sumup-php) client with your API key or an OAuth token, sending through Laravel's HTTP client so `Http::fake()` works in your tests.
- **Online checkouts.** Create, retrieve, list, process and deactivate checkouts, with a `<x-sumup-card>` Blade component for SumUp's Payment Widget.
- **Webhooks you can trust.** SumUp does not sign its notifications, so every one is confirmed with the API before a Laravel event is dispatched.
- **Card readers.** Pair SumUp Solo readers and take in-person payments through the Cloud API.
- **Refunds, customers and saved cards.** Full and partial refunds, customers and their payment instruments.
- **OAuth for platforms.** Connect other merchants' SumUp accounts with the authorization-code flow and act for them.
- **Exact money.** `Money` converts between the major units checkouts use and the minor units readers use, without float drift.

<p align="center">
  📦&nbsp;<a href="#-installation">Installation</a> ·
  🚀&nbsp;<a href="#-usage">Usage</a> ·
  🔔&nbsp;<a href="#-webhooks">Webhooks</a> ·
  📟&nbsp;<a href="#-card-readers">Card readers</a> ·
  🔑&nbsp;<a href="#-oauth">OAuth</a> ·
  🔌&nbsp;<a href="#-testing-your-integration">Testing your integration</a>
</p>

<br><br>

## 📦 Installation

```bash
composer require flairuk/laravel-sumup
php artisan sumup:install
```

`sumup:install` publishes `config/sumup.php` and adds any of these keys that are missing to `.env` and `.env.example`, empty. Fill them in:

```dotenv
SUMUP_API_KEY=                   # https://me.sumup.com/settings/api-keys
SUMUP_MERCHANT_CODE=MH4H92C7     # shown in the SumUp dashboard
SUMUP_CURRENCY=GBP               # default checkout currency (EUR if empty)
SUMUP_CLIENT_ID=                 # OAuth only
SUMUP_CLIENT_SECRET=             # OAuth only
SUMUP_REDIRECT_URI=              # OAuth only
```

Then check the connection:

```bash
php artisan sumup:status
```

It shows the merchant's name, country, currency and whether it is a sandbox. Without a merchant code, it lists the merchant codes the key can use.

> To test without moving real money, create a sandbox merchant in the [developer settings](https://me.sumup.com/settings/developer) and use an API key from it.

<br><br>

## 🚀 Usage

```php
use FLAIRUK\SumUp\Facades\SumUp;
```

You can also type-hint `FLAIRUK\SumUp\SumUp` to have it injected.

Methods return the SDK's typed objects from `SumUp\Types` (for example `Checkout`, `TransactionFull`, `Reader`), with camelCase properties.

### Online checkouts

A checkout is a payment waiting to happen. Create it on your server, let the payer pay with the Payment Widget, then confirm the result on your server.

```php
$checkout = SumUp::checkouts()->create([
    'checkout_reference' => $order->uuid,          // unique per payment attempt
    'amount' => SumUp::money('24.99'),             // or 24.99 in SUMUP_CURRENCY
    'description' => "Order #{$order->id}",
    'return_url' => SumUp::webhookUrl(),           // where SumUp sends status changes
]);

$order->update(['sumup_checkout_id' => $checkout->id]);
```

`merchant_code` and `currency` default to your config. Other attributes are passed to SumUp as they are: `customer_id`, `purpose`, `valid_until`, `redirect_url` (needed for some payment methods such as iDEAL) and `hosted_checkout`.

Render the widget in a Blade view:

```blade
<x-sumup-card :checkout="$checkout" :success-url="route('orders.paid', $order)" />
```

The component takes the checkout or its id, and the widget's options as attributes: `id`, `locale`, `email`, `show-email`, `show-submit-button`, `show-footer`, `amount`, `currency` and `country`. `success-url` and `fail-url` send the payer on, with `?checkout_id=` added. `on-response` names a global JavaScript function to call with the widget's `(type, body)`. `nonce` is added to the script tags if you use a Content Security Policy.

The widget runs in the payer's browser, so its "success" is only a hint. Confirm the payment with SumUp before you fulfil the order:

```php
public function paid(Order $order)
{
    abort_unless(SumUp::checkouts()->paid($order->sumup_checkout_id), 402);

    // ...
}
```

The rest of the checkout API:

```php
SumUp::checkouts()->find($id);                 // status: PENDING, PAID, FAILED or EXPIRED
SumUp::checkouts()->list($checkoutReference);  // or list() for all
SumUp::checkouts()->update($id, ['description' => 'Order #43']);
SumUp::checkouts()->deactivate($id);
SumUp::checkouts()->paymentMethods(SumUp::money('10'));   // ['card', 'apple_pay', ...]
```

To charge a saved card from your server, process the checkout with the card's token:

```php
$result = SumUp::checkouts()->process($checkout->id, [
    'payment_type' => 'card',
    'token' => $card->token,
    'customer_id' => $customer->customerId,
]);

if (isset($result['next_step'])) {
    // 3-D Secure: send the payer to $result['next_step']['url']
}
```

`process()` returns SumUp's response as an array, because a 3-D Secure challenge comes back with a `next_step` that the SDK's types do not have. It can also send raw card details, but then your servers are in scope for PCI DSS. Use the Payment Widget for new cards.

### Transactions and refunds

Transactions are SumUp's record of a payment, online or in person.

```php
SumUp::transactions()->find($transactionId);
SumUp::transactions()->findByCode('TEENSK4W2K');
SumUp::transactions()->findByClientTransactionId($id);   // from a reader checkout

$page = SumUp::transactions()->list([
    'limit' => 50,
    'order' => 'descending',
    'statuses' => ['SUCCESSFUL', 'REFUNDED'],
    'changes_since' => now()->subDay(),
]);

foreach ($page->items as $transaction) {
    // $transaction->transactionCode, ->amount, ->status ...
}

SumUp::transactions()->refund($transactionId);                         // in full
SumUp::transactions()->refund($transactionId, SumUp::money('5.00'));   // partly
```

The other filters are `transaction_code`, `users`, `payment_types`, `entry_modes`, `types`, `newest_time`, `newest_ref`, `oldest_time` and `oldest_ref`.

### Customers and saved cards

```php
SumUp::customers()->create([
    'customer_id' => (string) $user->id,
    'personal_details' => ['first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@example.com'],
]);

SumUp::customers()->find($customerId);
SumUp::customers()->update($customerId, ['personal_details' => ['phone' => '+447700900000']]);

$cards = SumUp::customers()->paymentInstruments($customerId);   // ->token, ->card->last4Digits
SumUp::customers()->deactivatePaymentInstrument($customerId, $cards[0]->token);
```

To save a card, create a checkout with `'purpose' => 'SETUP_RECURRING_PAYMENT'` and the `customer_id`, and let the payer complete it with the Payment Widget.

### Merchant profile

```php
$merchant = SumUp::merchant();       // or SumUp::merchant('MOTHER99')

$merchant->company?->name;
$merchant->country;                  // "GB"
$merchant->defaultCurrency;          // "GBP"
$merchant->sandbox;
```

### Money

```php
use FLAIRUK\SumUp\Money;

$price = Money::of('10.50', 'GBP');       // strings are exact; '10.505' throws
$price = Money::ofMinor(1050, 'GBP');
$price = SumUp::money('10.50');           // in SUMUP_CURRENCY

$price->minor;                            // 1050
$price->amount();                         // 10.5, as checkouts want it
$price->decimal();                        // "10.50"
$price->toReaderAmount();                 // ['value' => 1050, 'currency' => 'GBP', 'minor_unit' => 2]
$price->plus(Money::of('2', 'GBP'));      // and minus(), equals(), isZero()

Money::fromReaderAmount($checkout->data->totalAmount);   // works with arrays from the Cloud API
```

### Errors

```php
use FLAIRUK\SumUp\Exceptions\ApiException;
use FLAIRUK\SumUp\Exceptions\ConnectionException;
use FLAIRUK\SumUp\Exceptions\SumUpException;

try {
    SumUp::transactions()->refund($transactionId);
} catch (ApiException $e) {
    $e->status;      // 409
    $e->body;        // SumUp's error, decoded (e.g. \SumUp\Types\Problem)
    $e->notFound();
} catch (ConnectionException $e) {
    // SumUp could not be reached
}
```

Every exception extends `SumUpException`. A missing API key or merchant code throws `ConfigurationException`, and the OAuth helpers throw `OAuthException`.

Reads are retried on connection errors and 5xx responses. Writes are never retried automatically, so a refund is never sent twice.

### Everything else

The SDK covers more of SumUp's API, such as payouts, receipts, members and roles. Use it directly:

```php
use SumUp\Services\PayoutsListParams;

$params = new PayoutsListParams;
$params->startDate = '2026-09-01';
$params->endDate = '2026-09-30';

SumUp::sdk()->payouts()->list(SumUp::merchantCode(), $params);
```

Calls made through `sdk()` throw the SDK's own exceptions (`SumUp\Exception\ApiException` and so on). For an endpoint the SDK has no method for:

```php
SumUp::request('GET', '/v0.1/some/endpoint');
```

<br><br>

## 🔔 Webhooks

SumUp posts a notification to a checkout's `return_url` when its status changes. The package registers a `POST /sumup/webhook` route (named `sumup.webhook`, without the `web` middleware, so CSRF does not apply). Pass `SumUp::webhookUrl()` as the `return_url`. SumUp has to reach it, so it must be public and use HTTPS. Because notifications are unsigned and each one is confirmed with an API call, the route is rate limited to 60 requests a minute per IP; change `sumup.webhooks.middleware` in the config to adjust it.

**SumUp does not sign its webhooks.** A notification has no signature or secret, only an event type and an id:

```json
{"event_type": "CHECKOUT_STATUS_CHANGED", "id": "4ebfe5a8-..."}
```

[SumUp's documentation](https://developer.sumup.com/online-payments/webhooks) says to verify every notification by calling the API, and that is what the package does. It uses the body only to know what to look up (the checkout id, or a reader payment's merchant code and `client_transaction_id`), fetches it from SumUp with your credentials, and dispatches an event with what the API returned. A forged request can make you look something up, but it cannot change what the event says.

```php
use FLAIRUK\SumUp\Events\CheckoutStatusChanged;

Event::listen(function (CheckoutStatusChanged $event) {
    if ($event->paid()) {
        Order::where('sumup_checkout_id', $event->checkout->id)->first()?->markPaid();
    }
});
```

| Event | When | Carries |
| --- | --- | --- |
| `CheckoutStatusChanged` | `CHECKOUT_STATUS_CHANGED`, confirmed | `$checkout` fetched from the API, `$notification`, `paid()` |
| `ReaderCheckoutStatusChanged` | `solo.transaction.updated` from a reader checkout, confirmed | `$transaction` fetched by `client_transaction_id`, `$notification`, `successful()` |
| `WebhookReceived` | Every well-formed notification, before checking, including event types added later | `$notification` only. Don't fulfil orders from it |

All three are in `FLAIRUK\SumUp\Events`.

How the route answers:

- **200, empty body:** the notification was handled. If SumUp says the checkout or transaction does not exist (404), no event is dispatched, a warning is logged and the route still answers 200, so forged or stale notifications are not retried.
- **400:** the body was not a SumUp notification.
- **500:** SumUp could not be asked (an outage, or a 401 or 403 for your key). SumUp retries after 1 minute, 5 minutes, 20 minutes and 2 hours. Reader callbacks are retried up to 5 times.

SumUp retries notifications, so an event can arrive more than once for the same payment. Make listeners idempotent. If a listener does slow work, queue it: SumUp treats a slow answer as a failure.

If you create checkouts for merchants connected with OAuth, tell the handler which merchant a notification belongs to. Put that in the URL, then resolve a client for it:

```php
// When creating the checkout
'return_url' => SumUp::webhookUrl(['merchant' => $account->merchant_code]),

// In a service provider's boot()
use FLAIRUK\SumUp\Webhooks\Notification;
use Illuminate\Http\Request;

SumUp::webhooks()->resolveClientUsing(function (Notification $notification, Request $request) {
    $account = SumUpAccount::where('merchant_code', $request->query('merchant'))->firstOrFail();

    return SumUp::forMerchant($account->merchant_code, $account->token());
});
```

Change the path or middleware with `sumup.webhooks.path` and `sumup.webhooks.middleware`, or set `SUMUP_WEBHOOKS=false` and register your own route. `SumUp::webhooks()->handle($request)` does the same work and returns `false` if the body was not a notification; your route then has to answer with an empty 2xx.

<br><br>

## 📟 Card readers

The [Cloud API](https://developer.sumup.com/terminal-payments/cloud-api) takes in-person payments on SumUp Solo readers.

To pair a reader, log out on the reader, open **Connections › API › Connect** to get a pairing code, then:

```php
$reader = SumUp::readers()->pair('ABC123', 'Front counter');
```

Then start a payment on it:

```php
$started = SumUp::readers()->checkout($reader->id, SumUp::money('15.00'), [
    'description' => 'Table 4',
    'return_url' => SumUp::webhookUrl(),
]);

$started->data->clientTransactionId;   // keep this to match the result
```

The reader must be online. The result arrives as a `ReaderCheckoutStatusChanged` event (see [Webhooks](#-webhooks)). You can also look it up with `SumUp::transactions()->findByClientTransactionId()`. Other checkout attributes are `tip_rates`, `tip_timeout`, `installments`, `card_type` and `affiliate`.

```php
SumUp::readers()->list();
SumUp::readers()->find($readerId);
SumUp::readers()->status($readerId);                   // ->data->status (ONLINE/OFFLINE), ->data->state (IDLE, WAITING_FOR_CARD...)
SumUp::readers()->findCheckout($readerId, $checkoutId);
SumUp::readers()->terminate($readerId);                // cancel the payment in progress
SumUp::readers()->update($readerId, ['name' => 'Bar']);
SumUp::readers()->delete($readerId);
```

<br><br>

## 🔑 OAuth

API keys only work for your own account. If your app takes payments for other SumUp merchants, register an [OAuth application](https://me.sumup.com/settings/oauth2-applications), set `SUMUP_CLIENT_ID`, `SUMUP_CLIENT_SECRET` and `SUMUP_REDIRECT_URI`, and connect each merchant with the [authorization-code flow](https://developer.sumup.com/tools/authorization/oauth):

```php
// routes/web.php
Route::get('/sumup/connect', fn () => SumUp::oauth()->redirect(['payments', 'transactions.history']));

Route::get('/sumup/callback', function (Request $request) {
    $token = SumUp::oauth()->callback($request);   // checks the state, exchanges the code

    $membership = collect(SumUp::withToken($token)->memberships())->firstWhere('resource.type', 'merchant');
    $merchantCode = $membership->resource->id;

    $request->user()->sumupAccount()->updateOrCreate([], [
        'merchant_code' => $merchantCode,
        'token' => $token->toArray(),              // cast 'token' => 'encrypted:array'
    ]);

    return redirect('/settings');
});
```

`redirect()` stores a random `state` in the session and `callback()` checks it. A mismatch throws `InvalidStateException`. If the merchant declines, or the code cannot be exchanged, `callback()` throws `OAuthException`. Set the default scopes with `SUMUP_SCOPES` (space-separated). Pass an empty array for SumUp's defaults. SumUp has to verify your app before it can request `payments` or `payment_instruments`.

Then act for the merchant:

```php
use FLAIRUK\SumUp\OAuth\AccessToken;

$token = AccessToken::fromArray($account->token);

if ($token->isExpired()) {   // access tokens last about an hour
    $token = SumUp::oauth()->refresh($token);
    $account->update(['token' => $token->toArray()]);
}

SumUp::forMerchant($account->merchant_code, $token)->checkouts()->create([...]);
```

`refresh()` keeps the old refresh token if SumUp does not send a new one. There are also `authorizationUrl($state, $scopes)`, `exchange($code)` and `clientCredentials($scopes)` for your own account. A client-credentials token has no refresh token.

<br><br>

## 🔌 Testing your integration

The SDK sends its requests through Laravel's HTTP client, so `Http::fake()` works in your own tests:

```php
Http::fake([
    'api.sumup.com/v0.1/checkouts' => Http::response(['id' => 'chk_1', 'status' => 'PENDING'], 201),
    'api.sumup.com/v0.1/checkouts/chk_1' => Http::response(['id' => 'chk_1', 'status' => 'PAID']),
]);
```

To test your listeners, post a notification to the webhook route and fake the checkout it fetches:

```php
$this->postJson(route('sumup.webhook'), ['event_type' => 'CHECKOUT_STATUS_CHANGED', 'id' => 'chk_1'])->assertOk();
```

<br><br>

## 🧪 Testing

```bash
composer test
```

<br><br>

## 🔒 Security

If you discover a security issue, please email ijeffrouk@gmail.com instead of using the issue tracker.

<br><br>

## 🙌 Credits

- [Phil Graham](https://github.com/ijeffro)
- [FLAIR](https://github.com/flairuk)
- [All Contributors](../../contributors)

SumUp is a trademark of SumUp Payments Limited. This package is not affiliated with or endorsed by SumUp.

<br><br>

## 📄 License

MIT. See [LICENSE](LICENSE.md).
