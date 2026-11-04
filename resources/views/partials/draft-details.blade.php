<x-filament::section
    heading="Order details"
    description="Save a draft to prepare the order before placing it."
>
    <div class="grid gap-4 sm:grid-cols-2">
        <label class="grid min-w-0 gap-2 text-sm font-medium">
            <span>Supplier</span>
            <x-filament::input.wrapper>
                <x-filament::input.select
                    wire:model="data.supplier_id"
                    required
                >
                    <option value="">Select enabled supplier</option>
                    @foreach ($suppliers as $supplier)
                        <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </label>
        <label class="grid min-w-0 gap-2 text-sm font-medium">
            <span>Destination inventory</span>
            <x-filament::input.wrapper>
                <x-filament::input.select
                    wire:model="data.inventory_id"
                    required
                >
                    <option value="">Select destination</option>
                    @foreach ($inventories as $inventory)
                        <option value="{{ $inventory->id }}">{{ $inventory->name }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </label>
        <label class="grid min-w-0 gap-2 text-sm font-medium">
            <span>Currency</span>
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model="data.currency">
                    @foreach (array_keys(config('purchase-orders.currencies')) as $currency)
                        <option value="{{ $currency }}">{{ $currency }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </label>
        <label class="grid min-w-0 gap-2 text-sm font-medium">
            <span>Expected delivery</span>
            <x-filament::input.wrapper>
                <x-filament::input
                    type="date"
                    wire:model="data.expected_at"
                />
            </x-filament::input.wrapper>
        </label>
        <label class="grid min-w-0 gap-2 text-sm font-medium">
            <span>Supplier reference</span>
            <x-filament::input.wrapper>
                <x-filament::input
                    wire:model="data.supplier_reference"
                    maxlength="255"
                />
            </x-filament::input.wrapper>
        </label>
    </div>
</x-filament::section>
