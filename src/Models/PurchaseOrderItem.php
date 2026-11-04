<?php

declare(strict_types=1);

namespace ShopperPurchaseOrders\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    protected $guarded = ['id'];

    public function getTable(): string
    {
        return shopper_table('purchase_order_items');
    }

    protected function casts(): array
    {
        return [
            'ordered_quantity' => 'integer',
            'accepted_quantity' => 'integer',
            'rejected_quantity' => 'integer',
            'waived_quantity' => 'integer',
            'short_closed_quantity' => 'integer',
            'unit_cost_minor' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function outstanding(): int
    {
        return $this->ordered_quantity - $this->accepted_quantity - $this->waived_quantity;
    }
}
