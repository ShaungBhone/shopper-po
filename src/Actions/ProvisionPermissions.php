<?php

declare(strict_types=1);

namespace ShopperPurchaseOrders\Actions;

use Illuminate\Support\Facades\DB;
use ShopperPurchaseOrders\Support\Access;
use Spatie\Permission\Contracts\Role;
use Spatie\Permission\PermissionRegistrar;

final class ProvisionPermissions
{
    public function __invoke(?Role $role = null): void
    {
        $permissionClass = config('permission.models.permission');
        $guard = config('shopper.auth.guard', 'web');

        DB::transaction(function () use ($permissionClass, $guard, $role): void {
            foreach (Access::ABILITIES as $ability) {
                $permission = $permissionClass::query()->firstOrCreate(
                    ['name' => Access::permission($ability), 'guard_name' => $guard],
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

            DB::afterCommit(fn () => app(PermissionRegistrar::class)->forgetCachedPermissions());
        });
    }
}
