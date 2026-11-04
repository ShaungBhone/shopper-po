@if ($supplierRouteKey !== null)
    <button
        type="button"
        wire:click="$dispatch('openPanel', {
            component: 'shopper-slide-overs.supplier-form',
            arguments: { supplier: @js($supplierRouteKey) }
        })"
        class="cursor-pointer text-left font-medium text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white"
    >
        {{ $supplier['name'] ?? 'Supplier unavailable' }}
    </button>
@else
    <span>{{ $supplier['name'] ?? 'Supplier unavailable' }}</span>
@endif
