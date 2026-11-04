<x-shopper::container class="space-y-6 py-5">
    <x-shopper::breadcrumb :back="route('shopper.purchase-orders.show', ['orderId' => $order->id])">
        <x-untitledui-chevron-left class="size-4 shrink-0 text-gray-400" />
        <x-shopper::breadcrumb.link
            :link="route('shopper.purchase-orders.show', ['orderId' => $order->id])"
            :title="$order->number"
        />
    </x-shopper::breadcrumb>

    <x-shopper::heading title="Receive delivery" />
    @include('purchase-orders::partials.messages')

    <form
        wire:submit="save"
        class="space-y-6"
    >
        <x-filament::section
            heading="Delivery quantities"
            :description="($order->supplier_snapshot['name'] ?? 'Supplier') .
                ' · ' .
                ($inventory?->name ?? 'Unavailable destination')"
        >
            <div class="space-y-4">
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Enter units in this delivery only. Accepted units become stock. Refuse or return
                    rejected units immediately.
                </p>
                @foreach ($lines as $i => $line)
                    @php($item = $items->get($line['item_id']))
                    <div
                        class="grid items-end gap-4 sm:grid-cols-4"
                        wire:key="receive-{{ $line['item_id'] }}"
                    >
                        <div>
                            <div class="text-sm font-medium">{{ $item?->name }}</div>
                            <div class="text-sm text-gray-500 dark:text-gray-400">
                                {{ $item?->sku }} · {{ $item?->outstanding() }} outstanding
                            </div>
                        </div>
                        <label class="block space-y-2 text-sm font-medium">
                            <span>Accept now</span>
                            <x-filament::input.wrapper>
                                <x-filament::input
                                    type="number"
                                    min="0"
                                    wire:model="lines.{{ $i }}.accepted"
                                />
                            </x-filament::input.wrapper>
                        </label>
                        <label class="block space-y-2 text-sm font-medium">
                            <span>Reject now</span>
                            <x-filament::input.wrapper>
                                <x-filament::input
                                    type="number"
                                    min="0"
                                    wire:model="lines.{{ $i }}.rejected"
                                />
                            </x-filament::input.wrapper>
                        </label>
                        <label class="block space-y-2 text-sm font-medium">
                            <span>Rejected-unit resolution</span>
                            <x-filament::input.wrapper>
                                <x-filament::input.select
                                    wire:model="lines.{{ $i }}.resolution"
                                >
                                    <option value="replace">Replacement expected</option>
                                    <option value="waive">Waive supplier balance</option>
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </label>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        <x-filament::section heading="Delivery notes">
            <x-filament::input.wrapper>
                <textarea
                    aria-label="Delivery reference and discrepancy notes"
                    class="block w-full border-0 bg-transparent px-3 py-2 text-sm focus:ring-0"
                    wire:model="notes"
                    maxlength="5000"
                    rows="3"
                    placeholder="Enter a delivery reference or explain discrepancies."
                ></textarea>
            </x-filament::input.wrapper>
        </x-filament::section>

        <x-filament::button type="submit">Record delivery and update stock</x-filament::button>
    </form>
</x-shopper::container>
