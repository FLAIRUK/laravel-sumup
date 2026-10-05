<?php

namespace FLAIRUK\SumUp\Tests;

use FLAIRUK\SumUp\Events\CheckoutStatusChanged;
use FLAIRUK\SumUp\Events\ReaderCheckoutStatusChanged;
use FLAIRUK\SumUp\Events\WebhookReceived;
use FLAIRUK\SumUp\Facades\SumUp;
use FLAIRUK\SumUp\OAuth\AccessToken;
use FLAIRUK\SumUp\Webhooks\Notification;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;

class WebhookTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.debug', false);
    }

    #[Test]
    public function the_route_is_registered_without_the_web_middleware(): void
    {
        $route = Route::getRoutes()->getByName('sumup.webhook');

        $this->assertNotNull($route);
        $this->assertSame(['POST'], $route->methods());
        $this->assertSame('sumup/webhook', $route->uri());
        $this->assertNotContains('web', $route->gatherMiddleware());
    }

    #[Test]
    public function a_checkout_notification_is_verified_with_the_api_before_the_event(): void
    {
        Event::fake();
        $this->fakeApi(['/v0.1/checkouts/chk-1' => Http::response($this->checkout(['status' => 'PAID']))]);

        $this->postJson('/sumup/webhook', ['event_type' => 'CHECKOUT_STATUS_CHANGED', 'id' => 'chk-1'])
            ->assertOk()
            ->assertContent('');

        Http::assertSent(fn (HttpRequest $request) => $request->method() === 'GET' && $request->url() === self::BASE.'/v0.1/checkouts/chk-1');
        Event::assertDispatched(WebhookReceived::class, fn ($event) => $event->notification->checkoutId() === 'chk-1');
        Event::assertDispatched(CheckoutStatusChanged::class, fn ($event) => $event->paid()
            && $event->checkout->id === 'chk-1'
            && $event->notification->eventType === Notification::CHECKOUT_STATUS_CHANGED);
    }

    #[Test]
    public function the_event_carries_the_api_status_not_the_payload(): void
    {
        Event::fake();
        $this->fakeApi(['/v0.1/checkouts/chk-1' => Http::response($this->checkout(['status' => 'FAILED']))]);

        $this->postJson('/sumup/webhook', ['event_type' => 'CHECKOUT_STATUS_CHANGED', 'id' => 'chk-1', 'status' => 'PAID'])->assertOk();

        Event::assertDispatched(CheckoutStatusChanged::class, fn ($event) => ! $event->paid() && $event->checkout->status === 'FAILED');
    }

    #[Test]
    public function a_notification_the_api_does_not_know_is_acknowledged_without_an_event(): void
    {
        Event::fake();
        Log::spy();
        $this->fakeApi(['/v0.1/checkouts/forged' => Http::response(['error_code' => 'NOT_FOUND', 'message' => 'Resource not found'], 404)]);

        $this->postJson('/sumup/webhook', ['event_type' => 'CHECKOUT_STATUS_CHANGED', 'id' => 'forged'])->assertOk();

        Event::assertDispatched(WebhookReceived::class);
        Event::assertNotDispatched(CheckoutStatusChanged::class);
        Log::shouldHaveReceived('warning')->once();
    }

    #[Test]
    public function a_failed_verification_returns_an_error_so_sumup_retries(): void
    {
        Event::fake();
        $this->fakeApi(['/v0.1/checkouts/chk-1' => Http::response(['message' => 'Unavailable'], 503)]);

        $this->postJson('/sumup/webhook', ['event_type' => 'CHECKOUT_STATUS_CHANGED', 'id' => 'chk-1'])->assertServerError();

        Event::assertNotDispatched(CheckoutStatusChanged::class);
    }

    #[Test]
    public function a_body_that_is_not_a_notification_is_rejected(): void
    {
        Event::fake();
        Http::preventStrayRequests();

        $this->postJson('/sumup/webhook', ['hello' => 'world'])->assertStatus(400);
        $this->post('/sumup/webhook', [], ['Content-Type' => 'text/plain'])->assertStatus(400);

        Event::assertNotDispatched(WebhookReceived::class);
    }

    #[Test]
    public function unknown_event_types_only_dispatch_webhook_received(): void
    {
        Event::fake();
        Http::preventStrayRequests();

        $this->postJson('/sumup/webhook', ['event_type' => 'SOMETHING_NEW', 'id' => 'x-1'])->assertOk();

        Event::assertDispatched(WebhookReceived::class, fn ($event) => $event->notification->eventType === 'SOMETHING_NEW');
        Event::assertNotDispatched(CheckoutStatusChanged::class);
        Event::assertNotDispatched(ReaderCheckoutStatusChanged::class);
    }

    #[Test]
    public function a_reader_notification_is_verified_by_its_client_transaction_id(): void
    {
        Event::fake();
        $this->fakeApi(['/v2.1/merchants/MTEST123/transactions*' => Http::response($this->transaction(['client_transaction_id' => 'ctx-1']))]);

        $this->postJson('/sumup/webhook', [
            'id' => 'evt-1',
            'event_type' => 'solo.transaction.updated',
            'payload' => ['client_transaction_id' => 'ctx-1', 'merchant_code' => 'MTEST123', 'status' => 'successful'],
            'timestamp' => '2026-10-05T14:48:00Z',
        ])->assertOk();

        Http::assertSent(fn (HttpRequest $request) => $request->url() === self::BASE.'/v2.1/merchants/MTEST123/transactions?client_transaction_id=ctx-1');
        Event::assertDispatched(ReaderCheckoutStatusChanged::class, fn ($event) => $event->successful()
            && $event->transaction->id === 'txn-1'
            && $event->notification->clientTransactionId() === 'ctx-1'
            && $event->notification->timestamp === '2026-10-05T14:48:00Z');
    }

    #[Test]
    public function a_reader_notification_is_looked_up_under_the_merchant_it_names(): void
    {
        Event::fake();
        $this->fakeApi(['/v2.1/merchants/MOTHER99/transactions*' => Http::response($this->transaction(['status' => 'FAILED']))]);

        $this->postJson('/sumup/webhook', [
            'id' => 'evt-2',
            'event_type' => 'solo.transaction.updated',
            'payload' => ['transaction_id' => 'ctx-2', 'merchant_code' => 'MOTHER99', 'status' => 'failed'],
            'timestamp' => '2026-10-05T14:48:00Z',
        ])->assertOk();

        Event::assertDispatched(ReaderCheckoutStatusChanged::class, fn ($event) => ! $event->successful());
    }

    #[Test]
    public function the_client_resolver_picks_the_merchant_and_token(): void
    {
        Event::fake();
        $this->fakeApi(['/v0.1/checkouts/chk-1' => Http::response($this->checkout(['status' => 'PAID']))]);

        SumUp::webhooks()->resolveClientUsing(function (Notification $notification, Request $request) {
            $this->assertSame('MOTHER99', $request->query('merchant'));

            return SumUp::forMerchant($request->query('merchant'), new AccessToken('merchant-token'));
        });

        $this->postJson(SumUp::webhookUrl(['merchant' => 'MOTHER99']), ['event_type' => 'CHECKOUT_STATUS_CHANGED', 'id' => 'chk-1'])->assertOk();

        Http::assertSent(fn (HttpRequest $request) => $request->hasHeader('Authorization', 'Bearer merchant-token'));
        Event::assertDispatched(CheckoutStatusChanged::class);
    }

    #[Test]
    public function notifications_parse_defensively(): void
    {
        $this->assertNull(Notification::fromArray([]));
        $this->assertNull(Notification::fromArray(['event_type' => 'CHECKOUT_STATUS_CHANGED']));
        $this->assertNull(Notification::fromArray(['event_type' => ['x'], 'id' => 'chk-1']));

        $notification = Notification::fromArray(['event_type' => 'solo.transaction.updated', 'id' => 'evt-1', 'payload' => 'nope']);
        $this->assertSame([], $notification->payload);
        $this->assertNull($notification->checkoutId());
        $this->assertNull($notification->merchantCode());
        $this->assertNull($notification->clientTransactionId());
    }

    #[Test]
    public function the_webhook_route_is_rate_limited(): void
    {
        $this->assertContains('throttle:60,1', app('router')->getRoutes()->getByName('sumup.webhook')->gatherMiddleware());
    }
}
