<div class="flex flex-wrap items-start gap-4">
    <x-filament::section
        class="min-w-0"
        :compact="true"
        style="flex: 3 1 32rem"
        :heading="$readOnly ? 'Products and pricing' : 'Pricing'"
    >
        <div class="space-y-4">
            @include('purchase-orders::partials.draft-pricing-table')
            @if ($lines->hasPages())
                <x-filament::pagination :paginator="$lines" />
            @endif
        </div>
    </x-filament::section>

    @include('purchase-orders::partials.draft-pricing-summary')
</div>
