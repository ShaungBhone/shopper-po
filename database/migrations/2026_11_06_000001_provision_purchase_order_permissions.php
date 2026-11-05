<?php

use Illuminate\Database\Migrations\Migration;
use ShopperPurchaseOrders\Actions\ProvisionPermissions;

return new class extends Migration
{
    public function up(): void
    {
        (new ProvisionPermissions)();
    }

    public function down(): void
    {
        // Keep permissions and role assignments that may predate this migration.
    }
};
