<?php

declare(strict_types=1);

namespace ShopperPurchaseOrders\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReceiptItem extends Model
{
    protected $guarded = ['id'];

    public function getTable(): string
    {
        return shopper_table('purchase_order_receipt_items');
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(ReceiptCorrection::class);
    }

    public function effectiveAccepted(): int
    {
        return $this->accepted_quantity +
            (int) ($this->relationLoaded('corrections')
                ? $this->corrections->sum('accepted_delta')
                : $this->corrections()->sum('accepted_delta'));
    }

    public function effectiveRejected(): int
    {
        return $this->rejected_quantity +
            (int) ($this->relationLoaded('corrections')
                ? $this->corrections->sum('rejected_delta')
                : $this->corrections()->sum('rejected_delta'));
    }
}
