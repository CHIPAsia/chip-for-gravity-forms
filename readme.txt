=== CHIP for Gravity Forms ===
Contributors: chipasia, wanzulnet
Tags: chip, gravity forms, payment, fpx, payment gateway
Requires at least: 6.3
Tested up to: 6.9
Stable tag: 1.2.0
Requires PHP: 7.4
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html

CHIP - Digital Finance Platform. Securely accept one-time payments with CHIP for Gravity Forms.

== Description ==

**CHIP for Gravity Forms** is the official payment add-on that connects your Gravity Forms to CHIP's Digital Finance Platform. Accept payments seamlessly with Malaysia's leading payment methods—FPX, cards, DuitNow QR, e-wallets, and more—directly from your forms.

= Why Choose CHIP for Gravity Forms? =

* **Native Gravity Forms integration** - Add CHIP as a payment feed to any form; no custom code required
* **Global and form-specific settings** - Set Brand ID and Secret Key globally, or override per form
* **Multiple payment methods** - Accept FPX, Credit/Debit Cards, DuitNow QR, e-wallets, and more via CHIP's hosted checkout
* **Flexible client data** - Map form fields to CHIP client metadata (e.g. legal_name, street_address, country) for compliance
* **Due timing control** - Optional due strict and due timing (minutes) for payment links
* **Refund from entries** - Process full refunds from Gravity Forms → Entries when refund is enabled in settings
* **Webhook support** - Reliable payment status updates via CHIP webhooks

= Supported Payment Methods =

Payment methods are determined by your CHIP brand configuration. Typically available:

* **FPX** - Malaysian online banking
* **Credit/Debit Cards** - Visa, Mastercard, and others
* **DuitNow QR** - Malaysia's national QR payment
* **E-Wallets** - GrabPay, Touch 'n Go, Boost, and more (via your CHIP setup)

= About CHIP =

CHIP is a comprehensive Digital Finance Platform designed to support Micro, Small and Medium Enterprises (MSMEs). We provide payment collection, expense management, risk mitigation, and treasury solutions. With CHIP, you get a financial partner committed to simplifying and digitizing your operations.

= Documentation =

