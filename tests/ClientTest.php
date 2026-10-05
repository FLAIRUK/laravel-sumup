<?php

namespace FLAIRUK\SumUp\Tests;

use FLAIRUK\SumUp\Exceptions\ApiException;
use FLAIRUK\SumUp\Exceptions\ConfigurationException;
use FLAIRUK\SumUp\Exceptions\ConnectionException;
use FLAIRUK\SumUp\Facades\SumUp;
use FLAIRUK\SumUp\Money;
use FLAIRUK\SumUp\OAuth\AccessToken;
use FLAIRUK\SumUp\SumUp as Client;
use Illuminate\Http\Client\ConnectionException as LaravelConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use SumUp\SumUp as Sdk;
use SumUp\Types\Merchant;

class ClientTest extends TestCase
{
    #[Test]
    public function the_client_is_a_configured_singleton_behind_the_facade(): void
    {
        $client = $this->app->make(Client::class);

        $this->assertSame($client, $this->app->make(Client::class));
        $this->assertSame($client, $this->app->make('sumup'));
        $this->assertSame($client, SumUp::getFacadeRoot());
        $this->assertSame('MTEST123', SumUp::merchantCode());
        $this->assertSame('GBP', SumUp::currency());
        $this->assertInstanceOf(Sdk::class, SumUp::sdk());
        $this->assertSame('sup_sk_test', SumUp::sdk()->getDefaultAccessToken());
        $this->assertSame(SumUp::sdk(), SumUp::sdk());
    }

    #[Test]
    public function an_access_token_takes_precedence_over_the_api_key(): void
    {
        config(['sumup.access_token' => 'oauth-token']);
        $this->app->forgetInstance(Client::class);
        SumUp::clearResolvedInstances();

        $this->assertSame('oauth-token', SumUp::sdk()->getDefaultAccessToken());
    }

    #[Test]
    public function a_missing_api_key_throws_a_configuration_exception(): void
    {
        config(['sumup.api_key' => null]);
        $this->app->forgetInstance(Client::class);
        SumUp::clearResolvedInstances();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('SUMUP_API_KEY');

        SumUp::checkouts()->find('chk-1');
    }

    #[Test]
    public function a_missing_merchant_code_throws_a_configuration_exception(): void
    {
        config(['sumup.merchant_code' => null]);
        $this->app->forgetInstance(Client::class);
        SumUp::clearResolvedInstances();
        Http::preventStrayRequests();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('SUMUP_MERCHANT_CODE');

        SumUp::readers()->list();
    }

    #[Test]
    public function for_merchant_and_with_token_return_copies(): void
    {
        $this->fakeApi(['/v0.1/merchants/MOTHER99/readers' => Http::response(['items' => []])]);

        $other = SumUp::forMerchant('MOTHER99', new AccessToken('merchant-token'));

        $this->assertNotSame(SumUp::getFacadeRoot(), $other);
        $this->assertSame('MOTHER99', $other->merchantCode());
        $this->assertSame('MTEST123', SumUp::merchantCode());
        $this->assertSame('sup_sk_test', SumUp::sdk()->getDefaultAccessToken());

        $this->assertSame([], $other->readers()->list());
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer merchant-token'));

        $this->assertSame('sup_sk_test', SumUp::forMerchant('MOTHER99')->sdk()->getDefaultAccessToken());
        $this->assertSame('other', SumUp::withToken('other')->sdk()->getDefaultAccessToken());
        $this->assertSame('MTEST123', SumUp::withToken('other')->merchantCode());
    }

    #[Test]
    public function money_uses_the_default_currency(): void
    {
        $this->assertTrue(SumUp::money('10.50')->equals(Money::ofMinor(1050, 'GBP')));
        $this->assertSame('EUR', SumUp::money(3, 'eur')->currency);
    }

    #[Test]
    public function merchant_returns_the_profile(): void
    {
        $this->fakeApi(['/v1/merchants/MTEST123' => Http::response([
            'merchant_code' => 'MTEST123',
            'country' => 'GB',
            'default_currency' => 'GBP',
            'default_locale' => 'en-GB',
            'sandbox' => true,
            'company' => ['name' => 'Test Shop Ltd'],
            'created_at' => '2026-01-01T00:00:00Z',
            'updated_at' => '2026-01-01T00:00:00Z',
        ])]);

        $merchant = SumUp::merchant();

        $this->assertInstanceOf(Merchant::class, $merchant);
        $this->assertSame('Test Shop Ltd', $merchant->company->name);
        $this->assertTrue($merchant->sandbox);
    }

