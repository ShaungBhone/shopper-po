@php
    use ShopperPurchaseOrders\Enums\OrderState;

    $outstanding = $order->outstandingQuantity();
    $nextStep = match ($order->state) {
        OrderState::Draft => [
            'Mark ordered',
            'Record the supplier commitment once the order has been sent.',
        ],
        OrderState::Ordered => $outstanding > 0
            ? [
                'Awaiting delivery',
                trans_choice(
                    '{1} :count unit outstanding.|[2,*] :count units outstanding.',
                    $outstanding,
                ),
            ]
            : ['Ready to close', 'Close the purchase order when receiving is complete.'],
        OrderState::Closed, OrderState::Cancelled => null,
    };
    $expectedDate =
        $order->state === OrderState::Ordered && $outstanding > 0 ? $order->expected_at : null;
@endphp

<section class="py-4">
    <div class="flex items-center justify-between gap-2">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Activity</h2>
        {{ $this->auditHistoryAction }}
    </div>

    @if ($order->history->isEmpty() && !$nextStep)
        <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">No activity recorded.</p>
    @else
        <ol class="mt-4">
            @foreach ($order->history as $event)
                @php
                    $description = match ($event->type) {
                        'draft_created' => 'The purchase order was saved as a draft.',
                        'draft_updated' => 'The draft purchase order was edited.',
                        'ordered' => 'The supplier commitment was recorded.',
                        'received' => filled($event->data['receipt_id'] ?? null)
                            ? 'Receipt #' . $event->data['receipt_id'] . ' was recorded.'
                            : 'A receipt was recorded for this purchase order.',
                        'receipt_corrected' => 'The received quantities were corrected.',
                        'line_closed' => 'Outstanding units on the line were waived.',
                        'line_reopened' => 'Previously waived units on the line were restored.',
                        'line_amended' => 'The order line quantity or unit cost was updated.',
                        'expected_date_changed' => 'The expected delivery date was updated.',
                        'closed' => 'The purchase order was closed.',
                        'reopened' => 'The purchase order was reopened for changes.',
                        'cancelled' => 'The purchase order was cancelled.',
                        default => 'Purchase order activity was recorded.',
                    };
                    $reason = $event->data['reason'] ?? null;
                @endphp

                <li
                    class="flex gap-4"
                    wire:key="po-activity-{{ $event->id }}"
                >
                    <div
                        class="flex shrink-0 flex-col items-center"
                        aria-hidden="true"
                    >
                        <span
                            class="inline-flex size-5 shrink-0 items-center justify-center rounded-full bg-primary-100 text-primary-600 dark:bg-primary-900 dark:text-primary-400"
                        >
                            <x-heroicon-m-check class="size-3" />
                        </span>
                        @if (!$loop->last || $nextStep)
                            <span
                                @class([
                                    'flex-1',
                                    'bg-primary-100 dark:bg-primary-900' => !$loop->last,
                                    'bg-gray-200 dark:bg-gray-700' => $loop->last,
                                ])
                                style="inline-size: 2px; margin-block: 2px"
                            ></span>
                        @endif
                    </div>

                    <div
                        @class(['min-w-0', 'pb-6' => !$loop->last || $nextStep])
                        style="overflow-wrap: anywhere"
                    >
                        <time
                            class="block text-xs text-gray-500 dark:text-gray-400"
                            datetime="{{ $event->created_at->toIso8601String() }}"
                        >
                            {{ $event->created_at->translatedFormat('M j, Y') }}
                        </time>
                        <p class="mt-1 text-sm font-medium text-gray-900 dark:text-white">
                            {{ $event->label() }}</p>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            {{ filled($reason) && $event->type !== 'received' ? $reason : $description }}
                        </p>
                        @if ($event->type === 'received' && filled($reason))
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                {{ $reason }}</p>
                        @endif
                    </div>
                </li>
            @endforeach

            @if ($nextStep)
                <li
                    class="flex gap-4"
                    wire:key="po-activity-next"
                >
                    <span
                        class="size-5 shrink-0 rounded-full border-2 border-gray-300 dark:border-gray-600"
                        aria-hidden="true"
                    ></span>
                    <div class="min-w-0">
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            Next step
                            @if ($expectedDate)
                                · Expected <time
                                    datetime="{{ $expectedDate->toDateString() }}">{{ $expectedDate->translatedFormat('M j, Y') }}</time>
                            @endif
                        </p>
                        <p class="mt-1 text-sm font-medium text-gray-900 dark:text-white">
                            {{ $nextStep[0] }}</p>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            {{ $nextStep[1] }}</p>
                    </div>
                </li>
            @endif
        </ol>
    @endif
</section>
