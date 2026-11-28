=== ARkid Catalogue Link ===
Contributors: arkid
Tags: 3d, ar, augmented-reality, woocommerce, product-viewer
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.1
WC requires at least: 8.0
WC tested up to: 11.0
Stable tag: 1.0.0
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Show interactive 3D and AR viewers from your ARkid catalogue on WooCommerce product pages. Pick one per product, no code needed.

== Description ==

**Let shoppers turn the product around, change its colour and place it in their own room.**

ARkid Catalogue Link puts interactive 3D and augmented-reality (AR) viewers from [ARkid Catalogue](https://arkid.app/catalogue/) on your WooCommerce product pages. ARkid Catalogue is a platform for 3D and AR models of baby and juvenile products, signed off by the brands that make them. Pick a viewer from your catalogue on the product edit screen, choose where it appears, and it is live.

= What your shoppers get =

* A 3D viewer they can turn and zoom, with colour and variant options where the model offers them.
* Augmented reality on supported phones and tablets: shoppers place the product in their own room, with no app to install.
* A viewer that loads from ARkid's servers, so the models do not weigh on your hosting. Inline viewers are lazy-loaded; a viewer in a modal only loads when it is opened.

= What you get as a merchant =

* **Pick from your live catalogue** on every product. The picker is searchable and always reads the current catalogue, never a cached copy.
* **Five positions**: first image, last image (opens in a modal), above the product, below the product, or a standalone button (labelled "See in 3D & AR" unless you change it). Set a site default and override it per product.
* **Both product galleries**: the classic FlexSlider gallery and WooCommerce's new block-based product gallery, on classic and block themes alike.
* **A block** for embedding any viewer on any page or post.
* **A viewer list** under WooCommerce → ARkid Viewers: which of your products show each viewer, its colours (the ones it hides are struck through), the interactions it had in the last 30 days, and who changed it last. Filter it (live on a product, not linked, no interactions), search it, sort it, page through it, and preview a viewer without leaving wp-admin. Each viewer links to edit it or see its analytics in ARkid.
* **Product pages make no API calls.** Each product stores its own viewer link, so your shop keeps working even if ARkid is unreachable. A daily background job keeps those links in step with your catalogue.
* **Your API key is never sent to your visitors.** It is stored on your server and shown only to store managers on the settings screen, and viewers are browsed in wp-admin through the plugin's own REST endpoints, restricted to store managers.
* **Optional WP Consent API support**: with "Require visitor consent" on, the viewer is left out of the page for visitors who have not granted marketing consent. Consent is checked when the page is built, so see the FAQ if your site uses page caching.

= What you need =

An ARkid Catalogue account and its API key. The plugin is free; ARkid Catalogue is a separate service from ARkid Technologies GmbH with a free plan (currently up to 10,000 views a month and 10 products) and paid plans that scale with views. See [ARkid pricing](https://arkid.app/pricing/). To get started, [request a free account](https://catalogue.arkid.app/register): ARkid reviews each request and emails you when your account is ready.

= How it works =

1. Install and activate the plugin, then enter your API key under WooCommerce → Settings → Integration → ARkid Catalogue Link.
2. Edit a product, pick a viewer in the "ARkid Catalogue Link" box and, if you like, a position for this product.
3. Update the product and open it on your storefront.

== External services ==

This plugin connects to ARkid Catalogue, a service operated by ARkid Technologies GmbH (Austria), to list the viewers in your ARkid account and to show them to your visitors. It asks ARkid for your viewers only once you have entered an API key, and it shows a viewer to your visitors only on the products and pages where you have placed one. It makes three kinds of request.

**1. The ARkid Catalogue API, from your server (catalogue.arkid.app)**

Used to fetch the viewers in your ARkid account, and, on WooCommerce → ARkid Viewers, your viewing statistics for the last 30 days (how often each viewer was interacted with). A request is made when you open a product that already has a viewer, open or search the viewer picker, open WooCommerce → ARkid Viewers (which asks for the viewers and for the statistics), open a post or page that contains the ARkid block in the editor, save a product that has a viewer, or save a changed API key (the plugin then tests the key and re-syncs your products), and once a day in the background for each product that has a viewer.

Sent: your API key (as the password of an HTTP Basic login whose user name is `woocommerce`), the ID of the viewer being requested, `source=woocommerce`, for the statistics the number of days (`days=30`), and a User-Agent naming the plugin and its version. As with any request, your server's IP address is visible to ARkid. No customer data, order data or product data is sent, and neither is your site's address.

**2. The ARkid viewer, from your visitors' browsers (catalogue.arkid.app)**

The viewer is an iframe loaded from catalogue.arkid.app on your product pages, in the modal, and in the block. It is also loaded inside wp-admin: in the block editor, and when you click "Preview" on WooCommerce → ARkid Viewers or on the product edit screen (only when you click: nothing loads until then). In those cases editors' browsers contact ARkid too. When it loads, the visitor's browser contacts ARkid directly, and the viewer downloads its 3D model files and textures from cloud.arkid.app. According to [ARkid's viewer privacy notice](https://arkid.app/viewer-privacy/), ARkid receives at least the visitor's IP address, browser and operating system, display resolution, the viewer requested, the website it was requested from, and the time of the request. The viewer also reports usage events to ARkid (for example that it loaded or was viewed, how long loading took, whether and for how long AR was used, and errors), together with the browser language and whether the device has touch or AR support. ARkid uses this data to deliver the viewer and to give you aggregate view statistics. ARkid states that the viewer sets no cookies and stores no identifiers on the visitor's device. The viewer shows a small "3D by ARkid" credit that links to https://arkid.app/.

Under ARkid's viewer privacy notice, you as the site operator are the controller for this usage measurement and ARkid acts as your processor under a data processing agreement (Art. 28 GDPR). ARkid keeps raw request logs for at most 14 months, for billing verification and security.

When a visitor starts AR on a phone or tablet, the viewer hands the model to the browser's WebXR support or to the device's own AR viewer (Google Scene Viewer on Android, Apple Quick Look on iOS), which uses the camera and is provided by Google or Apple. The viewer can also show a QR code that opens it on a phone.

If you need a visitor's consent before this happens, turn on "Require visitor consent" in the plugin settings (it needs the WP Consent API plugin). The plugin then leaves the viewer, its preview images and its modal out of the page for visitors who have not granted marketing consent. Consent is checked when the page is built, so if a caching plugin serves your product pages, make it vary on the consent cookie (`wp_consent_marketing`) or exclude those pages; otherwise one visitor's consent state can be served to others.

**3. Preview images (cloud.arkid.app)**

Each viewer has a preview image served from ARkid's content delivery network at cloud.arkid.app, which also serves the viewer's 3D model files (see 2 above). The preview image is shown in wp-admin (the picker, the viewer list and the product edit screen) and on product pages where a viewer sits in the gallery. Requesting it discloses the requester's IP address, browser and the address of your site to ARkid, as any image request does.

* Terms of use: [arkid.app/terms](https://arkid.app/terms/). This covers ARkid's website; your use of ARkid Catalogue itself is governed by the agreement between you and ARkid Technologies GmbH.
* Privacy policy: [arkid.app/privacy](https://arkid.app/privacy/)
* Viewer privacy notice (what visitors' browsers send): [arkid.app/viewer-privacy](https://arkid.app/viewer-privacy/)

== Source code ==

The plugin's JavaScript and CSS are compiled and minified in the `build/` folder. The human-readable source is in `src/` of the public repository at https://github.com/ARkid-Studio/arkid-woocommerce-plugin and can be rebuilt with Node.js 24 and npm:

`npm ci --ignore-scripts && npm run build`

The PHP in `includes/` is not compiled. The `vendor/` folder holds only the Composer autoloader (`composer install --no-dev --optimize-autoloader`); the plugin bundles no third-party libraries.

== Installation ==

You need an ARkid Catalogue account first; the Description tab explains how to get one.

1. Install the plugin from Plugins → Add New (search for "ARkid Catalogue Link"), or upload the zip via Plugins → Add New → Upload Plugin, or extract it into `/wp-content/plugins/arkid-catalogue-link/`.
2. Activate the plugin via the WordPress Plugins screen. WooCommerce must be active.
3. Enter your API key, from your [ARkid developer settings](https://catalogue.arkid.app/settings/developer), under WooCommerce → Settings → Integration → ARkid Catalogue Link.
4. Edit any product and pick a viewer from the "ARkid Catalogue Link" meta box.

== Frequently Asked Questions ==

= Do I need an ARkid account? =

Yes. The viewers come from your ARkid Catalogue account, and the plugin connects to it with your API key. [Request a free account](https://catalogue.arkid.app/register); ARkid reviews each request and emails you when it is ready.

= What does it cost? =

The plugin is free. ARkid Catalogue has a free plan (currently up to 10,000 views a month and 10 products) and paid plans that scale with views. Current plans are on [arkid.app/pricing](https://arkid.app/pricing/).

= Where do I find my API key? =

Sign in to ARkid Catalogue and open your developer settings at [catalogue.arkid.app/settings/developer](https://catalogue.arkid.app/settings/developer). If you cannot find it there, write to support@arkid.app.

= What do the filters of the viewer list mean? =

Under WooCommerce → ARkid Viewers, "Live on a product" lists the viewers that are selected on at least one of your products (drafts and private products count, and are marked, but not products in the trash), and "Not linked" the ones that are not selected anywhere. "No interactions, 30 days" lists the viewers that nobody interacted with (turned, zoomed or opened in AR) in the last 30 days, which includes viewers nobody has opened at all. The counts come from ARkid's statistics; if ARkid cannot be reached for them, the list says so and leaves that filter and the "Interactions" column out rather than showing zeros. "Total views" counts all views since the viewer was created.

= Why doesn't my viewer show up on the product page? =

Check that WooCommerce's "Coming soon" mode is off: WooCommerce → Settings → Site visibility must be set to Live. While "Coming soon" is on, every storefront URL is replaced with a placeholder.

The "ARkid Catalogue Link" box on the product edit screen says what is wrong with a selected viewer:

* "This viewer no longer exists in your ARkid account" — ARkid does not have it (it was deleted, or your API key belongs to another account). It will not come back by itself unless you restore it in ARkid. Pick another viewer or remove it.
* "This viewer has not been synced from ARkid yet" — the plugin has a viewer selected but has not been able to fetch its details, usually a wrong API key or a temporary connection problem. The plugin retries in the background, and the viewer appears once the connection succeeds.
* "ARkid could not be reached, or it did not accept the API key, so this viewer could not be checked just now" — the product page keeps showing the last synced viewer. Check the API key under WooCommerce → Settings → Integration → ARkid Catalogue Link if it keeps happening.

= Can I use this on a block theme? =

Yes. Both the classic FlexSlider gallery and the new `woocommerce/product-gallery`
block are supported. The "Above product", "Below product" and "Button only"
positions are theme-agnostic. If a theme or a future WooCommerce release changes
the block gallery's markup beyond what the plugin recognises, the viewer is
rendered directly below the gallery rather than disappearing. The classic
gallery has no such fallback: if a theme replaces its markup, the "First image"
and "Last image" positions may show nothing, and one of the theme-agnostic
positions is the safe choice.

= How do I show a viewer somewhere other than a product page? =

Add the "ARkid Catalogue Link" block in the block editor on any page or post and pick a viewer.

= Does it slow down my product pages? =

Product pages make no API calls: each product stores its own viewer link. The viewer itself loads from ARkid. Inline viewers are lazy-loaded, and a viewer in a modal only loads when a visitor opens it.

= Does the viewer set cookies, and what about consent? =

ARkid states that the viewer sets no cookies and stores no identifiers on the visitor's device. It does load from ARkid's servers, so the visitor's IP address reaches ARkid; the "External services" part of the Description tab lists what is sent. If you need consent first, enable "Require visitor consent" in the settings (needs the WP Consent API plugin). Consent is checked when the page is built, so if a caching plugin serves your product pages, make it vary on the consent cookie (`wp_consent_marketing`) or exclude those pages.

= Does the viewer show ARkid branding? =

Yes. The viewer shows a small "3D by ARkid" credit that links to arkid.app. It is part of the viewer, which ARkid serves; the plugin has no setting for it.

= What happens to my data if I uninstall? =

The API key, the plugin's settings and its scheduled jobs are removed, and so
are the page size and column choices that users made in Screen Options on the
viewers list. The viewer chosen for each product is deliberately kept, so
reinstalling restores every product's viewer without you re-picking them. On a
multisite network the settings and jobs are removed for every site.

= Does it work with variable products? =

Yes, but on the block-based gallery the viewer slide is reset when a visitor
switches variation, because WooCommerce rebuilds the gallery from the
variation's own images. The "Above product", "Below product" and "Button only"
positions are unaffected.

= Where do I get support? =

For the plugin, use the support forum on WordPress.org. For questions about your ARkid account or catalogue, write to support@arkid.app.

== Screenshots ==

1. Picking a viewer from your ARkid catalogue on the product edit screen.
2. The 3D viewer below the product on the storefront, with colour options and an AR button.
3. The same viewer opened in a modal from the product gallery.
4. Integration settings: API key, default viewer position, button text and consent gating.
5. The ARkid Viewers list: where each viewer is shown, its colours and interactions, with a preview and links into ARkid.

== Changelog ==

= Unreleased =
* Tweak: The ARkid block no longer loads an empty front-end script.
* Fix: Removing a viewer from a product no longer discards the product's position override.
* Fix: The "Button text" setting no longer claims to label the last-image modal button. It labels the standalone "Button only" trigger, as it always did.
* Fix: The viewers list sorts by product and brand without regard to case or accents, and numbers in names by value, instead of putting every capital letter before every lower-case one.
* Fix: The viewer picker search no longer misses non-ASCII names (for example German, Danish, Polish or Japanese brands) and matches every word of the query across brand, product and note, so "felix learning" finds "Felix - Learning Tower".
* Fix: A viewer that was deleted in ARkid is no longer reported on the product edit screen as "not synced yet" with the promise that it will appear once the connection succeeds. The screen now says that the viewer no longer exists in your ARkid account, and tells that apart from ARkid being unreachable.
* New: The viewers list shows which products each viewer is shown on (the first three, then "+N more", which opens the Products list narrowed to that viewer's products), the colours of each viewer, the interactions of the last 30 days, the total views, and when and by whom each viewer was last changed.
* New: The viewers list has views for "Live on a product", "Not linked" and "No interactions, 30 days" with their counts, a search box, type and brand filters, and pages. "Viewers per page" and the columns to show are in Screen Options. On a phone the table collapses to the product column.
* New: A viewer can be previewed in a dialog from the viewers list and from the product edit screen. The viewer loads only when you click Preview.
* New: The colours of a viewer are shown as dots in the viewers list, under the selected viewer on the product edit screen and in the picker's results. A colour the viewer hides is struck through.
* Tweak: The links, thumbnails and previews of the viewers list only use https addresses from ARkid.
* Tweak: The connection to ARkid can now also create and change viewers, and read the catalogue's products, the available environments and your viewing statistics. Of these only the viewing statistics are asked for yet, by the viewers list; the rest is the groundwork for managing viewers from WordPress.

= 1.0.0 =
* Initial release.
