=== Pirkoo – Multi-Store WooCommerce Manager – Monitoring & Reports ===
Contributors: tvarkom
Tags: woocommerce, multi-store, monitoring, reports, uptime
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.7.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage, monitor and report on all your WooCommerce stores from one dashboard, with your team.

== Description ==

Connect your WooCommerce stores to **Pirkoo** and see every store's health, sales and upkeep in one place, with your team, and act on what needs attention.

_Your first company on Pirkoo is free for 1 store, with no time limit. Paid plans add every report, email alerts, the steps to fix each problem, one-click updates and your whole team._ **[Get started at app.pirkoo.com](https://app.pirkoo.com/)**

= What is Pirkoo? =

Pirkoo is a Multi-Store WooCommerce Manager, made for store owners and for the agencies that look after them. Connect a store in a click: Pirkoo checks it every day, watches it around the clock and turns its orders, customers and products into clear reports, next to all your other stores.

**At a glance:**

* ✅ A health score for every store, from 44 checks
* ✅ Uptime monitoring every 5 minutes, with email alerts
* ✅ A daily performance test with Google PageSpeed
* ✅ A weekly hunt for broken links and images
* ✅ Plugin and theme updates in one click; WordPress updates pointed out
* ✅ Known vulnerabilities and PHP errors
* ✅ Orders, refunds, customers and coupons
* ✅ Product, category and stock reports
* ✅ Teams with roles, for agencies and their clients
* ✅ In English and Brazilian Portuguese
* 💥 Free for 1 store; paid plans for more (see the FAQ)

= 🩺 Store Health Score =

Every store gets a **score out of 100** and a risk level, updated every day. Pirkoo looks at your store the way a shopper does, and from the inside: speed, cart and checkout, database, background tasks, security and email.

* See each store's score and how it changed over time.
* The problems that cost the most come first.
* Short steps to fix each one, with recommended plugins.
* Every check at a glance, in red, orange, yellow or green.
* A checkout speed test from inside your store, to find what slows it down.

= 📈 Uptime & Performance Monitoring =

Know your store is down before your customers tell you.

* Your home page is checked every 5 minutes.
* An email when a store goes down, and another when it's back.
* Response times and HTTPS certificate expiry.
* A daily Google PageSpeed test, on mobile and desktop.
* Every week, up to 200 pages checked for broken links and images.

= 🔄 Updates & Security =

Keep every store up to date without logging in to each one.

* Every store's plugins, themes and WordPress version, with the updates waiting.
* Update plugins and themes in one click from Pirkoo; WordPress updates are shown, and done from your store's admin.
* Install and activate the WordPress.org plugins Pirkoo recommends for a problem it found, from Pirkoo.
* Known vulnerabilities in what you have installed.
* The PHP errors of the last 7 days, grouped.
* Warnings about exposed backups, error messages shown to visitors, open registration and more.

= 🛒 Sales Reports =

Your stores' orders and customers, updated every day, without slowing the stores down.

* Orders and refunds from the last 12 months.
* Customers and what they bought.
* Coupons and how often they're used.
* Only your company's Owners and Admins see who your shoppers are.

= 👕 Product & Stock Reports =

* Products, variations and categories, with what they sold.
* Top sellers.
* Stock velocity: how fast each product sells.
* Sales by variation attribute, such as size or colour.
* Products often bought together.

= 👥 Made for Agencies & Teams =

* All your stores, or all your clients' stores, in one dashboard.
* Invite your team as Owners, Admins, Members or Viewers.
* Log in with a link sent to your email: no password to remember.
* Use Pirkoo in English or Brazilian Portuguese.
* White label: show the plugin under your agency's name, description and author on your clients' Plugins screens, or hide it there.

= ⚡ Light on Your Store =

The plugin has no settings to fill in and sends nothing until you connect. Pirkoo does its work on its own servers: the plugin only answers its requests, once a day at night and when you ask. If your firewall blocks Pirkoo, it can connect the other way round.

= 🚀 Get Started in 3 Steps =

1. Install and activate Pirkoo. Nothing is sent yet.
2. On the Plugins screen, click **Connect to Pirkoo** under its name.
3. Log in at app.pirkoo.com with your email and confirm the connection.

👉 **[Open app.pirkoo.com](https://app.pirkoo.com/)**

== External services ==

The technical details of what the plugin sends, when and why.

This plugin connects your store to Pirkoo (https://app.pirkoo.com), run by MB TVARKOM SOFTWARE (Lithuania). Nothing is sent until an administrator clicks **Connect to Pirkoo** on the Plugins screen and confirms on app.pirkoo.com, right under a list of what is collected, which accepts Pirkoo's Privacy Policy.

Once connected, app.pirkoo.com sends requests signed with a secret shared only with your store to the plugin's routes (`/wp-json/pirkoo/v1/`), and the plugin answers with:

* **Scans** (once a day at night in your store's timezone, and when you ask): store settings and versions, database and server figures, background task health, site and security checks, order and sales counts for the last 30 days, and a profile of one checkout page load (nothing is ordered). Never customers' details, option contents, task arguments or payment settings.
* **WordPress management** (once a day, and when you open those pages): WordPress's version and each installed plugin and theme with their updates, and the PHP errors of the last 7 days, with emails, IP addresses and links removed. A plugin or theme update runs only when an Owner or Admin clicks Update; WordPress itself is never updated from Pirkoo. From 0.6, an Owner or Admin can also install and activate one of the WordPress.org plugins Pirkoo recommends (only those): the plugin downloads it from WordPress.org (`https://api.wordpress.org/plugins/info/` and `https://downloads.wordpress.org/`), as Plugins → Add New does.
* **Store records** (the last 12 months when you connect, then what changed, daily): orders with the customer's billing and shipping names, email, phone, company and addresses, the order's notes, the note written at checkout and its custom fields; registered customers; products, variations, categories and coupons. Never IP addresses, browser details, payment details or custom fields whose names suggest secrets. Pirkoo processes this data on your behalf and keeps orders 12 months; ask privacy@pirkoo.com for a data processing agreement.

**White label** (from 0.7): app.pirkoo.com also sends the name, description, author, author address and logo address your company set for the plugin, or that it should be hidden, and the plugin shows itself that way on the Plugins screen and Dashboard → Updates. Nothing is sent back. Disconnecting removes it.

In relay mode (only for a store whose firewall blocks Pirkoo, and only if an administrator accepts it), the plugin fetches the same requests from app.pirkoo.com and sends back the same answers, every 5 minutes.

Without the plugin, app.pirkoo.com checks your home page every 5 minutes, reads up to 200 of your public pages once a week for broken links, and once a day sends only your home page's address to Google PageSpeed Insights (terms: https://developers.google.com/terms, privacy: https://policies.google.com/privacy). Vulnerabilities come from Wordfence Intelligence's public list (https://www.wordfence.com/threat-intel/); nothing about your store is sent to Wordfence.

For its core files check, the plugin asks WordPress.org (`https://api.wordpress.org/core/checksums/1.0/`) for the official checksums of your WordPress version and language, as WordPress does for its own updates. WordPress.org's privacy policy: https://wordpress.org/about/privacy/

To stop, click **Disconnect from Pirkoo** on the Plugins screen (**Disconnect** when the plugin shows under your agency's name; when it's hidden there, turn off hiding in Pirkoo's Settings → White label first, or deactivate it with WP-CLI). Uninstalling deletes the plugin's settings, secret and data from your store; to have Pirkoo's copies deleted sooner than their retention periods, email privacy@pirkoo.com.

* Privacy Policy, with everything collected and how long it's kept: https://pirkoo.com/privacy/ (also shipped with the plugin, `legal/privacy-policy.txt`)
* Terms of Use: https://pirkoo.com/terms/ (also shipped with the plugin, `legal/terms-of-use.txt`)

== Frequently Asked Questions ==

= How is the health score worked out? =

Pirkoo's scanner runs 44 checks, stage by stage: server, store pages, front end, database, background tasks, platform, security and email. Every problem a check finds lowers the score by a share of what's left: more for the cart, checkout, HTTPS, search visibility and registration, less for upkeep issues. Checks that couldn't be measured, or don't apply to your site (for example, search engines blocked on a staging site), are left out. A score of 80–100 is low risk, 50–79 moderate and 0–49 high. Click a check in the app to see what was measured and against which reference.

= Does it slow down my store? =

No. Apart from adding one to a daily total when a shopper adds to cart, opens the checkout or orders, the plugin runs only when Pirkoo scans the store (once a day, at night in your store's timezone, and when you ask): it collects its figures and loads your checkout once to profile it. Nothing runs in the background. The scanner runs on Pirkoo's servers and loads your public pages like one visitor would.

= Why does my store need to be public? =

Pirkoo's scanner measures your store from the internet, like a shopper. Stores on localhost, behind a password or in maintenance mode can't be scanned, and app.pirkoo.com needs to reach the WordPress REST API at `/wp-json/pirkoo/` (allow it in a security plugin or firewall that blocks it).

= How often is my store scanned? =

Once a day on its own, between 02:00 and 05:00 in your store's timezone (skipped if a scan ran in the 12 hours before). You can also click **Scan now** in the app, from 10 minutes after the previous scan finishes, up to 30 times a day.

= How much does Pirkoo cost? =

The plugin is free. On app.pirkoo.com, a person's first company is free for 1 store, with no time limit. Free gives the health score, its checks and a daily scan (Scan now once a day), uptime checks every 15 minutes without email alerts, a weekly PageSpeed test, a monthly broken-link check, vulnerabilities and PHP errors, the updates waiting (run from the store's admin), and the revenue, orders and products of the last 30 days, for the company's Owner alone.

Paid plans add everything else: every report for 12 months, the steps and plugins to fix each problem, uptime every 5 minutes with email alerts, daily PageSpeed, weekly broken links, one-click updates and installs, and from Growth up your whole team with roles and white label. Plans are for 1, 10, 20 or 40 stores (more on request), paid monthly or yearly, with their prices, VAT included, on the app's Billing page. Plans are for businesses and are paid through Stripe; cancel at any time from the app's Billing page. A company holding more stores than its plan allows is paused (dashboards and history stay; scans, the daily copies, updates and monitoring pause) until it removes stores or buys a larger plan.

= Does it send anything on its own? =

No. Nothing is sent before the store is connected, and after that only when app.pirkoo.com asks the plugin for a scan's data: once a day, and when someone starts a scan. To stop, disconnect the store; to remove its settings, delete the plugin.

= What if my wp-admin has another address? =

The Connect and Disconnect links are built with WordPress's own admin address (`admin_url()`), which plugins that rename wp-admin (for example to /meu-painel) change too, and app.pirkoo.com only calls `/wp-json/pirkoo/`, never wp-admin.

= Who makes Pirkoo? =

Pirkoo is made by tvarkom (MB TVARKOM SOFTWARE, Lithuania), which builds diagnostic tools for WooCommerce stores. Contact: support@pirkoo.com.

== Changelog ==

= 0.7.1 =
* The plans of app.pirkoo.com from 23 November 2026: the readme says what the free plan includes and what paid plans add; the Terms of Use (version 0.4.5) shipped with the plugin describe the free plan, monthly or yearly plans with VAT included and a 60-day money-back guarantee, and the Privacy Policy what is copied and kept for a store on the free plan. No change in what the plugin does.

= 0.7.0 =
* White label: the company managing the store can set, at app.pirkoo.com, the name, description, author and logo the plugin shows on the Plugins screen and Dashboard → Updates, or hide it there. Disconnecting removes it.

= 0.6.2 =
* The Plugins screen now lists the plugin simply as "Pirkoo".

= 0.6.1 =
* WordPress itself is no longer updated from app.pirkoo.com: the app shows the update waiting and links to the store's Dashboard → Updates. Plugin and theme updates are unchanged.

= 0.6.0 =
* Install and activate from app.pirkoo.com: an Owner or Admin can install one of the WordPress.org plugins Pirkoo recommends for a problem it found, and the plugin activates it. Only those plugins can be installed this way.
* Fix: an update from app.pirkoo.com on a server where WordPress can't write to its own files (it would ask for FTP details) could be reported as done although nothing changed. Plugin and theme updates and installs now check first and say why they can't.

= 0.5.0 =
* The records app.pirkoo.com copies now carry what its order, customer, product and coupon pages show: billing and shipping addresses, order notes, the note written at checkout, custom fields (leaving out any whose name suggests a secret, card details, an IP address or a user agent), fee and shipping lines, the payment method's id, how and from where an order came (WooCommerce's order attribution), customers' roles, product page and image addresses, and coupons' restrictions. Each scan also sends the address of the store's wp-admin, for the app's Edit links. The Privacy Policy shipped with the plugin lists them.

= 0.4.8 =
* The orders app.pirkoo.com copies now carry their fees and the names of their shipping methods, for the Sales reports. The Privacy Policy shipped with the plugin lists them.

= 0.4.7 =
* Plan prices are shown only in the app: removed from the plugin page and from the Terms of Use (version 0.4.4) shipped with the plugin.

= 0.4.6 =
* Paid plans for more stores on app.pirkoo.com: the readme, the Terms of Use (version 0.4.3) and the Privacy Policy shipped with the plugin describe them.

= 0.4.5 =
* A person's first company on app.pirkoo.com is now free for 1 store: the readme, the Terms of Use (version 0.4.2) and the Privacy Policy shipped with the plugin say so.

= 0.4.4 =
* Plugin page wording.

= 0.4.3 =
* A shorter plugin page.

= 0.4.2 =
* A new name, Pirkoo - Multi-Store WooCommerce Manager - Monitoring & Reports, and a new plugin page.

= 0.4.1 =
* Pirkoo is now the Multi-Store WooCommerce Manager.
* A person's first company on app.pirkoo.com is free for up to 3 stores, with no time limit: the readme, the Terms of Use (version 0.4.1) and the Privacy Policy shipped with the plugin say so, and no longer describe a 14-day trial.

= 0.4.0 =
* Relay mode, for a store whose firewall blocks app.pirkoo.com: when the app can't reach the plugin, it offers to connect the other way round, and the store then calls app.pirkoo.com for its requests (when the app asks, and every 5 minutes).
* In Brazilian Portuguese too, as is app.pirkoo.com (choose the language in its account menu).
* Works behind more firewalls: the plugin's own signed routes pass a site that blocks the REST API for visitors who aren't logged in, and app.pirkoo.com reaches the plugin through `?rest_route=` when `/wp-json/` is blocked.
* Plugins, themes and WordPress core in app.pirkoo.com, with their updates, an Update button for Owners and Admins, known vulnerabilities and the store's PHP errors of the last 7 days.
* Each scan sends the installed plugins' folder names and whether each is active, so app.pirkoo.com can tick the recommended plugins your store already has.
* app.pirkoo.com shows your store's orders, refunds, customers, products, variations, categories, top sellers, products bought together, stock velocity, variation attributes and coupons: the plugin answers its signed requests for them (the last 12 months when you connect, then what changed, once a day).

= 0.3.2 =
* The readme and the suggested privacy policy text describe Pirkoo's daily scans, and link the Terms of Use at https://pirkoo.com/terms/.
* The Privacy Policy and Terms of Use shipped with the plugin cover app.pirkoo.com: accounts, companies and daily scans.

= 0.3.1 =
* Stores with plain permalinks or WordPress in a subfolder can connect: the plugin tells Pirkoo its REST address.
* Each scan sends the titles your store gives its payment and shipping methods, so the app shows them as your shoppers see them.

= 0.3.0 =
* Pirkoo becomes a connector to app.pirkoo.com: Connect to Pirkoo and Disconnect from Pirkoo on the Plugins screen; scans start in the app, which asks the plugin for the store's data with signed requests.
* The WooCommerce → Pirkoo page, its dashboard and the store's key are removed (an update from 0.2.x deletes the key: connect the store again).
* The store's name, timezone, date and time formats and language are sent with each scan, for the app's pages.

= 0.2.3 =
* Everything the plugin creates on your site is named after Pirkoo: its settings, its table of daily totals, its page's styles and the address the service checks when connecting (`/wp-json/pirkoo/v1/verify`).

= 0.2.2 =
* Pirkoo's own address: the plugin now talks to https://api.pirkoo.com; the Privacy Policy is at https://pirkoo.com/privacy/; help at support@pirkoo.com and privacy requests at privacy@pirkoo.com.

= 0.2.1 =
* The privacy policy shipped with the plugin is the current text, the same as tvarkom.com/privacy/.

= 0.2.0 =
* The plugin's page is WooCommerce → Pirkoo.
* A new dashboard: the store summary, the store health score with how many checks it's based on, what needs attention first, your store's health trends, and your store's profile (sales and checkout as counted, catalog, operations, traffic and marketing).
* Every check now counts towards the score, by how much it matters; earlier scores keep the scoring model they were made with and aren't compared with new ones.
* The scan also sends the site's WordPress environment type, so a staging copy isn't marked down for blocking search engines or showing debug output.
* The store pipeline: every check as a coloured job, with what was measured and its reference.
* New checks: compression, WordPress and theme updates, debug output, search engine visibility, MyISAM tables and character set, expired transients, administrators and the file editor; upload limit, PHP extensions, disk space, server load and memory; paused plugins, fatal errors, automatic updates and permalinks; database latency and clutter; core files against WordPress.org's checksums, readable files, wp-config.php permissions, an "admin" username and open registration.
* Each scan now sends more store data (business context and a profile of one checkout page load), plus the site checks above, listed under External services; the plugin keeps a small table of daily shop totals.

= 0.1.0 =
* First release: scan on demand (once a week), and a dashboard with the score, the points lost, server response, homepage weight, database and server figures, HTTPS and email records, and the score over time.
