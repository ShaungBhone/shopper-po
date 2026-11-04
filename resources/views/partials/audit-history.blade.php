@php
    $formatMoney = fn(?int $minor): string => $minor === null
        ? '—'
        : $currency . ' ' . number_format($minor / 10 ** $currencyExponent, $currencyExponent);
@endphp

<ol class="divide-y divide-gray-200 dark:divide-white/10">
    @forelse ($events as $event)
        @php
            $data = $event->data ?? [];
            $actor = $actors->get($event->actor_id);
            $actorName = $actor?->full_name ?: $actor?->name ?: 'User #' . $event->actor_id;
            $itemId =
                $data['item_id'] ??
                ($event->type === 'line_closed' ? array_key_first($data['waived'] ?? []) : null);
            $product = $items->get($itemId)?->name ?? ($itemId ? 'Line #' . $itemId : null);
            $fields = match ($event->type) {
                'received' => [
                    'Receipt' => isset($data['receipt_id']) ? '#' . $data['receipt_id'] : null,
                ],
                'receipt_corrected' => [
                    'Receipt line' => isset($data['receipt_item_id'])
                        ? '#' . $data['receipt_item_id']
                        : null,
                    'Accepted units' => empty($data['accepted_delta'])
                        ? null
                        : sprintf('%+d', $data['accepted_delta']),
                    'Rejected units' => empty($data['rejected_delta'])
                        ? null
                        : sprintf('%+d', $data['rejected_delta']),
                ],
                'expected_date_changed' => [
                    'Expected delivery' =>
                        ($data['before'] ?? 'No date') . ' → ' . ($data['after'] ?? 'No date'),
                ],
                'line_amended' => [
                    'Product' => $product,
                    'Ordered units' =>
                        ($data['before']['ordered_quantity'] ?? '—') .
                        ' → ' .
                        ($data['after']['ordered_quantity'] ?? '—'),
                    'Unit cost' =>
                        $formatMoney($data['before']['unit_cost_minor'] ?? null) .
                        ' → ' .
                        $formatMoney($data['after']['unit_cost_minor'] ?? null),
                ],
                'closed', 'line_closed' => [
                    'Product' => $product,
                    'Units waived' => isset($data['waived']) ? array_sum($data['waived']) : null,
                ],
                'line_reopened' => [
                    'Product' => $product,
                    'Units restored' => $data['restored'] ?? null,
                ],
                default => [],
            };
        @endphp
        <li
            class="space-y-2 py-4"
            wire:key="po-audit-event-{{ $event->id }}"
        >
            <div>
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $event->label() }}
                </h3>
                <time
                    class="text-xs text-gray-500 dark:text-gray-400"
                    datetime="{{ $event->created_at->toIso8601String() }}"
                >
                    {{ $event->created_at->translatedFormat('M j, Y H:i:s T') }}
                </time>
            </div>
            <dl
                class="space-y-2 text-sm"
                style="overflow-wrap: anywhere"
            >
                <div class="flex justify-between gap-3">
                    <dt class="text-gray-500 dark:text-gray-400">Changed by</dt>
                    <dd class="text-right">{{ $actorName }}</dd>
                </div>
                @foreach ($fields as $label => $value)
                    @if ($value !== null)
                        <div class="flex justify-between gap-3">
                            <dt class="text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                            <dd class="text-right">{{ $value }}</dd>
                        </div>
                    @endif
                @endforeach
                @if (filled($data['reason'] ?? null))
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">Reason</dt>
                        <dd class="mt-1 whitespace-pre-wrap">{{ $data['reason'] }}</dd>
                    </div>
                @endif
            </dl>
        </li>
    @empty
        <li class="py-4 text-sm text-gray-500 dark:text-gray-400">No audit events recorded.</li>
    @endforelse
</ol>
