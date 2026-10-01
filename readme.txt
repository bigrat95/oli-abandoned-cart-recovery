=== Oli Abandoned Cart Recovery ===
Contributors: bigrat95
Tags: abandoned cart, woocommerce, cart recovery, pending orders, multilingual
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Recover abandoned WooCommerce carts and unpaid orders with reminder emails, unique coupons and reports. Classic and block checkout.

== Description ==

**Oli Abandoned Cart Recovery** asks for the customer's consent on the checkout page and, once the consent box is ticked, saves the email and the cart — even for guests who never place an order — and sends a sequence of reminder emails when the cart is abandoned. Consent is on by default for guests and logged-in customers (Quebec Law 25, GDPR). Every email has a secure recovery link that refills the cart, pre-fills the email and sends the customer straight to checkout. Reminders stop automatically as soon as an order is placed.

It works with the **classic checkout** and the **block checkout**, is compatible with **HPOS** (High-Performance Order Storage), and does all the heavy work in the background with **Action Scheduler** (WP-Cron fallback). Nothing runs on the front end except one tiny script on the checkout page.

= Capture =

* **Email captured on the fly, with consent** — once the consent box is ticked, the email is sent on blur/change with a debounce, a nonce and server-side `is_email` validation
* **Classic and block checkout** — one delegated listener works with both, through the lightweight `wc-ajax` endpoint
* **Phone, first and last name** captured with the email
* **Guests and registered customers** — logged-in carts are updated every time the cart changes, once the customer has consented
* **Consent first (default)** — with the **Require consent** switch on (default), nothing is saved for guests or logged-in customers (no email, phone or cart) until they tick the consent checkbox under the email field (classic and block checkout). A logged-in customer's consent is remembered in their account and can be withdrawn by unticking the box. The switch can be turned off by the site owner, under their own responsibility: a permanent warning (Law 25, GDPR) is then shown in the admin
* **Consent text with links** — edited per language with a small editor (links, bold, italic, saved with `wp_kses`), for example to link your privacy policy. Left empty, the default text is translated in each visitor's language
* **Abuse protection** — rate limits on the capture endpoint (per IP and per session, `oli_acr_capture_rate_limits` filter) and a honeypot field
* **Cache-proof** — checkout pages send no-cache headers and `DONOTCACHEPAGE`; an expired nonce from a cached page is refreshed automatically
* **Tracked roles** — all users or only the roles you choose
* **Cart snapshot** — products, quantities, variations, total, currency and language, linked to the WooCommerce session and a secure token

= Multilingual =

* **Templates per language** — each template has its own texts (name, subject, heading, content, button) for every active language, edited in language tabs; delay, coupon and status are shared
* **Consent text per language**
* **Works with** TranslatePress, Polylang (with *Polylang for WooCommerce*, or the short snippet in the FAQ), WPML and Weglot (and a single-language site); other plugins can be added with the `oli_acr_lang_adapters` filter
* **Sent in the cart's language** — the language of the checkout page is saved with the cart; recovery and unsubscribe links point to the URL of that language, and the recovery link opens the checkout page of that language
* **Adjustable fallback language** for carts in an unknown or deactivated language
* **WPML String Translation and Polylang strings** — texts of the fallback language are registered, so they can also be translated there

= Reminders =

* **Sent in the customer's language** — each reminder is sent in the language saved with the cart (`switch_to_locale`). Default templates stay translatable until you edit them
* **Several email templates**, each with its own delay, subject, heading, reply-to address, content, recovery button label and active/inactive status
* **Sent in sequence** — reminder #1, then #2, and so on, counted from the moment the cart was abandoned
* **Placeholders** — `{first_name}`, `{last_name}`, `{full_name}`, `{email}`, `{cart_items}`, `{cart_total}`, `{recovery_link}`, `{recovery_button}`, `{coupon}`, `{coupon_code}`, `{unsubscribe_link}`, `{site_name}`, `{site_url}`, `{order_number}`, `{order_date}`
* **WooCommerce email look** — messages are wrapped in the WooCommerce email template (colors, header, footer), with a plain-text part (multipart/alternative)
* **Retries after a failed send** — a failed reminder gets the *Send failed* status and is retried after 5 minutes, 30 minutes and 2 hours (`oli_acr_retry_delays` filter); the cause is shown in the admin and logged in WooCommerce > Status > Logs, even without `WP_DEBUG`
* **Unique coupons** — optional single-use coupon per reminder (prefix, percentage or fixed amount, validity), restricted to the customer's email and applied automatically when the recovery link is clicked; created only when the email shows it (`{coupon}` or `{coupon_code}`)
* **Coupon clean-up** — used and expired generated coupons are moved to the trash daily
* **Pending orders** — reminders for unpaid orders with their own delay and templates, with a secure "pay for order" link
* **Send a test** — send any template to the address of your choice
* **Send now** — send the next reminder of a cart or pending order manually
* **Admin notification** — a WooCommerce email is sent to the admin when a cart or pending order is recovered

