# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Official CHIP payment gateway add-on for Gravity Forms. Extends `GFPaymentAddOn` to create payments via CHIP's hosted checkout, handle callbacks/webhooks, and process refunds. Supports both Global Configuration (single set of credentials) and Form Configuration (per-form credentials).

## Architecture

The plugin is a Gravity Forms Payment Add-On with four layers:

1. **Entry point** (`chip-for-gravity-forms.php`): defines constants (`GF_CHIP_MODULE_VERSION`, `GF_CHIP_PLUGIN_PATH`), hooks `gform_loaded` to bootstrap.
2. **Bootstrap** (`class-gf-chip-bootstrap.php`): registers the addon via `GFAddOn::register()`, adds Settings link on plugin list.
3. **Main addon** (`class-gf-chip.php`): extends `GFPaymentAddOn`. Implements:
   - Global settings (Brand ID, Secret Key, optional refund/due timing)
   - Feed settings (per-form credentials, client metadata mapping, cancel URL)
   - Payment redirect (`redirect_url`) — builds CHIP purchase payload, stores `chip_payment_id` in entry meta
   - Callback handling (`callback`/`post_callback`) — GET (browser redirect) or POST (webhook with X-Signature verification)
   - Refund AJAX handler (`chip_refund_payment`)
   - Thank-you page validation (`maybe_thankyou_page`) — hash-based confirmation URL
4. **API client** (`class-gf-chip-api.php`): per-credential-singleton HTTP client. All instances are keyed by `md5(secret_key + '|' + brand_id)` to prevent cross-contamination.

### Credential Resolution

Always use `GF_Chip::get_credentials_for_feed( $feed )` when you need `secret_key`, `brand_id`, `due_strict`, `due_timing`, or `refund`. Do not manually look up `gravityformsaddon_gravityformschip_settings` and check `chipConfigurationType`.

### Webhook Signature Verification

On POST callbacks with `HTTP_X_SIGNATURE`, the plugin verifies the signature against a stored public key (`gf_chip_public_key_{company_id}`). If verification fails or no key is stored, it falls back to `get_payment()` via API.

### Per-Payment Locking

Both `callback()` and `process_webhook_callback()` use MySQL `GET_LOCK('chip_gf_payment_' + $payment_id, 15)` to prevent duplicate processing of the same payment while allowing other payments to run in parallel. The lock is released in `post_callback()`.

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

- **Do NOT add `Requires Plugins: gravityforms` header.** Gravity Forms is not on WordPress.org SVN. The `Requires Plugins` header (WP 6.5+) only works for plugins in the wordpress.org repository and would break activation.
- **Text domain:** always `chip-for-gravity-forms`.
- **PHP compatibility:** 7.4 through 8.5.
- **Never call `openssl_pkey_free()`** — it is deprecated in PHP 8.0+. OpenSSL key resources are freed automatically when the variable goes out of scope.

## Release Workflow

1. Use the **Prepare Release** GitHub Action (`workflow_dispatch`) to generate AI changelog and bump versions, or run `bash ./scripts/bump-version.sh X.Y.Z` manually.
2. Merge the release PR.
3. Create and push tag: `git tag -a vX.Y.Z -m "Release X.Y.Z" && git push origin vX.Y.Z`
4. The `deploy.yml` workflow triggers automatically, deploying to WordPress.org SVN and creating a GitHub release.

## CI/CD Workflows

- `plugin-check.yml` — runs on push/PR: build zip, PHPCompatibility matrix (7.4/8.0/8.2/8.4/8.5), PHPUnit, PHPCS, WordPress Plugin Check.
- `deploy.yml` — runs on tag push or manual `workflow_dispatch`: deploys to SVN (trunk + new tag), creates GitHub release with ZIP asset.
- `prepare-release.yml` — manual: AI-generated changelog + version bump + release PR.
- `pr-summary.yml` — auto-updates PR descriptions with AI-generated summaries.
- `release-zip.yml` — runs on GitHub release creation: attaches ZIP asset.

## File Conventions

- `.gitattributes` uses `export-ignore` to exclude dev files from release zips.
- `.wordpress-org/` contains banner/icon/screenshot assets for the WordPress.org plugin page. It is tracked by Git (not ignored) and deployed via `deploy.yml` to SVN `assets/`.
- `phpcs.xml` excludes `tests/` from linting; test files are checked by PHPUnit only.