Integrate your Gravity Forms with CHIP as documented in our [API Documentation](https://docs.chip-in.asia).

== Screenshots ==

1. Global configuration - Enter your Brand ID and Secret Key in the plugin settings to connect with CHIP.
2. Form-specific configuration - Override global settings per form; set Brand ID, Secret Key, and optional refund/due timing.
3. Form with CHIP payment - Form integrated with CHIP as a payment feed.
4. CHIP payment page - Secure hosted checkout where the customer completes payment.
5. Confirmation page - Success page after payment is completed.
6. Entry with refund - Process full refunds from Gravity Forms → Entries when refund is enabled.

== Changelog ==

= 1.2.0 2026-02-20 =
* Fixed - Global configuration (Brand ID and Secret Key) not saved when saving settings.
* Fixed - Fatal error when GFAddon expected full path; plugin now works with Gravity Forms addon loader.
* Fixed - Application files not permitted (WordPress Plugin Check compatibility).
* Added - "Copy from global configuration" button in form feed settings when using Form Configuration.
* Added - Account status block in form configuration to verify Brand ID and Secret Key.
* Added - Form settings image in global CHIP description.
* Added - Public key support: store CHIP public key by company ID when saving global or form settings; verify webhook signature when key is available and use payload directly, with fallback to get_payment.
* Added - Per-payment lock on callback to prevent duplicate processing while allowing other payments to run in parallel.
* Added - Refund button now requires user confirmation before processing the refund.
* Changed - Minimum WordPress version set to 6.3.

[See changelog for all versions](https://github.com/CHIPAsia/chip-for-gravity-forms/releases).

== Installation ==

= Minimum Requirements =

* WordPress 6.3 or greater
* Gravity Forms plugin (active)
* PHP 7.4 or greater (PHP 8.0+ recommended)
* MySQL 5.6 or greater, OR MariaDB 10.1 or greater

= Automatic installation =

Automatic installation is the easiest option—WordPress will handle the file transfer, and you won't need to leave your web browser. To do an automatic install of CHIP for Gravity Forms, log in to your WordPress dashboard, navigate to the Plugins menu, and click "Add New."

In the search field type "CHIP for Gravity Forms," then click "Search Plugins." Once you've found it, you can view details such as the point release, rating, and description. Click "Install Now," and WordPress will take it from there. Activate the plugin when the installation is complete.

= Manual installation =

The manual installation method requires downloading the CHIP for Gravity Forms plugin and uploading it to your web server via your favorite FTP application. The WordPress Codex contains [instructions on how to do this here](https://wordpress.org/support/article/managing-plugins/#manual-plugin-installation).

= Updating =

Automatic updates should work smoothly; we still recommend you back up your site before updating.

== Frequently Asked Questions ==

= Where is the Brand ID and Secret Key located? =

Brand ID and Secret Key are available through our [merchant dashboard](https://gate.chip-in.asia). Navigate to Developer > Credentials after logging in.

= Do I need to set a public key for webhook? =

No. The plugin works with CHIP's standard webhook flow; no separate public key is required. When you save your global or form CHIP settings, the plugin stores the public key automatically (by company ID) so it can verify webhook signatures when available and use the payload directly; otherwise it falls back to fetching payment status via the API.

= Where can I find documentation? =

Visit our [API documentation](https://docs.chip-in.asia/) for technical reference.

= How can I view CHIP plugin debug logs? =

The plugin uses Gravity Forms logging for callbacks, webhooks, and payment flow. To view these logs, enable logging in **Forms → Settings** (set Logging to On), then open the **Logging** tab to view or delete logs for CHIP for Gravity Forms. See [Gravity Forms Logging and Debugging](https://docs.gravityforms.com/logging-and-debugging/) for details. Disable logging when you are done troubleshooting.

= What CHIP API services are used in this plugin? =

**CHIP API** – `GF_CHIP_ROOT_URL` (https://gate.chip-in.asia)

*Payment operations:*

* `POST /purchases/` – Create payment
* `GET /purchases/{id}/` – Get payment status
* `POST /purchases/{id}/refund/` – Refund payment
* `POST /purchases/{id}/cancel/` – Cancel payment

= How do I configure CHIP on a form? =

1. Edit your form in Gravity Forms.
2. Go to Form Settings → CHIP (or the form's payment settings).
3. Add a new feed: set Brand ID and Secret Key (or leave blank to use Global Configuration), and map amount, currency, and optional client fields.
4. Enable the feed and save. Form submissions will then send customers to CHIP to complete payment.

= How to include Purchase ID in notifications? =

Use the merge tag `{entry:transaction_id}` in the Payment Completion notification. See [Gravity Forms Merge Tags](https://docs.gravityforms.com/merge-tags/#entry-data) for more information.

= Is a refund initiated through the WordPress Dashboard instant? =

A refund triggered from the WordPress Dashboard (Gravity Forms → Entries) is still subject to CHIP's refund policy. A successful refund message in the dashboard only indicates that the refund request was successfully sent to the CHIP API; completion depends on CHIP's processing.

= Can I refund only part of the payment? =

Refunds made through the Gravity Forms entry screen are full refunds only. For partial refunds, use the CHIP merchant dashboard or API.

= How do I disable the refund feature? =

Add the following to your wp-config.php to disable refunds from Gravity Forms:

`define( 'GF_CHIP_DISABLE_REFUND_PAYMENT', true );`

= What currencies are supported? =

Supported currencies depend on your CHIP brand configuration. Commonly MYR (Malaysian Ringgit) is supported; contact CHIP for other currencies.

= Why don't I see the CHIP payment option on my form? =

Ensure: (1) CHIP for Gravity Forms is activated, (2) Brand ID and Secret Key are set in Global Configuration or in the form feed, (3) the form has a CHIP feed added and enabled, and (4) the form has a product or total field so an amount is sent to CHIP.

== Links ==

[CHIP Website](https://www.chip-in.asia)

[Terms of Service](https://www.chip-in.asia/terms-of-service)

[Privacy Policy](https://www.chip-in.asia/privacy-policy)

[API Documentation](https://docs.chip-in.asia/)

[CHIP Merchants & DEV Community](https://www.facebook.com/groups/3210496372558088)
