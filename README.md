# shopper-po

Purchase orders for Laravel Shopper. Create drafts, record deliveries, and correct receipts with an audit trail linked to Shopper’s stock ledger.

The `1.x` branch targets Shopper 2.12.1, Laravel 12, and PHP 8.3+. A `v3` branch is planned for Shopper v3.

## Install

Place this checkout at `packages/shopper-po` in your Shopper app. Add this entry to the app’s Composer `repositories`:

```json
{
  "type": "path",
  "url": "packages/shopper-po",
  "options": {
    "versions": {"shaungbhone/shopper-po": "0.1.0"}
  }
}
```

From the Shopper app, run:

```sh
composer require shaungbhone/shopper-po:^0.1
php artisan migrate
php artisan purchase-orders:install
php artisan optimize:clear
```

Enable the supplier feature in `config/shopper/features.php`. Set up an enabled supplier and an inventory location. Assign purchase-order permissions in Shopper’s team settings or pass `--role=your-existing-role` to the install command. Manage currencies in Shopper’s **Settings → Currencies**. New orders use enabled currencies and the store’s default currency; existing drafts retain their saved currency if it is later disabled. Amounts follow Shopper’s currency precision without exchange-rate conversion. No package config file is needed.

To disable the add-on, set `'purchase-orders' => false` in Shopper’s `config/shopper/addons.php`.

## Use

Open **Purchasing → Purchase orders**. Save a draft, mark it ordered, and record deliveries against the outstanding quantities. Rejected units can await replacement or be waived. Correct a receipt to fix a receiving mistake, or close the order to waive the remaining balance. Accepted units update stock.

## Test

From the package checkout:

```sh
composer install
composer test
```

The default suite uses SQLite. See the [test workflow](.github/workflows/tests.yml) for MySQL concurrency tests and the [purchasing service](src/Actions/Purchasing.php) for integration entry points.
