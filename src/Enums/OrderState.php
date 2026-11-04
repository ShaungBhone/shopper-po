<?php

declare(strict_types=1);

namespace ShopperPurchaseOrders\Enums;

enum OrderState: string
{
    case Draft = 'draft';
    case Ordered = 'ordered';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Ordered => 'info',
            self::Closed => 'success',
            self::Cancelled => 'danger',
        };
    }
}
