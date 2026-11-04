<?php

declare(strict_types=1);

namespace ShopperPurchaseOrders\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Shopper\Models\Contracts\ShopperUser;
use Shopper\Traits\InteractsWithShopper;

class User extends Authenticatable implements ShopperUser
{
    use InteractsWithShopper;

    protected $guarded = [];
}
