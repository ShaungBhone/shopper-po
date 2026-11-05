# shopper-po

Purchase orders for Laravel Shopper. Create drafts, record deliveries, and correct receipts with an audit trail linked to Shopper’s stock ledger.

The `1.x` branch targets Shopper 2.12.1, Laravel 12, and PHP 8.3+. A `v3` branch is planned for Shopper v3.

## Install

Place this checkout at `packages/shopper-purchase-orders` in your Shopper app. Add this entry to the app’s Composer `repositories`:

```json
{
  "type": "path",
  "url": "packages/shopper-purchase-orders",
  "options": {
    "versions": {"community/shopper-purchase-orders": "0.1.0"}
  }
}
```

From the Shopper app, run:

```sh
composer require community/shopper-purchase-orders:^0.1
php artisan vendor:publish --tag=purchase-orders-config
php artisan migrate
php artisan purchase-orders:install
php artisan optimize:clear
```

Enable the supplier feature in `config/shopper/features.php`. Set up an enabled supplier and an inventory location. Assign purchase-order permissions in Shopper’s team settings or pass `--role=your-existing-role` to the install command. Configure purchasing currencies in [purchase-orders.php](config/purchase-orders.php).

## Use

Open **Purchasing → Purchase orders**. Save a draft, mark it ordered, and record deliveries against the outstanding quantities. Rejected units can await replacement or be waived. Correct a receipt to fix a receiving mistake, or close the order to waive the remaining balance. Accepted units update stock.

## Test

From the package checkout:

```sh
composer install
composer test
```

The default suite uses SQLite. See the [test workflow](.github/workflows/tests.yml) for MySQL concurrency tests and the [purchasing service](src/Actions/Purchasing.php) for integration entry points.
