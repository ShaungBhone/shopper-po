<?php

declare(strict_types=1);

namespace ShopperPurchaseOrders\Livewire;

use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Laravelcm\LivewireSlideOvers\SlideOverComponent;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\WithoutUrlPagination;
use Livewire\WithPagination;
use Shopper\Components\SlideOverWizard;
use ShopperPurchaseOrders\Actions\Purchasing;
use ShopperPurchaseOrders\Enums\OrderState;
use ShopperPurchaseOrders\Models\PurchaseOrder;
use ShopperPurchaseOrders\Support\Access;
use ShopperPurchaseOrders\Support\Stock;

#[Layout(name: 'shopper::components.layouts.app')]
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

    public array $data = [
        'supplier_id' => '',
        'inventory_id' => '',
        'currency' => 'USD',
        'expected_at' => null,
        'supplier_reference' => '',
        'notes' => '',
        'items' => [],
    ];

    #[Locked]
    public bool $choosingProducts = false;

    #[Locked]
    public bool $returningFromCatalog = false;

    public string $catalogSearch = '';

    public string $catalogKind = 'product';

    public array $selectedProducts = [];

    public string $lineSearch = '';

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
    }

    public function openCatalog(): void
    {
        Access::authorize($this->orderId ? 'edit' : 'add');
        $this->reset('catalogKind', 'catalogSearch', 'selectedProducts');
        $this->resetPage('catalogPage');
        $this->resetValidation();
        $this->choosingProducts = true;
    }

    public function closeCatalog(): void
    {
        $this->choosingProducts = false;
        $this->returningFromCatalog = true;
        $this->resetValidation();
    }

    public function updatedCatalogSearch(): void
    {
        $this->resetPage('catalogPage');
    }

    public function updatedCatalogKind(): void
    {
        $this->resetPage('catalogPage');
    }

    public function updatedLineSearch(): void
    {
        $this->resetPage('draftLinesPage');
    }

    public function addSelectedProducts(): void
    {
        Access::authorize($this->orderId ? 'edit' : 'add');
        abort_unless($this->choosingProducts, 422);

        $this->validate([
            'selectedProducts' => ['required', 'array', 'max:'.self::MAX_LINES],
            'selectedProducts.*' => ['string', 'regex:/^(product|variant):[1-9][0-9]*$/', 'distinct'],
        ], ['selectedProducts.required' => 'Select at least one product.']);

        $existing = $this->lineIdentities();
        $selection = array_diff($this->selectedProducts, $existing);
        if (count($existing) + count($selection) > self::MAX_LINES) {
            throw ValidationException::withMessages([
                'selectedProducts' => 'A purchase order can have up to '.self::MAX_LINES.' product lines.',
            ]);
        }

        $newLines = [];
        foreach ($selection as $identity) {
            [$kind, $id] = explode(':', $identity, 2);
            app(Stock::class)->resolve($kind, (int) $id);
            $newLines[] = [
                'stockable_kind' => $kind,
                'stockable_id' => (int) $id,
                'ordered_quantity' => 1,
                'unit_cost_minor' => 0,
            ];
        }

        foreach ($newLines as $line) {
            $this->data['items'][] = $line;
        }

        $this->reset('lineSearch', 'selectedProducts');
        $this->setPage(max(1, (int) ceil(count($this->data['items']) / self::PAGE_SIZE)), 'draftLinesPage');
        $this->closeCatalog();
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

    public function draftWizard(Schema $schema): Schema
    {
        return $schema->components([
            SlideOverWizard::make([
                Step::make('Details')->key('details')->icon('heroicon-o-document-text')
                    ->afterValidation(fn () => $this->validateDraftStep(self::DETAILS_FIELDS))
                    ->schema([
                        SchemaView::make('purchase-orders::partials.draft-details')
                            ->viewData(fn (): array => $this->draftOptions),
                    ]),
                Step::make('Products')->key('products')->icon('heroicon-o-cube')
                    ->afterValidation(fn () => $this->validateDraftStep(array_filter(
                        array_keys(Purchasing::draftRules()),
                        fn (string $field): bool => $field === 'items' || str_starts_with($field, 'items.'),
                    )))
                    ->schema([
                        SchemaView::make('purchase-orders::partials.draft-wizard-products')
                            ->viewData(fn (): array => [
                                'choosingProducts' => $this->choosingProducts,
                                'returningFromCatalog' => $this->returningFromCatalog,
                                'data' => $this->data,
                                'catalogKind' => $this->catalogKind,
                                'selectedProducts' => $this->selectedProducts,
                                ...($this->choosingProducts ? $this->catalogData() : ['lines' => $this->draftLines()]),
                            ]),
                    ]),
                Step::make('Review')->key('review')->icon('heroicon-o-clipboard-document-check')
                    ->schema([
                        SchemaView::make('purchase-orders::partials.draft-review')
                            ->viewData(fn (): array => [...$this->draftOptions, 'data' => $this->data]),
                    ]),
            ])
                ->label('Edit draft steps')
                ->nextAction(fn (Action $action): Action => $action->disabled($this->choosingProducts))
                ->previousAction(fn (Action $action): Action => $action->label('Back')->disabled($this->choosingProducts))
                ->extraAlpineAttributes([
                    'x-on:draft-wizard-error.window' => 'if ($event.detail.editor === $wire.$id) goToStep($event.detail.step)',
                ])
                ->cancelAction(new HtmlString(Blade::render(<<<'BLADE'
                    <x-filament::button type="button" color="gray" wire:click="$dispatch('closePanel')">
                        Cancel
                    </x-filament::button>
                    BLADE)))
                ->submitAction(new HtmlString(Blade::render(<<<'BLADE'
                    <x-filament::button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save">
                        Save draft
                    </x-filament::button>
                    BLADE))),
        ]);
    }

    private function validateDraftStep(array $fields): void
    {
        Access::authorize('edit');
        try {
            Validator::make($this->data, Arr::only(Purchasing::draftRules(), $fields))->validate();
        } catch (ValidationException $exception) {
            $this->focusInvalidLine(array_key_first($exception->errors()));

            throw $exception;
        }

        $this->resetValidation();
    }

    private function focusInvalidLine(?string $field): void
    {
        if (! preg_match('/^items\.(\d+)\./', $field ?? '', $matches)) {
            return;
        }

        $position = array_search((int) $matches[1], array_keys($this->data['items']), true);
        if ($position !== false) {
            $this->reset('lineSearch');
            $this->setPage(intdiv($position, self::PAGE_SIZE) + 1, 'draftLinesPage');
        }
    }

    public function save(): mixed
    {
        try {
            $order = app(Purchasing::class)->saveDraft($this->data, $this->orderId, $this->version);
        } catch (ValidationException $exception) {
            $field = array_key_first($exception->errors());
            $this->focusInvalidLine($field);

            if ($this->orderId !== null) {
                $step = match (true) {
                    in_array($field, self::DETAILS_FIELDS, true) => 'details',
                    str_starts_with($field ?? '', 'items') => 'products',
                    default => 'review',
                };
                $this->dispatch('draft-wizard-error', step: 'draftWizard.'.$step, editor: $this->getId());
            }

            throw $exception;
        }

        if ($this->orderId === null) {
            return $this->redirectRoute('shopper.purchase-orders.show', ['orderId' => $order->id]);
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
        if ($this->orderId !== null) {
            return view('purchase-orders::edit-draft')->title('Edit purchase order');
        }

        return view(
            'purchase-orders::form',
            $this->choosingProducts ? $this->catalogData() : [...$this->draftOptions, 'lines' => $this->draftLines()],
        )->title($this->choosingProducts ? 'Add products' : 'New purchase order');
    }

    #[Computed]
    public function draftOptions(): array
    {
        $supplier = config('shopper.models.supplier');
        $inventory = config('shopper.models.inventory');

        return [
            'suppliers' => $supplier::query()->where('is_enabled', true)->orderBy('name')->get(),
            'inventories' => $inventory::query()->orderBy('name')->get(),
        ];
    }

    private function catalogData(): array
    {
        return [
            'catalog' => $this->catalog(),
            'existingProducts' => $this->lineIdentities(),
        ];
    }

    private function catalog(): mixed
    {
        $kind = $this->catalogKind === 'variant' ? 'variant' : 'product';
        $model = config('shopper.models.'.$kind);
        $search = trim(mb_substr($this->catalogSearch, 0, 100));
        $query = $model::query();
        if ($kind === 'product') {
            $query->where('type', 'standard');
        } else {
            $query->with('product');
        }

        if ($search !== '') {
            $query->where(function (Builder $query) use ($kind, $search): void {
                $query->where('sku', 'like', '%'.$search.'%');
                if ($kind === 'product') {
                    $query->orWhere('name', 'like', '%'.$search.'%');
                } else {
                    $query->orWhereHas('product', fn (Builder $product) => $product->where('name', 'like', '%'.$search.'%'));
                }
            });
        }

        return $query->orderBy($kind === 'product' ? 'name' : 'sku')
            ->orderBy($query->getModel()->getKeyName())
            ->simplePaginate(self::PAGE_SIZE, pageName: 'catalogPage');
    }

    private function draftLines(): LengthAwarePaginator
    {
        $items = collect($this->data['items']);
        $models = [];
        foreach (['product', 'variant'] as $kind) {
            $ids = $items->where('stockable_kind', $kind)->pluck('stockable_id');
            $model = config('shopper.models.'.$kind);
            $models[$kind] = $ids->isEmpty()
                ? collect()
                : $model::query()->when($kind === 'variant', fn (Builder $query) => $query->with('product'))
                    ->whereKey($ids->all())->get()->keyBy(fn ($record) => $record->getKey());
        }

        $search = mb_strtolower(trim(mb_substr($this->lineSearch, 0, 100)));
        $lines = $items->map(function (array $line, int $index) use ($models): array {
            $record = ($models[$line['stockable_kind'] ?? ''] ?? collect())->get($line['stockable_id'] ?? 0);

            return [
                'index' => $index,
                'name' => $record?->name ?? $record?->product?->name ?? 'Unavailable item',
                'sku' => $record?->sku,
            ];
        })->filter(fn (array $line): bool => $search === ''
            || str_contains(mb_strtolower($line['name'].' '.$line['sku']), $search));

        $lastPage = max(1, (int) ceil($lines->count() / self::PAGE_SIZE));
        $page = min($this->getPage('draftLinesPage'), $lastPage);
        if ($page !== $this->getPage('draftLinesPage')) {
            $this->setPage($page, 'draftLinesPage');
        }

        return new LengthAwarePaginator(
            $lines->forPage($page, self::PAGE_SIZE),
            $lines->count(),
            self::PAGE_SIZE,
            $page,
            ['pageName' => 'draftLinesPage'],
        );
    }
}
