<?php

declare(strict_types=1);

namespace ShopperPurchaseOrders\Console;

use Illuminate\Console\Command;
use ShopperPurchaseOrders\Actions\ProvisionPermissions;

final class Install extends Command
{
    protected $signature = 'purchase-orders:install {--role= : Existing role to grant addon permissions to}';

    protected $description = 'Create purchase-order permissions without changing existing permissions or roles';

    public function handle(): int
    {
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
        (new ProvisionPermissions)($role);
        $this->info(
            'Purchase-order permissions are ready.'.
                ($role
                    ? ' Granted to '.$role->name.'.'
                    : ' Assign them to procurement roles in Shopper.'),
        );

        return self::SUCCESS;
    }
}
