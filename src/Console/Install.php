<?php

declare(strict_types=1);

namespace ShopperPurchaseOrders\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use ShopperPurchaseOrders\Support\Access;
use Spatie\Permission\PermissionRegistrar;

final class Install extends Command
{
    protected $signature = 'purchase-orders:install {--role= : Existing role to grant addon permissions to}';

    protected $description = 'Create purchase-order permissions without changing existing permissions or roles';

    public function handle(): int
    {
        $permissionClass = config('permission.models.permission');
        $roleClass = config('permission.models.role');
        $guard = config('shopper.auth.guard', 'web');
        $roleName = $this->option('role');
        $role = $roleName
            ? $roleClass::query()->where('name', $roleName)->where('guard_name', $guard)->first()
            : null;
        if ($roleName && ! $role) {
            $this->error('The requested role does not exist for the Shopper guard.');

            return self::FAILURE;
        }
        DB::transaction(function () use ($permissionClass, $guard, $role): void {
            foreach (Access::ABILITIES as $ability) {
                $permission = $permissionClass::query()->firstOrCreate(
                    ['name' => $ability.'_purchase_orders', 'guard_name' => $guard],
                    [
                        'group_name' => 'products',
                        'display_name' => ucfirst($ability).' purchase orders',
                        'can_be_removed' => false,
                    ],
                );
                if ($role) {
                    $role->givePermissionTo($permission);
                }
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->info(
            'Purchase-order permissions are ready.'.
                ($role
                    ? ' Granted to '.$role->name.'.'
                    : ' Assign them to procurement roles in Shopper.'),
        );

        return self::SUCCESS;
    }
}
