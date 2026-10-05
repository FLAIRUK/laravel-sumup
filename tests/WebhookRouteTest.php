<?php

namespace FLAIRUK\SumUp\Tests;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;

class WebhookRouteTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('sumup.webhooks.enabled', false);
    }

    #[Test]
    public function the_route_can_be_turned_off(): void
    {
        $this->assertNull(Route::getRoutes()->getByName('sumup.webhook'));
    }
}
