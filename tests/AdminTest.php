<?php

declare(strict_types=1);

namespace ShopperPurchaseOrders\Tests;

use Illuminate\Support\Facades\Route;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Shopper\Core\Enum\ProductType;
use Shopper\Core\Models\InventoryHistory;
use Shopper\Core\Models\Product;
use Shopper\Core\Models\Setting;
use Shopper\Http\Middleware\Authenticate;
use ShopperPurchaseOrders\Livewire\Detail;
use ShopperPurchaseOrders\Livewire\Form;
use ShopperPurchaseOrders\Livewire\Index;
use ShopperPurchaseOrders\Livewire\Receive;
use ShopperPurchaseOrders\Models\PurchaseOrder;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class AdminTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $migration = require __DIR__.'/../vendor/spatie/laravel-medialibrary/database/migrations/create_media_table.php.stub';
        $migration->up();
    }

    public function test_addon_registers_routes_inside_shopper_context(): void
    {
        $route = Route::getRoutes()->getByName('shopper.purchase-orders.show');
        $this->assertNotNull($route);
        $this->assertSame('cpanel/purchase-orders/{orderId}', $route->uri());
        $this->assertContains(Authenticate::class, $route->middleware());
        $this->assertTrue(app('shopper')->hasAddon('purchase-orders'));
        $this->assertSame(
            Index::class,
            app('shopper')->addonManager()->getLivewireComponents()['purchase-orders.index'],
        );
    }

    public function test_full_admin_route_renders_inside_shopper_layout(): void
    {
        $permission = config('permission.models.permission');
        foreach (
            [
                'products',
                'attributes',
                'suppliers',
                'tags',
                'categories',
                'collections',
                'brands',
                'orders',
                'discounts',
                'customers',
            ] as $resource
        ) {
            $permission::query()
                ->firstOrCreate(['name' => 'browse_'.$resource, 'guard_name' => 'web']);
        }
        $access = $permission::query()
            ->firstOrCreate(['name' => 'access_dashboard', 'guard_name' => 'web']);
        $this->user->givePermissionTo($access);
        foreach (
            ['email' => 'store@example.test', 'street_address' => 'Test warehouse'] as $key => $value
        ) {
            Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
        $this->get(route('shopper.purchase-orders.index'))
            ->assertOk()
            ->assertSee('Purchase orders');
    }

    public function test_registered_routes_and_configuration_can_be_cached(): void
    {
        try {
            $this->artisan('route:cache')->assertSuccessful();
            $this->artisan('config:cache')->assertSuccessful();
        } finally {
            $this->artisan('route:clear')->run();
            $this->artisan('config:clear')->run();
        }
    }

    public function test_state_tabs_filter_purchase_orders_and_keep_global_counts(): void
    {
        $draft = $this->draft();
        $ordered = $this->ordered();
        $received = $this->ordered();
        $this->purchasing->receive($received->id, $received->version, 'full-delivery', $this->receiveLine($received, 10));
        $toClose = $this->ordered();
        $closed = $this->purchasing->close($toClose->id, $toClose->version, 'Supplier cannot fill the order');

        $component = Livewire::test(Index::class)
            ->assertSet('activeTab', 'all')
            ->assertCanSeeTableRecords([$draft, $ordered, $received, $closed])
            ->set('activeTab', 'cancelled')
            ->assertSee('No purchase orders found');
        $this->assertSame('0', $component->instance()->getCachedTabs()['cancelled']->getBadge());

        $toCancel = $this->draft();
        $cancelled = $this->purchasing->cancel($toCancel->id, $toCancel->version, 'Duplicate order');

        $component
            ->set('activeTab', 'draft')
            ->assertCanSeeTableRecords([$draft])
            ->assertCountTableRecords(1)
            ->set('activeTab', 'ordered')
            ->assertCanSeeTableRecords([$ordered, $received])
            ->assertCountTableRecords(2)
            ->searchTable($closed->number)
            ->assertSee('No purchase orders found')
            ->searchTable($received->number)
            ->assertCanSeeTableRecords([$received])
            ->assertCountTableRecords(1);
        $this->assertSame('2', $component->instance()->getCachedTabs()['ordered']->getBadge());
        $this->assertSame('5', $component->instance()->getCachedTabs()['all']->getBadge());

        $component
            ->searchTable('')
            ->set('activeTab', 'closed')
            ->assertCanSeeTableRecords([$closed])
            ->assertCountTableRecords(1)
            ->set('activeTab', 'cancelled')
            ->assertCanSeeTableRecords([$cancelled])
            ->assertCountTableRecords(1)
            ->set('activeTab', 'all')
            ->assertCanSeeTableRecords([$draft, $ordered, $received, $closed, $cancelled])
            ->assertCountTableRecords(5);
    }

    public function test_table_filters_compose_with_state_tabs_and_search(): void
    {
        $supplier = $this->supplier::factory()->create(['is_enabled' => true]);
        $inventory = $this->inventory::factory()->create();
        $draft = $this->purchasing->saveDraft(array_replace($this->draftData(), [
            'supplier_id' => $supplier->id,
            'inventory_id' => $inventory->id,
            'expected_at' => '2026-11-11',
        ]));
        $partial = $this->ordered();
        $this->purchasing->receive($partial->id, $partial->version, 'partial-filter', $this->receiveLine($partial, 4));
        $full = $this->ordered();
        $this->purchasing->receive($full->id, $full->version, 'full-filter', $this->receiveLine($full, 10));

        // Historical purchase orders can still be filtered after their supplier is deleted.
        $supplier->delete();

        $table = Livewire::test(Index::class)
            ->filterTable('supplier_id', $supplier->id)
            ->assertCanSeeTableRecords([$draft])
            ->assertCanNotSeeTableRecords([$partial, $full])
            ->resetTableFilters()
            ->filterTable('inventory_id', $inventory->id)
            ->assertCanSeeTableRecords([$draft])
            ->assertCanNotSeeTableRecords([$partial, $full])
            ->resetTableFilters()
            ->filterTable('expected_at', ['from' => '2026-11-11', 'until' => '2026-11-11'])
            ->assertCanSeeTableRecords([$draft])
            ->assertCanNotSeeTableRecords([$partial, $full])
            ->resetTableFilters()
            ->filterTable('receiving_progress', 'not_received')
            ->assertCanSeeTableRecords([$draft])
            ->assertCanNotSeeTableRecords([$partial, $full])
            ->filterTable('receiving_progress', 'fully_received')
            ->assertCanSeeTableRecords([$full])
            ->assertCanNotSeeTableRecords([$draft, $partial])
            ->filterTable('receiving_progress', 'partially_received')
            ->set('activeTab', 'ordered')
            ->assertCanSeeTableRecords([$partial])
            ->assertCanNotSeeTableRecords([$draft, $full])
            ->searchTable($full->number)
            ->assertSee('No purchase orders found');

        $this->assertSame('2', $table->instance()->getCachedTabs()['ordered']->getBadge());
    }

    public function test_switching_state_tabs_resets_table_pagination(): void
    {
        $firstDraft = $this->draft();
        for ($index = 0; $index < 10; $index++) {
            $this->draft();
        }

        Livewire::test(Index::class)
            ->set('tableRecordsPerPage', 10)
            ->call('nextPage')
            ->assertSet('paginators.page', 2)
            ->assertCanSeeTableRecords([$firstDraft])
            ->set('activeTab', 'draft')
            ->assertSet('paginators.page', 1)
            ->assertCanNotSeeTableRecords([$firstDraft]);
    }

    public function test_four_admin_views_render_and_draft_can_be_saved(): void
    {
        Livewire::test(Index::class)
            ->assertSee('Purchase orders')
            ->assertSee('No purchase orders found');
        Livewire::test(Form::class)
            ->set('data', $this->draftData())
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();
        $order = PurchaseOrder::query()->first();
        Livewire::test(Detail::class, ['orderId' => $order->id])
            ->assertSee($order->number)
            ->assertSee('Mark ordered')
            ->callAction('place')
            ->assertHasNoErrors();
        Livewire::test(Receive::class, ['orderId' => $order->id])
            ->assertSee('Receive delivery')
            ->assertSee($this->inventory->name)
            ->set('lines.0.accepted', 4)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();
        $this->assertSame(4, $this->product->stockInventory($this->inventory->id));
        Livewire::test(Detail::class, ['orderId' => $order->id])
            ->assertSee('Partially received')
            ->assertSee('Receipt #');
    }

    public function test_draft_panel_saves_in_place_and_rejects_a_stale_editor(): void
    {
        $order = $this->draft();
        $detail = Livewire::test(Detail::class, ['orderId' => $order->id]);
        $stale = Livewire::test(Form::class, ['orderId' => $order->id]);

        Livewire::test(Form::class, ['orderId' => $order->id])
            ->assertSee('Edit draft')
            ->assertSet('data.items.0.ordered_quantity', 10)
            ->set('data.items.0.ordered_quantity', 12)
            ->call('save')
            ->assertHasNoErrors()
            ->assertNoRedirect()
            ->assertDispatched('closePanel')
            ->assertDispatchedTo(Detail::class, 'purchase-order-draft-saved', orderId: $order->id);

        $detail->dispatch('purchase-order-draft-saved', orderId: $order->id)
            ->assertSet('version', $order->version + 1)
            ->assertSee('USD 150.00')
            ->assertSee('Draft updated');

        $stale->set('data.items.0.ordered_quantity', 20)
            ->call('save')
            ->assertHasErrors()
            ->assertSee('This order changed. Refresh before trying again.')
            ->assertNotDispatched('closePanel');

        $this->assertSame(12, $order->items()->firstOrFail()->ordered_quantity);
    }

    public function test_draft_wizard_validates_each_step_and_reports_the_step_for_save_errors(): void
    {
        $order = $this->draft();
        $editor = Livewire::test(Form::class, ['orderId' => $order->id]);
        $wizard = $editor->instance()->getSchema('draftWizard')->getComponents()[0];
        $key = $wizard->getKey();
        [$details, $products, $review] = array_map(fn ($step) => $step->getKey(), $wizard->getChildSchema()->getComponents());

        $editor->set('data.supplier_id', '')
            ->call('callSchemaComponentMethod', $key, 'nextStep', ['currentStepIndex' => 0, 'currentStepKey' => $details])
            ->assertHasErrors('supplier_id')
            ->assertNotDispatched('next-wizard-step')
            ->set('data.supplier_id', $this->supplier->id)
            ->call('callSchemaComponentMethod', $key, 'nextStep', ['currentStepIndex' => 0, 'currentStepKey' => $details])
            ->assertDispatched('next-wizard-step', step: $products)
            ->set('data.items.0.ordered_quantity', 0)
            ->call('callSchemaComponentMethod', $key, 'nextStep', ['currentStepIndex' => 1, 'currentStepKey' => $products])
            ->assertHasErrors('items.0.ordered_quantity')
            ->assertNotDispatched('next-wizard-step')
            ->set('data.items.0.ordered_quantity', 2)
            ->call('callSchemaComponentMethod', $key, 'nextStep', ['currentStepIndex' => 1, 'currentStepKey' => $products])
            ->assertDispatched('next-wizard-step', step: $review);

        $this->supplier->update(['is_enabled' => false]);
        $editor->call('save')
            ->assertHasErrors('supplier_id')
            ->assertDispatched('draft-wizard-error', step: $details)
            ->assertNotDispatched('closePanel');
    }

    public function test_draft_catalog_selection_and_line_edits_survive_pagination(): void
    {
        $products = Product::withoutEvents(fn () => Product::factory()->count(12)
            ->sequence(fn ($sequence) => [
                'name' => sprintf('PO catalog item %02d', $sequence->index + 1),
                'sku' => sprintf('PO-CAT-%02d', $sequence->index + 1),
                'type' => ProductType::Standard,
            ])->create());
        $order = $this->draft();
        $selection = $products->map(fn (Product $product): string => 'product:'.$product->id)->all();

        Livewire::test(Form::class, ['orderId' => $order->id])
            ->call('openCatalog')
            ->set('catalogSearch', 'PO-CAT')
            ->assertSee('PO-CAT-01')
            ->assertDontSee('PO-CAT-12')
            ->set('selectedProducts', array_slice($selection, 0, 10))
            ->call('nextPage', 'catalogPage')
            ->assertSee('PO-CAT-12')
            ->assertSet('selectedProducts', array_slice($selection, 0, 10))
            ->set('selectedProducts', [...$selection, 'product:'.$this->product->id])
            ->call('addSelectedProducts')
            ->assertHasNoErrors()
            ->assertSet('paginators.draftLinesPage', 2)
            ->assertSee('13 lines')
            ->set('data.items.12.ordered_quantity', 4)
            ->set('lineSearch', 'PO-CAT-01')
            ->assertSet('paginators.draftLinesPage', 1)
            ->assertSee('PO-CAT-01')
            ->assertDontSee('PO-CAT-12')
            ->set('data.items.1.ordered_quantity', 5)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(13, $order->items()->count());
        $this->assertSame(4, $order->items()->where('sku', 'PO-CAT-12')->firstOrFail()->ordered_quantity);
        $this->assertSame(5, $order->items()->where('sku', 'PO-CAT-01')->firstOrFail()->ordered_quantity);
    }

    public function test_products_paginate_and_keep_the_full_order_total(): void
    {
        $data = $this->draftData(2);
        $this->product->updateQuietly(['name' => 'First product']);
        foreach (['Second product', 'Third product', 'Fourth product'] as $name) {
            $product = Product::withoutEvents(
                fn () => Product::factory()->create(['name' => $name, 'type' => ProductType::Standard]),
            );
            $data['items'][] = array_replace($data['items'][0], ['stockable_id' => $product->id]);
        }

        $order = $this->purchasing->saveDraft($data);
        $mediaProduct = config('shopper.models.product')::query()->findOrFail($this->product->id);
        $thumbnail = $mediaProduct->media()->create([
            'collection_name' => config('shopper.media.storage.thumbnail_collection'),
            'name' => 'Sample thumbnail',
            'file_name' => 'sample-thumbnail.jpg',
            'mime_type' => 'image/jpeg',
            'disk' => 'public',
            'conversions_disk' => 'public',
            'size' => 1024,
            'manipulations' => [],
            'custom_properties' => [],
            'generated_conversions' => [],
            'responsive_images' => [],
        ]);

        Livewire::test(Detail::class, ['orderId' => $order->id])
            ->assertSeeInOrder(['First product', 'Second product', 'Third product'])
            ->assertSee($thumbnail->getUrl())
            ->assertSee(shopper_fallback_url())
            ->assertDontSee('Fourth product')
            ->assertSee('USD 100.00')
            ->call('nextPage', 'productsPage')
            ->assertSee('Fourth product')
            ->assertDontSee('First product')
            ->assertSee('USD 100.00')
            ->set('productsPerPage', 5)
            ->assertSeeInOrder(['First product', 'Second product', 'Third product', 'Fourth product'])
            ->assertSet('paginators.productsPage', 1);
    }

    public function test_activity_lists_recorded_events_before_the_next_step(): void
    {
        $this->freezeTime();
        $order = $this->draft();
        Livewire::test(Detail::class, ['orderId' => $order->id])
            ->assertSeeInOrder(['Draft created', 'Next step', 'Record the supplier commitment'])
            ->assertDontSee('Audit details')
            ->assertDontSee('Actor #');
        $this->assertSame($this->user->id, $order->history()->sole()->actor_id);

        $order = $this->purchasing->place($order->id, $order->version);
        $order = $this->purchasing->updateExpectedDate(
            $order->id,
            $order->version,
            now()->addWeek()->toDateString(),
            'Supplier confirmed the delivery date',
        );
        $first = $this->purchasing->receive(
            $order->id,
            $order->version,
            'first-delivery',
            $this->receiveLine($order, 4),
        );
        $detail = Livewire::test(Detail::class, ['orderId' => $order->id])
            ->assertSeeInOrder([
                'Draft created',
                'Order marked as ordered',
                'Expected delivery updated',
                'Supplier confirmed the delivery date',
                'Receipt #'.$first->id.' was recorded.',
                'Next step',
                'Awaiting delivery',
                '6 units outstanding.',
            ]);
        $this->assertMatchesRegularExpression(
            '/Expected\s+<time\s+datetime="'.now()->addWeek()->toDateString().'"/',
            $detail->html(),
        );

        $order->refresh();
        $second = $this->purchasing->receive(
            $order->id,
            $order->version,
            'second-delivery',
            $this->receiveLine($order, 6),
        );
        Livewire::test(Detail::class, ['orderId' => $order->id])
            ->assertSeeInOrder([
                'Receipt #'.$first->id.' was recorded.',
                'Receipt #'.$second->id.' was recorded.',
                'Next step',
                'Ready to close',
            ]);

        $order->refresh();
        $this->purchasing->close($order->id, $order->version, 'All units received');
        Livewire::test(Detail::class, ['orderId' => $order->id])
            ->assertSeeInOrder(['Purchase order closed', 'All units received'])
            ->assertDontSee('Next step');

        $draft = $this->draft();
        $this->purchasing->cancel($draft->id, $draft->version, 'Duplicate order');
        Livewire::test(Detail::class, ['orderId' => $draft->id])
            ->assertSeeInOrder(['Draft created', 'Purchase order cancelled', 'Duplicate order'])
            ->assertDontSee('Next step');
    }

    public function test_livewire_rechecks_permissions_after_mount(): void
    {
        $order = $this->ordered();
        $component = Livewire::test(Receive::class, ['orderId' => $order->id])->set(
            'lines.0.accepted',
            2,
        );
        $this->user->revokePermissionTo('receive_purchase_orders');
        $component->call('save')->assertForbidden();
        $this->assertSame(0, InventoryHistory::query()->count());
    }

    public function test_order_identity_is_locked_in_livewire(): void
    {
        $order = $this->ordered();
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(Detail::class, ['orderId' => $order->id])->set('orderId', $order->id + 1);
    }

    public function test_receiving_form_rejects_negative_rows_instead_of_filtering_them_out(): void
    {
        $order = $this->ordered();
        Livewire::test(Receive::class, ['orderId' => $order->id])
            ->set('lines.0.accepted', -2)
            ->call('save')
            ->assertHasErrors('lines.0.accepted');
        $this->assertSame(0, InventoryHistory::query()->count());
    }

    public function test_correction_and_amendment_forms_prefill_current_quantities(): void
    {
        $order = $this->ordered();
        $receipt = $this->purchasing->receive(
            $order->id,
            $order->version,
            'a',
            $this->receiveLine($order, 4, 2),
            'Two damaged units refused; replacements due',
        );
        $component = Livewire::test(Detail::class, ['orderId' => $order->id])
            ->mountAction('correct', ['receiptItemId' => $receipt->items->first()->id])
            ->assertSchemaStateSet(['accepted' => 4, 'rejected' => 2])
            ->fillForm(['accepted' => 3, 'reason' => 'Correct receiving count'])
            ->callMountedAction()
            ->assertHasNoErrors()
            ->mountAction('amend', ['itemId' => $order->items->first()->id])
            ->assertSchemaStateSet(['quantity' => 10, 'unit_cost_minor' => 1250])
            ->fillForm(['quantity' => 12, 'reason' => 'Supplier confirmed two extra units'])
            ->callMountedAction()
            ->assertHasNoErrors()
            ->assertSee('Audit history')
            ->assertDontSee('Changed by')
            ->mountAction('auditHistory')
            ->assertActionMounted('auditHistory');

        $auditAction = $component->instance()->getMountedAction();
        $this->assertTrue($auditAction->isModalSlideOver());
        $historyHtml = $auditAction->getModalContent()->render();
        $this->assertStringContainsString('Changed by', $historyHtml);
        $this->assertStringContainsString('Buyer', $historyHtml);
        $this->assertStringContainsString('Accepted units', $historyHtml);
        $this->assertStringContainsString('-1', $historyHtml);
        $this->assertStringContainsString('10 → 12', $historyHtml);
        $this->assertStringContainsString('USD 12.50 → USD 12.50', $historyHtml);

        $this->assertSame(3, $this->product->stockInventory($this->inventory->id));
        $this->assertSame(12, $order->items()->firstOrFail()->ordered_quantity);
    }

    public function test_permission_installer_is_idempotent_and_preserves_existing_permissions(): void
    {
        $permission = config('permission.models.permission');
        $permission::query()->create(['name' => 'existing_permission', 'guard_name' => 'web']);
        $before = $permission::query()->count();
        $this->artisan('purchase-orders:install')->assertSuccessful();
        $this->assertSame($before, $permission::query()->count());
        $this->assertTrue($permission::query()->where('name', 'existing_permission')->exists());
        $this->artisan('purchase-orders:install', ['--role' => 'missing'])->assertFailed();
    }

    public function test_disabled_addon_rejects_actions_even_if_a_page_is_already_open(): void
    {
        $order = $this->ordered();
        config(['purchase-orders.enabled' => false]);
        $this->expectException(NotFoundHttpException::class);
        $this->purchasing->receive($order->id, $order->version, 'a', $this->receiveLine($order, 1));
    }
}
