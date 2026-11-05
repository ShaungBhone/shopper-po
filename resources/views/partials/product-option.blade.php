<div class="flex items-center gap-3 py-1">
    <img
        src="{{ $thumbnail }}"
        alt=""
        class="size-9 shrink-0 rounded object-cover"
        loading="lazy"
    />
    <span class="min-w-0">
        <span class="block truncate font-medium">{{ $name }}</span>
        @if ($sku)
            <span
                class="block truncate text-xs text-gray-500 dark:text-gray-400">{{ $sku }}</span>
        @endif
    </span>
</div>
