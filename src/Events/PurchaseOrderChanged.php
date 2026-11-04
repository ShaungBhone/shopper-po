<?php

declare(strict_types=1);

namespace ShopperPurchaseOrders\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final readonly class PurchaseOrderChanged implements ShouldDispatchAfterCommit
{
    public function __construct(
        public int $orderId,
        public string $type,
        public array $data = [],
    ) {}
}
