<x-shopper::container class="space-y-6 py-5">
    <x-shopper::breadcrumb :back="route('shopper.purchase-orders.index')">
        <x-untitledui-chevron-left class="size-4 shrink-0 text-gray-400" />
        <x-shopper::breadcrumb.link
            :link="route('shopper.purchase-orders.index')"
            title="Purchase orders"
        />
    </x-shopper::breadcrumb>

    <x-shopper::heading :title="$choosingProducts ? 'Add products' : 'New purchase order'" />
    @include('purchase-orders::partials.messages')

    <form
        wire:submit="{{ $choosingProducts ? 'addSelectedProducts' : 'save' }}"
        class="space-y-6"
    >
        @if ($choosingProducts)
            @include('purchase-orders::partials.draft-catalog')
        @else
            @include('purchase-orders::partials.draft-fields')
        @endif

        <div class="flex flex-wrap justify-end gap-3">
            @include('purchase-orders::partials.draft-actions')
        </div>
    </form>
</x-shopper::container>
