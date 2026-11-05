<?php

declare(strict_types=1);

namespace ShopperPurchaseOrders\Livewire;

use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Laravelcm\LivewireSlideOvers\SlideOverComponent;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\WithoutUrlPagination;
use Livewire\WithPagination;
use Shopper\Components\Form\MoneyInput;
use Shopper\Components\SlideOverWizard;
use ShopperPurchaseOrders\Actions\Purchasing;
use ShopperPurchaseOrders\Enums\OrderState;
use ShopperPurchaseOrders\Models\PurchaseOrder;
use ShopperPurchaseOrders\Support\Access;
use ShopperPurchaseOrders\Support\Catalog;
use ShopperPurchaseOrders\Support\Currencies;
use ShopperPurchaseOrders\Support\Limits;
use ShopperPurchaseOrders\Support\Stock;

final class Form extends SlideOverComponent implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use WithoutUrlPagination;
    use WithPagination;

    private const int PAGE_SIZE = 10;

    private const int MAX_LINES = 100;

    private const array DETAILS_FIELDS = ['supplier_id', 'inventory_id', 'currency', 'expected_at', 'supplier_reference'];

    #[Locked]
    public ?int $orderId = null;

    #[Locked]
    public ?int $version = null;

    #[Locked]
    public ?string $orderNumber = null;

    #[Locked]
    public ?string $savedCurrency = null;

    public array $data = [
        'supplier_id' => '',
        'inventory_id' => '',
        'currency' => 'USD',
        'expected_at' => null,
        'supplier_reference' => '',
        'notes' => '',
        'items' => [],
    ];

    public ?string $productSelection = null;

    public static function panelMaxWidth(): string
    {
        return '5xl';
    }

    public static function destroyOnClose(): bool
    {
        return true;
    }

    public static function closePanelOnClickAway(): bool
    {
        return false;
    }

    public function mount(?int $orderId = null): void
    {
        Access::authorize($orderId ? 'edit' : 'add');
        $this->orderId = $orderId;
        if ($orderId) {
            $order = PurchaseOrder::query()->with('items')->findOrFail($orderId);
            abort_unless($order->state === OrderState::Draft, 403);
            $this->version = $order->version;
            $this->orderNumber = $order->number;
            $this->savedCurrency = $order->currency;
            $this->data = array_merge(
                $this->data,
                $order->only([
                    'supplier_id',
                    'inventory_id',
                    'currency',
                    'supplier_reference',
                    'notes',
                ]),
            );
            $this->data['expected_at'] = $order->expected_at?->format('Y-m-d');
            $this->data['items'] = $order->items
                ->map(
                    fn ($item) => $item->only([
                        'stockable_kind',
                        'stockable_id',
                        'ordered_quantity',
                        'unit_cost_minor',
                    ]),
                )
                ->all();
        }

        if ($orderId === null) {
            $currencies = Currencies::codes();
            $defaultCurrency = shopper_currency();
            $this->data['currency'] = in_array($defaultCurrency, $currencies, true) ? $defaultCurrency : ($currencies[0] ?? '');
        }

        $this->costsForm->fill($this->data);
    }

    public function detailsForm(Schema $schema): Schema
    {
        $options = $this->draftOptions;

        return $schema
            ->statePath('data')
            ->columns(['sm' => 2])
            ->components([
                Select::make('supplier_id')
                    ->label('Supplier')
                    ->options($options['suppliers']->pluck('name', 'id')->all())
                    ->placeholder('Select enabled supplier')
                    ->native(false)
                    ->searchable()
                    ->required(),
                Select::make('inventory_id')
                    ->label('Destination inventory')
                    ->options($options['inventories']->pluck('name', 'id')->all())
                    ->placeholder('Select destination')
                    ->native(false)
                    ->searchable()
                    ->required(),
                Select::make('currency')
                    ->options(array_combine($options['currencies'], $options['currencies']))
                    ->placeholder('Select currency')
                    ->native(false)
                    ->searchable()
                    ->required()
                    ->live(),
                DatePicker::make('expected_at')
                    ->label('Expected delivery')
                    ->format('Y-m-d')
                    ->native(false)
                    ->closeOnDateSelection(),
                TextInput::make('supplier_reference')
                    ->maxLength(255),
            ]);
    }

    public function updatedDataExpectedAt(mixed $value): void
    {
        // The JavaScript date picker sends a midnight timestamp for date-only fields.
        if (is_string($value) && preg_match('/\A\d{4}-\d{2}-\d{2} 00:00:00\z/', $value)) {
            $this->data['expected_at'] = substr($value, 0, 10);
        }
    }

    public function productPicker(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('productSelection')
                ->label('Product')
                ->placeholder('Add product')
                ->native(false)
                ->searchable()
                ->allowHtml()
                ->optionsLimit(20)
                ->searchDebounce(300)
                ->searchPrompt('Search by name or SKU')
                ->noSearchResultsMessage('No matching products found.')
                ->loadingMessage('Loading products…')
                ->options(fn (): array => $this->searchProducts())
                ->getSearchResultsUsing(fn (string $search): array => $this->searchProducts($search))
                ->getOptionLabelUsing(fn (): ?string => null)
                ->disabled(fn (): bool => count($this->data['items']) >= self::MAX_LINES)
                ->dehydrated(false)
                ->live()
                ->afterStateUpdated(function (?string $state): void {
                    if (filled($state)) {
                        $this->addProduct($state);
                    }
                }),
        ]);
    }

    public function searchProducts(string $search = ''): array
    {
        Access::authorize($this->orderId ? 'edit' : 'add');

        return app(Catalog::class)->options($search, $this->data['items']);
    }

    public function addProduct(string $identity): void
    {
        Access::authorize($this->orderId ? 'edit' : 'add');
        $this->productSelection = null;
        Validator::make(['productSelection' => $identity, 'currency' => $this->data['currency']], [
            'productSelection' => ['required', 'regex:/^(product|variant):[1-9][0-9]*$/'],
            'currency' => ['required', Currencies::rule($this->savedCurrency)],
        ])->validate();
        if (in_array($identity, $this->lineIdentities(), true)) {
            throw ValidationException::withMessages(['productSelection' => 'This product is already in the order.']);
        }
        if (count($this->data['items']) >= self::MAX_LINES) {
            throw ValidationException::withMessages(['productSelection' => 'A purchase order can have up to 100 product lines.']);
        }

        [$kind, $id] = explode(':', $identity, 2);
        $record = app(Stock::class)->resolve($kind, (int) $id);
        $catalog = app(Catalog::class);
        $record->loadMissing($catalog->relations($record, $kind));
        $cost = $catalog->cost($record, $this->data['currency']);
        $this->data['items'][] = [
            'stockable_kind' => $kind,
            'stockable_id' => (int) $id,
            'ordered_quantity' => 1,
            'unit_cost_minor' => $cost === null ? null : (is_no_division_currency($this->data['currency']) ? $cost : $cost / 100),
        ];
        $this->resetValidation('productSelection');
        $this->setPage(max(1, (int) ceil(count($this->data['items']) / self::PAGE_SIZE)), 'draftLinesPage');
    }

    public function changeQuantity(int $index, int $delta): void
    {
        Access::authorize($this->orderId ? 'edit' : 'add');
        abort_unless(isset($this->data['items'][$index]) && in_array($delta, [-1, 1], true), 422);
        $path = "data.items.{$index}.ordered_quantity";
        $this->validateOnly($path, [$path => Purchasing::draftRules()['items.*.ordered_quantity']]);
        $quantity = (int) $this->data['items'][$index]['ordered_quantity'];
        $this->data['items'][$index]['ordered_quantity'] = max(1, min(Limits::MAX_QUANTITY, $quantity + $delta));
    }

    public function refreshPricing(int $index): void
    {
        Access::authorize($this->orderId ? 'edit' : 'add');
        abort_unless(isset($this->data['items'][$index]), 422);
        $path = "data.items.{$index}.unit_cost_minor";
        $this->validateOnly($path, [$path => $this->costRules()]);
    }

    public function removeLine(int $index): void
    {
        Access::authorize($this->orderId ? 'edit' : 'add');
        unset($this->data['items'][$index]);
        $this->resetValidation();
    }

    private function lineIdentities(): array
    {
        return array_map(
            fn (array $line): string => ($line['stockable_kind'] ?? '').':'.($line['stockable_id'] ?? ''),
            $this->data['items'],
        );
    }

    public function costsForm(Schema $schema): Schema
    {
        $currency = $this->data['currency'];
        $precision = is_no_division_currency($currency) ? 0 : 2;

        return $schema
            ->statePath('data')
            ->components(array_map(
                fn (int $index): MoneyInput => MoneyInput::make("items.{$index}.unit_cost_minor")
                    ->label(fn (): string => 'Unit cost for '.$this->lineRecords[$index]['name'])
                    ->hiddenLabel()
                    ->currency($currency)
                    ->currencyMask(precision: $precision)
                    ->suffix($currency, isInline: true)
                    ->minValue(0)
                    ->maxValue(Limits::MAX_TOTAL_MINOR / (10 ** $precision))
                    ->rules($this->costRules())
                    ->live(false)
                    ->extraInputAttributes([
                        'x-on:blur' => "updateInput(); \$wire.refreshPricing({$index})",
                        'class' => 'text-right',
                    ]),
                array_keys($this->data['items']),
            ));
    }

    private function draftInput(): array
    {
        $this->cacheSchema('costsForm');

        // MoneyInput holds display amounts; only its dehydrated state contains minor units.
        return array_replace_recursive($this->data, $this->costsForm->getState());
    }

    public function notesForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Textarea::make('notes')
                    ->label('Internal notes')
                    ->hiddenLabel()
                    ->rows(3)
                    ->maxLength(5000),
            ]);
    }

    public function draftWizard(Schema $schema): Schema
    {
        $cancel = Blade::render('<x-filament::button type="button" color="gray" wire:click="$dispatch(\'closePanel\')">Cancel</x-filament::button>');

        return $schema->components([
            SlideOverWizard::make($this->wizardSteps())
                ->label('Purchase order steps')
                ->previousAction(fn (Action $action): Action => $action->label('Back'))
                ->extraAlpineAttributes([
                    'x-on:draft-wizard-error.window' => 'if ($event.detail.editor === $wire.$id) goToStep($event.detail.step)',
                ])
                ->cancelAction(new HtmlString($cancel))
                ->submitAction(new HtmlString(Blade::render(<<<'BLADE'
                    <x-filament::button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save">
                        Save draft
                    </x-filament::button>
                    BLADE))),
        ]);
    }

    private function wizardSteps(): array
    {
        return [
            Step::make('Details')->key('details')->icon('heroicon-o-document-text')
                ->afterValidation(fn () => $this->validateDraftStep('details'))
                ->schema([
                    SchemaView::make('purchase-orders::partials.draft-details'),
                ]),
            Step::make('Products')->key('products')->icon('heroicon-o-cube')
                ->afterValidation(fn () => $this->validateDraftStep('products'))
                ->schema([
                    SchemaView::make('purchase-orders::partials.draft-lines')
                        ->viewData(fn (): array => ['data' => $this->data, 'lines' => $this->draftLines()]),
                ]),
            Step::make('Pricing')->key('pricing')->icon('heroicon-o-currency-dollar')
                ->afterValidation(fn () => $this->validateDraftStep('pricing'))
                ->schema([
                    SchemaView::make('purchase-orders::partials.draft-pricing')
                        ->viewData(fn (): array => [
                            'lines' => $this->pricingLines('pricingPage'),
                            'summary' => $this->pricingSummary,
                            'readOnly' => false,
                        ]),
                ]),
            Step::make('Review')->key('review')->icon('heroicon-o-clipboard-document-check')
                ->schema([
                    SchemaView::make('purchase-orders::partials.draft-review')
                        ->viewData(fn (): array => [
                            ...$this->draftOptions,
                            'data' => $this->data,
                            'lines' => $this->pricingLines('reviewPage'),
                            'summary' => $this->pricingSummary,
                        ]),
                ]),
        ];
    }

    private function validateDraftStep(string $step): void
    {
        Access::authorize($this->orderId ? 'edit' : 'add');
        try {
            match ($step) {
                'details' => Validator::make($this->data, Arr::only(Purchasing::draftRules($this->savedCurrency), self::DETAILS_FIELDS))->validate(),
                'products' => $this->validateProducts(),
                'pricing' => $this->validatePricing(),
            };
        } catch (ValidationException $exception) {
            $this->showValidationError(array_key_first($exception->errors()));
            throw $exception;
        }
        $this->resetValidation();
    }

    private function validateProducts(): void
    {
        $rules = array_filter(Purchasing::draftRules(), fn (string $field): bool => ($field === 'items' || str_starts_with($field, 'items.'))
            && ! str_ends_with($field, '.unit_cost_minor'), ARRAY_FILTER_USE_KEY);
        Validator::make($this->data, $rules)->validate();
        $seen = [];
        foreach ($this->data['items'] as $index => $line) {
            $identity = $line['stockable_kind'].':'.$line['stockable_id'];
            if (in_array($identity, $seen, true)) {
                throw ValidationException::withMessages(["items.{$index}.stockable_id" => 'This product is already in the order.']);
            }
            $seen[] = $identity;
            try {
                app(Stock::class)->resolve($line['stockable_kind'], (int) $line['stockable_id']);
            } catch (ValidationException $exception) {
                throw ValidationException::withMessages(["items.{$index}.stockable_id" => $exception->validator->errors()->first()]);
            }
        }
    }

    private function validatePricing(): array
    {
        $this->validateProducts();
        $input = $this->draftInput();
        Validator::make($input, Arr::only(Purchasing::draftRules(), ['items.*.unit_cost_minor']))->validate();
        if ($this->pricingSummary['over_limit']) {
            throw ValidationException::withMessages(['pricing' => 'Merchandise subtotal exceeds the supported amount.']);
        }

        return $input;
    }

    private function showValidationError(?string $field): void
    {
        $field = preg_replace('/^data\./', '', $field ?? '');
        $step = match (true) {
            in_array($field, self::DETAILS_FIELDS, true) => 'details',
            $field === 'pricing', str_ends_with($field, '.unit_cost_minor') => 'pricing',
            $field === 'items', str_starts_with($field, 'items.') => 'products',
            default => 'review',
        };
        if (preg_match('/^items\.(\d+)\./', $field, $matches)) {
            $position = array_search((int) $matches[1], array_keys($this->data['items']), true);
            if ($position !== false) {
                $pageName = $step === 'pricing' ? 'pricingPage' : 'draftLinesPage';
                $this->setPage(intdiv($position, self::PAGE_SIZE) + 1, $pageName);
            }
        }
        $this->dispatch('draft-wizard-error', step: 'draftWizard.'.$step, editor: $this->getId());
    }

    public function save(): mixed
    {
        $user = Access::authorize($this->orderId ? 'edit' : 'add');
        try {
            Validator::make($this->data, Arr::only(Purchasing::draftRules($this->savedCurrency), self::DETAILS_FIELDS))->validate();
            $order = app(Purchasing::class)->saveDraft($this->validatePricing(), $this->orderId, $this->version);
        } catch (ValidationException $exception) {
            $this->showValidationError(array_key_first($exception->errors()));

            throw $exception;
        }

        if ($this->orderId === null) {
            if ($user->can('read_purchase_orders')) {
                return $this->redirectRoute('shopper.purchase-orders.show', ['orderId' => $order->id]);
            }

            return $this->redirectRoute('shopper.purchase-orders.index');
        }

        Notification::make()->title('Draft updated')->success()->send();
        $this->closePanelWithEvents([
            Detail::class => ['purchase-order-draft-saved', ['orderId' => $order->id]],
        ]);

        return null;
    }

    public function render(): mixed
    {
        Access::authorize($this->orderId ? 'edit' : 'add');
        $this->cacheSchema('costsForm');

        return view('purchase-orders::draft-panel');
    }

    #[Computed]
    public function draftOptions(): array
    {
        $supplier = config('shopper.models.supplier');
        $inventory = config('shopper.models.inventory');

        return [
            'currencies' => Currencies::codes($this->savedCurrency),
            'suppliers' => $supplier::query()->where('is_enabled', true)->orderBy('name')->get(),
            'inventories' => $inventory::query()->orderBy('name')->get(),
        ];
    }

    #[Computed]
    public function lineRecords(): array
    {
        $items = collect($this->data['items']);
        $catalog = app(Catalog::class);
        $models = [];
        foreach (['product', 'variant'] as $kind) {
            $ids = $items->where('stockable_kind', $kind)->pluck('stockable_id');
            $model = config('shopper.models.'.$kind);
            $models[$kind] = $ids->isEmpty()
                ? collect()
                : $model::query()->with($catalog->relations(new $model, $kind, withPrices: false))
                    ->whereKey($ids->all())->get()->keyBy(fn ($record) => $record->getKey());
        }

        $lines = $items->map(function (array $line, int $index) use ($models, $catalog): array {
            $record = ($models[$line['stockable_kind'] ?? ''] ?? collect())->get($line['stockable_id'] ?? 0);

            return [
                'index' => $index,
                'name' => $record ? $catalog->name($record, $line['stockable_kind']) : 'Unavailable item',
                'thumbnail' => $catalog->thumbnail($record, $line['stockable_kind']),
                'quantity' => is_scalar($line['ordered_quantity'] ?? null) ? $line['ordered_quantity'] : '',
                'sku' => $record?->sku,
            ];
        });

        return $lines->all();
    }

    private function draftLines(): LengthAwarePaginator
    {
        return $this->paginateLines(collect($this->lineRecords), 'draftLinesPage');
    }

    private function pricingLines(string $pageName): LengthAwarePaginator
    {
        return $this->paginateLines(collect($this->pricingSummary['lines']), $pageName);
    }

    private function paginateLines(Collection $lines, string $pageName): LengthAwarePaginator
    {
        $lastPage = max(1, (int) ceil($lines->count() / self::PAGE_SIZE));
        $page = min($this->getPage($pageName), $lastPage);
        if ($page !== $this->getPage($pageName)) {
            $this->setPage($page, $pageName);
        }

        return new LengthAwarePaginator(
            $lines->forPage($page, self::PAGE_SIZE),
            $lines->count(),
            self::PAGE_SIZE,
            $page,
            ['pageName' => $pageName],
        );
    }

    private function costRules(): array
    {
        $precision = is_no_division_currency($this->data['currency']) ? 0 : 2;

        return ['required', 'numeric', 'min:0', 'max:'.(Limits::MAX_TOTAL_MINOR / (10 ** $precision)), 'decimal:0,'.$precision];
    }

    private function previewCost(mixed $amount): ?int
    {
        if (! is_scalar($amount) || Validator::make(['amount' => $amount], ['amount' => $this->costRules()])->fails()) {
            return null;
        }
        $precision = is_no_division_currency($this->data['currency']) ? 0 : 2;
        [$whole, $fraction] = array_pad(explode('.', (string) $amount, 2), 2, '');

        return ((int) $whole * (10 ** $precision)) + (int) str_pad($fraction, $precision, '0');
    }

    #[Computed]
    public function pricingSummary(): array
    {
        $limit = Limits::MAX_TOTAL_MINOR;
        $subtotal = 0;
        $totalQuantity = 0;
        $allQuantitiesValid = true;
        $complete = count($this->data['items']) > 0;
        $overLimit = false;
        $lines = [];
        foreach ($this->lineRecords as $index => $line) {
            $item = $this->data['items'][$index];
            $cost = $this->previewCost($item['unit_cost_minor'] ?? null);
            $quantity = $item['ordered_quantity'] ?? null;
            $validQuantity = is_scalar($quantity) && Validator::make(['quantity' => $quantity], ['quantity' => Purchasing::draftRules()['items.*.ordered_quantity']])->passes();
            $totalQuantity += $validQuantity ? (int) $quantity : 0;
            $allQuantitiesValid = $allQuantitiesValid && $validQuantity;
            $total = null;
            if ($cost !== null && $validQuantity) {
                if ($cost > intdiv($limit, (int) $quantity)) {
                    $overLimit = true;
                } else {
                    $total = $cost * (int) $quantity;
                    if ($total > $limit - $subtotal) {
                        $overLimit = true;
                    } else {
                        $subtotal += $total;
                    }
                }
            }
            $complete = $complete && $total !== null;
            $lines[$index] = [...$line,
                'cost_minor' => $cost,
                'cost' => $cost === null ? '—' : shopper_money_format($cost, $this->data['currency']),
                'total_minor' => $total,
            ];
        }

        return [
            'lines' => $lines,
            'total_quantity' => $allQuantitiesValid ? $totalQuantity : null,
            'subtotal_minor' => $complete && ! $overLimit ? $subtotal : null,
            'subtotal' => $complete && ! $overLimit ? shopper_money_format($subtotal, $this->data['currency']) : '—',
            'over_limit' => $overLimit,
        ];
    }
}
