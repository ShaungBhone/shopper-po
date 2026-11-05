<x-filament::section
    :heading="$readOnly ? 'Products and pricing' : 'Pricing'"
    :description="$readOnly
        ? null
        : 'Review unit costs in ' .
            $this->data['currency'] .
            '. Totals update when you leave a cost field.'"
>
    <div class="space-y-4">
        <ul class="divide-y divide-gray-200 dark:divide-white/10">
            @forelse ($lines as $line)
                @php($index = $line['index'])
                <li
                    class="grid gap-4 py-4 sm:grid-cols-2 sm:items-center"
                    wire:key="{{ $readOnly ? 'review' : 'pricing' }}-line-{{ $index }}-{{ $this->data['currency'] }}"
                >
                    <div class="flex min-w-0 items-start gap-3">
                        <img
                            src="{{ $line['thumbnail'] }}"
                            alt=""
                            class="size-10 shrink-0 rounded object-cover"
                            loading="lazy"
                        />
                        <div class="min-w-0 text-sm">
                            <p class="break-words font-medium">{{ $line['name'] }}</p>
                            @if ($line['sku'])
                                <p class="break-all text-xs text-gray-500 dark:text-gray-400">
                                    {{ $line['sku'] }}</p>
                            @endif
                            <p class="mt-1 text-gray-500 dark:text-gray-400">Quantity:
                                {{ $line['quantity'] }}</p>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 items-start gap-4">
                        <div class="min-w-0">
                            @if ($readOnly)
                                <p class="text-sm text-gray-500 dark:text-gray-400">Unit cost</p>
                                <p class="mt-1 text-sm font-medium">{{ $line['cost'] }}</p>
                            @else
                                {{ $this->costsForm->getComponentByStatePath("items.{$index}.unit_cost_minor") }}
                            @endif
                        </div>
                        <div
                            class="text-right text-sm"
                            aria-live="polite"
                        >
                            <p class="text-gray-500 dark:text-gray-400">Line total</p>
                            <p class="mt-1 font-semibold">{{ $line['total'] }}</p>
                        </div>
                    </div>
                </li>
            @empty
                <li class="py-4 text-sm text-gray-500 dark:text-gray-400">Add products in the
                    Products step before entering prices.</li>
            @endforelse
        </ul>
        @if ($lines->hasPages())
            <x-filament::pagination :paginator="$lines" />
        @endif
        <div
            class="flex items-start justify-between gap-4 border-t border-gray-200 pt-4 dark:border-white/10"
            aria-live="polite"
        >
            <div>
                <p class="text-sm font-medium">Merchandise subtotal</p>
                <p class="text-xs text-gray-500 dark:text-gray-400">All product lines</p>
            </div>
            <p class="font-semibold">{{ $summary['subtotal'] }}</p>
        </div>
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
</x-filament::section>
