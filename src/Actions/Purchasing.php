<?php

declare(strict_types=1);

namespace ShopperPurchaseOrders\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use ShopperPurchaseOrders\Enums\OrderState;
use ShopperPurchaseOrders\Events\PurchaseOrderChanged;
use ShopperPurchaseOrders\Models\PurchaseOrder;
use ShopperPurchaseOrders\Models\Receipt;
use ShopperPurchaseOrders\Models\ReceiptCorrection;
use ShopperPurchaseOrders\Models\ReceiptItem;
use ShopperPurchaseOrders\Support\Access;
use ShopperPurchaseOrders\Support\Stock;

final class Purchasing
{
    public function __construct(private Stock $stock) {}

    public static function draftRules(): array
    {
        return [
            'supplier_id' => ['required', 'integer', 'min:1'],
            'inventory_id' => ['required', 'integer', 'min:1'],
            'currency' => ['required', Rule::in(array_keys(config('purchase-orders.currencies')))],
            'expected_at' => ['nullable', 'date_format:Y-m-d'],
            'supplier_reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.stockable_kind' => ['required', Rule::in(['product', 'variant'])],
            'items.*.stockable_id' => ['required', 'integer', 'min:1'],
            'items.*.ordered_quantity' => [
                'required',
                'integer',
                'min:1',
                'max:'.config('purchase-orders.max_quantity'),
            ],
            'items.*.unit_cost_minor' => [
                'required',
                'integer',
                'min:0',
                'max:'.config('purchase-orders.max_total_minor'),
            ],
        ];
    }

    public function saveDraft(
        array $input,
        ?int $orderId = null,
        ?int $version = null,
    ): PurchaseOrder {
        $actor = Access::authorize($orderId ? 'edit' : 'add');
        $data = Validator::make($input, self::draftRules())->validate();

        return DB::transaction(function () use ($data, $actor, $orderId, $version) {
            $order = $orderId
                ? $this->locked($orderId, $version, [OrderState::Draft])
                : new PurchaseOrder;
            $supplier = $this->supplier((int) $data['supplier_id'], 'supplier_id');
            $this->inventory((int) $data['inventory_id'], 'inventory_id');
            $lines = [];
            $seen = [];
            $total = 0;
            $limit = (int) config('purchase-orders.max_total_minor');
            foreach ($data['items'] as $line) {
                $identity = $line['stockable_kind'].':'.$line['stockable_id'];
                $this->check(
                    ! isset($seen[$identity]),
                    'Duplicate products must be combined into one line.',
                    'items',
                );
                $seen[$identity] = true;
                $model = $this->stock->resolve(
                    $line['stockable_kind'],
                    (int) $line['stockable_id'],
                );
                $quantity = (int) $line['ordered_quantity'];
                $cost = (int) $line['unit_cost_minor'];
                $this->check(
                    $cost <= intdiv($limit - $total, $quantity),
                    'Merchandise subtotal exceeds the supported amount.',
                    'items',
                );
                $total += $quantity * $cost;
                $lines[] = [
                    'stockable_kind' => $line['stockable_kind'],
                    'stockable_id' => (int) $line['stockable_id'],
                    'ordered_quantity' => $quantity,
                    'unit_cost_minor' => $cost,
                    'name' => mb_substr(
                        (string) ($model->name ??
                            ($model->product?->name ?? 'Variant #'.$model->getKey())),
                        0,
                        255,
                    ),
                    'sku' => $model->sku,
                ];
            }
            $attributes = $data;
            unset($attributes['items']);
            $order->fill($attributes);
            $order->currency_exponent = config('purchase-orders.currencies.'.$data['currency']);
            $order->supplier_snapshot = $supplier->only(['name', 'email', 'phone', 'contact_name']);
            if (! $orderId) {
                $order->state = OrderState::Draft;
                $order->created_by = $actor->getAuthIdentifier();
                $order->version = 1;
            } else {
                $order->version++;
            }
            $order->save();
            if (! $order->number) {
                $order->update([
                    'number' => 'PO-'.str_pad((string) $order->id, 8, '0', STR_PAD_LEFT),
                ]);
            }
            $order->items()->delete();
            $order->items()->createMany($lines);
            $this->record(
                $order,
                $orderId ? 'draft_updated' : 'draft_created',
                (int) $actor->getAuthIdentifier(),
            );

            return $order->fresh('items');
        }, 3);
    }

