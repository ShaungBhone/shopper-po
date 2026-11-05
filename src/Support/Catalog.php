<?php

declare(strict_types=1);

namespace ShopperPurchaseOrders\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;

final class Catalog
{
    public function relations(Model $model, string $kind, bool $withPrices = true): array
    {
        $relations = [];
        if ($model instanceof HasMedia) {
            $relations[] = 'media';
        }
        if ($withPrices && method_exists($model, 'prices')) {
            $relations['prices.currency'] = fn ($query) => $query->withoutGlobalScopes();
        }
        if ($kind === 'variant') {
            $productClass = config('shopper.models.product');
            $relations[] = (new $productClass) instanceof HasMedia ? 'product.media' : 'product';
        }

        return $relations;
    }

    public function options(string $search, array $items): array
    {
        $search = trim(mb_substr($search, 0, 100));
        $results = collect();
        foreach (['product', 'variant'] as $kind) {
            $class = config('shopper.models.'.$kind);
            $model = new $class;
            $query = $class::query()->with($this->relations($model, $kind, withPrices: false))
                ->whereNotIn($model->getKeyName(), collect($items)->where('stockable_kind', $kind)->pluck('stockable_id'));
            if ($kind === 'product') {
                $query->where('type', 'standard');
            }
            if ($search !== '') {
                $query->where(function (Builder $query) use ($kind, $search): void {
                    $query->where('name', 'like', '%'.$search.'%')->orWhere('sku', 'like', '%'.$search.'%');
                    if ($kind === 'variant') {
                        $query->orWhereHas('product', fn (Builder $product) => $product->where('name', 'like', '%'.$search.'%'));
                    }
                });
            }
            foreach ($query->orderBy('name')->orderBy($model->getKeyName())->limit(20)->get() as $record) {
                if (! $record->tracksInventory()) {
                    continue;
                }
                $results->push([
                    'identity' => $kind.':'.$record->getKey(),
                    'name' => $this->name($record, $kind),
                    'label' => view('purchase-orders::partials.product-option', [
                        'name' => $this->name($record, $kind),
                        'sku' => $record->sku,
                        'thumbnail' => $this->thumbnail($record, $kind),
                    ])->render(),
                ]);
            }
        }

        return $results->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->take(20)->pluck('label', 'identity')->all();
    }

    public function cost(Model $record, string $currency): ?int
    {
        $cost = method_exists($record, 'getPrice') ? $record->getPrice($currency)?->cost_amount : null;

        return $cost === null ? null : (int) $cost;
    }

    public function name(Model $record, string $kind): string
    {
        if ($kind === 'variant') {
            return implode(' — ', array_filter([$record->product?->name, $record->name]));
        }

        return (string) $record->name;
    }

    public function thumbnail(?Model $record, string $kind): string
    {
        $collection = config('shopper.media.storage.thumbnail_collection', 'thumbnail');
        $url = $record instanceof HasMedia ? $record->getFirstMedia($collection)?->getUrl() : null;
        if (! $url && $kind === 'variant' && $record?->product instanceof HasMedia) {
            $url = $record->product->getFirstMedia($collection)?->getUrl();
        }

        return $url ?: shopper_fallback_url();
    }
}
