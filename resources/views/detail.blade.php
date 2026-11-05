<div>
    @php
        $progress = $order->receivingProgress();
        $supplier = $order->supplier_snapshot ?? [];
        $money = fn(int $minor): string => $order->currency .
            ' ' .
            number_format($minor / 10 ** $order->currency_exponent, $order->currency_exponent);
    @endphp

    <x-shopper::container class="pt-5">
        <x-shopper::breadcrumb :back="route('shopper.purchase-orders.index')">
            <x-untitledui-chevron-left class="size-4 shrink-0 text-gray-300 dark:text-gray-600" />
            <x-shopper::breadcrumb.link
                :link="route('shopper.purchase-orders.index')"
                title="Purchase orders"
            />
        </x-shopper::breadcrumb>
    </x-shopper::container>

    <div class="sticky top-12 z-10 bg-white pt-6 backdrop-blur-lg dark:bg-gray-900">
        <x-shopper::container class="space-y-2 border-b border-gray-200 pb-4 dark:border-white/10">
            <div class="justify-between space-y-3 lg:flex lg:items-center lg:space-y-0">
                <div class="min-w-0 gap-3 sm:flex sm:items-center">
                    <h1
                        class="font-heading text-2xl font-bold text-gray-900 sm:truncate sm:text-3xl dark:text-white">
                        {{ $order->number }}
                    </h1>
                    <div class="mt-3 flex flex-wrap items-center gap-2 sm:mt-0">
                        <x-filament::badge
                            size="md"
                            :color="$order->state->color()"
                        >
                            {{ $order->state->name }}
                        </x-filament::badge>
                        <x-filament::badge
                            size="md"
                            :color="match ($progress) {
                                'fully_received' => 'success',
                                'partially_received' => 'warning',
                                default => 'gray',
                            }"
                        >
                            {{ str_replace('_', ' ', ucfirst($progress)) }}
                        </x-filament::badge>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    @php
                        $moreActions = array_filter(
                            [
                                $this->expectedDateAction,
                                $this->closeOrderAction,
                                $this->reopenAction,
                            ],
                            fn($action) => $action->isVisible(),
                        );
                    @endphp

                    @if ($moreActions)
                        <x-filament-actions::group
                            :actions="$moreActions"
                            label="More actions"
                            icon="untitledui-chevron-selector-vertical"
                            color="gray"
                            size="md"
                            dropdown-width="sh-dropdown-width"
                            dropdown-placement="bottom-start"
                            :button="true"
                        />
                    @endif
                    {{ $this->cancelOrderAction }}
                    @if ($order->state->value === 'draft')
                        @can('edit_orders')
                            <x-filament::button
                                type="button"
                                color="gray"
                                wire:click="$dispatch('openPanel', {
                                    component: 'shopper-purchase-orders.form',
                                    arguments: { orderId: {{ $order->id }} }
                                })"
                            >
                                Edit draft
                            </x-filament::button>
                        @endcan
                    @endif
                </div>
            </div>

            <div
                class="flex flex-col gap-2 text-sm text-gray-500 sm:flex-row sm:items-center dark:text-gray-400">
                <time datetime="{{ $order->created_at->toDateString() }}">
                    Created {{ $order->created_at->translatedFormat('M j, Y') }}
                </time>
                <span class="max-sm:hidden text-gray-300 dark:text-gray-600">&middot;</span>
                @include('purchase-orders::partials.supplier-name')
            </div>
        </x-shopper::container>
    </div>

    <x-shopper::container>
        <div class="grid sm:grid-cols-6">
            <div
                class="min-w-0 divide-y divide-gray-200 pt-2 sm:col-span-4 sm:pr-4 lg:pr-6 dark:divide-white/10">
                @if ($errors->any() && blank($this->mountedActions))
                    <div class="py-4">@include('purchase-orders::partials.messages')</div>
                @endif

                <section class="py-4">
                    <x-shopper::card>
                        <x-slot:title>
                            <x-shopper::section-heading
                                title="Receiving"
                                :description="$inventory?->name ?? 'Destination unavailable'"
                            />
                        </x-slot:title>
                        <dl class="grid grid-cols-3 gap-3 text-sm">
                            <div>
                                <dt class="text-gray-500 dark:text-gray-400">Accepted</dt>
                                <dd class="mt-1 text-lg font-semibold">
                                    {{ $order->items->sum('accepted_quantity') }}</dd>
                            </div>
                            <div>
                                <dt class="text-gray-500 dark:text-gray-400">Outstanding</dt>
                                <dd class="mt-1 text-lg font-semibold">
                                    {{ $order->outstandingQuantity() }}</dd>
                            </div>
                            <div>
                                <dt class="text-gray-500 dark:text-gray-400">Waived</dt>
                                <dd class="mt-1 text-lg font-semibold">
                                    {{ $order->items->sum('waived_quantity') }}</dd>
                            </div>
                        </dl>
                    </x-shopper::card>

                    @if ($order->state->value === 'draft' && $this->placeAction->isVisible())
                        <div class="mt-5 flex items-center justify-end">
                            {{ $this->placeAction }}
                        </div>
                    @elseif ($order->state->value === 'ordered' && $order->outstandingQuantity() > 0)
                        @can('edit_orders')
                            <div class="mt-5 flex items-center justify-end">
                                <x-filament::button
                                    tag="a"
                                    :href="route('shopper.purchase-orders.receive', [
                                        'orderId' => $order->id,
                                    ])"
                                    wire:navigate
                                >
                                    Receive delivery
                                </x-filament::button>
                            </div>
                        @endcan
                    @endif
                </section>

                <section class="py-4">
                    @include('purchase-orders::partials.products')
                </section>

                <section class="py-4">
                    <div
                        class="divide-y divide-gray-200 overflow-hidden rounded-lg bg-white ring-1 ring-gray-200 dark:divide-white/10 dark:bg-gray-900 dark:ring-white/10">
                        <h2
                            class="bg-gray-50 p-3 text-base/5 font-semibold text-gray-900 dark:bg-gray-950 dark:text-white">
                            Order summary
                        </h2>
                        <dl class="flex items-center justify-between p-4 text-sm font-semibold">
                            <dt>Merchandise subtotal</dt>
                            <dd>{{ $money($order->totalMinor()) }}</dd>
                        </dl>
                    </div>
                </section>

                @include('purchase-orders::partials.receipts')

                @include('purchase-orders::partials.activity')
            </div>

            <aside
                class="min-w-0 divide-y divide-gray-200 border-t border-gray-200 py-2 sm:col-span-2 sm:border-l sm:border-t-0 sm:pl-6 dark:divide-white/10 dark:border-white/10"
            >
                <section class="py-4">
                    <h2 class="text-lg font-medium text-gray-900 dark:text-white">Supplier</h2>
                    <div class="mt-4 space-y-1 text-sm">
                        <div class="font-medium">
                            @include('purchase-orders::partials.supplier-name')
                        </div>
                        @if (filled($supplier['contact_name'] ?? null))
                            <p class="text-gray-500 dark:text-gray-400">
                                {{ $supplier['contact_name'] }}</p>
                        @endif
                        @if (filled($supplier['email'] ?? null))
                            <p class="break-all"><a
                                    class="text-primary-600 underline"
                                    href="mailto:{{ $supplier['email'] }}"
                                >{{ $supplier['email'] }}</a></p>
                        @endif
                        @if (filled($supplier['phone'] ?? null))
                            <p class="text-gray-500 dark:text-gray-400">{{ $supplier['phone'] }}
                            </p>
                        @endif
                    </div>
                </section>
                <section class="space-y-3 py-4">
                    <h2
                        class="text-xs font-medium tracking-wider text-gray-900 uppercase dark:text-white">
                        Delivery destination</h2>
                    <div class="space-y-1 text-sm">
                        <p class="font-medium">{{ $inventory?->name ?? 'Destination unavailable' }}
                        </p>
                        @if ($inventory)
                            <p class="text-gray-500 dark:text-gray-400">
                                {{ $inventory->street_address }}<br />
                                {{ $inventory->city }} {{ $inventory->postal_code }}
                            </p>
                        @endif
                    </div>
                </section>
                <section class="space-y-3 py-4">
                    <h2
                        class="text-xs font-medium tracking-wider text-gray-900 uppercase dark:text-white">
                        Order information</h2>
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between gap-2">
                            <dt class="text-gray-500 dark:text-gray-400">Expected delivery</dt>
                            <dd>{{ $order->expected_at?->translatedFormat('M j, Y') ?? '—' }}</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-gray-500 dark:text-gray-400">Supplier reference</dt>
                            <dd class="break-all text-right">
                                {{ $order->supplier_reference ?: '—' }}</dd>
                        </div>
                    </dl>
                </section>
                @if ($order->notes)
                    <section class="space-y-3 py-4">
                        <h2
                            class="text-xs font-medium tracking-wider text-gray-900 uppercase dark:text-white">
                            Internal notes</h2>
                        <p class="whitespace-pre-wrap text-sm text-gray-500 dark:text-gray-400">
                            {{ $order->notes }}</p>
                    </section>
                @endif
            </aside>
        </div>
    </x-shopper::container>

    <x-filament-actions::modals />
</div>
