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

Connect your WooCommerce store to the ARkid catalogue and embed 3D / AR viewers on the product display page.

== Description ==

ARkid Catalogue Link connects your WooCommerce store to the ARkid 3D / AR
viewer catalogue. Pick a viewer from your ARkid catalogue inside the product
edit screen and it renders on the product display page at one of five
configurable positions: first image, last image, above product, below product,
or as a standalone "View in 3D" button.

Features:

* Live-fetched viewer picker on every product — you always see the current
  catalogue, never a cached copy.
* Five rendering positions; first/last image plug into the product gallery,
  last-image opens the viewer in a modal.
* Both the classic FlexSlider gallery and the new block-based product gallery
  are supported.
* General-purpose Gutenberg block for embedding any viewer on any page.
* Viewer admin list with type badges and external Edit / Analytics links.
* Product pages make no API calls at all — each product stores its own viewer
  link, so your storefront keeps working even if ARkid is unreachable. A daily
  background job keeps those links in step with your catalogue.
* Optional WP Consent API integration, so the viewer only loads once a visitor
  has granted marketing consent.

== External services ==

This plugin connects to the ARkid catalogue API at https://catalogue.arkid.app
to fetch the viewers you select, and embeds a viewer from that domain in an
iframe on your product pages.

What is sent, and when:

* Your API key — server to server, when you browse viewers in wp-admin, when
  you save a product, and during the daily refresh job. It is never exposed to
  visitors.
* The viewer ID and `source=woocommerce` — from your visitor's browser when the
  viewer iframe loads on a product page.

No customer or order data is sent to ARkid.

Terms of service: https://catalogue.arkid.app/terms
Privacy policy: https://catalogue.arkid.app/privacy

== Installation ==

1. Upload the plugin zip via Plugins → Add New → Upload Plugin, or extract it
   into `/wp-content/plugins/arkid-catalogue-link/`.
2. Activate the plugin via the WordPress Plugins screen.
3. Enter your API key under WooCommerce → Settings → Integration → ARkid
   Catalogue Link.
4. Edit any product and pick a viewer from the "ARkid Catalogue Link" meta box.

== Frequently Asked Questions ==

= My viewer doesn't show up on the product page =

Check that WooCommerce's "Coming Soon" mode is OFF (WC → Settings → Site
visibility → Live). When Coming Soon is on, every storefront URL is replaced
with a placeholder.

If the product edit screen shows "This viewer has not been synced from ARkid
yet", the plugin has a viewer selected but has not been able to fetch its
details — usually a wrong API key or a temporary connection problem. It
retries in the background and appears once the connection succeeds.

= Can I use this on a block theme? =

Yes. Both the classic FlexSlider gallery and the new `woocommerce/product-gallery`
block are supported. The `above_product`, `below_product`, and `button_only`
positions are theme-agnostic. If a theme or a future WooCommerce release changes
the gallery markup beyond what the plugin recognises, the viewer is rendered
directly below the gallery rather than disappearing.

= What happens to my data if I uninstall? =

The API key, the plugin's settings and its scheduled jobs are removed. The
viewer chosen for each product is deliberately kept, so reinstalling restores
every product's viewer without you re-picking them. On a multisite network this
is done for every site.

= Does it work with variable products? =

Yes, but on the block-based gallery the viewer slide is reset when a visitor
switches variation, because WooCommerce rebuilds the gallery from the
variation's own images. The `above_product`, `below_product` and `button_only`
positions are unaffected.

== Screenshots ==

1. Picking a viewer from the ARkid catalogue on the product edit screen.
2. The viewer rendered below the product on the storefront.
3. The 3D viewer opened in a modal from the product gallery.
4. Integration settings: API key, default position and consent gating.
5. The ARkid Viewers admin list.

== Changelog ==

= 1.0.0 =
* First stable release.
* Fixed: database migrations never ran, because the migrator registered on a
  hook priority that had already passed.
* Fixed: changing the API key was never detected, so a store switching ARkid
  accounts kept serving the previous account's viewers.
* Fixed: the daily refresh loaded every linked product and made one blocking
  request each in a single scheduled task, which could time out and leave the
  refresh permanently half-finished. It now dispatches one task per product.
* Fixed: a product name containing `$1` corrupted the block gallery markup.
* Fixed: on a product with no featured image, the classic gallery replaced
  every image with the viewer instead of only the first.
* Fixed: if ARkid was unreachable while you switched viewers, the product kept
  the previous viewer's link under the new viewer's ID and rendered the wrong
  model.
* Fixed: an HTTP 401 from the API was reported as a connection problem rather
  than a rejected key.
* Removed the 12-hour viewer cache. Product pages render from each product's
  own stored link and make no API calls; admin screens always read live.
* Viewer URLs are now checked against an allow-list before being embedded.
* Uninstall is multisite-aware and removes scheduled jobs.
* Requires WordPress 7.0+ and is tested against WordPress 7.1 and WooCommerce 11.0.

= 0.2.0 =
* Initial release: settings, product picker, PDP rendering at five positions,
  viewer list, general embed Gutenberg block, Action Scheduler refresh job,
  WP Consent API integration.

= 0.1.0 =
* Internal scaffold.

== Upgrade Notice ==

= 1.0.0 =
First stable release. Fixes six defects, including one that could show the wrong
3D model on a product. Product pages no longer wait on the ARkid API. Blocks
inserted before 1.0.0 are repaired when you next open them in the editor.
