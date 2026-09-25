# Customising subscription emails

Everything here was verified against the plugin's own code and Gravity Forms
core. Where a hook is a Gravity Forms hook rather than one of ours, that is
stated, so you know who owns it.

## What already exists (build in the UI, don't code)

The plugin gives you a **merge tag**, not an email template:

    {chip_update_card_link}

Put it in the body of any Gravity Forms notification and it becomes the signed
card-update link, issued fresh for that entry when the notification is sent.

Use **Forms → Settings → Notifications** to design the email: the subject, the
body, HTML, your logo, your branding. This is the supported way to customise
the design, because Gravity Forms renders and sends it — the plugin only
supplies the link.

The plugin also registers three notification events you can build a
notification on:

    Subscription Renewed              subscription_renewed
    Subscription Payment Failed       subscription_payment_failed
    Subscription Expired              subscription_expired

So a dunning email with your own design is: one notification, event set to
*Subscription Payment Failed*, body containing `{chip_update_card_link}`.

### Read this before configuring that notification

The plugin sends a **built-in fallback email** on a failed renewal, and it does
so unconditionally — it does not check whether you have configured a
notification for the event. So if you configure a *Subscription Payment Failed*
notification **and** that fallback fires, the customer receives **two**
messages for the same failure.

Today the practical answer is to use one or the other:

- Configure the notification and design it, or
- Configure nothing and let the built-in email do the work.

If you need both — a designed customer email and an admin alert on the same
event — make the customer notification yours and keep the admin one on a
different event, or suppress one of them in your own code.

There is a further consequence worth knowing: a failure that exhausts the retry
ladder fires **both** events in the same run, so a notification on
*Subscription Payment Failed* and one on *Subscription Expired* both send. Make
them distinct messages, not variations of one.

Both of these are worth fixing in the plugin (the fallback should stand down
when a notification is configured). They are documented here rather than
silently worked around.

## Filters

The plugin exposes these, all `gf_chip_`-prefixed:

| Filter | Purpose |
|---|---|
| `gf_chip_card_update_expiry_days` | Lifetime of a card-update link, in days (default 7). |
| `gf_chip_recurring_payment_method_whitelist` | Payment methods a recurring token may be issued for. **Card-only by design** — widening it does not work, because the gateway only issues recurring tokens for cards. |
| `gf_chip_brand_supports_cards` | Force the card capability on/off instead of probing the gateway. |
| `gf_chip_purchases_api_parameters` | Final say over the purchase payload sent to the gateway. Receives `( $params, array( $feed, $submission_data, $form, $entry ) )`. |
| `gf_chip_sslverify` | TLS verification for API calls. Leave it on. |
| `gf_chip_purchase_timezone` | Timezone sent with a purchase. |
| `gf_chip_plugin_settings_fields`, `gf_chip_feed_settings_fields`, `gf_chip_client_info_fields`, `gf_chip_purchase_info_fields`, `gf_chip_miscellaneous_info_fields` | Add fields to the settings screens. |

**There is no filter for the email subject, body, or content type.** The
built-in fallback email is not templated: those three values are built inline.
That is worth knowing before you go looking for one.

## Gravity Forms hooks that apply

These are core hooks, not ours — the plugin does not hook them itself, so if
you use one you are the only thing running on it.

**To redesign a notification you configured in the UI:**

```php
// Gravity Forms. Fires for every notification, before it is sent.
add_filter( 'gform_notification', function ( $notification, $form, $entry ) {
	if ( 'subscription_payment_failed' !== rgar( $notification, 'event' ) ) {
		return $notification;
	}

	$notification['message'] = '<h1>' . esc_html( get_bloginfo( 'name' ) ) . '</h1>' . $notification['message'];

	return $notification;
}, 10, 3 );
```

This is the hook to use for HTML, branding and layout. It filters the whole
notification array — `subject`, `message`, `to`, `from` — for any notification.

**To change what is sent, whatever built it:**

