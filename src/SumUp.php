<?php

namespace FLAIRUK\SumUp;

use FLAIRUK\SumUp\Exceptions\ConfigurationException;
use FLAIRUK\SumUp\Http\LaravelHttpClient;
use FLAIRUK\SumUp\OAuth\AccessToken;
use FLAIRUK\SumUp\OAuth\OAuth;
use FLAIRUK\SumUp\Resources\Resource;
use FLAIRUK\SumUp\Webhooks\WebhookHandler;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\Client\Factory as Http;
use SumUp\ResponseDecoder;
use SumUp\SumUp as Sdk;
use SumUp\Types\Membership;
use SumUp\Types\Merchant;

/**
 * The SumUp client: a configured instance of SumUp's official SDK (sumup/sumup-php)
 * plus Laravel conveniences. Use sdk() for anything not wrapped here.
 */
class SumUp
{
    protected ?Sdk $sdk = null;

    /**
     * @param  array<string, mixed>  $config  the `sumup` config
     */
    public function __construct(
        protected Http $http,
        protected array $config,
        protected ?string $accessToken = null,
        protected ?string $merchantCode = null,
    ) {
        $this->accessToken ??= ($config['access_token'] ?? null) ?: (($config['api_key'] ?? null) ?: null);
        $this->merchantCode ??= ($config['merchant_code'] ?? null) ?: null;
    }

    /**
     * A copy of the client for another merchant, e.g. one connected with OAuth.
     * Without a token, the current one (your API key) is kept.
     */
    public function forMerchant(string $merchantCode, AccessToken|string|null $token = null): static
    {
        $clone = $token === null ? clone $this : $this->withToken($token);
        $clone->merchantCode = $merchantCode;

        return $clone;
    }

    /**
     * A copy of the client that authenticates with the given API key or OAuth access token.
     */
    public function withToken(AccessToken|string $token): static
    {
        $clone = clone $this;
        $clone->accessToken = (string) $token;
        $clone->sdk = null;

        return $clone;
    }

    /**
     * The underlying SumUp SDK client, authenticated and sending through Laravel's HTTP client.
     *
     * @throws ConfigurationException when there is no API key or access token
     */
    public function sdk(): Sdk
    {
        if (! $this->accessToken) {
            throw new ConfigurationException('Set SUMUP_API_KEY (or SUMUP_ACCESS_TOKEN), or call SumUp::withToken(), to use the SumUp API.');
        }

        return $this->sdk ??= new Sdk([
            'access_token' => $this->accessToken,
            'client' => new LaravelHttpClient(
                $this->http,
                $this->config['base_url'] ?? 'https://api.sumup.com',
                (int) ($this->config['timeout'] ?? 30),
                (int) ($this->config['connect_timeout'] ?? 10),
                $this->config['retry'] ?? [2, 250],
            ),
        ]);
    }

    public function merchantCode(): ?string
    {
        return $this->merchantCode;
    }

    /**
     * @throws ConfigurationException
     */
    public function requireMerchantCode(): string
    {
        return $this->merchantCode ?: throw new ConfigurationException('Set SUMUP_MERCHANT_CODE, or call SumUp::forMerchant(), to use this SumUp endpoint.');
    }

    /**
     * The default currency for checkouts and SumUp::money() (SUMUP_CURRENCY).
     */
    public function currency(): string
    {
        return strtoupper($this->config['currency'] ?? 'EUR');
    }

    /**
     * Money in the default currency: SumUp::money('10.50').
     */
    public function money(string|int|float $amount, ?string $currency = null): Money
    {
        return Money::of($amount, $currency ?? $this->currency());
    }

    // ---------------------------------------------------------------------
    // Resources
    // ---------------------------------------------------------------------

    public function checkouts(): Resources\Checkouts
    {
        return new Resources\Checkouts($this);
    }

    public function transactions(): Resources\Transactions
    {
        return new Resources\Transactions($this);
    }

    public function customers(): Resources\Customers
    {
        return new Resources\Customers($this);
    }

    public function readers(): Resources\Readers
    {
        return new Resources\Readers($this);
    }

    /**
     * The merchant's profile: business name, country, default currency, whether it is a sandbox.
     */
    public function merchant(?string $merchantCode = null): Merchant
    {
        $merchantCode ??= $this->requireMerchantCode();

        return Resource::rethrow(fn () => $this->sdk()->merchants()->get($merchantCode));
    }

    /**
     * The merchant accounts the token can act for. After OAuth, a membership's
     * `resource->id` is the merchant code to pass to forMerchant().
     *
     * @return list<Membership>
     */
    public function memberships(): array
    {
        return Resource::rethrow(fn () => $this->sdk()->memberships()->list()->items);
    }

    public function oauth(): OAuth
    {
        return new OAuth($this->http, $this->config['oauth'], (int) ($this->config['timeout'] ?? 30));
    }

    public function webhooks(): WebhookHandler
    {
        return app(WebhookHandler::class);
    }

    /**
     * The URL of this package's webhook route, for a checkout's `return_url`.
     * SumUp must be able to reach it, so it has to be public and HTTPS.
     *
     * @param  array<string, mixed>  $parameters  extra query parameters, e.g. to identify the merchant
     */
    public function webhookUrl(array $parameters = []): string
    {
        return app(UrlGenerator::class)->route('sumup.webhook', $parameters);
    }

    /**
     * Send a request the SDK has no method for, and return the decoded body.
     *
     * @param  array<string, mixed>  $body
     */
    public function request(string $method, string $path, array $body = []): mixed
    {
        $method = strtoupper($method);

        return Resource::rethrow(fn () => ResponseDecoder::decodeOrThrow(
            $this->sdk()->request($method, $path, $body), null, null, $method, $path,
        ));
    }
}
