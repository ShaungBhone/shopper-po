@if ($choosingProducts)
    <x-filament::button
        type="button"
        color="gray"
        wire:click="closeCatalog"
    >Back</x-filament::button>
    <x-filament::button type="submit">Add selected</x-filament::button>
@else
    @if ($orderId)
        <x-filament::button
            type="button"
            color="gray"
            wire:click="$dispatch('closePanel')"
        >
            Cancel
        </x-filament::button>
    @endif
    <x-filament::button type="submit">Save draft</x-filament::button>
@endif
