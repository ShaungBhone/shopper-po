<x-filament::section heading="Order lines">
    <div class="space-y-4">
        <div
            wire:key="product-picker-{{ $data['currency'] }}-{{ md5(json_encode(array_map(fn($item) => $item['stockable_kind'] . ':' . $item['stockable_id'], $data['items']))) }}">
            {{ $this->productPicker }}
        </div>
        @if (count($data['items']) >= 100)
            <p class="text-sm text-gray-500 dark:text-gray-400">Maximum of 100 product lines reached.
            </p>
        @endif

        @if ($data['items'])
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ count($data['items']) }} {{ count($data['items']) === 1 ? 'line' : 'lines' }}
            </p>
        @endif

        <ul class="space-y-3">
            @forelse ($lines as $line)
                @php
                    $index = $line['index'];
                    $quantityPath = "data.items.{$index}.ordered_quantity";
                @endphp
                <li
                    class="flex items-center gap-3"
                    wire:key="draft-line-{{ $index }}-{{ $data['currency'] }}"
                >
                    <div
                        class="min-w-0 flex-1 flex flex-wrap items-center gap-3 rounded-lg border border-gray-200 p-3 dark:border-white/10">
                        <div
                            class="flex min-w-0 items-center gap-3"
                            style="flex: 1 1 10rem"
                        >
                            <img
                                src="{{ $line['thumbnail'] }}"
                                alt=""
                                class="size-10 shrink-0 rounded object-cover"
                                loading="lazy"
                            />
                            <div class="min-w-0 flex-1">
                                <p class="break-words text-sm font-medium">{{ $line['name'] }}</p>
                                @if ($line['sku'])
                                    <p class="break-all text-xs text-gray-500 dark:text-gray-400">
                                        {{ $line['sku'] }}</p>
                                @endif
                            </div>
                        </div>
                        <div
                            class="shrink-0"
                            style="margin-inline-start: auto"
                        >
                            <div class="flex items-center gap-1">
                                <x-filament::icon-button
                                    type="button"
                                    color="gray"
                                    icon="heroicon-m-minus"
                                    :label="'Decrease quantity for ' . $line['name']"
                                    wire:click="changeQuantity({{ $index }}, -1)"
                                    :disabled="(int) ($data['items'][$index]['ordered_quantity'] ??
                                        0) <= 1"
                                />
                                <input
                                    type="number"
                                    min="1"
                                    max="{{ \ShopperPurchaseOrders\Support\Limits::MAX_QUANTITY }}"
                                    step="1"
                                    class="w-12 rounded border-0 bg-transparent p-1 text-center text-sm focus:ring-2 focus:ring-primary-500"
                                    aria-label="Quantity for {{ $line['name'] }}"
                                    wire:model.live="{{ $quantityPath }}"
                                    wire:keydown.enter.prevent
                                />
                                <x-filament::icon-button
                                    type="button"
                                    color="gray"
                                    icon="heroicon-m-plus"
                                    :label="'Increase quantity for ' . $line['name']"
                                    wire:click="changeQuantity({{ $index }}, 1)"
                                    :disabled="(int) ($data['items'][$index]['ordered_quantity'] ??
                                        0) >= \ShopperPurchaseOrders\Support\Limits::MAX_QUANTITY"
                                />
                            </div>
                            @error($quantityPath)
                                <p class="max-w-40 text-xs text-danger-600 dark:text-danger-400">
                                    {{ $message }}</p>
                            @enderror
                            @error('items.' . $index . '.ordered_quantity')
                                <p class="max-w-40 text-xs text-danger-600 dark:text-danger-400">
                                    {{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                    <x-filament::icon-button
                        class="shrink-0"
                        type="button"
                        color="gray"
                        icon="heroicon-m-trash"
                        :label="'Remove ' . $line['name']"
                        wire:click="removeLine({{ $index }})"
                    />
                </li>
            @empty
                <li class="py-3 text-sm text-gray-500 dark:text-gray-400">
                    Search for a product to add your first order line.
                </li>
            @endforelse
        </ul>

        @if ($lines->hasPages())
            <x-filament::pagination :paginator="$lines" />
        @endif
    </div>
</x-filament::section>
