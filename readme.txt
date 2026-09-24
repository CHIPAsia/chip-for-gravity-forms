=== CHIP for Gravity Forms ===
Contributors: chipasia, wanzulnet
Tags: chip, gravity forms, payment, fpx, payment gateway
Requires at least: 6.3
Tested up to: 7.1
Stable tag: 1.3.0
Requires PHP: 7.4
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html

CHIP - Digital Finance Platform. Securely accept one-time and subscription payments with CHIP for Gravity Forms.

== Description ==

**CHIP for Gravity Forms** is the official payment add-on that connects your Gravity Forms to CHIP's Digital Finance Platform. Accept payments seamlessly with Malaysia's leading payment methods—FPX, cards, DuitNow QR, e-wallets, and more—directly from your forms.

= Why Choose CHIP for Gravity Forms? =

* **Native Gravity Forms integration** - Add CHIP as a payment feed to any form; no custom code required
* **Global and form-specific settings** - Set Brand ID and Secret Key globally, or override per form
* **Multiple payment methods** - Accept FPX, Credit/Debit Cards, DuitNow QR, e-wallets, and more via CHIP's hosted checkout
* **Flexible client data** - Map form fields to CHIP client metadata (e.g. legal_name, street_address, country) for compliance
* **Due timing control** - Optional due strict and due timing (minutes) for payment links
* **Subscriptions** - Sell recurring plans with a recurring amount, billing cycle, optional trial, setup fee, and a limited number of installments
* **Automatic renewals** - CHIP charges the stored card on schedule; renewals run with no customer present
* **Recovery and dunning** - A failed renewal is retried on a 1, 3, and 5 day ladder, the customer is emailed a secure link to pay and update their card, and you can re-send that link from the admin
* **Subscriptions list** - See every subscription, its status, next payment date and retry count in one admin page
* **Refund from entries** - Process full refunds from Gravity Forms → Entries when refund is enabled in settings
* **Webhook support** - Reliable payment status updates via CHIP webhooks

= Supported Payment Methods =

Payment methods are determined by your CHIP brand configuration. Typically available:

* **FPX** - Malaysian online banking
* **Credit/Debit Cards** - Visa, Mastercard, and others
* **DuitNow QR** - Malaysia's national QR payment
* **E-Wallets** - GrabPay, Touch 'n Go, Boost, and more (via your CHIP setup)

**Subscriptions are card-only.** CHIP's recurring tokens can only be issued for card payments, so a form feed set to the Subscription transaction type offers cards only. FPX, DuitNow QR and e-wallets remain available on one-time forms.

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

= 1.3.0 2026-05-28 =
* Fixed - API singleton returning wrong credentials when a site uses both Global and Form Configuration with different keys.
* Fixed - `rgar()` argument order in `complete_payment()` that broke delayed feed triggering after payment completion.
* Added - `WP_Error` and HTTP status code handling in the API client for robust error handling.
* Added - `get_credentials_for_feed()` helper to centralize credential resolution across payment flows.
* Added - Unit tests for `GF_Chip` core logic (credentials, callback actions, timezone).
* Added - PHP 8.5 to the CI compatibility matrix.
* Added - CONTRIBUTING.md and CLAUDE.md for developer documentation.
* Added - `.wordpress-org/` assets directory for WordPress.org plugin page banners and screenshots.
* Changed - Bumped "Tested up to" to WordPress 7.0.
* Changed - Modernized CI/CD workflows: deploy.yml, prepare-release.yml, pr-summary.yml.
* Removed - composer.lock from git tracking to reduce merge conflicts.

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
* `POST /purchases/{id}/charge/` – Charge a stored recurring token (subscription renewal)
* `POST /purchases/{id}/delete_recurring_token/` – Delete a stored recurring token (subscription cancellation or card change)

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

= How do I set up a subscription on a form? =

1. Edit your form in Gravity Forms and open the CHIP payment settings for the feed.
2. Set **Transaction Type** to **Subscription**.
3. Fill in the recurring amount, the billing cycle (interval and period), and optionally a trial and a setup fee.
4. Optionally set **Recurring Times** to limit the plan to a fixed number of installments. Leave it at 0 for an ongoing subscription with no end date.
5. Save the feed. Customers who submit the form will pay the first installment at CHIP and their card is saved for future renewals.

= Why can't customers choose FPX or an e-wallet on a subscription form? =

Because CHIP's recurring tokens are issued for cards only. A subscription needs a card on file to charge later, and FPX, DuitNow QR and e-wallets do not provide one, so a Subscription feed offers cards only. One-time forms are unaffected and still support every method your CHIP brand has enabled.

= When do renewals run? =

An hourly scheduled task checks for due subscriptions. A renewal is charged on the date it is due, not on the date the check happens to run — so a subscription billed on the 1st stays on the 1st even if the task runs late. Renewals need no action from the customer; the stored card is charged automatically.

= What happens if a renewal payment fails? =

The renewal is retried on a ladder of 1, 3, and 5 days after the original due date. The customer is emailed a secure link where they can pay the outstanding amount and save a new card in one step. If all retries fail, the subscription is marked **Expired** rather than retrying indefinitely.

You can also re-send the update-card link yourself: go to **Forms → CHIP Subscriptions** and use **Send update-card link** on the subscription's row.

= How does a customer change their card? =

Open **Forms → CHIP Subscriptions** and click **Send update-card link** on the subscription. The customer receives an email with a secure, single-use link. Opening it shows their subscription, the amount due if there is one, and a button to enter a new card at CHIP.

Nobody types a card number in the WordPress admin — card details are entered only on CHIP's hosted page. This keeps the site out of scope for card data.

If the subscription has an outstanding payment, the link collects that payment and saves the new card in one step, so the customer does not have to wait for the next scheduled attempt.

= What do the subscription statuses mean? =

* **Pending** - The first payment has not completed yet.
* **Active** - Paying normally; the next renewal is scheduled.
* **On hold** - A renewal failed and is being retried. The customer has been emailed a link to update their card.
* **Cancelled** - Stopped at the customer's or your request. No further charges.
* **Expired** - All retries failed, or a fixed number of installments completed. No further charges.

= How do customers cancel a subscription? =

In the WordPress admin, open the entry for the subscription under **Forms → Entries** and use the **Cancel Subscription** button. Cancelling stops future charges immediately and revokes the stored card token at CHIP.

= Can I use merge tags in subscription notifications? =

Yes. The plugin adds three notification events you can attach a notification to: **Subscription Renewed**, **Subscription Payment Failed**, and **Subscription Expired**. It also provides the merge tag `{chip_update_card_link}`, which inserts a secure card-update link for that customer. The link is generated fresh each time a notification sends, so a link that has already been used is replaced by a working one.

= Are subscription payments refundable? =

Subscription charges appear in the entry's payment transactions and can be refunded like any other payment, subject to CHIP's refund policy. Refunds do not cancel the subscription — cancel it separately if you also want charges to stop.

== Links ==

[CHIP Website](https://www.chip-in.asia)

[Terms of Service](https://www.chip-in.asia/terms-of-service)

[Privacy Policy](https://www.chip-in.asia/privacy-policy)

[API Documentation](https://docs.chip-in.asia/)

[CHIP Merchants & DEV Community](https://www.facebook.com/groups/3210496372558088)
