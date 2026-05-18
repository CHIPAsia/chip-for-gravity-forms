# CHIP for Gravity Forms

## Project Overview

Official CHIP payment gateway add-on for Gravity Forms. Accepts payments via FPX, cards, DuitNow QR, and e-wallets through CHIP's hosted checkout.

## Architecture

- **Entry point:** `chip-for-gravity-forms.php` — defines constants, loads bootstrap.
- **Bootstrap:** `class-gf-chip-bootstrap.php` — registers addon with Gravity Forms, adds Settings link.
- **Main addon:** `class-gf-chip.php` (~1,500 lines) — extends `GFPaymentAddOn`. Handles settings, payment creation, callbacks, webhooks, refunds.
- **API client:** `class-gf-chip-api.php` — singleton-per-credentials HTTP client for CHIP REST API.

## Testing

- PHPUnit unit tests in `tests/Unit/` using WP_Mock.
- `composer install` then `./vendor/bin/phpunit`.
- CI runs PHPCompatibility, PHPCS, PHPUnit, and WordPress Plugin Check.

## Important Notes

### Do NOT add `Requires Plugins: gravityforms` header
Gravity Forms is **not** hosted on WordPress.org SVN. The `Requires Plugins` header (added in WP 6.5) only works for plugins available in the wordpress.org repository. Adding it would break activation because WordPress cannot resolve the dependency.

### API singleton is per-credentials
`GF_CHIP_API::get_instance()` returns a unique instance for each `secret_key + brand_id` hash. This prevents credential cross-contamination when a site uses both Global and Form Configuration with different keys.

### Credential resolution
Use `GF_Chip::get_credentials_for_feed( $feed )` instead of manually looking up global settings and checking `chipConfigurationType`. This helper returns an array with `secret_key`, `brand_id`, `due_strict`, `due_timing`, and `refund`.

## Code Standards

- WordPress Coding Standards (WPCS).
- PHPCS ruleset: `phpcs.xml`.
- PHP 7.4–8.4 compatibility.
- Text domain: `chip-for-gravity-forms`.

## Release Process

1. Update `readme.txt` `Stable tag` and `Tested up to`.
2. Update `chip-for-gravity-forms.php` version header and `GF_CHIP_MODULE_VERSION`.
3. Update `package.json` version.
4. Add changelog entry in `readme.txt` and `changelog.txt`.
5. Tag: `gravity-forms-upload-vX.Y.Z` triggers the SVN upload workflow.
6. Run the stable-release workflow to mark the tag as stable in SVN.
