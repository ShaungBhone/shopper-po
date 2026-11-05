<?php

declare(strict_types=1);

namespace ShopperPurchaseOrders\Tests;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Shopper\Core\Models\InventoryHistory;
use ShopperPurchaseOrders\Enums\OrderState;
use ShopperPurchaseOrders\Events\PurchaseOrderChanged;
use ShopperPurchaseOrders\Models\PurchaseOrderItem;
use ShopperPurchaseOrders\Models\Receipt;
use ShopperPurchaseOrders\Models\ReceiptCorrection;
use ShopperPurchaseOrders\Models\ReceiptItem;

final class PurchasingTest extends TestCase
{
    public function test_partial_receipts_increment_existing_shopper_stock_exactly_once(): void
    {
        $order = $this->ordered();
        $receipt = $this->purchasing->receive(
            $order->id,
            $order->version,
            'delivery-a',
            $this->receiveLine($order, 4),
        );
        $this->assertSame(4, $this->product->stockInventory($this->inventory->id));
        $this->assertSame('partially_received', $order->fresh('items')->receivingProgress());
        $replay = $this->purchasing->receive(
            $order->id,
            $order->version,
            'delivery-a',
            $this->receiveLine($order, 4),
        );
        $this->assertSame($receipt->id, $replay->id);
        $order->refresh();
        $this->purchasing->receive(
            $order->id,
            $order->version,
            'delivery-b',
            $this->receiveLine($order, 6),
        );
        $this->assertSame(10, $this->product->stockInventory($this->inventory->id));
        $this->assertSame('fully_received', $order->fresh('items')->receivingProgress());
        $this->assertSame(OrderState::Ordered, $order->state);
    }

    public function test_rejected_units_remain_outstanding_when_replacements_are_expected(): void
    {
        $order = $this->ordered(100);
        $this->purchasing->receive(
            $order->id,
            $order->version,
            'a',
            $this->receiveLine($order, 80, 10),
            'Ten damaged units refused; supplier will replace.',
        );
        $order = $order->fresh('items');
        $this->assertSame(20, $order->outstandingQuantity());
        $this->assertSame(10, $order->items->first()->rejected_quantity);
        $this->assertSame(80, $this->product->stockInventory($this->inventory->id));
        $this->purchasing->receive(
            $order->id,
            $order->version,
            'b',
            $this->receiveLine($order, 20),
        );
        $this->assertSame(0, $order->fresh('items')->outstandingQuantity());
    }

    public function test_rejected_waived_units_resolve_the_commitment_without_adding_stock(): void
    {
        $order = $this->ordered(100);
        $this->purchasing->receive(
            $order->id,
            $order->version,
            'a',
            $this->receiveLine($order, 80, 10, 'waive'),
            'Supplier credit agreed; goods refused.',
        );
        $order = $order->fresh('items');
        $this->assertSame(10, $order->outstandingQuantity());
        $this->assertSame(10, $order->items->first()->waived_quantity);
        $closed = $this->purchasing->close(
            $order->id,
            $order->version,
            'No further supply available',
        );
        $this->assertSame(OrderState::Closed, $closed->state);
        $this->assertSame(0, $closed->outstandingQuantity());
        $this->assertSame(80, $this->product->stockInventory($this->inventory->id));
        $this->assertSame('partially_received', $closed->receivingProgress());
    }

    public function test_receipt_correction_preserves_original_and_reopens_five_units(): void
    {
        $order = $this->ordered(50);
        $receipt = $this->purchasing->receive(
            $order->id,
            $order->version,
            'delivery',
            $this->receiveLine($order, 50),
        );
        $order->refresh();
        $item = $receipt->items->first();
        $correction = $this->purchasing->correct(
            $order->id,
            $order->version,
            'correction',
            $item->id,
            45,
            0,
            'Counted five units twice',
        );
        $this->assertSame(50, $item->fresh()->accepted_quantity);
        $this->assertSame(45, $item->effectiveAccepted());
        $this->assertSame(45, $this->product->stockInventory($this->inventory->id));
        $this->assertSame(5, $order->fresh('items')->outstandingQuantity());
        $this->assertSame('partially_received', $order->fresh('items')->receivingProgress());
        $this->assertSame(
            $correction->id,
            $this->purchasing->correct(
                $order->id,
                $order->version,
                'correction',
                $item->id,
                45,
                0,
                'Counted five units twice',
            )->id,
        );
        $this->assertSame(2, InventoryHistory::query()->count());
        $this->assertSame('po_receipt_item', InventoryHistory::query()->first()->reference_type);
        $this->assertSame(
            'po_receipt_correction',
            InventoryHistory::query()->latest('id')->first()->reference_type,
        );
    }

