# AGENTS.md

This file provides guidance to AI coding agents working in this repository.

## Project Overview

Official CHIP payment gateway add-on for Gravity Forms. Extends `GFPaymentAddOn` to create payments via CHIP's hosted checkout, handle callbacks/webhooks, and process refunds. Supports both Global Configuration (single set of credentials) and Form Configuration (per-form credentials).

## Architecture

The plugin is a Gravity Forms Payment Add-On with four layers:

1. **Entry point** (`chip-for-gravity-forms.php`): defines constants (`GF_CHIP_MODULE_VERSION`, `GF_CHIP_PLUGIN_PATH`), hooks `gform_loaded` to bootstrap.
2. **Bootstrap** (`includes/class-gf-chip-bootstrap.php`): registers the addon via `GFAddOn::register()`, adds Settings link on plugin list.
3. **Main addon** (`includes/class-gf-chip.php`): extends `GFPaymentAddOn`. Implements:
   - Global settings (Brand ID, Secret Key, optional refund/due timing)
   - Feed settings (per-form credentials, client metadata mapping, cancel URL)
   - Payment redirect (`redirect_url`) — builds CHIP purchase payload, stores `chip_payment_id` in entry meta
   - Callback handling (`callback`/`post_callback`) — GET (browser redirect) or POST (webhook with X-Signature verification)
   - Refund AJAX handler (`chip_refund_payment`)
   - Thank-you page validation (`maybe_thankyou_page`) — hash-based confirmation URL
4. **API client** (`includes/class-gf-chip-api.php`): per-credential-singleton HTTP client. All instances are keyed by `md5(secret_key + '|' + brand_id)` to prevent cross-contamination.

### Credential Resolution

Always use `GF_Chip::get_credentials_for_feed( $feed )` when you need `secret_key`, `brand_id`, `due_strict`, `due_timing`, or `refund`. Do not manually look up `gravityformsaddon_gravityformschip_settings` and check `chipConfigurationType`.

### Webhook Signature Verification

On POST callbacks with `HTTP_X_SIGNATURE`, the plugin verifies the signature against a stored public key (`gf_chip_public_key_{company_id}`). If verification fails or no key is stored, it falls back to `get_payment()` via API.

### Per-Payment Locking

Both `callback()` and `process_webhook_callback()` use MySQL `GET_LOCK('chip_gf_payment_' + $payment_id, 15)` to prevent duplicate processing of the same payment while allowing other payments to run in parallel. The lock is released in `post_callback()`.

## Subscriptions

Subscriptions are a plugin feature, not a Gravity Forms one. Core ships the scaffold — an hourly cron hook, a Cancel button on the entry detail, notification events — but `GFPaymentAddOn::check_status()` is an **empty method** and `creditcard_token_info()` returns an empty array. There is no token table. The renewal engine, the token lifecycle and the card-update flow are all implemented here.

### The scheduler invariant: advance before charge

`GF_Chip_Renewals::plan_renewal()` returns the next-payment date to write **before** the charge is attempted. This is what makes an hourly cron safe to run repeatedly: a second run in the same window sees a future date and skips.

The trade-off is deliberate. A crash after the claim costs one billing cycle, recoverable from the entry notes. A crash before it would **double-charge a real customer**. When the behaviour is ambiguous, resolve it in favour of under-charging.

Two consequences worth knowing before you touch this code:

- Cycles are anchored to the date a payment was **due**, not to `now`. A subscription billed on the 1st stays on the 1st even when the cron runs late.
- The retry ladder (1, 3, 5 days) is measured from the **original due date** too, so a slow cron cannot stretch it.

### Entry meta keys

State lives in entry meta. There is no custom table — transactions go into `gf_addon_payment_transaction` through `insert_transaction()` with `is_recurring` and the subscription id.

| Key | Meaning |
|---|---|
| `chip_recurring_token` | The card token CHIP charges at renewal. Treat as a credential. |
| `chip_sub_status` | `pending`, `active`, `on-hold`, `cancelled`, `expired` |
| `chip_sub_next_payment` | UTC datetime of the next attempt (or the retry) |
| `chip_sub_amount` | Recurring amount in the smallest currency unit |
| `chip_sub_remaining` | Installments left, or `0` for unlimited |
| `chip_sub_retry_count` | Position on the dunning ladder |
| `chip_sub_last_payment` | UTC datetime of the last successful renewal |
| `chip_dunned_attempt` | Attempt index already emailed, so a retry is not emailed twice |
| `chip_card_update_signature` / `_expiry` / `_nonce` | The live card-update link |

**`chip_sub_remaining` is read, not just written.** It is resolved through `GF_Chip_Renewals::resolve_remaining()` so a stored value overrides the feed's `recurringTimes`. Re-reading the feed every cycle was a real defect: a 12-installment plan charged forever. The same class of bug appeared twice, so check both sides when changing this.

### Card-only constraint

CHIP's recurring tokens are issued for card payments only. A subscription feed requests a recurring token with `force_recurring` plus a card-only `payment_method_whitelist`, and the Subscription transaction type is withheld entirely when the brand cannot take cards. Do not widen the whitelist — no other method can back a recurring charge.

`platform` must stay `gravityforms`. CHIP does not accept a subscription-specific value, and a made-up one is rejected at charge time.

