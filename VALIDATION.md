# Validation — 4 November 2026

Local implementation verified with PHP 8.4.27, Laravel 12.71.0, Shopper 2.12.1, Livewire 3.8.11, Filament 4.16.1, and an isolated MySQL 8.4 container.

| Check | Observed result |
| --- | --- |
| Full MySQL suite: `PO_TEST_MYSQL=1 vendor/bin/phpunit` | **42 tests, 207 assertions passed**; no skipped tests; 3 minutes 3 seconds. |
| SQLite suite: `vendor/bin/phpunit` | **38 tests passed**, 182 assertions; 4 MySQL-only concurrency cases skipped; 11 seconds. |
| Formatting: `vendor/bin/pint --test` | Passed. |
| Package metadata: `composer validate --strict` | Passed. |
| PHP syntax: `php -l` across source, migrations, config, tests | 30 files passed. |
| Development dependency PHP constraints | Every locked package permits PHP 8.3; lock resolved with platform PHP 8.3.0. |

The MySQL suite exercised real simultaneous workers for competing receipts, duplicate retry requests, receiving versus cancellation, and receiving versus Shopper’s checkout reservation. Receipt/correction tests used Shopper’s actual `InventoryHistory` and stock mutation APIs rather than a mocked stock balance.

Other coverage includes rejection replacement/waiver rules, manual short-close restoration, receiving corrections, stock underflow prevention, immutable original receipts, receipt cost snapshots, audited amendments, transaction rollback after a ledger write, events after commit, authorization revocation, forged IDs/counters, stale versions, rollback protection, custom product models, custom table prefixes, and a custom authentication guard.

Admin validation includes Livewire interactions for draft creation, ordering, receiving, and correction/amendment prefilling; a full HTTP page rendered inside Shopper’s admin layout; and successful route/config cache commands. A browser visual inspection was not performed.

The alternate MySQL table prefix was `po_`, alongside the default `sh_`. A longer `alt_` prefix exposed an existing Shopper core tax migration whose generated index name exceeds MySQL’s identifier limit. Core was not modified; use a host prefix already supported by Shopper’s migrations.

The GitHub Actions PHP 8.3/8.4 matrix is provided but has not run remotely. PHP 8.3 was not installed locally. No existing store, production data, external deployment, or Packagist publication was used. The isolated database container was stopped after testing.

## Formatting follow-up

- Expanded long PHP arrays/calls and Blade/CSS source. Pint is the final PHP style authority, Blade Formatter handles templates/embedded CSS, and Prettier handles JSON.
- `npm run format:check` passes. A second `npm run format` changes no files.
- The post-formatting SQLite run passed 38 tests and 182 assertions; the 4 MySQL-only race tests were skipped in this follow-up. The full MySQL result above is from before the formatting pass.
- All production PHP syntax trees match the pre-formatting source. One test helper now loads a migration and invokes it in two explicit statements, preserving its intended behavior.
- Detail Blade template: 69 → 290 lines. CSS partial: 5 → 203 lines. Purchasing service: 418 → 682 lines.
- Node formatting dependencies are development-only and pinned in `package-lock.json`; `npm install` reported zero vulnerabilities after the dependency updates.