    public function test_closed_order_must_be_reopened_before_receipt_correction(): void
    {
        $order = $this->ordered();
        $receipt = $this->purchasing->receive(
            $order->id,
            $order->version,
            'delivery',
            $this->receiveLine($order, 10),
        );
        $order->refresh();
        $closed = $this->purchasing->close($order->id, $order->version, 'Received in full');
        $this->invalid(
            fn () => $this->purchasing->correct(
                $closed->id,
                $closed->version,
                'x',
                $receipt->items->first()->id,
                9,
                0,
                'Mistake',
            ),
        );
        $reopened = $this->purchasing->reopen(
            $closed->id,
            $closed->version,
            'Investigate receiving discrepancy',
        );
        $this->purchasing->correct(
            $reopened->id,
            $reopened->version,
            'x',
            $receipt->items->first()->id,
            9,
            0,
            'Mistake',
        );
        $this->assertSame(1, $order->fresh('items')->outstandingQuantity());
    }

    public function test_correcting_rejected_waived_units_restores_outstanding_balance(): void
    {
        $order = $this->ordered();
        $receipt = $this->purchasing->receive(
            $order->id,
            $order->version,
            'delivery',
            $this->receiveLine($order, 5, 5, 'waive'),
            'Five rejected, credit agreed',
        );
        $order->refresh();
        $this->purchasing->correct(
            $order->id,
            $order->version,
            'correction',
            $receipt->items->first()->id,
            5,
            3,
            'Only three were actually rejected',
        );
        $order = $order->fresh('items');
        $this->assertSame(3, $order->items->first()->waived_quantity);
        $this->assertSame(2, $order->outstandingQuantity());
        $this->assertSame(5, $this->product->stockInventory($this->inventory->id));
    }

    public function test_correction_cannot_make_location_stock_negative(): void
    {
        $order = $this->ordered();
        $receipt = $this->purchasing->receive(
            $order->id,
            $order->version,
            'delivery',
            $this->receiveLine($order, 10),
        );
        $this->product->decreaseStock($this->inventory->id, 8, userId: $this->user->id);
        $order->refresh();
        $this->invalid(
            fn () => $this->purchasing->correct(
                $order->id,
                $order->version,
                'correction',
                $receipt->items->first()->id,
                5,
                0,
                'Miscount',
            ),
        );
        $this->assertSame(0, ReceiptCorrection::query()->count());
        $this->assertSame(10, $order->fresh('items')->items->first()->accepted_quantity);
        $this->assertSame(2, $this->product->stockInventory($this->inventory->id));
    }

    public function test_partial_order_cannot_be_cancelled_but_can_be_closed(): void
    {
        $order = $this->ordered();
        $this->purchasing->receive(
            $order->id,
            $order->version,
            'delivery',
            $this->receiveLine($order, 4),
        );
        $order->refresh();
        $this->invalid(
            fn () => $this->purchasing->cancel($order->id, $order->version, 'Stop purchasing'),
        );
        $closed = $this->purchasing->close($order->id, $order->version, 'Stop purchasing');
        $this->assertSame(6, $closed->items->first()->waived_quantity);
        $this->assertSame(4, $this->product->stockInventory($this->inventory->id));
    }

    public function test_unreceived_cancellation_is_terminal_and_does_not_change_stock(): void
    {
        $order = $this->ordered();
        $cancelled = $this->purchasing->cancel(
            $order->id,
            $order->version,
            'Supplier cannot supply',
        );
        $this->assertSame(OrderState::Cancelled, $cancelled->state);
        $this->assertSame(0, InventoryHistory::query()->count());
        $this->invalid(
            fn () => $this->purchasing->receive(
                $cancelled->id,
                $cancelled->version,
                'delivery',
                $this->receiveLine($cancelled, 1),
            ),
        );
    }

    public function test_over_receipt_stale_version_and_key_collision_are_rejected(): void
    {
        $order = $this->ordered();
        $this->invalid(
            fn () => $this->purchasing->receive(
                $order->id,
                $order->version,
                'a',
                $this->receiveLine($order, 11),
            ),
        );
        $this->purchasing->receive($order->id, $order->version, 'a', $this->receiveLine($order, 4));
        $this->invalid(
            fn () => $this->purchasing->receive(
                $order->id,
                $order->version,
                'b',
                $this->receiveLine($order, 4),
            ),
        );
        $this->invalid(
            fn () => $this->purchasing->receive(
                $order->id,
                $order->version,
                'a',
                $this->receiveLine($order, 5),
            ),
        );
        $this->assertSame(4, $this->product->stockInventory($this->inventory->id));
    }

    public function test_foreign_and_duplicate_line_ids_cannot_receive_stock(): void
    {
        $order = $this->ordered();
        $other = $this->ordered();
        $this->invalid(
            fn () => $this->purchasing->receive(
                $order->id,
                $order->version,
                'a',
                $this->receiveLine($other, 1),
            ),
        );
        $line = $this->receiveLine($order, 1)[0];
        $this->invalid(
            fn () => $this->purchasing->receive($order->id, $order->version, 'a', [$line, $line]),
        );
        $this->assertSame(0, InventoryHistory::query()->count());
    }

