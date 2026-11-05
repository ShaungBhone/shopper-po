<?php

declare(strict_types=1);

namespace ShopperPurchaseOrders\Tests;

use Filament\Schemas\Components\Wizard;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Shopper\Components\SlideOverWizard;
use Shopper\Core\Enum\ProductType;
use Shopper\Core\Models\Currency;
use Shopper\Core\Models\InventoryHistory;
use Shopper\Core\Models\Product;
use Shopper\Core\Models\ProductVariant;
use Shopper\Core\Models\Setting;
use Shopper\Http\Middleware\Authenticate;
use ShopperPurchaseOrders\Livewire\Detail;
use ShopperPurchaseOrders\Livewire\Form;
use ShopperPurchaseOrders\Livewire\Index;
use ShopperPurchaseOrders\Livewire\Receive;
use ShopperPurchaseOrders\Models\PurchaseOrder;
use ShopperPurchaseOrders\Support\Catalog;
use ShopperPurchaseOrders\Support\Limits;
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
            ->set('data.items.0.unit_cost_minor', '12.50')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();
        $order = PurchaseOrder::query()->first();
        $this->assertSame(1250, $order->items()->firstOrFail()->unit_cost_minor);
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

    public function test_draft_money_input_converts_display_amounts_without_repeated_conversion(): void
    {
        $order = $this->draft();
        $editor = Livewire::test(Form::class, ['orderId' => $order->id])
            ->assertSet('data.items.0.unit_cost_minor', 12.5)
            ->set('data.items.0.unit_cost_minor', '19.99')
            ->set('data.supplier_id', '')
            ->call('save')
            ->assertHasErrors('supplier_id')
            ->assertSet('data.items.0.unit_cost_minor', '19.99')
            ->set('data.supplier_id', $this->supplier->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1999, $order->items()->firstOrFail()->unit_cost_minor);
        Livewire::test(Form::class, ['orderId' => $order->id])
            ->assertSet('data.items.0.unit_cost_minor', 19.99)
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame(1999, $order->items()->firstOrFail()->unit_cost_minor);
    }

    public function test_draft_money_input_respects_currency_precision_and_zero(): void
    {
        $order = $this->draft();
        Livewire::test(Form::class, ['orderId' => $order->id])
            ->set('data.items.0.unit_cost_minor', '12.345')
            ->call('save')
            ->assertHasErrors('data.items.0.unit_cost_minor')
            ->set('data.currency', 'JPY')
            ->set('data.items.0.unit_cost_minor', '1250.50')
            ->call('save')
            ->assertHasErrors('data.items.0.unit_cost_minor')
            ->set('data.items.0.unit_cost_minor', '1250')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('JPY', $order->fresh()->currency);
        $this->assertSame(1250, $order->items()->firstOrFail()->unit_cost_minor);
        Livewire::test(Form::class, ['orderId' => $order->id])
            ->assertSet('data.items.0.unit_cost_minor', 1250)
            ->set('data.currency', 'USD')
            ->set('data.items.0.unit_cost_minor', '0')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame(0, $order->items()->firstOrFail()->unit_cost_minor);
    }

    public function test_amendment_money_input_saves_display_amounts_for_usd_and_jpy(): void
    {
        foreach (['USD' => ['12.5', '19.99', 1999], 'JPY' => ['1250', '1900', 1900]] as $currency => [$display, $input, $expected]) {
            $order = $this->purchasing->saveDraft(array_replace($this->draftData(), ['currency' => $currency]));
            $order = $this->purchasing->place($order->id, $order->version);
            Livewire::test(Detail::class, ['orderId' => $order->id])
                ->mountAction('amend', ['itemId' => $order->items->first()->id])
                ->assertSchemaStateSet(['unit_cost_minor' => $display])
                ->fillForm(['unit_cost_minor' => $input, 'reason' => 'Supplier revised the unit cost'])
                ->callMountedAction()
                ->assertHasNoErrors();
            $this->assertSame($expected, $order->items()->firstOrFail()->unit_cost_minor);
        }
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
        [$details, $products, $pricing, $review] = array_map(fn ($step) => $step->getKey(), $wizard->getChildSchema()->getComponents());

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
            ->assertDispatched('next-wizard-step', step: $pricing)
            ->call('callSchemaComponentMethod', $key, 'nextStep', ['currentStepIndex' => 2, 'currentStepKey' => $pricing])
            ->assertDispatched('next-wizard-step', step: $review);

        $this->supplier->update(['is_enabled' => false]);
        $editor->call('save')
            ->assertHasErrors('supplier_id')
            ->assertDispatched('draft-wizard-error', step: $details)
            ->assertNotDispatched('closePanel');
    }

    public function test_creation_has_four_steps_and_add_only_permission_can_reach_review(): void
    {
        $this->user->revokePermissionTo('edit_purchase_orders');
        $editor = Livewire::test(Form::class);
        $wizard = $editor->instance()->getSchema('draftWizard')->getComponents()[0];
        $this->assertInstanceOf(Wizard::class, $wizard);
        $this->assertNotInstanceOf(SlideOverWizard::class, $wizard);
        $steps = $wizard->getChildSchema()->getComponents();
        $this->assertSame(['Details', 'Products', 'Pricing', 'Review'], array_map(fn ($step) => $step->getLabel(), $steps));
        [$details, $products, $pricing, $review] = array_map(fn ($step) => $step->getKey(), $steps);
        $key = $wizard->getKey();
        $editor->set('data.supplier_id', $this->supplier->id)
            ->set('data.inventory_id', $this->inventory->id)
            ->call('callSchemaComponentMethod', $key, 'nextStep', ['currentStepIndex' => 0, 'currentStepKey' => $details])
            ->assertHasNoErrors()->assertDispatched('next-wizard-step', step: $products)
            ->call('addProduct', 'product:'.$this->product->id)
            ->call('callSchemaComponentMethod', $key, 'nextStep', ['currentStepIndex' => 1, 'currentStepKey' => $products])
            ->assertHasNoErrors()->assertDispatched('next-wizard-step', step: $pricing)
            ->call('callSchemaComponentMethod', $key, 'nextStep', ['currentStepIndex' => 2, 'currentStepKey' => $pricing])
            ->assertHasErrors('data.items.0.unit_cost_minor')->assertNotDispatched('next-wizard-step')
            ->assertDispatched('draft-wizard-error', step: $pricing)
            ->set('data.items.0.unit_cost_minor', '0')
            ->call('callSchemaComponentMethod', $key, 'nextStep', ['currentStepIndex' => 2, 'currentStepKey' => $pricing])
            ->assertHasNoErrors()->assertDispatched('next-wizard-step', step: $review)
            ->assertSet('data.items.0.unit_cost_minor', '0')
            ->call('save')->assertHasNoErrors()->assertRedirect();
        $this->assertSame(0, PurchaseOrder::latest('id')->firstOrFail()->items()->sole()->unit_cost_minor);
    }

    public function test_product_step_has_no_prices_and_validates_identity_without_requiring_costs(): void
    {
        $order = $this->draft();
        $editor = Livewire::test(Form::class, ['orderId' => $order->id]);
        $wizard = $editor->instance()->getSchema('draftWizard')->getComponents()[0];
        $this->assertInstanceOf(SlideOverWizard::class, $wizard);
        $steps = $wizard->getChildSchema()->getComponents();
        $document = new \DOMDocument;
        $document->loadHTML($editor->html(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $productStep = $document->getElementById($steps[1]->getId());
        $this->assertNotNull($productStep);
        $html = $document->saveHTML($productStep);
        $this->assertStringNotContainsString('Unit cost', $html);
        $this->assertStringNotContainsString('costsForm.items', $html);
        $options = $editor->instance()->searchProducts();
        $this->assertStringNotContainsString('Cost not set', implode('', $options));
        $key = $wizard->getKey();
        $products = $steps[1]->getKey();
        $editor->set('data.items.0.unit_cost_minor', null)
            ->call('callSchemaComponentMethod', $key, 'nextStep', ['currentStepIndex' => 1, 'currentStepKey' => $products])
            ->assertHasNoErrors()->assertDispatched('next-wizard-step', step: $steps[2]->getKey())
            ->set('data.items.0.stockable_id', 999999)
            ->call('callSchemaComponentMethod', $key, 'nextStep', ['currentStepIndex' => 1, 'currentStepKey' => $products])
            ->assertHasErrors('items.0.stockable_id')->assertDispatched('draft-wizard-error', step: $products);
    }

    public function test_pricing_summary_uses_exact_minor_units_and_never_changes_display_state(): void
    {
        $order = $this->draft(3);
        $editor = Livewire::test(Form::class, ['orderId' => $order->id])->set('data.items.0.unit_cost_minor', '0.10')->call('refreshPricing', 0);
        $this->assertSame(30, $editor->instance()->pricingSummary['subtotal_minor']);
        $this->assertSame(10, $editor->instance()->pricingSummary['lines'][0]['cost_minor']);
        $editor->assertSet('data.items.0.unit_cost_minor', '0.10')
            ->set('data.items.0.unit_cost_minor', '12.34')->call('refreshPricing', 0);
        $this->assertSame(3702, $editor->instance()->pricingSummary['subtotal_minor']);
        $editor->set('data.items.0.unit_cost_minor', null);
        $this->assertNull($editor->instance()->pricingSummary['subtotal_minor']);
        $this->assertNull($editor->instance()->pricingSummary['lines'][0]['total_minor']);
        $editor->set('data.items.0.unit_cost_minor', '12.345');
        $this->assertNull($editor->instance()->pricingSummary['subtotal_minor']);
        $editor->set('data.currency', 'JPY')->set('data.items.0.unit_cost_minor', '1250')->call('refreshPricing', 0);
        $this->assertSame(3750, $editor->instance()->pricingSummary['subtotal_minor']);
        $editor->set('data.currency', 'USD')->set('data.items.0.unit_cost_minor', '0')->call('refreshPricing', 0);
        $this->assertSame(0, $editor->instance()->pricingSummary['subtotal_minor']);
        $editor->assertSet('data.items.0.unit_cost_minor', '0');
    }

    public function test_pricing_totals_all_pages_and_reveals_the_first_invalid_cost_page(): void
    {
        $order = $this->draft(1);
        $products = Product::withoutEvents(fn () => Product::factory()->count(11)->create(['type' => ProductType::Standard]));
        $editor = Livewire::test(Form::class, ['orderId' => $order->id]);
        foreach ($products as $product) {
            $editor->call('addProduct', 'product:'.$product->id);
        }
        foreach (array_keys($editor->get('data.items')) as $index) {
            $editor->set("data.items.{$index}.unit_cost_minor", '1.25');
        }
        $this->assertSame(1500, $editor->instance()->pricingSummary['subtotal_minor']);
        $editor->set('lineSearch', 'no-matches')->set('data.items.11.unit_cost_minor', null)
            ->call('save')->assertHasErrors('data.items.11.unit_cost_minor')
            ->assertSet('paginators.pricingPage', 2)->assertDispatched('draft-wizard-error', step: 'draftWizard.pricing')
            ->assertSee('costsForm.items.11.unit_cost_minor')
            ->set('data.items.11.unit_cost_minor', '0')->call('refreshPricing', 11);
        $this->assertSame(1375, $editor->instance()->pricingSummary['subtotal_minor']);
        $editor->call('removeLine', 11);
        $this->assertSame(1375, $editor->instance()->pricingSummary['subtotal_minor']);
        $editor->call('save')->assertHasNoErrors();
        $this->assertSame(11, $order->items()->count());
    }

    public function test_merchandise_limit_and_save_errors_route_to_the_correct_steps(): void
    {
        $order = $this->draft(2);
        $editor = Livewire::test(Form::class, ['orderId' => $order->id]);
        $editor->set('data.supplier_id', '')->call('save')->assertHasErrors('supplier_id')
            ->assertDispatched('draft-wizard-error', step: 'draftWizard.details')
            ->set('data.supplier_id', $this->supplier->id)->set('data.items.0.ordered_quantity', 0)->call('save')
            ->assertHasErrors('items.0.ordered_quantity')->assertDispatched('draft-wizard-error', step: 'draftWizard.products')
            ->set('data.items.0.ordered_quantity', 2)->set('data.items.0.unit_cost_minor', null)->call('save')
            ->assertHasErrors('data.items.0.unit_cost_minor')->assertDispatched('draft-wizard-error', step: 'draftWizard.pricing')
            ->set('data.items.0.unit_cost_minor', '1.25')->set('data.notes', str_repeat('x', 5001))->call('save')
            ->assertHasErrors('notes')->assertDispatched('draft-wizard-error', step: 'draftWizard.review');
        $editor->set('data.notes', '')->set('data.items.0.unit_cost_minor', (string) (Limits::MAX_TOTAL_MINOR / 100))->call('save')
            ->assertHasErrors('pricing')->assertDispatched('draft-wizard-error', step: 'draftWizard.pricing');
        $this->assertTrue($editor->instance()->pricingSummary['over_limit']);
        $this->assertNull($editor->instance()->pricingSummary['subtotal_minor']);
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

        $editor = Livewire::test(Form::class, ['orderId' => $order->id]);
        foreach ($selection as $identity) {
            $editor->call('addProduct', $identity)->assertHasNoErrors();
        }
        foreach (array_keys($editor->get('data.items')) as $index) {
            $editor->set("data.items.{$index}.unit_cost_minor", '0');
        }
        $editor
            ->assertHasNoErrors()
            ->assertSet('paginators.draftLinesPage', 2)
            ->assertSee('13 lines')
            ->set('data.items.12.ordered_quantity', 4)
            ->set('data.items.12.unit_cost_minor', '7.25')
            ->set('lineSearch', 'PO-CAT-01')
            ->assertSet('paginators.draftLinesPage', 1)
            ->assertSee('PO-CAT-01')
            ->set('data.items.1.ordered_quantity', 5)
            ->set('data.items.1.unit_cost_minor', '3.99')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(13, $order->items()->count());
        $this->assertSame(4, $order->items()->where('sku', 'PO-CAT-12')->firstOrFail()->ordered_quantity);
        $this->assertSame(5, $order->items()->where('sku', 'PO-CAT-01')->firstOrFail()->ordered_quantity);
        $this->assertSame(725, $order->items()->where('sku', 'PO-CAT-12')->firstOrFail()->unit_cost_minor);
        $this->assertSame(399, $order->items()->where('sku', 'PO-CAT-01')->firstOrFail()->unit_cost_minor);
    }

    public function test_picker_prefills_matching_purchase_costs_and_saves_usd_and_jpy(): void
    {
        foreach (['USD' => [1250, 12.5], 'JPY' => [1250, 1250]] as $code => [$cost, $display]) {
            $currency = Currency::query()->where('code', $code)->firstOrFail();
            $this->product->prices()->create(['currency_id' => $currency->id, 'amount' => 9999, 'cost_amount' => $cost]);
            $editor = Livewire::test(Form::class)
                ->set('data.supplier_id', $this->supplier->id)
                ->set('data.inventory_id', $this->inventory->id)
                ->set('data.currency', $code)
                ->set('productSelection', 'product:'.$this->product->id)
                ->assertSet('productSelection', null)
                ->assertSet('data.items.0.ordered_quantity', 1)
                ->assertSet('data.items.0.unit_cost_minor', $display)
                ->call('save')->assertHasNoErrors()->assertRedirect();
            $this->assertSame($cost, PurchaseOrder::latest('id')->firstOrFail()->items()->sole()->unit_cost_minor);
        }
    }

    public function test_saved_draft_currency_and_cost_remain_available_after_disabling_currency(): void
    {
        $order = $this->draft(3);
        $currency = Currency::query()->where('code', 'USD')->firstOrFail();
        $currency->update(['is_enabled' => false]);
        $this->product->prices()->create(['currency_id' => $currency->id, 'amount' => 9999, 'cost_amount' => 2000]);
        $editor = Livewire::test(Form::class, ['orderId' => $order->id])
            ->assertSet('data.currency', 'USD')
            ->assertSet('data.items.0.unit_cost_minor', '12.5');
        $this->assertContains('USD', $editor->instance()->draftOptions['currencies']);
        $editor->call('save')->assertHasNoErrors();
        $this->assertSame(1250, $order->items()->sole()->unit_cost_minor);
        $editor = Livewire::test(Form::class, ['orderId' => $order->id])
            ->call('removeLine', 0)->call('addProduct', 'product:'.$this->product->id);
        $this->assertSame(20, array_values($editor->get('data.items'))[0]['unit_cost_minor']);
        $editor->call('save')->assertHasNoErrors();
        $this->assertSame(2000, $order->items()->sole()->unit_cost_minor);
    }

    public function test_new_orders_use_shopper_default_and_enabled_currencies_without_package_config(): void
    {
        $this->assertNull(config('purchase-orders'));
        $jpy = Currency::query()->where('code', 'JPY')->firstOrFail();
        Setting::query()->updateOrCreate(['key' => 'default_currency_id'], ['value' => $jpy->id]);
        Currency::query()->where('code', 'EUR')->update(['is_enabled' => false]);
        $editor = Livewire::test(Form::class)->assertSet('data.currency', 'JPY');
        $this->assertSame(['JPY', 'USD'], $editor->instance()->draftOptions['currencies']);
        $editor->set('data.currency', 'EUR')->call('addProduct', 'product:'.$this->product->id)
            ->assertHasErrors('currency')->assertSet('data.items', []);
        $editor->set('data.currency', 'ZZZ')->call('addProduct', 'product:'.$this->product->id)
            ->assertHasErrors('currency')->assertSet('data.items', []);
        $data = $this->draftData();
        $data['currency'] = 'EUR';
        $this->expectException(ValidationException::class);
        $this->purchasing->saveDraft($data);
    }

    public function test_currency_defaults_to_an_enabled_option_or_requires_selection_if_none_exist(): void
    {
        Currency::query()->where('code', 'USD')->update(['is_enabled' => false]);
        Livewire::test(Form::class)->assertSet('data.currency', 'EUR');
        Currency::query()->update(['is_enabled' => false]);
        Livewire::test(Form::class)->assertSet('data.currency', '')
            ->set('data.supplier_id', $this->supplier->id)
            ->set('data.inventory_id', $this->inventory->id)
            ->call('save')->assertHasErrors('currency')
            ->assertDispatched('draft-wizard-error', step: 'draftWizard.details');
    }

    public function test_picker_missing_cost_is_required_and_zero_is_preserved(): void
    {
        $euro = Currency::query()->where('code', 'EUR')->firstOrFail();
        $usd = Currency::query()->where('code', 'USD')->firstOrFail();
        $this->product->prices()->create(['currency_id' => $euro->id, 'amount' => 9000, 'cost_amount' => 400]);
        $this->product->prices()->create(['currency_id' => $usd->id, 'amount' => 9000, 'cost_amount' => null]);
        $editor = Livewire::test(Form::class)
            ->set('data.supplier_id', $this->supplier->id)
            ->set('data.inventory_id', $this->inventory->id)
            ->call('addProduct', 'product:'.$this->product->id)
            ->assertSet('data.items.0.unit_cost_minor', null)
            ->call('save')->assertHasErrors('data.items.0.unit_cost_minor')
            ->assertDispatched('draft-wizard-error', step: 'draftWizard.pricing');
        $this->product->prices()->where('currency_id', $usd->id)->update(['cost_amount' => 0]);
        Livewire::test(Form::class)->call('addProduct', 'product:'.$this->product->id)
            ->assertSet('data.items.0.unit_cost_minor', 0)
            ->assertSee(shopper_money_format(0, 'USD'));
    }

    public function test_picker_searches_products_and_variants_excludes_duplicates_and_escapes_labels(): void
    {
        $this->product->updateQuietly(['name' => '<script>unsafe</script> Notebook', 'sku' => 'NOTE-SKU']);
        $variant = ProductVariant::factory()->create(['product_id' => $this->product->id, 'name' => 'Blue', 'sku' => 'BLUE-SKU']);
        $component = Livewire::test(Form::class);
        $options = $component->instance()->searchProducts('Notebook');
        $this->assertArrayHasKey('product:'.$this->product->id, $options);
        $this->assertArrayHasKey('variant:'.$variant->id, $options);
        $this->assertStringNotContainsString('<script>', implode('', $options));
        $this->assertStringContainsString('&lt;script&gt;', implode('', $options));
        $this->assertArrayHasKey('variant:'.$variant->id, $component->instance()->searchProducts('BLUE-SKU'));
        $component->call('addProduct', 'product:'.$this->product->id)
            ->call('addProduct', 'product:'.$this->product->id)->assertHasErrors('productSelection');
        $this->assertCount(1, $component->get('data.items'));
        $this->assertArrayNotHasKey('product:'.$this->product->id, $component->instance()->searchProducts());
        $component->call('addProduct', 'variant:'.$variant->id)->assertHasNoErrors()
            ->assertSet('data.items.1.stockable_kind', 'variant');
        $component->call('removeLine', 0);
        $this->assertArrayHasKey('product:'.$this->product->id, $component->instance()->searchProducts());
    }

    public function test_picker_limits_results_and_revalidates_product_identity_and_line_limit(): void
    {
        Product::withoutEvents(fn () => Product::factory()->count(25)->create(['type' => ProductType::Standard]));
        $component = Livewire::test(Form::class);
        $this->assertCount(20, $component->instance()->searchProducts());
        $this->assertSame([], $component->instance()->searchProducts('no-such-product-xyz'));
        $component->call('addProduct', 'wrong:1')->assertHasErrors('productSelection');
        $component->call('addProduct', 'product:999999')->assertHasErrors('items');
        $virtual = Product::withoutEvents(fn () => Product::factory()->create(['type' => ProductType::Virtual]));
        $component->call('addProduct', 'product:'.$virtual->id)->assertHasErrors('items');
        $component->set('data.items', array_fill(0, 100, ['stockable_kind' => 'product', 'stockable_id' => 99999, 'ordered_quantity' => 1, 'unit_cost_minor' => 0]))
            ->call('addProduct', 'product:'.$this->product->id)->assertHasErrors('productSelection');
        $this->assertCount(100, $component->get('data.items'));
        $this->user->revokePermissionTo('add_purchase_orders');
        $component->call('addProduct', 'product:'.$this->product->id)->assertForbidden();
    }

    public function test_compact_rows_edit_costs_and_bound_quantity_controls(): void
    {
        $order = $this->draft(1);
        $editor = Livewire::test(Form::class, ['orderId' => $order->id])
            ->call('changeQuantity', 0, -1)->assertSet('data.items.0.ordered_quantity', 1)
            ->call('changeQuantity', 0, 1)->assertSet('data.items.0.ordered_quantity', 2)
            ->set('data.items.0.unit_cost_minor', '18.25')
            ->call('refreshPricing', 0)->assertHasNoErrors()->assertSee(shopper_money_format(1825, 'USD'))
            ->set('data.items.0.unit_cost_minor', '-1')
            ->call('refreshPricing', 0)->assertHasErrors('data.items.0.unit_cost_minor')
            ->set('data.items.0.unit_cost_minor', '18.25')->call('refreshPricing', 0)->assertHasNoErrors()
            ->set('data.items.0.ordered_quantity', Limits::MAX_QUANTITY)
            ->call('changeQuantity', 0, 1)->assertSet('data.items.0.ordered_quantity', Limits::MAX_QUANTITY)
            ->set('data.items.0.ordered_quantity', 2)
            ->call('save')->assertHasNoErrors();
        $this->assertSame(1825, $order->items()->sole()->unit_cost_minor);
        $this->assertSame(2, $order->items()->sole()->ordered_quantity);
    }

    public function test_variant_thumbnail_falls_back_to_parent_and_saved_draft_cost_is_not_repriced(): void
    {
        $media = $this->product->media()->create([
            'collection_name' => config('shopper.media.storage.thumbnail_collection'), 'name' => 'Thumbnail',
            'file_name' => 'parent.jpg', 'mime_type' => 'image/jpeg', 'disk' => 'public', 'conversions_disk' => 'public',
            'size' => 100, 'manipulations' => [], 'custom_properties' => [], 'generated_conversions' => [], 'responsive_images' => [],
        ]);
        $variant = ProductVariant::factory()->create(['product_id' => $this->product->id, 'name' => 'Blue']);
        $this->assertSame($media->getUrl(), app(Catalog::class)->thumbnail($variant, 'variant'));
        $data = $this->draftData();
        $data['supplier_reference'] = 'REF-REVIEW-123';
        $data['items'][0]['stockable_kind'] = 'variant';
        $data['items'][0]['stockable_id'] = $variant->id;
        $variantOrder = $this->purchasing->saveDraft($data);
        Livewire::test(Detail::class, ['orderId' => $variantOrder->id])->assertSee($media->getUrl());
        Livewire::test(Form::class, ['orderId' => $variantOrder->id])->assertSee('REF-REVIEW-123');
        $variantMedia = $variant->media()->create([
            'collection_name' => config('shopper.media.storage.thumbnail_collection'), 'name' => 'Variant thumbnail',
            'file_name' => 'variant.jpg', 'mime_type' => 'image/jpeg', 'disk' => 'public', 'conversions_disk' => 'public',
            'size' => 100, 'manipulations' => [], 'custom_properties' => [], 'generated_conversions' => [], 'responsive_images' => [],
        ]);
        $variant->unsetRelation('media');
        $this->assertSame($variantMedia->getUrl(), app(Catalog::class)->thumbnail($variant, 'variant'));
        Livewire::test(Detail::class, ['orderId' => $variantOrder->id])->assertSee($variantMedia->getUrl());

        $currency = Currency::query()->where('code', 'USD')->firstOrFail();
        $order = $this->draft();
        $this->product->prices()->create(['currency_id' => $currency->id, 'amount' => 9999, 'cost_amount' => 8000]);
        Livewire::test(Form::class, ['orderId' => $order->id])->assertSet('data.items.0.unit_cost_minor', 12.5)
            ->assertSee($media->getUrl())->call('save')->assertHasNoErrors();
        $this->assertSame(1250, $order->items()->sole()->unit_cost_minor);
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
            ->assertSchemaStateSet(['quantity' => 10, 'unit_cost_minor' => 12.5])
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
        config(['shopper.addons.purchase-orders' => false]);
        $this->expectException(NotFoundHttpException::class);
        $this->purchasing->receive($order->id, $order->version, 'a', $this->receiveLine($order, 1));
    }
}