### The card-update link is a capability

Whoever holds the link can replace the card that will be charged. Every control in `GF_Chip_Card_Update` exists for that reason:

- signed with `wp_hash()` (HMAC-SHA256 over `AUTH_KEY`), compared with `hash_equals()`
- bound to one entry **and** to its expiry, both inside the signed payload
- a per-link nonce, so two links for one entry are never identical
- single use: the signature is stored and cleared on success
- 7 day default lifetime, filterable via `gf_chip_card_update_expiry_days`

The nonce is not decoration. Without it the payload is `entry_id + expiry`, and because `wp_hash()` is deterministic, two links issued in the same second share a signature — which silently resurrected a **consumed** link. That was found by live testing against the real `wp_hash()`; a stubbed hash hides it.

**No card data is ever entered in the admin.** The customer types their card at CHIP's hosted page. This is the PCI answer, and the reason there is no card field anywhere in this plugin.

### Amount is resolved server-side

`GF_Chip_Card_Update::resolve_amount_cents()` is the only source of the amount, derived from subscription state: a healthy subscription is a free token swap, while an on-hold or past-due one collects the outstanding cycle. The value is never read from a request, so a tampered amount is impossible by construction. Do not add a code path that accepts one.

### Testing this area

The unit tests run against an in-memory entry-meta double (`GF_Chip_Test_Meta` in `tests/bootstrap.php`), **not** empty no-ops. That matters: the previous no-ops returned `''` for every read and discarded every write, which hid four real defects — including a counter that was written but never read back. If you change that double, run the test harness's own tests (`GF_Chip_Test_MetaTest`) and confirm the wiring tests still fail when the double is reverted to no-ops.

Two things a unit test cannot reach here, both seen in practice:

- **real `wp_hash()` determinism** (the nonce collision above)
- **the rendered HTML and the Forms navigation**, which need a live install

Both have been verified against a live WordPress instance during development. If you change the link or the page, re-verify there rather than trusting the suite.

## Common Commands

```bash
# Install dependencies
composer install --no-interaction --prefer-dist

# Run all tests
./vendor/bin/phpunit

# Run a single test class
./vendor/bin/phpunit --filter GF_ChipTest

# Run a single test method
./vendor/bin/phpunit --filter test_get_credentials_for_feed_returns_global_settings

# PHPCS (WordPress standards)
phpcs --standard=phpcs.xml .

# PHP compatibility (single version)
phpcs --standard=PHPCompatibilityWP --runtime-set testVersion 8.5 --extensions=php --ignore=vendor,node_modules,assets .

# Local Plugin Check (Docker)
docker compose run --rm plugin-check ./scripts/run-plugin-check.sh

# Local Plugin Check (wp-env, requires Node + Docker)
./scripts/run-wp-plugin-check.sh
```

## Important Rules

- **`readme.txt` carries only the current release.** WordPress.org renders the changelog from `readme.txt`, so it must hold exactly one version entry; `changelog.txt` keeps the full history.
- **Do NOT add `Requires Plugins: gravityforms` header.** Gravity Forms is not on WordPress.org SVN. The `Requires Plugins` header (WP 6.5+) only works for plugins in the wordpress.org repository and would break activation.
- **Text domain:** always `chip-for-gravity-forms`.
- **PHP compatibility:** 7.4 through 8.5.
- **Never call `openssl_pkey_free()`** — it is deprecated in PHP 8.0+. OpenSSL key resources are freed automatically when the variable goes out of scope.

## Release Workflow

1. Use the **Prepare Release** GitHub Action (`workflow_dispatch`, requires a `version` input such as `1.3.0`) to generate an AI changelog and bump versions, or run `bash ./scripts/bump-version.sh X.Y.Z` manually.
2. Merge the release PR.
3. Create and push tag: `git tag -a vX.Y.Z -m "Release X.Y.Z" && git push origin vX.Y.Z`
4. The `deploy.yml` workflow triggers automatically, deploying to WordPress.org SVN and creating a GitHub release.

## CI/CD Workflows

- `plugin-check.yml` — runs on push to `main` and on PRs: PHPCompatibility matrix (7.4/8.0/8.2/8.4/8.5), PHPUnit, PHPCS.
- `plugin-check-main.yml` — runs on push to `main` only: WordPress Plugin Check. A real gate — do not add `continue-on-error`. Do NOT copy `.wp-env.json` into `build-dir`: the action writes its own config in the working directory and only excludes a `.wp-env.json` it created itself — a copy placed inside `build-dir` is scanned as a plugin file and trips `hidden_files`.
- `deploy.yml` — runs on tag push or manual `workflow_dispatch`: deploys to SVN (trunk + new tag), creates GitHub release with ZIP asset.
- `prepare-release.yml` — manual: AI-generated changelog + version bump + release PR.
- `release-zip.yml` — runs on GitHub release creation: attaches ZIP asset.

## File Conventions

- `.gitattributes` uses `export-ignore` to exclude dev files from release zips.
- `.wordpress-org/` contains banner/icon/screenshot assets for the WordPress.org plugin page. It is tracked by Git (not ignored) and deployed via `deploy.yml` to SVN `assets/`.
- `phpcs.xml` excludes `tests/` from linting; test files are checked by PHPUnit only.
