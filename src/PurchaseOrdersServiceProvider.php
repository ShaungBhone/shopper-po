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
        $this->app->resolving('shopper', function (ShopperPanel $panel): void {
            if (! $panel->hasAddon('purchase-orders')) {
                $panel->addon(new PurchaseOrdersAddon);
            }
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        Relation::morphMap([
            'po_receipt_item' => ReceiptItem::class,
            'po_receipt_correction' => ReceiptCorrection::class,
        ]);
        if ($this->app->runningInConsole()) {
            $this->commands([Console\Install::class]);
        }
    }
}
