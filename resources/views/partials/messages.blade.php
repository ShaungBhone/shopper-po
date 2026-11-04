@if ($errors->any())
    <x-filament::callout
        color="danger"
        role="alert"
        heading="Check the following fields"
    >
        <x-slot:description>
            @foreach ($errors->all() as $error)
                <span class="block">{{ $error }}</span>
            @endforeach
        </x-slot:description>
    </x-filament::callout>
@endif
