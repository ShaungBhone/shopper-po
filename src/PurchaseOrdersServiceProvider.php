<?php

declare(strict_types=1);

namespace ShopperPurchaseOrders;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;
use Shopper\ShopperPanel;
use ShopperPurchaseOrders\Models\ReceiptCorrection;
use ShopperPurchaseOrders\Models\ReceiptItem;

final class PurchaseOrdersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/purchase-orders.php', 'purchase-orders');
        $this->app->resolving('shopper', function (ShopperPanel $panel): void {
            if (config('purchase-orders.enabled') && ! $panel->hasAddon('purchase-orders')) {
                $panel->addon(new PurchaseOrdersAddon);
            }
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'purchase-orders');
        Relation::morphMap([
            'po_receipt_item' => ReceiptItem::class,
            'po_receipt_correction' => ReceiptCorrection::class,
        ]);
        $this->publishes(
            [__DIR__.'/../config/purchase-orders.php' => config_path('purchase-orders.php')],
            'purchase-orders-config',
        );
        if ($this->app->runningInConsole()) {
            $this->commands([Console\Install::class]);
        }
    }
}
