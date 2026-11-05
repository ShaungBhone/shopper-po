<?php

declare(strict_types=1);

namespace ShopperPurchaseOrders\Livewire;

use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Shopper\Livewire\Pages\AbstractPageComponent;
use ShopperPurchaseOrders\Actions\Purchasing;
use ShopperPurchaseOrders\Enums\OrderState;
use ShopperPurchaseOrders\Models\PurchaseOrder;
use ShopperPurchaseOrders\Support\Access;

final class Receive extends AbstractPageComponent
{
    #[Locked]
    public int $orderId;

    #[Locked]
    public int $version;

    #[Locked]
    public string $requestKey;

    public array $lines = [];

    public string $notes = '';

    public function mount(int $orderId): void
    {
        Access::authorize('edit_orders');
        $order = PurchaseOrder::query()->with('items')->findOrFail($orderId);
        abort_unless($order->state === OrderState::Ordered, 403);
        $this->orderId = $orderId;
        $this->version = $order->version;
        $this->requestKey = (string) Str::uuid();
        $this->lines = $order->items
            ->filter(fn ($item) => $item->outstanding() > 0)
            ->map(
                fn ($item) => [
                    'item_id' => $item->id,
                    'accepted' => 0,
                    'rejected' => 0,
                    'resolution' => 'replace',
                ],
            )
            ->values()
            ->all();
    }

    public function save(): mixed
    {
        // Validate every row before dropping zero rows; invalid quantities must never disappear silently.
        $this->validate([
            'lines.*.item_id' => 'required|integer|min:1|distinct',
            'lines.*.accepted' => 'required|integer|min:0',
            'lines.*.rejected' => 'required|integer|min:0',
            'lines.*.resolution' => 'required|in:replace,waive',
        ]);
        $lines = array_values(
            array_filter(
                $this->lines,
                fn ($line) => (int) $line['accepted'] !== 0 || (int) $line['rejected'] !== 0,
            ),
        );
        app(Purchasing::class)->receive(
            $this->orderId,
            $this->version,
            $this->requestKey,
            $lines,
            $this->notes,
        );

        return $this->redirectRoute('shopper.purchase-orders.show', ['orderId' => $this->orderId]);
    }

    public function render(): mixed
    {
        Access::authorize('edit_orders');
        $order = PurchaseOrder::query()->with('items')->findOrFail($this->orderId);
        $class = config('shopper.models.inventory');

        return view('purchase-orders::receive', [
            'order' => $order,
            'items' => $order->items->keyBy('id'),
            'inventory' => $class::query()->find($order->inventory_id),
        ])->title('Receive purchase order');
    }
}