= Stop, unsubscribe and privacy =

* **Automatic stop** — as soon as an order is placed with the same session or email, the cart is marked *Recovered* and linked to the order
* **Unsubscribe link** in every email (with `List-Unsubscribe` and `List-Unsubscribe-Post` one-click headers, RFC 8058) and an **exclusion list** you can edit
* **No email ever sent** to unsubscribed addresses
* **Personal data exporter and eraser** for the WordPress privacy tools
* **Suggested privacy policy text**
* **Automatic clean-up** — old unrecovered carts are deleted, old reminded pending orders can be cancelled, and all data is kept only for the retention period you choose

= Dashboard and reports =

* **Abandoned carts** list (WP_List_Table) with status filters, search, bulk delete and row actions
* **Pending orders**, **Recovered** orders and **Email log** (sent, clicked, recovered, coupon)
* **Reports** — abandoned carts, emails sent, clicks, recovered carts and pending orders, recovery rate and recovered revenue, by period (7, 30, 90 days, 12 months, all time), by day and by template
* **Shop manager access** — optional, through the dedicated `oli_acr_manage` capability

= Lightweight by design =

* Two custom tables with indexes, created with `dbDelta` and a schema version
* One indexed `UPDATE` marks carts as abandoned; reminders are processed in batches (`oli_acr_batch_size` filter), under an atomic lock with a time budget so two runs never send the same reminder
* No work on the front end besides the checkout script (about 3 KB, no jQuery); settings are autoloaded, so no extra query on other pages
* Works with HPOS and with the legacy order storage
* No external service, no tracking pixel

== Installation ==

1. Upload the `oli-abandoned-cart-recovery` folder to `/wp-content/plugins/`, or install the zip from **Plugins > Add New > Upload Plugin**
2. Activate the plugin through the **Plugins** menu in WordPress (WooCommerce must be active)
3. Go to **WooCommerce > Abandoned Carts > Settings** and choose the abandonment delay
4. Go to the **Email templates** tab, review the templates and activate the ones you want
5. Click **Send a test** to check the email

== Frequently Asked Questions ==

= Is it compatible with multilingual plugins? =

Yes: TranslatePress, Polylang, WPML and Weglot are detected automatically (in that order of priority: WPML, Polylang, TranslatePress, Weglot, then WordPress itself). The language of the checkout page is saved with the cart, the reminder is sent in that language, and its links open the store in that language. Under Abandoned Carts > Email templates, each template has one tab per active language. Polylang needs *Polylang for WooCommerce* or a short snippet: see "Polylang: what do I need?" below.

= Polylang: what do I need? =

