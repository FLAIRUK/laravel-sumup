<?php

namespace FLAIRUK\SumUp\Tests;

use PHPUnit\Framework\Attributes\Test;
use SumUp\Types\CheckoutSuccess;

class CardWidgetTest extends TestCase
{
    #[Test]
    public function it_renders_the_mount_point_script_and_options(): void
    {
        $view = $this->blade('<x-sumup-card checkout="chk-1" class="my-4" />');

        $view->assertSee('<div id="sumup-card" class="my-4"></div>', false);
        $view->assertSee('<script src="https://gateway.sumup.com/gateway/ecom/card/v2/sdk.js"', false);
        $view->assertSee('SumUpCard.mount(options);', false);
        $view->assertSee($this->js('"checkoutId":"chk-1"'), false);
        $view->assertDontSee('showEmail', false);
        $view->assertDontSee('locale', false);
    }

    #[Test]
    public function it_takes_a_checkout_object_and_widget_options(): void
    {
        $checkout = new CheckoutSuccess;
        $checkout->id = 'chk-2';

        $view = $this->blade(
            '<x-sumup-card :checkout="$checkout" id="pay" locale="de-DE" email="jane@example.com" :show-email="true" :show-submit-button="false" success-url="/orders/42/paid" fail-url="/orders/42" on-response="trackPayment" nonce="abc123" />',
            ['checkout' => $checkout],
        );

        $view->assertSee('<div id="pay"></div>', false);
        $view->assertSee($this->js('"id":"pay"'), false);
        $view->assertSee($this->js('"checkoutId":"chk-2"'), false);
        $view->assertSee($this->js('"locale":"de-DE"'), false);
        $view->assertSee($this->js('"showEmail":true'), false);
        $view->assertSee($this->js('"showSubmitButton":false'), false);
        $view->assertSee($this->js('"success":"').'\\\\\\/orders\\\\\\/42\\\\\\/paid', false);
        $view->assertSee('trackPayment', false);
        $view->assertSee('nonce="abc123"', false);
    }

    #[Test]
    public function the_default_locale_comes_from_config(): void
    {
        config(['sumup.widget.locale' => 'fr-FR']);

        $this->blade('<x-sumup-card checkout="chk-1" />')->assertSee($this->js('"locale":"fr-FR"'), false);
    }

    /**
     * A JSON fragment as Js::from() writes it inside JSON.parse('…').
     */
    protected function js(string $json): string
    {
        return str_replace('"', '\\u0022', $json);
    }
}
