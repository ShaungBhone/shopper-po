# Changelog

## Unreleased

- Share Shopper's built-in Orders permissions across sales and purchase orders. Remove the separate permission installer.

## 1.0.0

- Add supplier purchase orders to the Shopper admin, with create and edit slideovers, a live pricing summary, and an activity timeline.
- Record partial and final deliveries, add accepted units to the selected Shopper inventory location, and audit receipt corrections and waived quantities.
- Register panel views and permission keys through Shopper's add-on API. Install permissions with `php artisan purchase-orders:install` and optionally grant them to an existing role.
- Support Shopper 2.12.1 through 2.12.x, Laravel 12, and PHP 8.3 or later.

## 0.1.0 — 2026-11-04

Initial local implementation: Shopper addon integration, purchasing screens, separated order/receiving states, partial receipts, explicit rejected-unit resolution, line/order short-closing, receipt corrections, audited amendments, permissions, ledger integration, and database/concurrency tests.

Formatting follow-up: expanded PHP/Blade/CSS source, committed Pint and Blade Formatter configuration, and added repeatable npm formatting/check commands and a CI formatting check.
