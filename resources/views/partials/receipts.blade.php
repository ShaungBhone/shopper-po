<section class="py-4">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Delivery receipts</h2>

    <div class="mt-4 space-y-4">
        @forelse ($order->receipts as $receipt)
            <x-shopper::card wire:key="po-receipt-{{ $receipt->id }}">
                <x-slot:title>
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Receipt
                            #{{ $receipt->id }}</h3>
                        <time
                            class="text-xs text-gray-500 dark:text-gray-400"
                            datetime="{{ $receipt->created_at->toIso8601String() }}"
                        >
                            {{ $receipt->created_at->translatedFormat('M j, Y H:i') }}
                        </time>
                    </div>
                </x-slot:title>

                <ul class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($receipt->items as $receiptItem)
                        @php
                            $name =
                                $order->items->find($receiptItem->purchase_order_item_id)?->name ??
                                'Receipt line #' . $receiptItem->id;
                            $accepted = $receiptItem->effectiveAccepted();
                            $rejected = $receiptItem->effectiveRejected();
                        @endphp

                        <li
                            class="py-3 first:pt-0 last:pb-0"
                            wire:key="po-receipt-line-{{ $receiptItem->id }}"
                        >
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div
                                    class="min-w-0 space-y-1"
                                    style="overflow-wrap: anywhere"
                                >
                                    <p class="text-sm font-medium text-gray-900 dark:text-white">
                                        {{ $name }}</p>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <x-filament::badge
                                            size="sm"
                                            :color="$accepted > 0 ? 'success' : 'gray'"
                                        >
                                            {{ $accepted }} accepted
                                        </x-filament::badge>
                                        @if ($rejected > 0)
                                            <x-filament::badge
                                                size="sm"
                                                color="warning"
                                            >{{ $rejected }} rejected</x-filament::badge>
                                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                                {{ $receiptItem->rejection_resolution === 'replace' ? 'Replacement expected' : 'Balance waived' }}
                                            </span>
                                        @endif
                                        @if ($receiptItem->inventory_history_id)
                                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                                Stock entry
                                                #{{ $receiptItem->inventory_history_id }}
                                            </span>
                                        @endif
                                    </div>
                                </div>

                                {{ ($this->correctAction)(['receiptItemId' => $receiptItem->id])->button()->color('gray')->size('sm') }}
                            </div>

                            @if ($receiptItem->corrections->isNotEmpty())
                                <ul
                                    class="mt-3 space-y-2 border-l border-gray-200 pl-3 dark:border-white/10">
                                    @foreach ($receiptItem->corrections as $correction)
                                        <li
                                            class="text-xs text-gray-500 dark:text-gray-400"
                                            style="overflow-wrap: anywhere"
                                        >
                                            <p>
                                                <span
                                                    class="font-medium text-gray-700 dark:text-gray-300"
                                                >Correction #{{ $correction->id }}</span>
                                                @if ($correction->accepted_delta)
                                                    ·
                                                    {{ sprintf('%+d', $correction->accepted_delta) }}
                                                    accepted
                                                @endif
                                                @if ($correction->rejected_delta)
                                                    ·
                                                    {{ sprintf('%+d', $correction->rejected_delta) }}
                                                    rejected
                                                @endif
                                                @if ($correction->inventory_history_id)
                                                    · Stock entry
                                                    #{{ $correction->inventory_history_id }}
                                                @endif
                                            </p>
                                            <p class="mt-1 whitespace-pre-wrap">
                                                {{ $correction->reason }}</p>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </li>
                    @endforeach
                </ul>

                @if ($receipt->notes)
                    <div
                        class="mt-3 border-t border-gray-100 pt-3 text-xs dark:border-white/5"
                        style="overflow-wrap: anywhere"
                    >
                        <p class="font-medium text-gray-700 dark:text-gray-300">Delivery note</p>
                        <p class="mt-1 whitespace-pre-wrap text-gray-500 dark:text-gray-400">
                            {{ $receipt->notes }}</p>
                    </div>
                @endif
            </x-shopper::card>
        @empty
            <p class="text-sm text-gray-500 dark:text-gray-400">No deliveries recorded.</p>
        @endforelse
    </div>
</section>
