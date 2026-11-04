<x-filament::section
    heading="Catalog"
    description="Select products or variants to add them together."
>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center gap-3">
            <x-filament::input.wrapper
                prefix-icon="heroicon-m-magnifying-glass"
                class="min-w-0 flex-1"
            >
                <x-filament::input
                    wire:model.live.debounce.300ms="catalogSearch"
                    wire:keydown.enter.prevent
                    placeholder="Search by name or SKU"
                    aria-label="Search catalog"
                />
            </x-filament::input.wrapper>

            <x-filament::input.wrapper>
                <x-filament::input.select
                    wire:model.live="catalogKind"
                    aria-label="Catalog type"
                >
                    <option value="product">Products</option>
                    <option value="variant">Variants</option>
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>

        <p
            class="text-sm text-gray-500 dark:text-gray-400"
            aria-live="polite"
        >
            {{ count($selectedProducts) }} selected
        </p>

        <ul class="divide-y divide-gray-200 dark:divide-white/10">
            @forelse ($catalog as $record)
                @php
                    $kind = $catalogKind === 'variant' ? 'variant' : 'product';
                    $identity = $kind . ':' . $record->getKey();
                    $alreadyAdded = in_array($identity, $existingProducts, true);
                @endphp
                <li wire:key="draft-catalog-{{ $identity }}">
                    <label class="flex items-center gap-3 py-3 text-sm">
                        @if ($alreadyAdded)
                            <x-filament::input.checkbox
                                checked
                                disabled
                            />
                        @else
                            <x-filament::input.checkbox
                                wire:model.live="selectedProducts"
                                value="{{ $identity }}"
                            />
                        @endif
                        <span class="min-w-0 flex-1">
                            <span class="block break-words font-medium">
                                {{ $record->name ?? ($record->product?->name ?? 'Unnamed product') }}
                            </span>
                            @if ($record->sku)
                                <span
                                    class="break-all text-gray-500 dark:text-gray-400">{{ $record->sku }}</span>
                            @endif
                        </span>
                        @if ($alreadyAdded)
                            <x-filament::badge color="gray">Added</x-filament::badge>
                        @endif
                    </label>
                </li>
            @empty
                <li class="py-4 text-sm text-gray-500 dark:text-gray-400">No matching products
                    found.</li>
            @endforelse
        </ul>

        @if ($catalog->hasPages())
            <x-filament::pagination :paginator="$catalog" />
        @endif
    </div>
</x-filament::section>