```php
// Gravity Forms. Last stop before the mailer, for GF-generated mail.
add_filter( 'gform_pre_send_email', function ( $args, $format, $notification, $entry ) {
	if ( 'subscription_payment_failed' !== rgar( $notification, 'event' ) ) {
		return $args;
	}

	$args['subject'] = 'Your card needs attention';

	return $args;
}, 10, 4 );
```

This one also fires for the plugin's own built-in email, with `$notification`
empty — so it can be used to filter that too. Check `$entry` rather than
assuming a notification.

**To react to the outcome rather than change the email:**

```php
// Gravity Forms. Fires for every gateway payment action.
add_action( 'gform_post_payment_action', function ( $entry, $action ) {
	if ( 'subscription_payment_failed' !== rgar( $action, 'type' ) ) {
		return;
	}

	// Your own alerting, CRM, or log.
}, 10, 2 );
```

**To send your own mail instead**, use the merge tag from your own code. The
signature is `( $text, $form, $entry, $url_encode, $esc_html )`:

```php
$entry = GFAPI::get_entry( $entry_id );
$form  = GFAPI::get_form( rgar( $entry, 'form_id' ) );

$body = '<h2>Payment failed</h2><p>Update your card: <a href="{chip_update_card_link}">here</a></p>';

$html = GF_Chip_Renewal_Notifications::replace_merge_tag( $body, $form, $entry, false, true );
```

Pass `$esc_html = true` for an HTML body, so the link is escaped for the
attribute it sits in. Verified: a one-time payment resolves to no link at all
rather than a broken one, because there is no future charge to redirect.

A custom `wp_mail()` call gives you full control of subject, body and HTML.
Note `wp_mail()` sends **plain text by default**; set
`Content-Type: text/html` in the headers for HTML.

## Changing the content type of the built-in email

**Do not use `wp_mail_content_type` for this.** That filter is global: it would
turn every other email on the site into HTML for the rest of the request,
including the ones the plugin builds as plain text.

There is currently no supported way to change the built-in email's content
type. It sets `Content-Type: text/plain; charset=UTF-8` on its own `wp_mail()`
call, and that call is not filtered.

You can override it anyway with the `wp_mail` filter, since it runs per call —
but you have to identify the message, and the built-in email carries no marker
you can identify it by. Matching on the subject is fragile: change the
notification's subject in the UI and the filter silently stops applying. This
is the gap to close if you need HTML there.

The built-in email is deliberately plain text: it is a transactional notice
about money, and a text body cannot be broken by a theme, a template plugin or
a mail client's HTML handling. **If you want designed mail, build a
notification in the UI with `{chip_update_card_link}` in the body** — that is
the supported route, and it gives you full control of subject, body, HTML and
layout.

## Changing who the email comes from

The plugin never sets a From address. WordPress decides it, from the site name
and host, and Gravity Forms falls back to the admin email when the plugin's
address is not valid.

To set it site-wide, use the core filters:

```php
add_filter( 'wp_mail_from', function ( $email ) {
	return 'billing@example.com';
} );

add_filter( 'wp_mail_from_name', function ( $name ) {
	return 'Example Sdn Bhd';
} );
```

**Check for another plugin already on these hooks first.** A host panel plugin
commonly sets its own From on the *last* possible priority, and then a filter
of your own at any priority still loses: theirs is registered later and wins
the tie. If you find yourself unable to change the From, that is why — the way
out is to set it on the mailer itself:

```php
add_action( 'phpmailer_init', function ( $phpmailer ) {
	$phpmailer->From     = 'billing@example.com';
	$phpmailer->FromName = 'Example Sdn Bhd';
	$phpmailer->Sender   = 'billing@example.com'; // the envelope MAIL FROM
} );
```

WordPress calls `setFrom()` and *then* fires `phpmailer_init`, so this is the
last word. Set `Sender` as well: left unset, the mail relay substitutes its own
envelope address, which is how a message ends up with a From that does not
appear in its envelope.

Whichever route you take, the sending domain must be one your mail service is
authorised to send as, and it should have SPF and DKIM records. A From on a
domain with neither is filtered or junked by the recipient.
