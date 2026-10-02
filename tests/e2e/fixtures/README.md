# E2E fixtures

## `single-product-block-gallery.html`

A `single-product` template that uses the **new** `woocommerce/product-gallery`
block.

WooCommerce 11's default single-product template — the one Twenty Twenty-Five
gets — uses `woocommerce/product-image-gallery`, the *legacy* gallery, which
renders `woocommerce-product-gallery__wrapper` and is handled by
`ClassicGalleryInjector`. `BlockGalleryInjector` therefore never runs on a
default install, and nothing in the suite exercises it.

Install it as a template override to cover that path:

```bash
wp --path=/var/www/html post create \
  --post_type=wp_template --post_name=single-product --post_status=publish \
  --post_title='Single Product (block gallery)' \
  --post_content="$(cat tests/e2e/fixtures/single-product-block-gallery.html)"
# then assign it to the active theme:
wp --path=/var/www/html eval 'wp_set_object_terms( <ID>, get_stylesheet(), "wp_theme" );'
```

The `woocommerce/product-image` inner block is required: `ProductGalleryLargeImage`
builds its slide `<ul>` only from that block, so a template without it renders a
gallery with no slide container at all.

Delete the post to fall back to the theme's own template.
