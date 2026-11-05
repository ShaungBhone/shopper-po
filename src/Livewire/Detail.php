<?php

declare(strict_types=1);

namespace ShopperPurchaseOrders\Livewire;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\WithPagination;
use Shopper\Components\Form\MoneyInput;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Livewire\Pages\AbstractPageComponent;
use ShopperPurchaseOrders\Actions\Purchasing;
use ShopperPurchaseOrders\Enums\OrderState;
use ShopperPurchaseOrders\Models\PurchaseOrder;
use ShopperPurchaseOrders\Models\PurchaseOrderItem;
use ShopperPurchaseOrders\Models\ReceiptItem;
use ShopperPurchaseOrders\Support\Access;
use Spatie\MediaLibrary\HasMedia;

final class Detail extends AbstractPageComponent implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use WithPagination;

    public int $productsPerPage = 3;

    #[Locked]
    public int $orderId;

    #[Locked]
    public int $version;

    #[Locked]
    public string $correctionKey;

    public function mount(int $orderId): void
    {
        Access::authorize('read');
        $this->orderId = $orderId;
        $this->version = $this->order->version;
        $this->correctionKey = (string) Str::uuid();
    }

    #[Computed]
    public function order(): PurchaseOrder
    {
        Access::authorize('read');

        return PurchaseOrder::query()
            ->with(['items', 'receipts.items.corrections', 'history'])
            ->findOrFail($this->orderId);
    }

    public function auditHistoryAction(): Action
    {
        return Action::make('auditHistory')
            ->label('Audit history')
            ->color('gray')
            ->icon('heroicon-o-clock')
            ->authorize(fn (): bool => $this->can('read'))
            ->slideOver()
            ->modalWidth(Width::Large)
            ->modalHeading('Audit history')
            ->modalDescription(fn (): string => $this->order->number)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalContent(function (): View {
                $order = $this->order;
                $reader = Access::authorize('read');
                $actorIds = $order->history->pluck('actor_id')->filter()->unique()->all();
                $actors = $reader instanceof Model && $actorIds !== []
                    ? $reader->newQuery()->whereKey($actorIds)->get()->keyBy(fn (Model $actor) => $actor->getKey())
                    : collect();

                return view('purchase-orders::partials.audit-history', [
                    'events' => $order->history->reverse(),
                    'actors' => $actors,
                    'items' => $order->items->keyBy('id'),
                    'currency' => $order->currency,
                    'currencyExponent' => $order->currency_exponent,
                ]);
            });
    }

    public function placeAction(): Action
    {
        return Action::make('place')
            ->label('Mark ordered')
            ->authorize(fn (): bool => $this->can('place'))
            ->visible(fn (): bool => $this->order->state === OrderState::Draft)
            ->requiresConfirmation()
            ->slideOver()
            ->modalWidth(Width::ExtraLarge)
            ->modalAlignment(Alignment::Start)
            ->modalFooterActionsAlignment(Alignment::End)
            ->modalDescription('Record the supplier commitment after you send the purchase order.')
            ->modalSubmitActionLabel('Mark ordered')
            ->action(fn (Purchasing $purchasing) => $this->applyChange(
                fn () => $purchasing->place($this->orderId, $this->version),
            ));
    }

    public function closeOrderAction(): Action
    {
        return $this->reasonAction('closeOrder', 'Close purchase order', 'close')
            ->visible(fn (): bool => $this->order->state === OrderState::Ordered)
            ->modalDescription('Closing waives the outstanding units and keeps stock from recorded deliveries.')
            ->action(fn (Purchasing $purchasing, array $data) => $this->applyChange(
                fn () => $purchasing->close($this->orderId, $this->version, $data['reason']),
            ));
    }

    public function closeLineAction(): Action
    {
        return $this->reasonAction('closeLine', 'Close line', 'close')
            ->visible(fn (array $arguments): bool => $this->order->state === OrderState::Ordered
                && $this->order->items->find($arguments['itemId'] ?? null)?->outstanding() > 0)
            ->modalDescription('Waive the outstanding units on this line.')
            ->action(fn (Purchasing $purchasing, array $data, array $arguments) => $this->applyChange(
                fn () => $purchasing->close($this->orderId, $this->version, $data['reason'], $this->line($arguments)->id),
            ));
    }

    public function restoreLineAction(): Action
    {
        return $this->reasonAction('restoreLine', 'Restore closed balance', 'close')
            ->visible(fn (array $arguments): bool => $this->order->state === OrderState::Ordered
                && $this->order->items->find($arguments['itemId'] ?? null)?->short_closed_quantity > 0)
            ->modalDescription('Restore units previously waived by closing this line.')
            ->action(fn (Purchasing $purchasing, array $data, array $arguments) => $this->applyChange(
                fn () => $purchasing->restoreLine($this->orderId, $this->version, $this->line($arguments)->id, $data['reason']),
            ));
    }

    public function reopenAction(): Action
    {
        return $this->reasonAction('reopen', 'Reopen purchase order', 'close')
            ->visible(fn (): bool => $this->order->state === OrderState::Closed)
            ->modalDescription('Previously waived balances stay waived when you reopen the order.')
            ->action(fn (Purchasing $purchasing, array $data) => $this->applyChange(
                fn () => $purchasing->reopen($this->orderId, $this->version, $data['reason']),
            ));
    }

    public function cancelOrderAction(): Action
    {
        return $this->reasonAction('cancelOrder', 'Cancel', 'cancel')
            ->button()
            ->color(OrderStatus::Cancelled->getColor())
            ->icon(OrderStatus::Cancelled->getIcon())
            ->modalHeading('Cancel purchase order')
            ->modalCancelActionLabel('Keep order')
            ->visible(fn (): bool => in_array($this->order->state, [OrderState::Draft, OrderState::Ordered], true)
                && $this->order->receipts->isEmpty())
            ->action(fn (Purchasing $purchasing, array $data) => $this->applyChange(
                fn () => $purchasing->cancel($this->orderId, $this->version, $data['reason']),
            ));
    }

    public function expectedDateAction(): Action
    {
        return $this->reasonAction('expectedDate', 'Update expected delivery', 'edit')
            ->visible(fn (): bool => $this->order->state === OrderState::Ordered)
            ->schema([DatePicker::make('date')->label('Expected delivery'), $this->reasonField()])
            ->fillForm(fn (): array => ['date' => $this->order->expected_at?->toDateString()])
            ->action(fn (Purchasing $purchasing, array $data) => $this->applyChange(
                fn () => $purchasing->updateExpectedDate($this->orderId, $this->version, $data['date'] ?? null, $data['reason']),
            ));
    }

    public function amendAction(): Action
    {
        return $this->reasonAction('amend', 'Amend line', 'edit')
            ->authorize(fn (): bool => $this->can('edit') && $this->can('place'))
            ->visible(fn (): bool => $this->order->state === OrderState::Ordered)
            ->modalDescription('Each existing receipt keeps its original unit cost.')
            ->schema([
                TextInput::make('quantity')->label('Total ordered quantity')->integer()->minValue(1)->required(),
                MoneyInput::make('unit_cost_minor')
                    ->label('Unit cost')
                    ->currency(fn (): string => $this->order->currency)
                    ->suffix(fn (): string => $this->order->currency)
                    ->minValue(0)
                    ->rules(fn (): array => ['decimal:0,'.(is_no_division_currency($this->order->currency) ? 0 : 2)])
                    ->required(),
                $this->reasonField(),
            ])
            ->fillForm(function (array $arguments): array {
                $line = $this->line($arguments);

                return ['quantity' => $line->ordered_quantity, 'unit_cost_minor' => $line->unit_cost_minor];
            })
            ->action(fn (Purchasing $purchasing, array $data, array $arguments) => $this->applyChange(
                fn () => $purchasing->amendLine(
                    $this->orderId,
                    $this->version,
                    $this->line($arguments)->id,
                    (int) $data['quantity'],
                    (int) $data['unit_cost_minor'],
                    $data['reason'],
                ),
            ));
    }

    public function correctAction(): Action
    {
        return $this->reasonAction('correct', 'Correct receipt line', 'correct')
            ->modalWidth(Width::Large)
            ->visible(fn (): bool => $this->order->state === OrderState::Ordered)
            ->modalDescription('Enter the corrected counts for this receipt line. Use a new delivery for additional units.')
            ->schema([
                TextInput::make('accepted')->label('Accepted units')->integer()->minValue(0)->required(),
                TextInput::make('rejected')->label('Rejected units')->integer()->minValue(0)->required(),
                $this->reasonField(),
            ])
            ->fillForm(function (array $arguments): array {
                $line = $this->receiptLine($arguments);

                return ['accepted' => $line->effectiveAccepted(), 'rejected' => $line->effectiveRejected()];
            })
            ->action(fn (Purchasing $purchasing, array $data, array $arguments) => $this->applyChange(
                function () use ($purchasing, $data, $arguments): PurchaseOrder {
                    $purchasing->correct(
                        $this->orderId,
                        $this->version,
                        $this->correctionKey,
                        $this->receiptLine($arguments)->id,
                        (int) $data['accepted'],
                        (int) $data['rejected'],
                        $data['reason'],
                    );
                    $this->correctionKey = (string) Str::uuid();

                    return $this->order->refresh();
                },
            ));
    }

    private function reasonAction(string $name, string $label, string $ability): Action
    {
        return Action::make($name)
            ->label($label)
            ->authorize(fn (): bool => $this->can($ability))
            ->slideOver()
            ->modalWidth(Width::ExtraLarge)
            ->modalFooterActionsAlignment(Alignment::End)
            ->modalSubmitActionLabel($label)
            ->schema([$this->reasonField()]);
    }

    private function reasonField(): Textarea
    {
        return Textarea::make('reason')->required()->maxLength(5000);
    }

    private function can(string $ability): bool
    {
        return Access::authorize('read')->can($ability.'_purchase_orders');
    }

    private function line(array $arguments): PurchaseOrderItem
    {
        return $this->order->items()->findOrFail((int) ($arguments['itemId'] ?? 0));
    }

    private function receiptLine(array $arguments): ReceiptItem
    {
        return ReceiptItem::query()
            ->with('corrections')
            ->whereIn('receipt_id', $this->order->receipts()->select('id'))
            ->findOrFail((int) ($arguments['receiptItemId'] ?? 0));
    }

    private function applyChange(Closure $change): void
    {
        try {
            $this->version = $change()->version;
        } catch (ValidationException $exception) {
            Notification::make()->title($exception->validator->errors()->first())->danger()->send();

            throw $exception;
        }

        unset($this->order);
        Notification::make()->title('Purchase order updated')->success()->send();
    }

    #[On('purchase-order-draft-saved')]
    public function refreshDraft(int $orderId): void
    {
        if ($orderId !== $this->orderId) {
            return;
        }

        unset($this->order);
        $this->version = $this->order->version;
        $this->resetPage('productsPage');
    }

    public function updatedProductsPerPage(): void
    {
        $this->resetPage('productsPage');
    }

    private function productThumbnails(Collection $items): array
    {
        $thumbnails = [];
        $collection = config('shopper.media.storage.thumbnail_collection', 'thumbnail');

        foreach ($items->groupBy('stockable_kind') as $kind => $lines) {
            $model = config('shopper.models.'.$kind);
            if (! is_string($model) || ! is_a($model, HasMedia::class, true)) {
                continue;
            }

            $products = $model::query()
                ->with('media')
                ->whereKey($lines->pluck('stockable_id')->all())
                ->get()
                ->keyBy(fn (HasMedia $product) => $product->getKey());

            foreach ($lines as $line) {
                $thumbnails[$line->id] = $products->get($line->stockable_id)?->getFirstMediaUrl($collection);
            }
        }

        return $thumbnails;
    }

    public function render(): View
    {
        $order = $this->order;
        $inventoryClass = config('shopper.models.inventory');
        $supplierClass = config('shopper.models.supplier');
        $supplierRouteKey = $order->supplier_id && shopper()->auth()->user()->can('edit_suppliers')
            ? $supplierClass::query()->find($order->supplier_id)?->getRouteKey()
            : null;

        $perPage = in_array($this->productsPerPage, [3, 5, 10], true) ? $this->productsPerPage : 3;
        $products = $order->items()->orderBy('id')->simplePaginate($perPage, pageName: 'productsPage');

        return view('purchase-orders::detail', [
            'products' => $products,
            'productThumbnails' => $this->productThumbnails($products->getCollection()),
            'order' => $order,
            'supplierRouteKey' => $supplierRouteKey,
            'inventory' => $inventoryClass::query()->find($order->inventory_id),
        ])->title($order->number);
    }
}
