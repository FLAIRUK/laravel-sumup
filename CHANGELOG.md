# Changelog

All notable changes to `laravel-sumup` will be documented in this file.

## 1.0.1 - 2026-10-05

- An empty `SUMUP_CURRENCY`, `SUMUP_WEBHOOK_PATH`, `SUMUP_BASE_URL` or `SUMUP_TIMEOUT`, as `sumup:install` writes them, now falls back to the default; an empty currency used to break `money()` and checkouts.
- A webhook whose confirmation is refused with a 403 is acknowledged like a 404, instead of failing so SumUp retries it for ever.
- README: requirements, the 403 case, and that reader `status` and `state` are enums.

## 1.0.0 - 2026-10-05

First release, for Laravel 12 and 13 (PHP 8.2+), built on SumUp's official PHP SDK (`sumup/sumup-php` 0.1.6+).

- A configured SDK client (API key or OAuth access token, merchant code) bound as a singleton behind the `SumUp` facade, sending through Laravel's HTTP client so `Http::fake()` works. GET requests are retried on connection errors and 5xx; writes never are.
- Online checkouts: create, retrieve, `paid()`, list by reference, update, process (including saved cards and the 3-D Secure `next_step`), deactivate and available payment methods.
- Transactions (by id, transaction code or client transaction id, history with filters) and full or partial refunds.
- Customers and their saved cards (payment instruments).
- Merchant profile and memberships.
- Cloud API card readers: pair, list, update, delete, status, start and cancel a payment, read a reader checkout.
- OAuth 2.0 authorization-code helpers: consent redirect with session state, callback with state check, code exchange, refresh, client credentials, and an `AccessToken` value object.
- A webhook route that confirms each notification with the SumUp API (SumUp does not sign them) before dispatching `CheckoutStatusChanged` or `ReaderCheckoutStatusChanged`, plus `WebhookReceived` for every notification.
- `Money` for exact major/minor unit conversion.
- `<x-sumup-card>` Blade component for the Payment Widget.
- `sumup:install` and `sumup:status` commands.
- Typed exceptions: `ApiException`, `ConnectionException`, `ConfigurationException`, `OAuthException`, `InvalidStateException`.
