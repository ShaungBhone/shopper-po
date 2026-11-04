<?php

declare(strict_types=1);

namespace ShopperPurchaseOrders\Tests;

use Illuminate\Support\Facades\Schema;
use Shopper\Core\Models\InventoryHistory;
use ShopperPurchaseOrders\Tests\Fixtures\CustomProduct;

final class ConfigurationTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('shopper.core.table_prefix', 'po_');
        $app['config']->set('shopper.models.product', CustomProduct::class);
        $app['config']->set('auth.guards.procurement', [
            'driver' => 'session',
            'provider' => 'users',
        ]);
        $app['config']->set('auth.defaults.guard', 'procurement');
        $app['config']->set('shopper.auth.guard', 'procurement');
    }

    public function test_custom_model_prefix_and_guard_are_used_for_receiving(): void
    {
        $order = $this->ordered();
        $this->purchasing->receive($order->id, $order->version, 'a', $this->receiveLine($order, 3));
        $this->assertTrue(Schema::hasTable('po_purchase_orders'));
        $this->assertFalse(Schema::hasTable('sh_purchase_orders'));
        $this->assertSame(
            'Custom model stock integration',
            InventoryHistory::query()->first()->description,
        );
        $this->assertSame(3, $this->product->stockInventory($this->inventory->id));
    }
}
