=== CHIP for Gravity Forms ===
Contributors: chipasia, wanzulnet
Tags: chip
Requires at least: 4.7
Tested up to: 6.5
Stable tag: 1.0.7
Requires PHP: 7.1
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html

CHIP - Better Payment & Business Solutions. Securely accept payment with CHIP for Gravity Forms.

== Description ==

This is a CHIP plugin for Gravity Forms.

CHIP is a payment and business solutions platform that allow you to securely sell your products and get paid via multiple local and international payment methods.

== Screenshots ==
* Fill up the form with Brand ID and Secret Key on Global Configuration.
* Fill up the form with Brand ID and Secret Key on Form-specific Configuration.
* Form that have been integrated with CHIP.
* Test mode payment page.
* Confirmation page after successful payment.

== Changelog ==

= 1.0.7 2024-05-30 =
* Added - Add cancel_redirect for handling cancel event

== Installation ==

= Minimum Requirements =

* WordPress 5.9 or greater

= Automatic installation =

Automatic installation is the easiest option as WordPress handles the file transfers itself and you don’t need to leave your web browser. To do an automatic install of, log in to your WordPress dashboard, navigate to the Plugins menu and click Add New.

In the search field type "CHIP for Gravity Forms" and click Search Plugins. Once you’ve found our plugin you can view details about it such as the point release, rating and description. Most importantly of course, you can install it by simply clicking “Install Now”.

= Manual installation =

The manual installation method involves downloading our plugin and uploading it to your webserver via your favorite FTP application. The
WordPress codex contains [instructions on how to do this here](http://codex.wordpress.org/Managing_Plugins#Manual_Plugin_Installation).

= Updating =

Automatic updates should work like a charm; as always though, ensure you backup your site just in case.

== Frequently Asked Questions ==

= Where is the Brand ID and Secret Key located? =

Brand ID and Secret Key available through our merchant dashboard.

= Do I need to set public key for webhook? =

No.

= Where can I find documentation? =

You can visit our [API documentation](https://docs.chip-in.asia/) for your reference.

= What CHIP API services used in this plugin? =

This plugin rely on CHIP API ([GF_CHIP_ROOT_URL](https://gate.chip-in.asia)) as follows:

  - **/purchases/**
    - This is for accepting payment
  - **/purchases/<id\>**
    - This is for getting payment status from CHIP

= How to include Purchases ID on notification? =

Set the tag {entry:transaction_id} at the event Payment Completion notification. You may refer to [GravityForms Merge Tag](https://docs.gravityforms.com/merge-tags/#entry-data) for more information.

= Does refund initiated through WordPress Dashboard instant? =

Refund made through WordPress Dashboard is still subject to CHIP refund policy. Having refund successful message in dashboard only indicates that CHIP API has been called for the refund process.

= Why post creation is not delayed after installing Gravity Forms Advanced Post Creation plugin? =

This plugin is not compatible to use together with [Gravity Forms Advanced Post Creation plugin](https://www.gravityforms.com/add-ons/advanced-post-creation/).

= How to disable refund feature? =

You need to paste the code below in your wp-config.php to disable refund.
```
define( 'GF_CHIP_DISABLE_REFUND_PAYMENT', true);
```

== Links ==

[CHIP Website](https://www.chip-in.asia)

[Terms of Service](https://www.chip-in.asia/terms-of-service)

[Privacy Policy](https://www.chip-in.asia/privacy-policy)

[API Documentation](https://docs.chip-in.asia/)

[CHIP Merchants & DEV Community](https://www.facebook.com/groups/3210496372558088)
