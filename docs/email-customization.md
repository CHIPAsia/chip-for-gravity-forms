# Customising subscription emails

Everything here was verified against the plugin's own code and Gravity Forms
core. Where a hook is a Gravity Forms hook rather than one of ours, that is
stated, so you know who owns it.

Two defects found while writing this are now fixed: the built-in email used to
send unconditionally alongside a configured notification, and a terminal
failure used to announce two events. Both produced two emails for one failed
payment.

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

### The built-in email steps aside for yours

The plugin sends a built-in fallback email so dunning works with no setup at
all. When you configure a notification on *Subscription Payment Failed*, the
built-in email **stands down** and yours is the only one the customer receives.

The stand-down is decided the way Gravity Forms itself decides whether a
notification will send, so it holds in the cases that catch people out:

- a notification you have switched **off** does not suppress the built-in —
  otherwise a merchant who parked their notification would leave the customer
  with nothing
- a notification whose **conditional logic** will not pass for this entry does
  not suppress it either — the fallback exists for the entries nothing else
  covers

Pressing **Send update-card link** in the admin always sends, regardless. That
is a deliberate act by support, not the automated fallback.

## The end of the retry ladder

When the last retry fails the subscription is **expired** in the same pass, and
that is the only event announced — *Subscription Expired*. The failure event is
not also fired, so a notification on each will not produce two emails for one
payment.

The built-in email is not sent on that path either. The card-update link stops
working the moment the subscription is expired, so an email carrying one would
hand the customer a link that is already dead. Customers are dunned on every
attempt *before* that, while action is still possible — so make the
*Subscription Expired* notification the one that tells them the subscription
has ended.

## When the customer saves a card on a failed renewal

The card-update link charges the outstanding amount **as part of saving the
card** — it is not a free token swap. The customer does not have to be chased
separately for the missed payment; settling the card settles the debt.

Whether it charges is decided by the subscription's state, and the outcome is
visible in the purchase itself. Verified against a live install, printing the
real payload sent to the gateway:

| Subscription state | Product sent | Amount | Result |
|---|---|---|---|
| Active, past due | Outstanding payment | the subscription amount | **charged** |
| On-hold (dunning) | Outstanding payment | the subscription amount | **charged** |
| Active, not yet due | Update payment method | 0 | card saved only |
| Cancelled | Update payment method | 0 | card saved only |
| Expired | Update payment method | 0 | card saved only |

So a customer who is merely replacing an expiring card is **not** charged, and
a customer whose renewal failed **is**. The amount charged is the one the
subscription was set up with, not whatever the form currently resolves to, so a
settled cycle matches what the customer agreed to pay.

The customer's new card is captured in the same transaction, so there is no
window where the old card is gone and no new one is stored.

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
