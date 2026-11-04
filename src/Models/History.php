<?php

declare(strict_types=1);

namespace ShopperPurchaseOrders\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class History extends Model
{
    protected $guarded = ['id'];

    public function getTable(): string
    {
        return shopper_table('purchase_order_events');
    }

    public function label(): string
    {
        return match ($this->type) {
            'draft_created' => 'Draft created',
            'draft_updated' => 'Draft updated',
            'ordered' => 'Order marked as ordered',
            'received' => 'Delivery recorded',
            'receipt_corrected' => 'Receipt corrected',
            'line_closed' => 'Order line closed',
            'line_reopened' => 'Order line restored',
            'line_amended' => 'Order line amended',
            'expected_date_changed' => 'Expected delivery updated',
            'closed' => 'Purchase order closed',
            'reopened' => 'Purchase order reopened',
            'cancelled' => 'Purchase order cancelled',
            default => Str::headline($this->type),
        };
    }

    protected function casts(): array
    {
        return ['data' => 'array'];
    }
}
