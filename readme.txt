=== Oli Abandoned Cart Recovery ===
Contributors: bigrat95
Tags: abandoned cart, woocommerce, cart recovery, pending orders, coupons
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Recover abandoned WooCommerce carts and unpaid orders with a sequence of reminder emails, unique coupons and clear reports. Classic and block checkout.

== Description ==

**Oli Abandoned Cart Recovery** captures the customer's email as soon as it is typed on the checkout page — even for guests who never place an order — saves the cart, and sends a sequence of reminder emails when the cart is abandoned. Every email has a secure recovery link that refills the cart, pre-fills the email and sends the customer straight to checkout. Reminders stop automatically as soon as an order is placed.

It works with the **classic checkout** and the **block checkout**, is compatible with **HPOS** (High-Performance Order Storage), and does all the heavy work in the background with **Action Scheduler** (WP-Cron fallback). Nothing runs on the front end except one tiny script on the checkout page.

= Capture =

* **Email captured on the fly** — sent on blur/change with a debounce, a nonce and server-side `is_email` validation
* **Classic and block checkout** — one delegated listener works with both, through the lightweight `wc-ajax` endpoint
* **Phone, first and last name** captured with the email
* **Guests and registered customers** — logged-in carts are updated every time the cart changes
* **Consent mode** — optional checkbox under the email field, with your own text (classic and block checkout)
* **Tracked roles** — all users or only the roles you choose
* **Cart snapshot** — products, quantities, variations, total, currency and language, linked to the WooCommerce session and a secure token

= Reminders =

* **Several email templates**, each with its own delay, subject, heading, reply-to address, content, recovery button label and active/inactive status
* **Sent in sequence** — reminder #1, then #2, and so on, counted from the moment the cart was abandoned
* **Placeholders** — `{first_name}`, `{last_name}`, `{full_name}`, `{email}`, `{cart_items}`, `{cart_total}`, `{recovery_link}`, `{recovery_button}`, `{coupon}`, `{coupon_code}`, `{unsubscribe_link}`, `{site_name}`, `{site_url}`, `{order_number}`, `{order_date}`
* **WooCommerce email look** — messages are wrapped in the WooCommerce email template (colors, header, footer)
* **Unique coupons** — optional single-use coupon per reminder (prefix, percentage or fixed amount, validity), restricted to the customer's email and applied automatically when the recovery link is clicked
* **Coupon clean-up** — used and expired generated coupons are moved to the trash daily
* **Pending orders** — reminders for unpaid orders with their own delay and templates, with a secure "pay for order" link
* **Send a test** — send any template to the address of your choice
* **Send now** — send the next reminder of a cart or pending order manually
* **Admin notification** — a WooCommerce email is sent to the admin when a cart or pending order is recovered

= Stop, unsubscribe and privacy =

* **Automatic stop** — as soon as an order is placed with the same session or email, the cart is marked *Recovered* and linked to the order
* **Unsubscribe link** in every email (with `List-Unsubscribe` and one-click support) and an **exclusion list** you can edit
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
* One indexed `UPDATE` marks carts as abandoned; reminders are processed in batches (`oli_acr_batch_size` filter)
* No work on the front end besides the checkout script (about 2 KB, no jQuery)
* No external service, no tracking pixel

= Feature mapping (YITH Recover Abandoned Cart 3.8.0 → Oli) =

This plugin was written from scratch to cover the same needs as the YITH plugin used on client sites. No code, name or text was reused.

* Abandoned cart post type → custom indexed table `{prefix}oli_acr_carts`
* Guest email grab (AJAX on checkout) → `wc-ajax=oli_acr_capture`, classic **and block** checkout
* Guest phone grab → phone captured with the email
* "Recover guest carts: never / always / with terms" → Guest carts: never / always / with consent box, editable text
* User roles selection → Registered customers tracked: all roles / selected roles
* Cut-off time → "Consider a cart abandoned after"
* Cron interval → "Run the recovery task every" (Action Scheduler, WP-Cron fallback)
* Email templates (post type) with delay, subject, content, auto send → Email templates with delay, subject, heading, reply-to, content, button label, active status
* Placeholders `{{ywrac.*}}` → `{first_name}`, `{cart_items}`, `{recovery_button}`, `{coupon}`, `{unsubscribe_link}`…
* Coupon per template (amount, type, validity, prefix) → same, plus email restriction and automatic apply on click
* Delete coupons after use / when expired → same (moved to trash daily)
* Pending order recovery → same, with its own start delay
* Delete pending orders after (hold stock) → Cancel reminded pending orders after
* Delete abandoned carts after → Delete unrecovered carts after + global data retention
* Recover link (encrypted URL) → random 32-character token + log ID
* Unsubscribe page and blacklist → signed unsubscribe link, confirmation page, one-click, exclusion list
* Admin email on recovered cart → WooCommerce email "Recovered cart (admin)"
* Test email → "Send a test" button
* Carts, pending orders, recovered, email log, reports tabs → same tabs
* Shop manager option → same, with the `oli_acr_manage` capability
* Privacy exporter/eraser and policy text → same
* HPOS and block checkout compatibility → declared and tested

== Installation ==

1. Upload the `oli-abandoned-cart-recovery` folder to `/wp-content/plugins/`, or install the zip from **Plugins > Add New > Upload Plugin**
2. Activate the plugin through the **Plugins** menu in WordPress (WooCommerce must be active)
3. Go to **WooCommerce > Abandoned Carts > Settings** and choose the abandonment delay
4. Go to the **Email templates** tab, review the templates and activate the ones you want
5. Click **Send a test** to check the email

== Frequently Asked Questions ==

= Does it capture guests who never place an order? =

Yes. The email is saved as soon as it is typed on the checkout page (on blur or change), with the cart content. No account or order is needed.

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

= How do I remove all data? =

Deleting the plugin from the Plugins screen removes its tables, options, scheduled actions, capability and order metadata. Generated coupons stay in WooCommerce because they may still be in use.

= Which languages are supported? =

English, French (Canada) and French (France). A POT file is included.

== Privacy ==

The plugin stores, in the store's own database: the email, phone, first and last name typed on the checkout page, the cart content, and the reminder email history. No data is sent to a third party. A suggested text is added to **Settings > Privacy**, and the data can be exported or erased with the WordPress privacy tools.

== Screenshots ==

1. Dashboard with recovery stats and reports
2. Abandoned carts list with status filters
3. Email template editor with placeholders, coupon and test button
4. Settings
5. Reminder email received by the customer

== Changelog ==

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

= 1.0.0 =
Initial release.