    public function place(int $id, int $version): PurchaseOrder
    {
        $actor = Access::authorize('place');

        return DB::transaction(function () use ($id, $version, $actor) {
            $order = $this->locked($id, $version, [OrderState::Draft]);
            $supplier = $this->supplier($order->supplier_id);
            $this->inventory($order->inventory_id);
            $this->check($order->items()->exists(), 'Add at least one line.');
            foreach ($order->items as $item) {
                $model = $this->stock->resolve($item->stockable_kind, $item->stockable_id);
                $item->update([
                    'name' => mb_substr(
                        (string) ($model->name ??
                            ($model->product?->name ?? 'Variant #'.$model->getKey())),
                        0,
                        255,
                    ),
                    'sku' => $model->sku,
                ]);
            }
            $order->supplier_snapshot = $supplier->only(['name', 'email', 'phone', 'contact_name']);
            $order->state = OrderState::Ordered;
            $order->ordered_at = now();
            $order->ordered_by = $actor->getAuthIdentifier();
            $this->persist($order, 'ordered', (int) $actor->getAuthIdentifier());

            return $order->fresh('items');
        }, 3);
    }

    public function receive(
        int $id,
        int $version,
        string $requestKey,
        array $lines,
        ?string $notes = null,
    ): Receipt {
        $actor = Access::authorize('receive');
        $data = Validator::make(
            ['request_key' => $requestKey, 'lines' => $lines, 'notes' => $notes],
            [
                'request_key' => ['required', 'string', 'max:100'],
                'notes' => ['nullable', 'string', 'max:5000'],
                'lines' => ['required', 'array', 'min:1', 'max:100'],
                'lines.*.item_id' => ['required', 'integer', 'min:1', 'distinct'],
                'lines.*.accepted' => [
                    'required',
                    'integer',
                    'min:0',
                    'max:'.config('purchase-orders.max_quantity'),
                ],
                'lines.*.rejected' => [
                    'required',
                    'integer',
                    'min:0',
                    'max:'.config('purchase-orders.max_quantity'),
                ],
                'lines.*.resolution' => ['required', Rule::in(['replace', 'waive'])],
            ],
        )->validate();
        $normalized = array_map(
            fn ($line) => [
                'item_id' => (int) $line['item_id'],
                'accepted' => (int) $line['accepted'],
                'rejected' => (int) $line['rejected'],
                'resolution' => $line['resolution'],
            ],
            $data['lines'],
        );
        usort($normalized, fn ($a, $b) => $a['item_id'] <=> $b['item_id']);
        $fingerprint = $this->fingerprint([$normalized, $notes]);

        return DB::transaction(function () use (
            $id,
            $version,
            $requestKey,
            $normalized,
            $notes,
            $fingerprint,
            $actor,
        ) {
            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($id);
            if ($existing = $order->receipts()->where('request_key', $requestKey)->first()) {
                $this->check(
                    hash_equals($existing->fingerprint, $fingerprint),
                    'This request key was already used with different receipt data.',
                );

                return $existing->load('items');
            }
            $this->assertVersionState($order, $version, [OrderState::Ordered]);
            $this->inventory($order->inventory_id);
            $items = $order
                ->items()
                ->orderBy('stockable_kind')
                ->orderBy('stockable_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $requested = collect($normalized)->keyBy('item_id');
            $this->check(
                $requested->keys()->diff($items->keys())->isEmpty(),
                'A receipt line does not belong to this order.',
            );
            $this->check(
                collect($normalized)->sum(fn ($line) => $line['accepted'] + $line['rejected']) > 0,
                'Enter at least one accepted or rejected unit.',
            );
            $this->check(
                collect($normalized)->sum('rejected') === 0 || trim((string) $notes) !== '',
                'Explain rejected goods and the replacement or waiver agreement in delivery notes.',
            );
            $models = [];
            foreach ($items as $item) {
                if (! $requested->has($item->id)) {
                    continue;
                }
                $line = $requested[$item->id];
                $this->check(
                    $line['accepted'] + $line['rejected'] > 0,
                    'Remove empty receipt lines.',
                );
                $this->check(
                    $item->outstanding() >= $line['accepted'] + $line['rejected'],
                    'Accepted plus rejected units cannot exceed the outstanding quantity.',
                );
                $this->check(
                    $item->rejected_quantity + $line['rejected'] <= 2147483647,
                    'Rejected quantity exceeds the supported limit.',
                );
                if ($line['accepted'] > 0) {
                    $models[$item->id] = $this->stock->resolve(
                        $item->stockable_kind,
                        $item->stockable_id,
                        true,
                    );
                }
            }
            $receipt = $order
                ->receipts()
                ->create([
                    'request_key' => $requestKey,
                    'fingerprint' => $fingerprint,
                    'received_by' => $actor->getAuthIdentifier(),
                    'notes' => $notes,
                ]);
            foreach ($items as $item) {
                if (! $requested->has($item->id)) {
                    continue;
                }
                $line = $requested[$item->id];
                $receiptItem = $receipt
                    ->items()
                    ->create([
                        'purchase_order_item_id' => $item->id,
                        'accepted_quantity' => $line['accepted'],
                        'rejected_quantity' => $line['rejected'],
                        'unit_cost_minor' => $item->unit_cost_minor,
                        'rejection_resolution' => $line['resolution'],
                    ]);
                if ($line['accepted'] > 0) {
                    $receiptItem->update([
                        'inventory_history_id' => $this->stock->mutate(
                            $models[$item->id],
                            $order->inventory_id,
                            $line['accepted'],
                            $actor->getAuthIdentifier(),
                            $receiptItem,
                        ),
                    ]);
                }
                $item->accepted_quantity += $line['accepted'];
                $item->rejected_quantity += $line['rejected'];
                $item->waived_quantity += $line['resolution'] === 'waive' ? $line['rejected'] : 0;
                $item->save();
            }
            $this->persist($order, 'received', (int) $actor->getAuthIdentifier(), [
                'receipt_id' => $receipt->id,
            ]);

            return $receipt->load('items');
        }, 3);
    }

    public function correct(
        int $id,
        int $version,
        string $key,
        int $receiptItemId,
        int $accepted,
        int $rejected,
        string $reason,
    ): ReceiptCorrection {
        $actor = Access::authorize('correct');
        Validator::make(compact('key', 'accepted', 'rejected', 'reason'), [
            'key' => ['required', 'string', 'max:100'],
            'accepted' => ['integer', 'min:0'],
            'rejected' => ['integer', 'min:0'],
            'reason' => ['required', 'string', 'max:5000'],
        ])->validate();
        $fingerprint = $this->fingerprint([$receiptItemId, $accepted, $rejected, $reason]);

        return DB::transaction(function () use (
            $id,
            $version,
            $key,
            $receiptItemId,
            $accepted,
            $rejected,
            $reason,
            $fingerprint,
            $actor,
        ) {
            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($id);
            if (
                $existing = ReceiptCorrection::query()
                    ->where('purchase_order_id', $id)
                    ->where('request_key', $key)
                    ->first()
            ) {
                $this->check(
                    hash_equals($existing->fingerprint, $fingerprint),
                    'This correction key was already used with different data.',
                );

                return $existing;
            }
            // Closed orders must be explicitly reopened, with a recorded reason, before correction.
            $this->assertVersionState($order, $version, [OrderState::Ordered]);
            $receiptItem = ReceiptItem::query()
                ->whereIn('receipt_id', $order->receipts()->select('id'))
                ->lockForUpdate()
                ->findOrFail($receiptItemId);
            $item = $order
                ->items()
                ->lockForUpdate()
                ->findOrFail($receiptItem->purchase_order_item_id);
            $currentAccepted = $receiptItem->effectiveAccepted();
            $currentRejected = $receiptItem->effectiveRejected();
            $this->check(
                $accepted <= $currentAccepted && $rejected <= $currentRejected,
                'Corrections can reduce recorded units. Record additional units in a new receipt.',
            );
            $acceptedDelta = $accepted - $currentAccepted;
            $rejectedDelta = $rejected - $currentRejected;
            $waivedDelta = $receiptItem->rejection_resolution === 'waive' ? $rejectedDelta : 0;
            $this->check(
                $acceptedDelta !== 0 || $rejectedDelta !== 0,
                'The correction must change a quantity.',
            );
            $correction = ReceiptCorrection::query()->create([
                'purchase_order_id' => $id,
                'receipt_item_id' => $receiptItemId,
                'request_key' => $key,
                'fingerprint' => $fingerprint,
                'accepted_delta' => $acceptedDelta,
                'rejected_delta' => $rejectedDelta,
                'waived_delta' => $waivedDelta,
                'corrected_by' => $actor->getAuthIdentifier(),
                'reason' => $reason,
            ]);
            if ($acceptedDelta !== 0) {
                $model = $this->stock->resolve($item->stockable_kind, $item->stockable_id, true);
                $correction->update([
                    'inventory_history_id' => $this->stock->mutate(
                        $model,
                        $order->inventory_id,
                        $acceptedDelta,
                        $actor->getAuthIdentifier(),
                        $correction,
                    ),
                ]);
            }
            $item->accepted_quantity += $acceptedDelta;
            $item->rejected_quantity += $rejectedDelta;
            $item->waived_quantity += $waivedDelta;
            $item->save();
            $this->persist($order, 'receipt_corrected', (int) $actor->getAuthIdentifier(), [
                'correction_id' => $correction->id,
                'receipt_item_id' => $receiptItemId,
                'accepted_delta' => $acceptedDelta,
                'rejected_delta' => $rejectedDelta,
                'reason' => $reason,
            ]);

            return $correction;
        }, 3);
    }

    public function close(int $id, int $version, string $reason, ?int $itemId = null): PurchaseOrder
    {
        $actor = Access::authorize('close');
        $this->reason($reason);

        return DB::transaction(function () use ($id, $version, $reason, $itemId, $actor) {
            $order = $this->locked($id, $version, [OrderState::Ordered]);
            $items = $order->items()->lockForUpdate()->get();
            if ($itemId !== null) {
                $this->check(
                    $items->contains('id', $itemId),
                    'This line does not belong to the order.',
                );
            }
            $waived = [];
            foreach ($items as $item) {
                if ($itemId !== null && $item->id !== $itemId) {
                    continue;
                }
                $waived[$item->id] = $item->outstanding();
                $item->short_closed_quantity += $waived[$item->id];
                $item->waived_quantity += $waived[$item->id];
                $item->save();
            }
            if ($itemId === null) {
                $order->state = OrderState::Closed;
                $order->closed_at = now();
            }
            $this->persist(
                $order,
                $itemId ? 'line_closed' : 'closed',
                (int) $actor->getAuthIdentifier(),
                ['reason' => $reason, 'waived' => $waived],
            );

            return $order->fresh('items');
        }, 3);
    }

    public function reopen(int $id, int $version, string $reason): PurchaseOrder
    {
        $actor = Access::authorize('close');
        $this->reason($reason);

        return DB::transaction(function () use ($id, $version, $reason, $actor) {
            $order = $this->locked($id, $version, [OrderState::Closed]);
            $order->state = OrderState::Ordered;
            $order->closed_at = null;
            // Previously waived quantities stay waived; reopening never silently recreates a supplier commitment.
            $this->persist($order, 'reopened', (int) $actor->getAuthIdentifier(), [
                'reason' => $reason,
            ]);

            return $order->fresh('items');
        }, 3);
    }

    public function restoreLine(int $id, int $version, int $itemId, string $reason): PurchaseOrder
    {
        $actor = Access::authorize('close');
        $this->reason($reason);

        return DB::transaction(function () use ($id, $version, $itemId, $reason, $actor) {
            $order = $this->locked($id, $version, [OrderState::Ordered]);
            $item = $order->items()->lockForUpdate()->findOrFail($itemId);
            $restored = $item->short_closed_quantity;
            $this->check(
                $restored > 0,
                'This line has no manually short-closed balance to restore.',
            );
            $item->waived_quantity -= $restored;
            $item->short_closed_quantity = 0;
            $item->save();
            $this->persist($order, 'line_reopened', (int) $actor->getAuthIdentifier(), [
                'item_id' => $itemId,
                'restored' => $restored,
                'reason' => $reason,
            ]);

            return $order->fresh('items');
        }, 3);
    }

    public function cancel(int $id, int $version, string $reason): PurchaseOrder
    {
        $actor = Access::authorize('cancel');
        $this->reason($reason);

        return DB::transaction(function () use ($id, $version, $reason, $actor) {
            $order = $this->locked($id, $version, [OrderState::Draft, OrderState::Ordered]);
            $this->check(
                ! $order->receipts()->exists(),
                'This order has receipts. Close the remaining balance instead of cancelling.',
            );
            foreach ($order->items()->lockForUpdate()->get() as $item) {
                $item->update(['waived_quantity' => $item->ordered_quantity]);
            }
            $order->state = OrderState::Cancelled;
            $this->persist($order, 'cancelled', (int) $actor->getAuthIdentifier(), [
                'reason' => $reason,
            ]);

            return $order->fresh('items');
        }, 3);
    }

    public function updateExpectedDate(
        int $id,
        int $version,
        ?string $date,
        string $reason,
    ): PurchaseOrder {
        $actor = Access::authorize('edit');
        $this->reason($reason);
        Validator::make(
            ['date' => $date],
            ['date' => ['nullable', 'date_format:Y-m-d']],
        )->validate();

        return DB::transaction(function () use ($id, $version, $date, $reason, $actor) {
            $order = $this->locked($id, $version, [OrderState::Ordered]);
            $before = $order->expected_at?->format('Y-m-d');
            $order->expected_at = $date;
            $this->persist($order, 'expected_date_changed', (int) $actor->getAuthIdentifier(), [
                'before' => $before,
                'after' => $date,
                'reason' => $reason,
            ]);

            return $order->fresh('items');
        }, 3);
    }

    public function amendLine(
        int $id,
        int $version,
        int $itemId,
        int $quantity,
        int $unitCostMinor,
        string $reason,
    ): PurchaseOrder {
        $actor = Access::authorize('edit');
        Access::authorize('place');
        $this->reason($reason);
        Validator::make(compact('quantity', 'unitCostMinor'), [
            'quantity' => ['integer', 'min:1', 'max:'.config('purchase-orders.max_quantity')],
            'unitCostMinor' => [
                'integer',
                'min:0',
                'max:'.config('purchase-orders.max_total_minor'),
            ],
        ])->validate();

        return DB::transaction(function () use (
            $id,
            $version,
            $itemId,
            $quantity,
            $unitCostMinor,
            $reason,
            $actor,
        ) {
            $order = $this->locked($id, $version, [OrderState::Ordered]);
            $items = $order->items()->lockForUpdate()->get();
            $item = $items->firstWhere('id', $itemId);
            $this->check($item !== null, 'This line does not belong to the order.');
            $this->check(
                $quantity >= $item->accepted_quantity + $item->waived_quantity,
                'The amended quantity cannot be below accepted plus waived units.',
            );
            $before = $item->only(['ordered_quantity', 'unit_cost_minor']);
            $item->ordered_quantity = $quantity;
            $item->unit_cost_minor = $unitCostMinor;
            $total = 0;
            $limit = (int) config('purchase-orders.max_total_minor');
            foreach ($items as $line) {
                $this->check(
                    $line->unit_cost_minor <= intdiv($limit - $total, $line->ordered_quantity),
                    'Merchandise subtotal exceeds the supported amount.',
                );
                $total += $line->ordered_quantity * $line->unit_cost_minor;
            }
            $item->save();
            $this->persist($order, 'line_amended', (int) $actor->getAuthIdentifier(), [
                'item_id' => $itemId,
                'before' => $before,
                'after' => $item->only(['ordered_quantity', 'unit_cost_minor']),
                'reason' => $reason,
            ]);

            return $order->fresh('items');
        }, 3);
    }

    private function locked(int $id, ?int $version, array $states): PurchaseOrder
    {
        $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($id);
        $this->assertVersionState($order, $version, $states);

        return $order;
    }

    private function assertVersionState(PurchaseOrder $order, ?int $version, array $states): void
    {
        $this->check(
            $version === $order->version,
            'This order changed. Refresh before trying again.',
        );
        $this->check(
            in_array($order->state, $states, true),
            'This action is not allowed in the current order state.',
        );
    }

    private function supplier(int $id, string $field = 'order'): mixed
    {
        $class = config('shopper.models.supplier');
        $supplier = $class::query()->find($id);
        $this->check($supplier && $supplier->is_enabled, 'Select an enabled supplier.', $field);

        return $supplier;
    }

    private function inventory(int $id, string $field = 'order'): void
    {
        $class = config('shopper.models.inventory');
        $this->check(
            $class::query()->whereKey($id)->exists(),
            'Select an existing destination inventory.',
            $field,
        );
    }

    private function record(PurchaseOrder $order, string $type, int $actor, array $data = []): void
    {
        $data['state'] = $order->state->value;
        $data['version'] = $order->version;
        $order->history()->create(['type' => $type, 'actor_id' => $actor, 'data' => $data]);
        event(new PurchaseOrderChanged($order->id, $type, $data));
    }

    private function persist(PurchaseOrder $order, string $type, int $actor, array $data = []): void
    {
        $order->version++;
        $order->save();
        $this->record($order, $type, $actor, $data);
    }

    private function fingerprint(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function reason(string $reason): void
    {
        Validator::make(
            ['reason' => $reason],
            ['reason' => ['required', 'string', 'max:5000']],
        )->validate();
    }

    private function check(bool $condition, string $message, string $field = 'order'): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
