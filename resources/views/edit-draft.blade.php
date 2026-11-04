<x-shopper::slideover-card class="divide-y divide-gray-100 dark:divide-white/10">
    <header class="flex items-start justify-between gap-3 p-4">
        <div>
            <h2 class="text-lg font-medium text-gray-900 dark:text-white">Edit draft</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $orderNumber }}</p>
        </div>
        <x-livewire-slide-over::close-icon />
    </header>

    @if ($errors->any())
        <div
            class="shrink-0 overflow-y-auto px-4 py-2"
            style="max-height: 10rem"
        >
            @include('purchase-orders::partials.messages')
        </div>
    @endif

    <div class="h-0 min-h-0 flex-1 [&>div]:h-full">
        {{ $this->draftWizard }}
    </div>
</x-shopper::slideover-card>
