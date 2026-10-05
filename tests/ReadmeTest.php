<?php

namespace FLAIRUK\SumUp\Tests;

use FLAIRUK\SumUp\Events\CheckoutStatusChanged;
use FLAIRUK\SumUp\Facades\SumUp;
use FLAIRUK\SumUp\OAuth\AccessToken;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use SumUp\Services\PayoutsListParams;

/**
 * The README's examples, run as written (with fakes).
 */
class ReadmeTest extends TestCase
{
    #[Test]
    public function the_testing_your_integration_example_works(): void
    {
        Http::fake([
            'api.sumup.com/v0.1/checkouts' => Http::response(['id' => 'chk_1', 'status' => 'PENDING'], 201),
            'api.sumup.com/v0.1/checkouts/chk_1' => Http::response(['id' => 'chk_1', 'status' => 'PAID']),
        ]);

        $checkout = SumUp::checkouts()->create(['checkout_reference' => 'order-1', 'amount' => SumUp::money('24.99')]);

        $this->assertSame('chk_1', $checkout->id);
        $this->assertTrue(SumUp::checkouts()->paid('chk_1'));

        Event::fake();
        $this->postJson(route('sumup.webhook'), ['event_type' => 'CHECKOUT_STATUS_CHANGED', 'id' => 'chk_1'])->assertOk();
        Event::assertDispatched(CheckoutStatusChanged::class, fn ($event) => $event->paid());
    }

    #[Test]
    public function the_oauth_example_finds_the_merchant_code(): void
    {
        $this->fakeApi(['/v0.1/memberships' => Http::response(['total_count' => 2, 'items' => [
            $this->membership('org_1', 'organization'),
            $this->membership('MCONNECTED', 'merchant'),
        ]])]);

        $token = new AccessToken('merchant-token');
        $membership = collect(SumUp::withToken($token)->memberships())->firstWhere('resource.type', 'merchant');

        $this->assertSame('MCONNECTED', $membership->resource->id);
    }

    #[Test]
    public function the_sdk_example_works(): void
    {
        $this->fakeApi(['/v1.0/merchants/MTEST123/payouts*' => Http::response([])]);

        $params = new PayoutsListParams;
        $params->startDate = '2026-09-01';
        $params->endDate = '2026-09-30';

        $this->assertSame([], SumUp::sdk()->payouts()->list(SumUp::merchantCode(), $params));
        Http::assertSent(fn ($request) => $request['start_date'] === '2026-09-01' && $request['end_date'] === '2026-09-30');
    }

    /**
     * @return array<string, mixed>
     */
    protected function membership(string $id, string $type): array
    {
        return [
            'id' => "mem-{$id}", 'resource_id' => $id, 'type' => $type, 'roles' => [], 'permissions' => [],
            'created_at' => '2026-01-01T00:00:00Z', 'updated_at' => '2026-01-01T00:00:00Z', 'status' => 'accepted',
            'resource' => ['id' => $id, 'type' => $type, 'name' => $id, 'created_at' => '2026-01-01T00:00:00Z', 'updated_at' => '2026-01-01T00:00:00Z'],
        ];
    }
}
