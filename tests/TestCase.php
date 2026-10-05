<?php

namespace FLAIRUK\SumUp\Tests;

use FLAIRUK\SumUp\Facades\SumUp;
use FLAIRUK\SumUp\SumUpServiceProvider;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected const BASE = 'https://api.sumup.com';

    protected function getPackageProviders($app): array
    {
        return [SumUpServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['SumUp' => SumUp::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('sumup.api_key', 'sup_sk_test');
        $app['config']->set('sumup.merchant_code', 'MTEST123');
        $app['config']->set('sumup.currency', 'GBP');
        $app['config']->set('sumup.retry', [2, 0]);
        $app['config']->set('sumup.oauth.client_id', 'client-id');
        $app['config']->set('sumup.oauth.client_secret', 'client-secret');
        $app['config']->set('sumup.oauth.redirect_uri', 'https://shop.test/sumup/callback');
    }

    /**
     * Fake the given SumUp endpoints (paths relative to the API) and block everything else.
     *
     * @param  array<string, mixed>  $responses
     */
    protected function fakeApi(array $responses = []): void
    {
        Http::preventStrayRequests();

        $fakes = [];
        foreach ($responses as $path => $response) {
            $fakes[str_starts_with($path, 'http') ? $path : self::BASE.$path] = $response;
        }

        Http::fake($fakes);
    }

    /**
     * @return array<string, mixed>
     */
    protected function checkout(array $overrides = []): array
    {
        return $overrides + [
            'id' => 'chk-1',
            'checkout_reference' => 'order-42',
            'amount' => 10.5,
            'currency' => 'GBP',
            'merchant_code' => 'MTEST123',
            'status' => 'PENDING',
            'date' => '2026-10-05T10:00:00.000+00:00',
            'transactions' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function transaction(array $overrides = []): array
    {
        return $overrides + [
            'id' => 'txn-1',
            'transaction_code' => 'TEENSK4W2K',
            'amount' => 10.5,
            'currency' => 'GBP',
            'status' => 'SUCCESSFUL',
            'timestamp' => '2026-10-05T10:00:00.000Z',
        ];
    }
}
