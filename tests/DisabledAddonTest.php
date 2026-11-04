<?php

declare(strict_types=1);

namespace ShopperPurchaseOrders\Tests;

use Illuminate\Support\Facades\Route;

final class DisabledAddonTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('shopper.addons.purchase-orders', false);
    }

    public function test_disabled_addon_has_no_registered_routes_or_components(): void
    {
        $this->assertNull(Route::getRoutes()->getByName('shopper.purchase-orders.index'));
        $this->assertFalse(app('shopper')->hasAddon('purchase-orders'));
        $this->assertArrayNotHasKey(
            'purchase-orders.index',
            app('shopper')->addonManager()->getLivewireComponents(),
        );
    }
}
