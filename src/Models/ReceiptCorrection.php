<?php

declare(strict_types=1);

namespace ShopperPurchaseOrders\Models;

use Illuminate\Database\Eloquent\Model;

class ReceiptCorrection extends Model
{
    protected $guarded = ['id'];

    public function getTable(): string
    {
        return shopper_table('purchase_order_corrections');
    }
}
