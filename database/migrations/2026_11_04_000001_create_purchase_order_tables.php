<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(shopper_table('purchase_orders'), function (Blueprint $table) {
            $table->id();
            $table->string('number')->nullable()->unique();
            $table->unsignedBigInteger('supplier_id')->nullable()->index();
            $table->json('supplier_snapshot');
            $table
                ->foreignId('inventory_id')
                ->constrained(shopper_table('inventories'))
                ->restrictOnDelete();
            $table->string('currency', 3);
            $table->unsignedTinyInteger('currency_exponent');
            $table->string('state', 20)->default('draft')->index();
            $table->date('expected_at')->nullable();
            $table->string('supplier_reference')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('ordered_by')->nullable();
            $table->timestamp('ordered_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['supplier_id', 'created_at']);
        });
        Schema::create(shopper_table('purchase_order_items'), function (Blueprint $table) {
            $table->id();
            $table
                ->foreignId('purchase_order_id')
                ->constrained(shopper_table('purchase_orders'))
                ->restrictOnDelete();
            $table->string('stockable_kind', 10);
            $table->unsignedBigInteger('stockable_id');
            $table->string('name');
            $table->string('sku')->nullable();
            $table->unsignedInteger('ordered_quantity');
            $table->unsignedInteger('accepted_quantity')->default(0);
            $table->unsignedInteger('rejected_quantity')->default(0);
            $table->unsignedInteger('waived_quantity')->default(0);
            $table->unsignedInteger('short_closed_quantity')->default(0);
            $table->unsignedBigInteger('unit_cost_minor');
            $table->timestamps();
            $table->unique(
                ['purchase_order_id', 'stockable_kind', 'stockable_id'],
                'po_unique_stockable',
            );
        });
        Schema::create(shopper_table('purchase_order_receipts'), function (Blueprint $table) {
            $table->id();
            $table
                ->foreignId('purchase_order_id')
                ->constrained(shopper_table('purchase_orders'))
                ->restrictOnDelete();
            $table->string('request_key', 100);
            $table->string('fingerprint', 64);
            $table->unsignedBigInteger('received_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['purchase_order_id', 'request_key'], 'po_unique_receipt_request');
        });
        Schema::create(shopper_table('purchase_order_receipt_items'), function (Blueprint $table) {
            $table->id();
            $table
                ->foreignId('receipt_id')
                ->constrained(shopper_table('purchase_order_receipts'))
                ->restrictOnDelete();
            $table
                ->foreignId('purchase_order_item_id')
                ->constrained(shopper_table('purchase_order_items'))
                ->restrictOnDelete();
            $table->unsignedInteger('accepted_quantity');
            $table->unsignedInteger('rejected_quantity');
            $table->unsignedBigInteger('unit_cost_minor');
            $table->string('rejection_resolution', 10);
            $table->unsignedBigInteger('inventory_history_id')->nullable()->unique();
            $table
                ->foreign('inventory_history_id', 'po_receipt_history_fk')
                ->references('id')
                ->on(shopper_table('inventory_histories'))
                ->restrictOnDelete();
            $table->timestamps();
            $table->unique(['receipt_id', 'purchase_order_item_id'], 'po_unique_receipt_line');
        });
        Schema::create(shopper_table('purchase_order_corrections'), function (Blueprint $table) {
            $table->id();
            $table
                ->foreignId('purchase_order_id')
                ->constrained(shopper_table('purchase_orders'))
                ->restrictOnDelete();
            $table
                ->foreignId('receipt_item_id')
                ->constrained(shopper_table('purchase_order_receipt_items'))
                ->restrictOnDelete();
            $table->string('request_key', 100);
            $table->string('fingerprint', 64);
            $table->integer('accepted_delta');
            $table->integer('rejected_delta');
            $table->integer('waived_delta');
            $table->unsignedBigInteger('corrected_by')->nullable();
            $table->text('reason');
            $table->unsignedBigInteger('inventory_history_id')->nullable()->unique();
            $table
                ->foreign('inventory_history_id', 'po_correction_history_fk')
                ->references('id')
                ->on(shopper_table('inventory_histories'))
                ->restrictOnDelete();
            $table->timestamps();
            $table->unique(['purchase_order_id', 'request_key'], 'po_unique_correction_request');
        });
        Schema::create(shopper_table('purchase_order_events'), function (Blueprint $table) {
            $table->id();
            $table
                ->foreignId('purchase_order_id')
                ->constrained(shopper_table('purchase_orders'))
                ->restrictOnDelete();
            $table->string('type', 40);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->json('data');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (
            Schema::hasTable(shopper_table('purchase_orders')) &&
            DB::table(shopper_table('purchase_orders'))->exists()
        ) {
            throw new LogicException(
                'Purchase orders exist. Preserve business history; migration rollback is blocked.',
            );
        }
        foreach (
            [
                'purchase_order_events',
                'purchase_order_corrections',
                'purchase_order_receipt_items',
                'purchase_order_receipts',
                'purchase_order_items',
                'purchase_orders',
            ] as $table
        ) {
            Schema::dropIfExists(shopper_table($table));
        }
    }
};
