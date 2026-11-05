<x-filament::section
    class="po-draft-details"
    heading="Order details"
    description="Save a draft to prepare the order before placing it."
>
    {{-- Shopper's theme targets Filament's earlier, flat calendar markup. --}}
    <style>
        .po-draft-details .fi-fo-date-time-picker-panel {
            inline-size: min(19rem, calc(100vw - 2rem));
        }

        .po-draft-details .fi-fo-date-time-picker-calendar {
            grid-template-columns: minmax(0, 1fr);
        }

        .po-draft-details .fi-fo-date-time-picker-calendar-header,
        .po-draft-details .fi-fo-date-time-picker-calendar-week {
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
            gap: 0.25rem;
        }

        .po-draft-details .fi-fo-date-time-picker-calendar-header {
            margin-block-end: 0.5rem;
        }

        .po-draft-details .fi-fo-date-time-picker-calendar-description {
            position: absolute;
            inline-size: 1px;
            block-size: 1px;
            overflow: hidden;
            clip-path: inset(50%);
            white-space: nowrap;
        }
    </style>

    {{ $this->detailsForm }}
</x-filament::section>
