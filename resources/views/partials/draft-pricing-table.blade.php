<table
    class="w-full table-fixed text-sm"
    aria-label="Purchase order pricing"
>
    <colgroup>
        <col />
        <col style="width: 15%" />
        <col style="width: 40%" />
    </colgroup>
    <thead
        class="border-b border-gray-200 text-xs text-gray-500 dark:border-white/10 dark:text-gray-400"
    >
        <tr>
            <th
                scope="col"
                class="pb-3 text-left font-medium"
            >Product</th>
            <th
                scope="col"
                class="pb-3 text-right font-medium"
                style="padding-inline-end: clamp(0.5rem, 1.5vw, 1rem)"
            >Qty</th>
            <th
                scope="col"
                class="pb-3 text-right font-medium"
            >Unit cost</th>
        </tr>
    </thead>
    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
        @forelse ($lines as $line)
            @php($index = $line['index'])
            <tr
                wire:key="{{ $readOnly ? 'review' : 'pricing' }}-line-{{ $index }}-{{ $this->data['currency'] }}">
                <th
                    scope="row"
                    class="py-3 pr-2 text-left font-medium"
                    style="overflow-wrap: anywhere"
                >
                    <div class="flex items-center gap-2">
                        <img
                            src="{{ $line['thumbnail'] }}"
                            alt=""
                            class="size-8 shrink-0 rounded object-cover"
                            loading="lazy"
                        />
                        <div class="min-w-0">
                            {{ $line['name'] }}
                            @if ($line['sku'])
                                <span
                                    class="block text-xs font-normal text-gray-500 dark:text-gray-400"
                                >{{ $line['sku'] }}</span>
                            @endif
                        </div>
                    </div>
                </th>
                <td
                    class="py-3 text-right"
                    style="overflow-wrap: anywhere; padding-inline-end: clamp(0.5rem, 1.5vw, 1rem)"
                >
                    {{ $line['quantity'] }}
                </td>
                <td
                    class="py-3 text-right"
                    style="overflow-wrap: anywhere"
                >
                    @if ($readOnly)
                        {{ $line['cost'] }}
                    @else
                        <div style="max-inline-size: 10rem; margin-inline-start: auto">
                            {{ $this->costsForm->getComponentByStatePath("items.{$index}.unit_cost_minor") }}
                        </div>
                    @endif
                </td>
            </tr>
        @empty
            <tr>
                <td
                    colspan="3"
                    class="py-3 text-gray-500 dark:text-gray-400"
                >
                    Add products in the Products step before entering prices.
                </td>
            </tr>
        @endforelse
    </tbody>
</table>
