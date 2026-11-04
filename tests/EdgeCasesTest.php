<?php

declare(strict_types=1);

namespace ShopperPurchaseOrders\Tests;

use Illuminate\Validation\ValidationException;
use Shopper\Core\Enum\ProductType;
use Shopper\Core\Models\Product;
use Shopper\Core\Models\ProductVariant;
use ShopperPurchaseOrders\Enums\OrderState;

final class EdgeCasesTest extends TestCase
{
    public function test_restoring_short_closed_balance_preserves_rejection_waivers(): void
    {
        $order = $this->ordered(100);
        $receipt = $this->purchasing->receive(
            $order->id,
            $order->version,
            'a',
            $this->receiveLine($order, 80, 10, 'waive'),
            'Ten rejected; credit agreed',
        );
        $order->refresh();
        $closed = $this->purchasing->close($order->id, $order->version, 'Accidental closure');
        $this->assertSame(10, $closed->items->first()->short_closed_quantity);
        $open = $this->purchasing->reopen($closed->id, $closed->version, 'Supplier still owes ten');
        $restored = $this->purchasing->restoreLine(
            $open->id,
            $open->version,
            $open->items->first()->id,
            'Restore only the short-closed balance',
        );
        $this->assertSame(100, $restored->items->first()->ordered_quantity);
        $this->assertSame(10, $restored->items->first()->waived_quantity);
        $this->assertSame(10, $restored->outstandingQuantity());
        $this->purchasing->correct(
            $restored->id,
            $restored->version,
            'fix-rejection',
            $receipt->items->first()->id,
            80,
            9,
            'One rejection was recorded twice',
        );
        $this->assertSame(11, $restored->fresh('items')->outstandingQuantity());
        $this->assertSame(80, $this->product->stockInventory($this->inventory->id));
    }

    public function test_migration_refuses_to_drop_business_records(): void
    {
        $order = $this->draft();
        $migration = require __DIR__.
            '/../database/migrations/2026_11_04_000001_create_purchase_order_tables.php';
        try {
            $migration->down();
            $this->fail('Expected rollback protection');
        } catch (\LogicException $error) {
            $this->assertStringContainsString('Preserve business history', $error->getMessage());
        }
        $this->assertNotNull($order->fresh());
    }

    public function test_one_line_can_be_closed_while_another_stays_open(): void
    {
        $product = Product::withoutEvents(
            fn () => Product::factory()->create(['type' => ProductType::Standard]),
        );
        $data = $this->draftData();
        $data['items'][] = [
            'stockable_kind' => 'product',
            'stockable_id' => $product->id,
            'ordered_quantity' => 20,
            'unit_cost_minor' => 500,
        ];
        $draft = $this->purchasing->saveDraft($data);
        $order = $this->purchasing->place($draft->id, $draft->version);
        $changed = $this->purchasing->close(
            $order->id,
            $order->version,
            'Discontinued product',
            $order->items->first()->id,
        );
        $this->assertSame(OrderState::Ordered, $changed->state);
        $this->assertSame(20, $changed->outstandingQuantity());
        $this->assertSame(10, $changed->items->first()->waived_quantity);
        $reopened = $this->purchasing->amendLine(
            $changed->id,
            $changed->version,
            $changed->items->first()->id,
            15,
            1250,
            'Supplier can supply five more',
        );
        $this->assertSame(25, $reopened->outstandingQuantity());
    }

    public function test_specific_variants_receive_their_own_stock(): void
    {
        $parent = Product::withoutEvents(
            fn () => Product::factory()->create(['type' => ProductType::Variant]),
        );
        $variant = ProductVariant::withoutEvents(
            fn () => ProductVariant::factory()->create(['product_id' => $parent->id]),
        );
        $data = $this->draftData();
        $data['items'][0]['stockable_kind'] = 'variant';
        $data['items'][0]['stockable_id'] = $variant->id;
        $draft = $this->purchasing->saveDraft($data);
        $order = $this->purchasing->place($draft->id, $draft->version);
        $this->purchasing->receive($order->id, $order->version, 'a', $this->receiveLine($order, 2));
        $this->assertSame(2, $variant->stockInventory($this->inventory->id));
        $this->assertSame(0, $parent->stockInventory($this->inventory->id));
    }

    public function test_virtual_and_variant_parent_products_cannot_be_ordered(): void
    {
        foreach ([ProductType::Virtual, ProductType::External, ProductType::Variant] as $type) {
            $this->product->updateQuietly(['type' => $type]);
            try {
                $this->draft();
                $this->fail('Expected invalid product rejection');
            } catch (ValidationException $error) {
                $this->assertNotEmpty($error->errors());
            }
        }
    }

    public function test_totals_cannot_overflow_and_duplicate_products_are_rejected(): void
    {
        $data = $this->draftData();
        $data['items'][0]['unit_cost_minor'] = 9000000000000;
        try {
            $this->purchasing->saveDraft($data);
            $this->fail('Expected subtotal rejection');
        } catch (ValidationException $error) {
            $this->assertNotEmpty($error->errors());
        }
        $data = $this->draftData();
        $data['items'][] = $data['items'][0];
        $this->expectException(ValidationException::class);
        $this->purchasing->saveDraft($data);
    }

    public function test_forged_draft_counters_and_state_are_not_mass_assigned(): void
    {
        $data = $this->draftData();
        $data['state'] = 'closed';
        $data['items'][0]['accepted_quantity'] = 10;
        $data['items'][0]['waived_quantity'] = 10;
        $data['items'][0]['name'] = 'Forged name';
        $draft = $this->purchasing->saveDraft($data);
        $this->assertSame(OrderState::Draft, $draft->state);
        $this->assertSame(0, $draft->items->first()->accepted_quantity);
        $this->assertSame(0, $draft->items->first()->waived_quantity);
        $this->assertSame($this->product->name, $draft->items->first()->name);
    }

    public function test_product_snapshot_is_refreshed_on_placement_and_frozen_afterwards(): void
    {
        $draft = $this->draft();
        $this->product->updateQuietly(['name' => 'Name at ordering']);
        $order = $this->purchasing->place($draft->id, $draft->version);
        $this->assertSame('Name at ordering', $order->items->first()->name);
        $this->product->updateQuietly(['name' => 'Later catalog name']);
        $this->assertSame('Name at ordering', $order->fresh('items')->items->first()->name);
    }
}
