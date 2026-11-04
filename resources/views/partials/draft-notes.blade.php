<x-filament::section heading="Internal notes">
    <x-filament::input.wrapper>
        <textarea
            aria-label="Internal notes"
            class="block w-full border-0 bg-transparent px-3 py-2 text-sm focus:ring-0"
            wire:model="data.notes"
            maxlength="5000"
            rows="3"
        ></textarea>
    </x-filament::input.wrapper>
</x-filament::section>
