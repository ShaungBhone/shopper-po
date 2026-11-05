<?php

declare(strict_types=1);

namespace ShopperPurchaseOrders\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Shopper\Feature;

final class Access
{
    public const ABILITIES = [
        'browse',
        'read',
        'add',
        'edit',
        'place',
        'receive',
        'correct',
        'close',
        'cancel',
    ];

    public static function authorize(string $ability): Authenticatable
    {
        abort_unless(
            config('shopper.addons.purchase-orders', true) !== false,
            404,
        );
        abort_unless(
            Feature::enabled('supplier'),
            403,
            'Enable the Shopper supplier feature before using purchase orders.',
        );
        $user = Auth::guard(config('shopper.auth.guard'))->user();
        abort_unless($user, 403);
        Gate::forUser($user)->authorize($ability.'_purchase_orders');
        if (! in_array($ability, ['browse', 'read', 'add'], true)) {
            Gate::forUser($user)->authorize('read_purchase_orders');
        }

        return $user;
    }
}
