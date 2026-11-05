<?php

declare(strict_types=1);

namespace ShopperPurchaseOrders\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Shopper\Core\Models\Currency;

final class Currencies
{
    public static function codes(?string $savedCurrency = null): array
    {
        return Currency::withoutGlobalScopes()
            ->where(fn ($query) => $query->where('is_enabled', true)
                ->when($savedCurrency !== null, fn ($query) => $query->orWhere('code', $savedCurrency)))
            ->orderBy('code')
            ->pluck('code')->all();
    }

    public static function rule(?string $savedCurrency = null): Exists
    {
        return Rule::exists((new Currency)->getTable(), 'code')
            ->where(fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('is_enabled', true)
                ->when($savedCurrency !== null, fn (Builder $query) => $query->orWhere('code', $savedCurrency))));
    }
}