    public function test_zero_negative_fractional_and_unexplained_rejections_fail(): void
    {
        $order = $this->ordered();
        foreach ([0, -1, 1.5] as $quantity) {
            $lines = $this->receiveLine($order, 0);
            $lines[0]['accepted'] = $quantity;
            $this->invalid(
                fn () => $this->purchasing->receive($order->id, $order->version, 'x', $lines),
            );
        }
        $this->invalid(
            fn () => $this->purchasing->receive(
                $order->id,
                $order->version,
                'x',
                $this->receiveLine($order, 1, 1),
            ),
        );
    }

    public function test_failure_after_stock_write_rolls_back_receipt_stock_and_history(): void
    {
        $order = $this->ordered();
        $version = $order->version;
        $historyCount = $order->history()->count();
        $event = 'eloquent.updated: '.PurchaseOrderItem::class;
        Event::listen($event, function (): void {
            throw new \RuntimeException('Injected failure after stock write');
        });
        try {
            $this->purchasing->receive(
                $order->id,
                $version,
                'delivery',
                $this->receiveLine($order, 4),
            );
            $this->fail('Expected injected failure');
        } catch (\RuntimeException $error) {
            $this->assertSame('Injected failure after stock write', $error->getMessage());
        }
        Event::forget($event);
        $this->assertSame(0, Receipt::query()->count());
        $this->assertSame(0, ReceiptItem::query()->count());
        $this->assertSame(0, InventoryHistory::query()->count());
        $this->assertSame($version, $order->fresh()->version);
        $this->assertSame($historyCount, $order->history()->count());
        $this->purchasing->receive($order->id, $version, 'delivery', $this->receiveLine($order, 4));
        $this->assertSame(4, $this->product->stockInventory($this->inventory->id));
    }

    public function test_events_are_dispatched_only_after_commit(): void
    {
        $order = $this->ordered();
        $seen = [];
        Event::listen(PurchaseOrderChanged::class, function ($event) use (&$seen): void {
            $seen[] = [$event->type, DB::transactionLevel()];
        });
        DB::beginTransaction();
        $this->purchasing->receive($order->id, $order->version, 'a', $this->receiveLine($order, 2));
        $this->assertSame([], $seen);
        DB::rollBack();
        $this->assertSame([], $seen);
        $this->purchasing->receive($order->id, $order->version, 'a', $this->receiveLine($order, 2));
        $this->assertSame([['received', 0]], $seen);
    }

    public function test_permission_revocation_prevents_mutation(): void
    {
        $order = $this->ordered();
        $this->user->revokePermissionTo('edit_orders');
        $this->expectException(AuthorizationException::class);
        $this->purchasing->receive($order->id, $order->version, 'a', $this->receiveLine($order, 2));
    }

    public function test_amendments_are_audited_and_existing_receipt_costs_do_not_change(): void
    {
        $order = $this->ordered();
        $receipt = $this->purchasing->receive(
            $order->id,
            $order->version,
            'a',
            $this->receiveLine($order, 4),
        );
        $order->refresh();
        $this->invalid(
            fn () => $this->purchasing->amendLine(
                $order->id,
                $order->version,
                $order->items->first()->id,
                3,
                1000,
                'Supplier amendment',
            ),
        );
        $changed = $this->purchasing->amendLine(
            $order->id,
            $order->version,
            $order->items->first()->id,
            12,
            1500,
            'Supplier confirmed extra units',
        );
        $this->assertSame(8, $changed->outstandingQuantity());
        $this->assertSame(1250, $receipt->items->first()->unit_cost_minor);
        $this->assertSame(1500, $changed->items->first()->unit_cost_minor);
        $this->assertSame(
            'line_amended',
            $changed->history()->reorder('id', 'desc')->first()->type,
        );
    }

    public function test_draft_and_expected_date_updates_check_versions_and_preserve_history(): void
    {
        $order = $this->draft();
        $updated = $this->purchasing->saveDraft($this->draftData(20), $order->id, $order->version);
        $this->invalid(
            fn () => $this->purchasing->saveDraft($this->draftData(), $order->id, $order->version),
        );
        $placed = $this->purchasing->place($updated->id, $updated->version);
        $this->invalid(
            fn () => $this->purchasing->saveDraft($this->draftData(), $placed->id, $placed->version),
        );
        $dated = $this->purchasing->updateExpectedDate(
            $placed->id,
            $placed->version,
            '2026-12-01',
            'Supplier confirmed schedule',
        );
        $this->assertSame('2026-12-01', $dated->expected_at->format('Y-m-d'));
        $this->assertSame(4, $dated->history()->count());
    }

    public function test_disabled_supplier_blocks_placement_but_not_existing_order_receipts(): void
    {
        $draft = $this->draft();
        $order = $this->ordered();
        $this->supplier->update(['is_enabled' => false]);
        $this->invalid(fn () => $this->purchasing->place($draft->id, $draft->version));
        $this->purchasing->receive($order->id, $order->version, 'a', $this->receiveLine($order, 2));
        $this->assertSame(2, $this->product->stockInventory($this->inventory->id));
    }

    protected function invalid(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected business rule rejection.');
        } catch (ValidationException $error) {
            $this->assertNotEmpty($error->errors());
        }
    }
}
