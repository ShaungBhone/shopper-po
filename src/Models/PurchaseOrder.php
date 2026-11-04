<?php

declare(strict_types=1);

namespace ShopperPurchaseOrders\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ShopperPurchaseOrders\Enums\OrderState;

class PurchaseOrder extends Model
{
    protected $guarded = ['id'];

    public function getTable(): string
    {
        return shopper_table('purchase_orders');
    }

    protected function casts(): array
    {
        return [
            'state' => OrderState::class,
            'supplier_snapshot' => 'array',
            'expected_at' => 'date',
            'ordered_at' => 'datetime',
            'closed_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }

    public function history(): HasMany
    {
        return $this->hasMany(History::class)->orderBy('id');
    }

    public function receivingProgress(): string
    {
        $items = $this->items;
        if ($items->isEmpty() || $items->sum('accepted_quantity') === 0) {
            return 'not_received';
        }

        return $items->every(fn ($item) => $item->accepted_quantity === $item->ordered_quantity)
            ? 'fully_received'
            : 'partially_received';
    }

    public function outstandingQuantity(): int
    {
        return (int) $this->items->sum(fn ($item) => $item->outstanding());
    }

    public function totalMinor(): int
    {
        return (int) $this->items->sum(
            fn ($item) => $item->ordered_quantity * $item->unit_cost_minor,
        );
    }
}
