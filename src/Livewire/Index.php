<?php

declare(strict_types=1);

namespace ShopperPurchaseOrders\Livewire;

use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Concerns\HasTabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Shopper\Livewire\Pages\AbstractPageComponent;
use ShopperPurchaseOrders\Enums\OrderState;
use ShopperPurchaseOrders\Models\PurchaseOrder;
use ShopperPurchaseOrders\Support\Access;

final class Index extends AbstractPageComponent implements HasActions, HasSchemas, HasTable
{
    use HasTabs;
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public function mount(): void
    {
        Access::authorize('browse');
        $this->loadDefaultActiveTab();
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        Access::authorize('browse');

        $counts = PurchaseOrder::query()
            ->toBase()
            ->selectRaw('state, COUNT(*) as total')
            ->groupBy('state')
            ->pluck('total', 'state');

        $tabs = [
            'all' => Tab::make('All')
                ->icon('untitledui-layers-three')
                ->badge((int) $counts->sum()),
        ];

        foreach (OrderState::cases() as $state) {
            $tabs[$state->value] = Tab::make($state->name)
                ->icon(match ($state) {
                    OrderState::Draft => 'heroicon-o-pencil-square',
                    OrderState::Ordered => 'heroicon-o-truck',
                    OrderState::Closed => 'heroicon-o-check-circle',
                    OrderState::Cancelled => 'heroicon-o-x-circle',
                })
                ->badge((int) $counts->get($state->value, 0))
                ->badgeColor($state->color())
                ->query(fn (Builder $query): Builder => $query->where('state', $state->value));
        }

        return $tabs;
    }

    public function table(Table $table): Table
    {
        $canRead = Access::authorize('browse')->can('read_purchase_orders');

        return $table
            ->query(PurchaseOrder::query()->with('items'))
            ->modifyQueryUsing($this->modifyQueryWithActiveTab(...))
            ->defaultSort('id', 'desc')
            ->defaultPaginationPageOption(20)
            ->paginated([10, 20, 50])
            ->searchPlaceholder('Search PO number')
            ->recordUrl(fn (PurchaseOrder $record): ?string => $canRead
                ? route('shopper.purchase-orders.show', ['orderId' => $record->id])
                : null)
            ->columns([
                TextColumn::make('number')
                    ->label('Purchase order')
                    ->description(fn (PurchaseOrder $record): ?string => $record->supplier_snapshot['name'] ?? null)
                    ->searchable(),
                TextColumn::make('state')
                    ->label('Order state')
                    ->badge()
                    ->formatStateUsing(fn (OrderState $state): string => $state->name)
                    ->color(fn (OrderState $state): string => $state->color()),
                TextColumn::make('receiving_progress')
                    ->label('Receiving')
                    ->state(fn (PurchaseOrder $record): string => str($record->receivingProgress())->replace('_', ' ')->ucfirst()->toString())
                    ->badge()
                    ->color(fn (PurchaseOrder $record): string => match ($record->receivingProgress()) {
                        'fully_received' => 'success',
                        'partially_received' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('outstanding')
                    ->state(fn (PurchaseOrder $record): int => $record->outstandingQuantity())
                    ->numeric(),
                TextColumn::make('expected_at')
                    ->label('Expected')
                    ->date()
                    ->placeholder('—'),
                TextColumn::make('subtotal')
                    ->label('Merchandise subtotal')
                    ->state(fn (PurchaseOrder $record): string => $record->currency.' '.number_format(
                        $record->totalMinor() / 10 ** $record->currency_exponent,
                        $record->currency_exponent,
                    )),
            ])
            ->filters([
                SelectFilter::make('supplier_id')
                    ->label('Supplier')
                    ->options(fn (): array => PurchaseOrder::query()
                        ->whereIn('id', PurchaseOrder::query()
                            ->selectRaw('MAX(id)')
                            ->whereNotNull('supplier_id')
                            ->groupBy('supplier_id'))
                        ->get(['supplier_id', 'supplier_snapshot'])
                        ->mapWithKeys(fn (PurchaseOrder $order): array => [
                            $order->supplier_id => $order->supplier_snapshot['name'] ?? 'Supplier #'.$order->supplier_id,
                        ])
                        ->sort(SORT_NATURAL | SORT_FLAG_CASE)
                        ->all())
                    ->searchable(),
                SelectFilter::make('inventory_id')
                    ->label('Delivery destination')
                    ->options(fn (): array => config('shopper.models.inventory')::query()
                        ->whereIn('id', PurchaseOrder::query()->select('inventory_id'))
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable(),
                SelectFilter::make('receiving_progress')
                    ->label('Receiving')
                    ->options([
                        'not_received' => 'Not received',
                        'partially_received' => 'Partially received',
                        'fully_received' => 'Fully received',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $accepted = fn (Builder $items): Builder => $items->where('accepted_quantity', '>', 0);
                        $incomplete = fn (Builder $items): Builder => $items->whereColumn('accepted_quantity', '<>', 'ordered_quantity');

                        return match ($data['value'] ?? null) {
                            'not_received' => $query->whereDoesntHave('items', $accepted),
                            'partially_received' => $query->whereHas('items', $accepted)->whereHas('items', $incomplete),
                            'fully_received' => $query->whereHas('items', $accepted)->whereDoesntHave('items', $incomplete),
                            default => $query,
                        };
                    }),
                Filter::make('expected_at')
                    ->label('Expected delivery')
                    ->schema([
                        DatePicker::make('from')->label('Expected from')->native(false),
                        DatePicker::make('until')->label('Expected through')->native(false),
                    ])
                    ->columns()
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('expected_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('expected_at', '<=', $date)))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        foreach (['from' => 'Expected from', 'until' => 'Expected through'] as $field => $label) {
                            if (filled($data[$field] ?? null)) {
                                $indicators[] = Indicator::make($label.' '.Carbon::parse($data[$field])->toFormattedDateString())
                                    ->removeField($field);
                            }
                        }

                        return $indicators;
                    }),
            ])
            ->filtersLayout(FiltersLayout::Modal)
            ->filtersFormColumns(2)
            ->emptyStateHeading('No purchase orders found');
    }

    public function render(): View
    {
        Access::authorize('browse');

        return view('purchase-orders::index')->title('Purchase orders');
    }
}
