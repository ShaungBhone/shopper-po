@if ($choosingProducts)
    <div
        class="space-y-4"
        wire:key="draft-wizard-catalog"
    >
        <div class="flex flex-wrap justify-end gap-3">
            <x-filament::button
                type="button"
                color="gray"
                wire:click="closeCatalog"
            >Back to products</x-filament::button>
            <x-filament::button
                type="button"
                wire:click="addSelectedProducts"
            >Add selected</x-filament::button>
        </div>
        @include('purchase-orders::partials.draft-catalog')
    </div>
@else
    <div wire:key="draft-wizard-lines">
        @include('purchase-orders::partials.draft-lines')
    </div>
@endif
