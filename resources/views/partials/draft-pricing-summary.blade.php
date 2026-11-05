<div
    class="min-w-0 space-y-4 rounded-lg border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900"
    style="flex: 1 1 16rem"
>
    <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Summary</h2>
    <dl
        class="space-y-2 text-sm"
        aria-live="polite"
    >
        <div class="flex items-baseline justify-between gap-3">
            <dt class="text-gray-500 dark:text-gray-400">Total quantity</dt>
            <dd class="text-right">
                {{ $summary['total_quantity'] === null ? '—' : number_format($summary['total_quantity']) }}
            </dd>
        </div>
        <div
            class="flex items-baseline justify-between gap-3 border-t border-gray-200 pt-2 dark:border-white/10">
            <dt class="font-medium text-gray-900 dark:text-white">Merchandise subtotal</dt>
            <dd class="text-right">{{ $summary['subtotal'] }}</dd>
        </div>
    </dl>
    @if ($summary['over_limit'])
        <p class="text-sm text-danger-600 dark:text-danger-400">Merchandise subtotal exceeds the
            supported amount.</p>
    @elseif ($summary['subtotal_minor'] === null)
        <p class="text-sm text-gray-500 dark:text-gray-400">Complete valid quantities and unit
            costs to see the subtotal.</p>
    @endif
    @error('pricing')
        <p class="text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>
    @enderror
</div>