    #[Test]
    public function memberships_list_the_merchants_a_token_can_use(): void
    {
        $this->fakeApi(['/v0.1/memberships' => Http::response(['items' => [$this->membership()], 'total_count' => 1])]);

        $memberships = SumUp::memberships();

        $this->assertCount(1, $memberships);
        $this->assertSame('MTEST123', $memberships[0]->resource->id);
    }

    #[Test]
    public function api_errors_become_api_exceptions(): void
    {
        $this->fakeApi(['/v0.1/checkouts/missing' => Http::response(['error_code' => 'NOT_FOUND', 'message' => 'Resource not found'], 404)]);

        try {
            SumUp::checkouts()->find('missing');
            $this->fail('No exception was thrown.');
        } catch (ApiException $e) {
            $this->assertSame(404, $e->status);
            $this->assertTrue($e->notFound());
            $this->assertSame('GET', $e->method);
            $this->assertSame('/v0.1/checkouts/missing', $e->path);
            $this->assertStringContainsString('Resource not found', $e->getMessage());
            $this->assertInstanceOf(\SumUp\Exception\ApiException::class, $e->getPrevious());
        }
    }

    #[Test]
    public function reads_are_retried_on_server_errors(): void
    {
        $this->fakeApi(['/v0.1/checkouts/chk-1' => Http::sequence()
            ->push('', 503)
            ->push($this->checkout())]);

        $this->assertSame('chk-1', SumUp::checkouts()->find('chk-1')->id);
        Http::assertSentCount(2);
    }

    #[Test]
    public function writes_are_never_retried(): void
    {
        $this->fakeApi(['/v1.0/merchants/MTEST123/payments/txn-1/refunds' => Http::sequence()
            ->push(['detail' => 'Unavailable'], 503)
            ->push([], 201)]);

        $this->expectException(ApiException::class);

        try {
            SumUp::transactions()->refund('txn-1');
        } finally {
            Http::assertSentCount(1);
        }
    }

    #[Test]
    public function connection_failures_become_connection_exceptions(): void
    {
        Http::fake(fn () => throw new LaravelConnectionException('cURL error 6: Could not resolve host'));

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Could not resolve host');

        SumUp::checkouts()->create(['checkout_reference' => 'x', 'amount' => 1]);
    }

    #[Test]
    public function raw_requests_go_through_the_configured_client(): void
    {
        $this->fakeApi(['/v1.0/merchants/MTEST123/payouts*' => Http::response([['id' => 1, 'amount' => 20.5]])]);

        $this->assertSame(20.5, SumUp::request('get', '/v1.0/merchants/MTEST123/payouts?start_date=2026-10-01&end_date=2026-10-05')[0]['amount']);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET' && $request->hasHeader('Authorization', 'Bearer sup_sk_test'));
    }

    #[Test]
    public function the_webhook_url_is_the_package_route(): void
    {
        $this->assertSame('http://localhost/sumup/webhook', SumUp::webhookUrl());
        $this->assertSame('http://localhost/sumup/webhook?merchant=MOTHER99', SumUp::webhookUrl(['merchant' => 'MOTHER99']));
    }

    /**
     * @return array<string, mixed>
     */
    protected function membership(): array
    {
        return [
            'id' => 'mem-1',
            'resource_id' => 'MTEST123',
            'type' => 'merchant',
            'roles' => ['role_owner'],
            'permissions' => [],
            'created_at' => '2026-01-01T00:00:00Z',
            'updated_at' => '2026-01-01T00:00:00Z',
            'status' => 'accepted',
            'resource' => [
                'id' => 'MTEST123',
                'type' => 'merchant',
                'name' => 'Test Shop',
                'created_at' => '2026-01-01T00:00:00Z',
                'updated_at' => '2026-01-01T00:00:00Z',
            ],
        ];
    }
}
