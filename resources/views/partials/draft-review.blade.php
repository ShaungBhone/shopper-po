<div class="space-y-6">
    <x-shopper::card title="Review purchase order">
        <dl class="grid gap-4 text-sm sm:grid-cols-2">
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Supplier</dt>
                <dd class="mt-1 font-medium">
                    {{ $suppliers->find($data['supplier_id'] ?? null)?->name ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Destination inventory</dt>
                <dd class="mt-1 font-medium">
                    {{ $inventories->find($data['inventory_id'] ?? null)?->name ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Expected delivery</dt>
                <dd class="mt-1 font-medium">{{ $data['expected_at'] ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Currency</dt>
                <dd class="mt-1 font-medium">{{ $data['currency'] }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Supplier reference</dt>
                <dd class="mt-1 font-medium">{{ $data['supplier_reference'] ?? '' ?: '—' }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Product lines</dt>
                <dd class="mt-1 font-medium">{{ count($data['items']) }}</dd>
            </div>
        </dl>
    </x-shopper::card>

    @include('purchase-orders::partials.draft-pricing', ['readOnly' => true])

    @include('purchase-orders::partials.draft-notes')
</div>
