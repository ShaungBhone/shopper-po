<?php

declare(strict_types=1);

namespace ShopperPurchaseOrders\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Shopper\Core\Models\Contracts\Stockable;

final class Stock
{
    public function resolve(string $kind, int $id, bool $lock = false): Model
    {
        $class = config(
            'shopper.models.'.
                match ($kind) {
                    'product' => 'product',
                    'variant' => 'variant',
                    default => throw ValidationException::withMessages([
                        'items' => 'Invalid stockable type.',
                    ]),
                },
        );
        $query = $class::query();
        $model = ($lock ? $query->lockForUpdate() : $query)->find($id);
        if (
            ! ($model instanceof Stockable) ||
            ! $model->tracksInventory() ||
            ($kind === 'product' && ! $model->isStandard())
        ) {
            throw ValidationException::withMessages([
                'items' => 'Select an existing physical product or a specific variant.',
            ]);
        }
        if ($model->getConnection()->getName() !== app('db')->connection()->getName()) {
            throw ValidationException::withMessages([
                'items' => 'Stockable models must use the default database connection.',
            ]);
        }

        return $model;
    }

    public function mutate(
        Model $model,
        int $inventoryId,
        int $delta,
        int|string $actorId,
        Model $reference,
    ): int {
        $before = $model->stockInventory($inventoryId);
        if ($delta < 0 && $before + $delta < 0) {
            throw ValidationException::withMessages([
                'quantity' => 'This correction would make location stock negative. Reconcile consumed stock first.',
            ]);
        }
        $max = 2147483647;
        if ($before + $delta > $max || $before > $max || $before < -$max) {
            throw ValidationException::withMessages([
                'quantity' => 'Inventory quantity exceeds the ledger integer limit.',
            ]);
        }

        return $model
            ->mutateStock(
                inventoryId: $inventoryId,
                quantity: $delta,
                oldQuantity: $before,
                event: $delta > 0 ? 'purchase_order.received' : 'purchase_order.corrected',
                description: 'Purchase order receiving',
                userId: (int) $actorId,
                reference: $reference,
            )
            ->getKey();
    }
}
