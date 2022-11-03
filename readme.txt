=== CHIP for Gravity Forms ===
Contributors: wanzulnet
Tags: chip, cash, card, coin
Requires at least: 4.7
Tested up to: 6.0
Stable tag: 1.0.0
Requires PHP: 7.1
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html

CHIP - Cash, Card and Coin Handling Integrated Platform. Securely accept payment with CHIP for Gravity Forms.

== Description ==

This is a CHIP plugin for Gravity Forms.

CHIP allows you to securely sell your products and get paid with Cash, Card and Coin.

== Screenshots ==
* Fill up the form with Brand ID and Secret Key on Global Configuration.
* Fill up the form with Brand ID and Secret Key on Form-specific Configuration.
* Form that have integrated with CHIP. 

== Changelog ==

= 1.0.0 - 2022-10-20 =
* New - Intial Release

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

If on the off-chance you do encounter issues with the shop/category pages after an update you simply need to flush the permalinks by going to WordPress > Settings > Permalinks and hitting 'save'. That should return things to normal.


== Frequently Asked Questions ==

= Where is the Brand ID and Secret Key located? =

Brand ID and Secret Key available through our merchant dashboard.

= Do I need to set public key for webhook? =

No.

= Where can I find documentation? =

You can visit our [API documentation](https://developer.chip-in.asia/) for your reference.

= What CHIP API services used in this plugin? =

This plugin rely on CHIP AP ([WC_CHIP_ROOT_URL](https://gate.chip-in.asia)) as follows:

  - **/purchases/**
    - This is for accepting payment
  - **/purchases/<id\>**
    - This is for getting payment status from CHIP

= How to include Purchases ID on notification? =

Set the tag {entry:transaction_id} at the event Payment Completion notification. You may refer to [GravityForms Merge Tag](https://docs.gravityforms.com/merge-tags/#entry-data) for more information.

== Links ==

[CHIP Website](https://www.chip-in.asia)

[Terms of Service](https://www.chip-in.asia/terms-of-service)

[Privacy Policy](https://www.chip-in.asia/privacy-policy)

[API Documentation](https://developer.chip-in.asia/)

[CHIP Merchants & DEV Community](https://www.facebook.com/groups/3210496372558088)