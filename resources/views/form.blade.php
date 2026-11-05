<x-shopper::container class="space-y-6 py-5">
    <x-shopper::breadcrumb :back="route('shopper.purchase-orders.index')">
        <x-untitledui-chevron-left class="size-4 shrink-0 text-gray-400" />
        <x-shopper::breadcrumb.link
            :link="route('shopper.purchase-orders.index')"
            title="Purchase orders"
        />
    </x-shopper::breadcrumb>
    <x-shopper::heading title="New purchase order" />
    @include('purchase-orders::partials.messages')
    {{ $this->draftWizard }}
</x-shopper::container>
