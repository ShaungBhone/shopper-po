<?php

declare(strict_types=1);

namespace ShopperPurchaseOrders;

use Illuminate\Support\Facades\Route;
use Shopper\Addon\BaseAddon;
use Shopper\ShopperPanel;

final class PurchaseOrdersAddon extends BaseAddon
{
    public function getId(): string
    {
        return 'purchase-orders';
    }

    public function register(ShopperPanel $panel): void
    {
        $panel->addonRoutes(function (): void {
            Route::prefix('purchase-orders')
                ->name('purchase-orders.')
                ->group(function (): void {
                    Route::get('/', Livewire\Index::class)->name('index');
                    Route::get('/create', Livewire\Form::class)->name('create');
                    Route::get('/{orderId}/edit', fn (int $orderId) => redirect()->route(
                        'shopper.purchase-orders.show',
                        ['orderId' => $orderId],
                    ))
                        ->whereNumber('orderId')
                        ->name('edit');
                    Route::get('/{orderId}/receive', Livewire\Receive::class)
                        ->whereNumber('orderId')
                        ->name('receive');
                    Route::get('/{orderId}', Livewire\Detail::class)
                        ->whereNumber('orderId')
                        ->name('show');
                });
        });
        $panel->addonSidebar(Sidebar\PurchasingSidebar::class);
        $panel->addonLivewireComponents([
            'purchase-orders.index' => Livewire\Index::class,
            'purchase-orders.form' => Livewire\Form::class,
            'purchase-orders.detail' => Livewire\Detail::class,
            'purchase-orders.receive' => Livewire\Receive::class,
        ]);
    }
}
