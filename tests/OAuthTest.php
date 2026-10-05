<?php

namespace FLAIRUK\SumUp\Tests;

use Carbon\CarbonImmutable;
use FLAIRUK\SumUp\Exceptions\ConfigurationException;
use FLAIRUK\SumUp\Exceptions\InvalidStateException;
use FLAIRUK\SumUp\Exceptions\OAuthException;
use FLAIRUK\SumUp\Facades\SumUp;
use FLAIRUK\SumUp\OAuth\AccessToken;
use FLAIRUK\SumUp\OAuth\OAuth;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

class OAuthTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function the_authorization_url_has_the_documented_parameters(): void
    {
        config(['sumup.oauth.scopes' => ['payments', 'transactions.history']]);

        $url = SumUp::oauth()->authorizationUrl('state-123');

        $this->assertStringStartsWith('https://api.sumup.com/authorize?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame([
            'response_type' => 'code',
            'client_id' => 'client-id',
            'redirect_uri' => 'https://shop.test/sumup/callback',
            'scope' => 'payments transactions.history',
            'state' => 'state-123',
        ], $query);
        $this->assertStringContainsString('scope=payments%20transactions.history', $url);
    }

    #[Test]
    public function an_empty_scope_list_leaves_scope_out_for_sumups_defaults(): void
    {
        $url = SumUp::oauth()->authorizationUrl('s', [], 'https://other.test/cb');

        $this->assertStringNotContainsString('scope=', $url);
        $this->assertStringContainsString('redirect_uri=https%3A%2F%2Fother.test%2Fcb', $url);
    }

    #[Test]
    public function redirect_stores_the_state_in_the_session(): void
    {
        $response = SumUp::oauth()->redirect(['payments']);

        $state = session(OAuth::SESSION_KEY);
        $this->assertSame(40, strlen($state));
        $this->assertStringStartsWith('https://api.sumup.com/authorize?', $response->getTargetUrl());
        $this->assertStringContainsString('state='.$state, $response->getTargetUrl());
    }

    #[Test]
    public function callback_checks_the_state_and_exchanges_the_code(): void
    {
        CarbonImmutable::setTestNow('2026-10-05 12:00:00');
        $this->fakeApi(['/token' => Http::response([
            'access_token' => 'access-1',
            'token_type' => 'Bearer',
            'expires_in' => 3599,
            'refresh_token' => 'refresh-1',
            'scope' => 'payments transactions.history',
        ])]);

        $token = SumUp::oauth()->callback($this->callbackRequest(['code' => 'code-1', 'state' => 'abc'], 'abc'));

        $this->assertSame('access-1', $token->token);
        $this->assertSame('refresh-1', $token->refreshToken);
        $this->assertSame(['payments', 'transactions.history'], $token->scopes);
        $this->assertTrue($token->hasScope('payments'));
        $this->assertEquals(CarbonImmutable::parse('2026-10-05 12:59:59'), $token->expiresAt);

        Http::assertSent(fn (HttpRequest $request) => $request->url() === self::BASE.'/token'
            && $request->method() === 'POST'
            && $request->isForm()
            && $request->data() === [
                'grant_type' => 'authorization_code',
                'code' => 'code-1',
                'redirect_uri' => 'https://shop.test/sumup/callback',
                'client_id' => 'client-id',
                'client_secret' => 'client-secret',
            ]);
    }

    #[Test]
    public function callback_rejects_a_mismatched_state(): void
    {
        Http::preventStrayRequests();
        $request = $this->callbackRequest(['code' => 'code-1', 'state' => 'forged'], 'abc');

        $this->expectException(InvalidStateException::class);

        try {
            SumUp::oauth()->callback($request);
        } finally {
            $this->assertNull($request->session()->get(OAuth::SESSION_KEY), 'The state is single-use.');
        }
    }

    #[Test]
    public function callback_rejects_a_missing_session_state(): void
    {
        Http::preventStrayRequests();

        $this->expectException(InvalidStateException::class);

        SumUp::oauth()->callback($this->callbackRequest(['code' => 'code-1', 'state' => 'abc'], null));
    }

    #[Test]
    public function callback_reports_a_declined_authorisation(): void
    {
        Http::preventStrayRequests();

        try {
            SumUp::oauth()->callback($this->callbackRequest(['error' => 'access_denied', 'state' => 'abc'], 'abc'));
            $this->fail('No exception was thrown.');
        } catch (OAuthException $e) {
            $this->assertNotInstanceOf(InvalidStateException::class, $e);
            $this->assertSame('access_denied', $e->error);
        }
    }

    #[Test]
    public function refresh_keeps_the_old_refresh_token_when_none_is_returned(): void
    {
        $this->fakeApi(['/token' => Http::response(['access_token' => 'access-2', 'token_type' => 'Bearer', 'expires_in' => 3599])]);

        $token = SumUp::oauth()->refresh(new AccessToken('access-1', 'refresh-1'));

        $this->assertSame('access-2', $token->token);
        $this->assertSame('refresh-1', $token->refreshToken);
        Http::assertSent(fn (HttpRequest $request) => $request['grant_type'] === 'refresh_token'
            && $request['refresh_token'] === 'refresh-1'
            && $request['client_secret'] === 'client-secret');
    }

    #[Test]
    public function refresh_uses_a_rotated_refresh_token(): void
    {
        $this->fakeApi(['/token' => Http::response(['access_token' => 'access-2', 'refresh_token' => 'refresh-2'])]);

        $this->assertSame('refresh-2', SumUp::oauth()->refresh('refresh-1')->refreshToken);
    }

    #[Test]
    public function client_credentials_request_a_token_for_your_own_account(): void
    {
        $this->fakeApi(['/token' => Http::response(['access_token' => 'cc-token', 'expires_in' => 3599])]);

        $token = SumUp::oauth()->clientCredentials(['payments']);

        $this->assertSame('cc-token', $token->token);
        $this->assertNull($token->refreshToken);
        Http::assertSent(fn (HttpRequest $request) => $request['grant_type'] === 'client_credentials' && $request['scope'] === 'payments');
    }

    #[Test]
    public function token_endpoint_errors_become_oauth_exceptions(): void
    {
        $this->fakeApi(['/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'The code has expired'], 400)]);

        try {
            SumUp::oauth()->exchange('old-code');
            $this->fail('No exception was thrown.');
        } catch (OAuthException $e) {
            $this->assertSame('invalid_grant', $e->error);
            $this->assertSame(400, $e->getCode());
            $this->assertStringContainsString('The code has expired', $e->getMessage());
        }
    }

    #[Test]
    public function missing_client_credentials_are_a_configuration_error(): void
    {
        config(['sumup.oauth.client_secret' => null]);
        Http::preventStrayRequests();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('SUMUP_CLIENT_SECRET');

        SumUp::oauth()->exchange('code');
    }

    #[Test]
    public function access_tokens_round_trip_and_expire(): void
    {
        CarbonImmutable::setTestNow('2026-10-05 12:00:00');

        $token = AccessToken::fromResponse(['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 3599, 'scope' => 'payments']);
        $copy = AccessToken::fromArray(json_decode(json_encode($token), true));

        $this->assertEquals($token, $copy);
        $this->assertSame('a', (string) $copy);
        $this->assertFalse($copy->isExpired());

        CarbonImmutable::setTestNow('2026-10-05 12:59:00');
        $this->assertTrue($copy->isExpired(), 'Within the 60-second leeway.');
        $this->assertFalse($copy->isExpired(0));
        $this->assertFalse((new AccessToken('no-expiry'))->isExpired());
    }

    #[Test]
    public function an_oauth_token_is_used_for_a_connected_merchant(): void
    {
        $this->fakeApi(['/v0.1/checkouts' => Http::response($this->checkout(['merchant_code' => 'MOTHER99']), 201)]);

        SumUp::forMerchant('MOTHER99', new AccessToken('merchant-token'))
            ->checkouts()
            ->create(['checkout_reference' => 'r', 'amount' => 1]);

        Http::assertSent(fn (HttpRequest $request) => $request->hasHeader('Authorization', 'Bearer merchant-token')
            && $request['merchant_code'] === 'MOTHER99');
    }

    /**
     * @param  array<string, string>  $query
     */
    protected function callbackRequest(array $query, ?string $sessionState): Request
    {
        $request = Request::create('/sumup/callback', 'GET', $query);
        $request->setLaravelSession($session = $this->app['session.store']);
        $sessionState === null ? $session->forget(OAuth::SESSION_KEY) : $session->put(OAuth::SESSION_KEY, $sessionState);

        return $request;
    }
}
