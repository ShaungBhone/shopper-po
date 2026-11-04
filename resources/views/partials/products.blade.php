<div class="flex items-center justify-between gap-2">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
        {{ __('shopper::pages/products.menu') }}
    </h2>
    <div class="flex items-center space-x-3">
        <label
            for="po-products-per-page"
            class="text-sm font-medium whitespace-nowrap text-gray-500 dark:text-gray-400"
        >
            {{ __('shopper::words.per_page') }}
        </label>
        <x-filament::input.wrapper>
            <x-filament::input.select
                id="po-products-per-page"
                wire:model.live="productsPerPage"
            >
                <option value="3">3</option>
                <option value="5">5</option>
                <option value="10">10</option>
            </x-filament::input.select>
        </x-filament::input.wrapper>
    </div>
</div>

<ul class="mt-2 divide-y divide-gray-100 dark:divide-white/5">
    @foreach ($products as $item)
        <li
            class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between"
            wire:key="po-line-{{ $item->id }}"
        >
            <div class="flex min-w-0 flex-1 items-center gap-2">
                <img
                    class="size-6 shrink-0 rounded object-cover"
                    src="{{ $productThumbnails[$item->id] ?? null ?: shopper_fallback_url() }}"
                    alt=""
                    loading="lazy"
                />
                <p
                    class="truncate text-sm text-gray-900 dark:text-white"
                    title="{{ $item->name }} · {{ $item->sku }}"
                >
                    {{ $item->name }}
                </p>
                <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400">&times;
                    {{ $item->ordered_quantity }}</span>
            </div>
            <div class="flex flex-wrap items-center gap-2 sm:shrink-0 sm:pl-3">
                <span
                    class="text-sm font-medium text-gray-700 dark:text-gray-300"
                    title="{{ $money($item->unit_cost_minor) }} each"
                >
                    {{ $money($item->unit_cost_minor * $item->ordered_quantity) }}
                </span>
                @if ($order->state->value === 'ordered')
                    <x-filament-actions::group
                        :actions="[
                            ($this->amendAction)(['itemId' => $item->id]),
                            ($this->closeLineAction)(['itemId' => $item->id]),
                            ($this->restoreLineAction)(['itemId' => $item->id]),
                        ]"
                        icon="heroicon-m-ellipsis-vertical"
                        :label="'Actions for ' . $item->name"
                    />
                @endif
            </div>
        </li>
    @endforeach
</ul>

@if ($products->hasPages())
    <div class="mt-4">{{ $products->links() }}</div>
@endif
