<?php

declare(strict_types=1);

namespace ShopperPurchaseOrders\Sidebar;

use Shopper\Feature;
use Shopper\Sidebar\AbstractAdminSidebar;
use Shopper\Sidebar\Contracts\Builder\Group;
use Shopper\Sidebar\Contracts\Builder\Item;
use Shopper\Sidebar\Contracts\Builder\Menu;

final class PurchasingSidebar extends AbstractAdminSidebar
{
    public function extendWith(Menu $menu): Menu
    {
        $allowed =
            Feature::enabled('supplier') && ($this->user?->can('browse_orders') ?? false);
        $menu->group('Purchasing', function (Group $group) use ($allowed): void {
            $group->weight(5);
            $group->setAuthorized($allowed);
            $group->item('Purchase orders', function (Item $item) use ($allowed): void {
                $item->setAuthorized($allowed);
                $item->route('shopper.purchase-orders.index');
                $item->setIcon('phosphor-package');
                $item->useSpa();
            });
        });

        return $menu;
    }
}
