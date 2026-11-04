<x-shopper::card>
    <x-slot:title>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <x-shopper::section-heading title="Order lines" />
            <x-filament::button
                type="button"
                color="gray"
                icon="heroicon-m-plus"
                wire:click="openCatalog"
            >
                Add products
            </x-filament::button>
        </div>
    </x-slot:title>

    <div
        class="space-y-4"
        @if ($returningFromCatalog) x-data x-init="$el.closest('.sh-card').scrollIntoView({ block: 'start' })" @endif
    >
        @if ($data['items'])
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ count($data['items']) }} {{ count($data['items']) === 1 ? 'line' : 'lines' }}
                </p>
                <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                    <x-filament::input
                        wire:model.live.debounce.300ms="lineSearch"
                        wire:keydown.enter.prevent
                        placeholder="Find an order line"
                        aria-label="Find an order line"
                    />
                </x-filament::input.wrapper>
            </div>
        @endif

        <ul class="divide-y divide-gray-200 dark:divide-white/10">
            @forelse ($lines as $line)
                @php($index = $line['index'])
                <li
                    class="grid gap-3 py-3 sm:grid-cols-2 sm:items-center"
                    wire:key="draft-line-{{ $index }}"
                >
                    <div class="min-w-0 text-sm">
                        <p class="break-words font-medium">{{ $line['name'] }}</p>
                        @if ($line['sku'])
                            <p class="break-all text-gray-500 dark:text-gray-400">
                                {{ $line['sku'] }}</p>
                        @endif
                    </div>
                    <div class="flex items-end gap-2">
                        <label class="grid min-w-0 flex-1 gap-2 text-sm font-medium">
                            <span>Quantity</span>
                            <x-filament::input.wrapper>
                                <x-filament::input
                                    type="number"
                                    min="1"
                                    required
                                    wire:model="data.items.{{ $index }}.ordered_quantity"
                                />
                            </x-filament::input.wrapper>
                        </label>
                        <label class="grid min-w-0 flex-1 gap-2 text-sm font-medium">
                            <span>Unit cost</span>
                            <x-filament::input.wrapper>
                                <x-filament::input
                                    type="number"
                                    min="0"
                                    required
                                    wire:model="data.items.{{ $index }}.unit_cost_minor"
                                />
                            </x-filament::input.wrapper>
                        </label>
                        <x-filament::icon-button
                            type="button"
                            color="gray"
                            icon="heroicon-m-trash"
                            :label="'Remove ' . $line['name']"
                            wire:click="removeLine({{ $index }})"
                        />
                    </div>
                </li>
            @empty
                <li class="py-3 text-sm text-gray-500 dark:text-gray-400">
                    {{ $data['items'] ? 'No order lines match your search.' : 'Add products or variants from the catalog.' }}
                </li>
            @endforelse
        </ul>

        @if ($lines->hasPages())
            <x-filament::pagination :paginator="$lines" />
        @endif

        @if ($data['items'])
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Enter unit costs in minor units. For USD, 1250 is $12.50.
            </p>
        @endif
    </div>
</x-shopper::card>
