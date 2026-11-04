<?php

declare(strict_types=1);

namespace ShopperPurchaseOrders\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Shopper\Core\Models\InventoryHistory;
use Shopper\Core\Models\Product;

class CustomProduct extends Product
{
    public function mutateStock(
        int $inventoryId,
        int $quantity = 1,
        int $oldQuantity = 0,
        ?string $event = null,
        ?string $description = null,
        ?int $userId = null,
        ?Model $reference = null,
    ): InventoryHistory {
        return parent::mutateStock(
            $inventoryId,
            $quantity,
            $oldQuantity,
            $event,
            'Custom model stock integration',
            $userId,
            $reference,
        );
    }
}
