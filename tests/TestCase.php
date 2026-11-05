<?php

declare(strict_types=1);

namespace ShopperPurchaseOrders\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Shopper\Cart\CartServiceProvider;
use Shopper\Core\CoreServiceProvider;
use Shopper\Core\Enum\ProductType;
use Shopper\Core\Models\Currency;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Product;
use Shopper\Core\Models\Supplier;
use Shopper\Enum\FeatureState;
use Shopper\Payment\PaymentServiceProvider;
use Shopper\Shipping\ShippingServiceProvider;
use Shopper\ShopperServiceProvider;
use Shopper\Sidebar\SidebarServiceProvider;
use ShopperPurchaseOrders\Actions\Purchasing;
use ShopperPurchaseOrders\Models\PurchaseOrder;
use ShopperPurchaseOrders\PurchaseOrdersServiceProvider;
use ShopperPurchaseOrders\Tests\Fixtures\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionServiceProvider;

abstract class TestCase extends Orchestra
{
    protected User $user;

    protected Supplier $supplier;

    protected Inventory $inventory;

    protected Product $product;

    protected Purchasing $purchasing;

    protected function getPackageProviders($app): array
    {
        $installed = json_decode(
            file_get_contents(__DIR__.'/../vendor/composer/installed.json'),
            true,
        );
        $providers = [];
        foreach ($installed['packages'] as $package) {
            $providers = array_merge($providers, $package['extra']['laravel']['providers'] ?? []);
        }

        return array_values(
            array_unique(
                array_merge($providers, [
                    LivewireServiceProvider::class,
                    PermissionServiceProvider::class,
                    CoreServiceProvider::class,
                    SidebarServiceProvider::class,
                    ShopperServiceProvider::class,
                    CartServiceProvider::class,
                    PaymentServiceProvider::class,
                    ShippingServiceProvider::class,
                    PurchaseOrdersServiceProvider::class,
                ]),
            ),
        );
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        if (getenv('PO_TEST_MYSQL') === '1') {
            // Hardcoded dedicated test database; never read the host application's DB_* environment.
            $app['config']->set('database.connections.testing', [
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'port' => 33077,
                'database' => 'shopper_po_test',
                'username' => 'root',
                'password' => 'shopper_po_local_test',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
                'engine' => 'InnoDB',
            ]);
        }
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('shopper.features.supplier', FeatureState::Enabled);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('session.driver', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('PO_TEST_MYSQL') === '1') {
            Schema::dropAllTables();
        }
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->default('Buyer');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });
        $this->artisan('migrate', ['--force' => true])->run();
        foreach (['USD', 'EUR', 'JPY'] as $code) {
            Currency::factory()->create(['code' => $code, 'is_enabled' => true]);
        }
        // Shopper publishes the permission migration during its installer.
        if (! Schema::hasTable('permissions')) {
            $file = glob(
                __DIR__.
                    '/../vendor/spatie/laravel-permission/database/migrations/*create_permission_tables*',
            )[0];
            $migration = require $file;
            $migration->up();
            Schema::table('permissions', function (Blueprint $table): void {
                $table->string('group_name')->nullable();
                $table->string('display_name')->nullable();
                $table->boolean('can_be_removed')->default(false);
            });
        }
        $this->artisan('purchase-orders:install')->assertSuccessful();
        $this->user = User::query()->create([
            'last_name' => 'Buyer',
            'email' => 'buyer@example.test',
            'password' => bcrypt('testing'),
        ]);
        $this->user->givePermissionTo(Permission::all());
        $this->actingAs($this->user, config('shopper.auth.guard'));
        $this->supplier = Supplier::factory()->create(['is_enabled' => true]);
        $this->inventory = Inventory::factory()->create();
        $this->product = Product::withoutEvents(
            fn () => Product::factory()->create(['type' => ProductType::Standard]),
        );
        $this->purchasing = app(Purchasing::class);
    }

    protected function draft(int $quantity = 10): PurchaseOrder
    {
        return $this->purchasing->saveDraft($this->draftData($quantity));
    }

    protected function draftData(int $quantity = 10): array
    {
        return [
            'supplier_id' => $this->supplier->id,
            'inventory_id' => $this->inventory->id,
            'currency' => 'USD',
            'items' => [
                [
                    'stockable_kind' => 'product',
                    'stockable_id' => $this->product->id,
                    'ordered_quantity' => $quantity,
                    'unit_cost_minor' => 1250,
                ],
            ],
        ];
    }

    protected function ordered(int $quantity = 10): PurchaseOrder
    {
        $order = $this->draft($quantity);

        return $this->purchasing->place($order->id, $order->version);
    }

    protected function receiveLine(
        $order,
        int $accepted,
        int $rejected = 0,
        string $resolution = 'replace',
    ): array {
        return [
            [
                'item_id' => $order->items->first()->id,
                'accepted' => $accepted,
                'rejected' => $rejected,
                'resolution' => $resolution,
            ],
        ];
    }
}
