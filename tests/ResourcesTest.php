<?php

namespace FLAIRUK\SumUp\Tests;

use FLAIRUK\SumUp\Facades\SumUp;
use FLAIRUK\SumUp\Money;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use SumUp\Types\Checkout;
use SumUp\Types\CheckoutStatus;

class ResourcesTest extends TestCase
{
    #[Test]
    public function checkouts_are_created_with_the_default_merchant_and_currency(): void
    {
        $this->fakeApi(['/v0.1/checkouts' => Http::response($this->checkout(), 201)]);

        $checkout = SumUp::checkouts()->create([
            'checkout_reference' => 'order-42',
            'amount' => 10.5,
            'description' => 'Order #42',
            'return_url' => SumUp::webhookUrl(),
        ]);

        $this->assertInstanceOf(Checkout::class, $checkout);
        $this->assertSame('chk-1', $checkout->id);
        $this->assertSame(CheckoutStatus::PENDING, $checkout->status);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === self::BASE.'/v0.1/checkouts'
            && $request['merchant_code'] === 'MTEST123'
            && $request['currency'] === 'GBP'
            && $request['amount'] === 10.5
            && $request['description'] === 'Order #42'
            && $request['return_url'] === 'http://localhost/sumup/webhook');
    }

    #[Test]
    public function checkouts_take_money_and_explicit_values(): void
    {
        $this->fakeApi(['/v0.1/checkouts' => Http::response($this->checkout(['currency' => 'EUR']), 201)]);

        SumUp::forMerchant('MOTHER99')->checkouts()->create([
            'checkout_reference' => 'order-43',
            'amount' => Money::of('19.99', 'EUR'),
        ]);

        Http::assertSent(fn (Request $request) => $request['merchant_code'] === 'MOTHER99'
            && $request['currency'] === 'EUR'
            && $request['amount'] === 19.99);
    }

    #[Test]
    public function checkouts_can_be_found_listed_updated_and_deactivated(): void
    {
        $this->fakeApi([
            '/v0.1/checkouts?checkout_reference=order-42' => Http::response([$this->checkout()]),
            '/v0.1/checkouts/chk-1' => function (Request $request) {
                return match ($request->method()) {
                    'GET' => Http::response($this->checkout(['status' => 'PAID', 'transaction_code' => 'TEENSK4W2K'])),
                    'PATCH' => Http::response($this->checkout(['description' => 'Updated'])),
                    'DELETE' => Http::response($this->checkout(['status' => 'EXPIRED'])),
                };
            },
        ]);

        $checkout = SumUp::checkouts()->find('chk-1');
        $this->assertSame('PAID', $checkout->status);
        $this->assertSame('TEENSK4W2K', $checkout->transactionCode);
        $this->assertTrue(SumUp::checkouts()->paid('chk-1'));

        $list = SumUp::checkouts()->list('order-42');
        $this->assertCount(1, $list);
        $this->assertSame('order-42', $list[0]->checkoutReference);

        $this->assertSame('Updated', SumUp::checkouts()->update('chk-1', ['description' => 'Updated'])->description);
        $this->assertSame(CheckoutStatus::EXPIRED, SumUp::checkouts()->deactivate('chk-1')->status);

        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH' && $request['description'] === 'Updated');
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && $request->url() === self::BASE.'/v0.1/checkouts/chk-1');
    }

    #[Test]
    public function checkouts_are_processed_with_a_put_and_keep_next_step(): void
    {
        $this->fakeApi(['/v0.1/checkouts/chk-1' => Http::response($this->checkout([
            'next_step' => ['url' => 'https://bank.test/3ds', 'method' => 'POST', 'payload' => ['PaReq' => 'abc']],
        ]), 202)]);

        $result = SumUp::checkouts()->process('chk-1', [
            'payment_type' => 'card',
            'token' => 'card-token',
            'customer_id' => 'cus-1',
        ]);

        $this->assertSame('https://bank.test/3ds', $result['next_step']['url']);
        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && $request->url() === self::BASE.'/v0.1/checkouts/chk-1'
            && $request['payment_type'] === 'card'
            && $request['token'] === 'card-token'
            && $request['customer_id'] === 'cus-1');
    }

    #[Test]
    public function available_payment_methods_are_returned_as_ids(): void
    {
        $this->fakeApi(['/v0.1/merchants/MTEST123/payment-methods*' => Http::response([
            'available_payment_methods' => [['id' => 'card'], ['id' => 'apple_pay']],
        ])]);

        $this->assertSame(['card', 'apple_pay'], SumUp::checkouts()->paymentMethods(SumUp::money('5')));
        Http::assertSent(fn (Request $request) => $request['amount'] == 5 && $request['currency'] === 'GBP');
    }

    #[Test]
    public function transactions_are_found_listed_and_refunded(): void
    {
        $this->fakeApi([
            '/v2.1/merchants/MTEST123/transactions/history*' => Http::response(['items' => [$this->transaction()], 'links' => []]),
            '/v2.1/merchants/MTEST123/transactions*' => Http::response($this->transaction()),
            '/v1.0/merchants/MTEST123/payments/txn-1/refunds' => Http::response(null, 201),
        ]);

        $this->assertSame('SUCCESSFUL', SumUp::transactions()->find('txn-1')->status);
        SumUp::transactions()->findByCode('TEENSK4W2K');
        SumUp::transactions()->findByClientTransactionId('ctx-1');

        $history = SumUp::transactions()->list([
            'limit' => 10,
            'order' => 'descending',
            'statuses' => ['SUCCESSFUL', 'REFUNDED'],
            'newest_time' => new \DateTimeImmutable('2026-10-05T12:00:00+00:00'),
        ]);
        $this->assertCount(1, $history->items);

        $this->assertSame([], SumUp::transactions()->refund('txn-1'));
        SumUp::transactions()->refund('txn-1', Money::of('2.50', 'GBP'));

        Http::assertSent(fn (Request $request) => $request->url() === self::BASE.'/v2.1/merchants/MTEST123/transactions?id=txn-1');
        Http::assertSent(fn (Request $request) => $request->url() === self::BASE.'/v2.1/merchants/MTEST123/transactions?transaction_code=TEENSK4W2K');
        Http::assertSent(fn (Request $request) => $request->url() === self::BASE.'/v2.1/merchants/MTEST123/transactions?client_transaction_id=ctx-1');
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), self::BASE.'/v2.1/merchants/MTEST123/transactions/history?')
            && $request['limit'] == 10
            && $request['order'] === 'descending'
            && $request['statuses'] === ['SUCCESSFUL', 'REFUNDED']
            && str_contains($request->url(), 'statuses%5B%5D=SUCCESSFUL&statuses%5B%5D=REFUNDED')
            && $request['newest_time'] === '2026-10-05T12:00:00+00:00');
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/refunds') && $request->body() === '');
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/refunds') && ($request->data()['amount'] ?? null) === 2.5);
    }

    #[Test]
    public function unknown_transaction_filters_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SumUp::transactions()->list(['colour' => 'blue']);
    }

    #[Test]
    public function customers_and_payment_instruments(): void
    {
        $customer = ['customer_id' => 'cus-1', 'personal_details' => ['first_name' => 'Jane', 'email' => 'jane@example.com']];

        $this->fakeApi([
            '/v0.1/customers' => Http::response($customer, 201),
            '/v0.1/customers/cus-1' => Http::response($customer),
            '/v0.1/customers/cus-1/payment-instruments' => Http::response([
                ['token' => 'card-token', 'active' => true, 'type' => 'card', 'card' => ['last_4_digits' => '4242', 'type' => 'VISA']],
            ]),
            '/v0.1/customers/cus-1/payment-instruments/card-token' => Http::response(null, 204),
        ]);

        $created = SumUp::customers()->create($customer);
        $this->assertSame('Jane', $created->personalDetails->firstName);
        $this->assertSame('cus-1', SumUp::customers()->find('cus-1')->customerId);
        SumUp::customers()->update('cus-1', ['personal_details' => ['last_name' => 'Doe']]);

        $cards = SumUp::customers()->paymentInstruments('cus-1');
        $this->assertSame('card-token', $cards[0]->token);
        $this->assertSame('4242', $cards[0]->card->last4Digits);

        SumUp::customers()->deactivatePaymentInstrument('cus-1', 'card-token');

        Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && $request['personal_details']['last_name'] === 'Doe');
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/payment-instruments/card-token'));
    }

    #[Test]
    public function readers_are_paired_and_take_payments(): void
    {
        $reader = [
            'id' => 'rdr_1',
            'name' => 'Front counter',
            'status' => 'paired',
            'device' => ['identifier' => 'SN123', 'model' => 'solo'],
            'created_at' => '2026-10-05T10:00:00Z',
            'updated_at' => '2026-10-05T10:00:00Z',
        ];

        $this->fakeApi([
            '/v0.1/merchants/MTEST123/readers' => fn (Request $request) => $request->method() === 'POST'
                ? Http::response($reader, 201)
                : Http::response(['items' => [$reader]]),
            '/v0.1/merchants/MTEST123/readers/rdr_1' => fn (Request $request) => match ($request->method()) {
                'DELETE' => Http::response(null, 200),
                default => Http::response($reader),
            },
            '/v0.1/merchants/MTEST123/readers/rdr_1/status' => Http::response(['data' => ['status' => 'ONLINE', 'state' => 'IDLE', 'battery_level' => 80]]),
            '/v0.1/merchants/MTEST123/readers/rdr_1/checkout' => Http::response(['data' => ['client_transaction_id' => 'ctx-1']], 201),
            '/v0.1/merchants/MTEST123/readers/rdr_1/checkout/chk-9' => Http::response(['data' => ['checkout_id' => 'chk-9', 'status' => 'successful']]),
            '/v0.1/merchants/MTEST123/readers/rdr_1/terminate' => Http::response(null, 202),
        ]);

        $this->assertSame('rdr_1', SumUp::readers()->pair('ABC123', 'Front counter')->id);
        $this->assertSame('Front counter', SumUp::readers()->list()[0]->name);
        $this->assertSame('rdr_1', SumUp::readers()->find('rdr_1')->id);
        SumUp::readers()->update('rdr_1', ['name' => 'Back counter']);
        $this->assertSame('IDLE', SumUp::readers()->status('rdr_1')->data->state->value);

        $started = SumUp::readers()->checkout('rdr_1', SumUp::money('15.00'), [
            'description' => 'Table 4',
            'return_url' => 'https://shop.test/sumup/webhook',
        ]);
        $this->assertSame('ctx-1', $started->data->clientTransactionId);

        $this->assertSame('successful', SumUp::readers()->findCheckout('rdr_1', 'chk-9')->data->status->value);
        SumUp::readers()->terminate('rdr_1');
        SumUp::readers()->delete('rdr_1');

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/readers')
            && $request['pairing_code'] === 'ABC123'
            && $request['name'] === 'Front counter');
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/readers/rdr_1/checkout')
            && $request['total_amount'] == ['value' => 1500, 'currency' => 'GBP', 'minor_unit' => 2]
            && $request['description'] === 'Table 4');
        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH' && $request['name'] === 'Back counter');
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/terminate'));
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/readers/rdr_1'));
    }
}
