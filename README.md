<img src="./assets/logo.svg" alt="CHIP Logo" width="50"/>

[![WordPress Plugin Check](https://github.com/CHIPAsia/chip-for-gravity-forms/actions/workflows/plugin-check.yml/badge.svg?branch=main)](https://github.com/CHIPAsia/chip-for-gravity-forms/actions/workflows/plugin-check.yml)

# CHIP for Gravity Forms

The official CHIP payment add-on for Gravity Forms. Accept payments seamlessly with Malaysia's leading payment methods including FPX, credit/debit cards, DuitNow QR, and e-wallets.

## Features

- **Native Gravity Forms integration** - Add CHIP as a payment feed to any form
- **Global and form-specific settings** - Set Brand ID and Secret Key once globally or override per form
- **Multiple payment methods** - FPX, Credit/Debit Cards, DuitNow QR, E-Wallets, and more via CHIP hosted checkout
- **Client data mapping** - Map form fields to CHIP client metadata (e.g. legal_name, address, country)
- **Refund from entries** - Process full refunds from Gravity Forms → Entries when enabled in settings

## Installation

1. [Download the latest release](https://github.com/CHIPAsia/chip-for-gravity-forms/releases/latest)
2. Log in to your WordPress admin panel and navigate to **Plugins** → **Add New**
3. Click **Upload Plugin**, select the downloaded zip file, and click **Install Now**
4. Activate the plugin

## Configuration

### Global configuration

1. Navigate to **Forms** → **Settings** → **CHIP**
2. Enter your **Brand ID** and **Secret Key**
3. Optionally enable refund and set due timing; save changes

### Form-specific configuration

1. Edit your form and go to **Form Settings** → **CHIP**
2. Add a new feed and enter Brand ID / Secret Key (or leave blank to use global settings)
3. Map amount, currency, and optional client fields; enable the feed and save

## Refund

Refunds made through the WordPress Dashboard (Gravity Forms → Entries) are full refunds only. A successful refund message means the request was sent to the CHIP API; completion depends on CHIP's refund policy.

## Development

**Plugin check:** To check plugin compliance locally before committing, run one of the following:

```bash
# Using Docker (recommended)
sudo docker compose run --rm plugin-check ./scripts/run-plugin-check.sh

# Or without Docker
./scripts/run-wp-plugin-check.sh
```

**Unit tests:** After `composer install`, run PHPUnit:

```bash
composer install
./vendor/bin/phpunit
```

## Documentation

- [API Documentation](https://docs.chip-in.asia)
- [Gravity Forms Merge Tags](https://docs.gravityforms.com/merge-tags/#entry-data) (e.g. `{entry:transaction_id}` for Purchase ID in notifications)
- [Gravity Forms Logging and Debugging](https://docs.gravityforms.com/logging-and-debugging/) – enable logging in Forms → Settings to view CHIP callback and payment logs

## Community

Join our [Merchants & Developers Community](https://www.facebook.com/groups/3210496372558088) on Facebook for support and discussions.

## License

This plugin is licensed under the [GPLv3](http://www.gnu.org/licenses/gpl-3.0.html).