Free Polylang alone translates the pages, but WooCommerce does not recognize the translated cart and checkout pages: on those pages the capture script is not loaded and **no cart is saved** in the other languages. Install **Polylang for WooCommerce**, or link the translated pages to WooCommerce with this snippet (in a small plugin or your theme's `functions.php`):

`add_filter( 'woocommerce_get_checkout_page_id', function ( $id ) { return function_exists( 'pll_get_post' ) && pll_get_post( $id ) ? pll_get_post( $id ) : $id; } );`
`add_filter( 'woocommerce_get_cart_page_id', function ( $id ) { return function_exists( 'pll_get_post' ) && pll_get_post( $id ) ? pll_get_post( $id ) : $id; } );`

Until one of them is in place, the plugin shows an admin notice.

= Which text is used for a language? =

For each field, in this order: the text written in that language's tab; the WPML String Translation or Polylang string translation of the fallback language text; the default text translated in that language (if the fallback text is still the default one); then the fallback language text. The same order applies to the consent text.

= What is the fallback language? =

The language used when a cart's language is unknown or no longer active, and for texts missing in a language. By default it is the site's default language (or the multilingual plugin's default language); you can change it in Settings.

= How do I add another multilingual plugin? =

Extend the `OLI_ACR_Lang_Adapter` class (languages, current language, URL conversion, optional string translation) and add an instance with the `oli_acr_lang_adapters` filter.


= Does it capture guests who never place an order? =

Yes, once they consent. With the default settings, the email and the cart are saved only after the guest ticks the consent box on the checkout page; the email is then sent on blur or change, with the cart content. No account or order is needed. If you turn consent off (your responsibility), the email is saved as soon as it is typed.

= Does it work with the block checkout? =

Yes. The same script listens to the email field of the classic checkout and of the block checkout.

= When is a cart considered abandoned? =

When it has had no activity for the delay set in Settings (60 minutes by default). Each template is then sent after its own delay, counted from that moment.

= What happens when the customer comes back? =

The recovery link refills the cart, pre-fills the email, applies the coupon of the email (if any) and redirects to the checkout. As soon as the order is placed, the cart is marked *Recovered* and no other reminder is sent.

= Is it compatible with HPOS? =

Yes. Orders are only accessed through the WooCommerce CRUD API and compatibility is declared for custom order tables and the cart/checkout blocks.

= Does it slow down my store? =

No. The capture request only runs on the checkout page. Detection and sending run in the background with Action Scheduler, in batches, on indexed queries.

= Are customers tracked without consent? =

No, not by default. The **Require consent** switch is on by default and applies to guests and to logged-in customers: until the box is ticked, nothing is sent to the server or saved (no email, phone or cart), and no reminder is sent. A logged-in customer who ticked the box once is remembered (the box is shown ticked and can be unticked to withdraw consent). This follows Quebec's Law 25 and the GDPR, which require express consent before collecting personal information for marketing reminders. You can turn the switch off in Settings — guests and logged-in customers are then tracked without the box — but you become responsible for having another legal basis, and a warning stays in the admin. Carts saved while the switch is off are marked as captured without consent: if you turn the switch back on, they are not reminded (unless the customer ticks the box later). You can also choose to never track guests. Reminders for pending (unpaid) orders do not depend on this switch.

= I upgraded from 1.0.x and my store was in "Always" mode. What changed? =

Version 1.1.0 turns consent on for these stores (the switch is on) and shows an admin notice until you dismiss it. You can turn consent off again in Settings, under your own responsibility.

= Are carts saved by 1.0.x still reminded after the update? =

No. Version 1.0.x did not record whether a cart was saved with the consent box ticked or in "Always" mode (a store may also have switched modes before updating). To be safe, every cart saved by 1.0.x — guests and logged-in customers — is marked as captured without consent and is not reminded until the customer ticks the box again in 1.1.0. Pending order reminders are not affected.

= What happens when an email cannot be sent? =

The cart gets the *Send failed* status with the error message, an admin notice is shown and the error is logged in WooCommerce > Status > Logs (source `oli-abandoned-cart-recovery`), even without `WP_DEBUG`. The reminder is retried after 5 minutes, 30 minutes and 2 hours, then the cart stays *Send failed*; use **Send next reminder now** once your mail settings are fixed.

= In which language are reminders sent? =

In the language saved with the cart (site language, or WPML / Polylang language). Default templates and the default consent text are translated in that language as long as you did not edit them. An edited template is sent as written, in a single language.

= How do I remove all data? =

Deleting the plugin from the Plugins screen removes its tables (carts and email log), options, capability, scheduled actions with their Action Scheduler logs and group, WP-Cron events, the plugin's WooCommerce log files, order, user and coupon metadata (including the block checkout consent field), the plugin's keys in WooCommerce sessions, and generated coupons that were never used. Generated coupons that were used stay in WooCommerce, because they are linked to orders; only the plugin's marker is removed. To keep everything, tick **Keep the data when the plugin is deleted** in Settings (off by default).

= Which languages are supported? =

English, French (Canada) and French (France). A POT file is included.

== Privacy ==

The plugin stores, in the store's own database: the email, phone, first and last name typed on the checkout page, the cart content, and the reminder email history. No data is sent to a third party. A suggested text is added to **Settings > Privacy**, and the data can be exported or erased with the WordPress privacy tools.

== Screenshots ==

1. Dashboard: recovery stats (abandoned carts, emails sent, clicks, recovery rate, recovered revenue), report by day and report by template
2. Abandoned carts list with status filters (active, abandoned, reminded, recovered, unsubscribed, send failed), including the error of a failed send
3. Email template editor: language tabs, placeholders, unique coupon and "Send a test"
4. Settings: the "Require consent" switch turned off, with the Quebec Law 25 / GDPR warning (consent is on by default)
5. Cart reminder email received by the customer, with the cart items and the recovery button

== Changelog ==

= 1.1.0 =
* New: consent switch (on by default) that applies to guests and logged-in customers; when off, a permanent Law 25 / GDPR warning is shown in the admin
* New: consent text edited per language with links, bold and italic (`wp_kses`)
* Privacy: logged-in customers must also tick the consent box (consent remembered in their account, withdrawable); 1.0.x logged-in carts without consent are no longer reminded
* Privacy: stores upgraded from 1.0.x in "Always" mode switch to consent, with an admin notice; carts saved by 1.0.x (which did not record how consent was given) and carts captured in 1.1.0 while the switch is off are never reminded once consent is required, until the customer ticks the box
* New: retries after a failed send (5 min, 30 min, 2 h) with the *Send failed* status, the error in the carts list, an admin notice and an error log entry, even without `WP_DEBUG`
* New: plain-text part in reminder emails (multipart/alternative)
* New: rate limits and honeypot on the capture endpoint
* Fix: legacy order storage (HPOS off) — no more `meta_query` notice; already processed pending orders no longer block newer ones
* Fix: atomic lock with renewal and time budget, and each cart is claimed before sending, so slow SMTP runs never overlap
* Fix: checkout pages are never cached (no-cache headers, `DONOTCACHEPAGE`), and an expired capture nonce is refreshed automatically
* Performance: settings and version options are autoloaded (2 fewer queries per page)
* New: templates and consent text per language, edited in language tabs; default templates are created in every active language
* New: language adapters for TranslatePress, Polylang, WPML, Weglot and WordPress core, with the `oli_acr_lang_adapters` filter to add others
* New: reminders sent in the cart's language (language of the checkout page); recovery and unsubscribe links use the URL of that language, the recovery link opens the checkout page of that language, and the unsubscribe page is shown in that language
* New: adjustable fallback language
* New: fallback language texts registered in WPML String Translation and Polylang; documented priority order
* Fix: no coupon is created when the email does not show it (no `{coupon}` or `{coupon_code}`), with a warning in the template list and editor
* New: notice when Polylang is active without Polylang for WooCommerce (translated checkout pages not recognized)
* Fix: "1 hour" / "1 hours" plurals and the date of the failure notice in the site's date and time format
* New: `{coupon_amount}` placeholder and a translatable coupon introduction sentence in the default templates (removed when there is no coupon)
* New: `oli_acr_translate_url` filter
* Fix: with the site in English, the admin showed template names, subjects and content in French (list, editor, log, "Send a test"); the admin now shows templates in the admin language or in the selected language tab, and "Send a test" uses that language
* Fix: uninstall also removes the block checkout consent value (`_wc_other/oli-acr/consent`) stored in the customer data of WooCommerce sessions
* Upgrade: 1.0.x templates and consent text become the fallback language texts; unchanged default templates get their texts in every active language


= 1.0.1 =
* Privacy: guests are now captured only with explicit consent by default (Quebec Law 25, GDPR); without consent, no email, phone or cart is sent or saved
* Fix: "Send next reminder now" refuses recovered, unsubscribed or finished carts; nonces are tied to each cart and order, and the sequence continues after a manual send
* Fix: uninstall removes Action Scheduler logs and group, the plugin's WooCommerce log files, consent metadata, session keys and unused generated coupons
* New: "Keep the data when the plugin is deleted" option (off by default)
* Fix: reminders are sent in the language saved with the cart; default templates and the default consent text follow that language until edited
* Fix: the default consent text is no longer frozen in one language when settings are saved
* Fix: one-click unsubscribe header (List-Unsubscribe-Post)
* Fix: the empty image column is removed from the email items table, and the coupon border uses the WooCommerce email base color
* Fix: email addresses no longer break mid-address in the admin Customer column
* Fix: test emails contain a dummy unsubscribe link with no effect
* Code quality: WordPress Coding Standards (Extra, Docs), PHPCompatibility 7.4+, PHPStan level 6 and Plugin Check

= 1.0.0 =
* Initial release
* Email and phone capture on the classic and block checkout, guests and registered customers
* Consent mode and tracked roles
* Multiple email templates sent in sequence, unique coupons, coupon clean-up
* Pending order recovery and automatic cancellation of old reminded pending orders
* Secure recovery link, unsubscribe link and exclusion list, automatic stop on order
* Admin notification for recovered carts and orders
* Dashboard, reports, carts, pending orders, recovered and email log tabs
* Shop manager access with a dedicated capability
* Privacy exporter, eraser and policy text
* HPOS and cart/checkout blocks compatibility
* French (Canada) and French (France) translations

== Upgrade Notice ==

= 1.1.0 =
Multilingual templates and consent text. Consent is now required for guests and logged-in customers (a store in "Always" mode switches to consent, with a notice); carts saved by 1.0.x are no longer reminded until the customer consents again. Failed reminders are retried.


= 1.0.1 =
New installs capture guests only with consent (Law 25). Several fixes: manual send, uninstall clean-up, reminder language, one-click unsubscribe.

= 1.0.0 =
Initial release.
