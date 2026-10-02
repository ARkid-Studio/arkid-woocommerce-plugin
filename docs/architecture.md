# Architecture notes

How the plugin is put together, and the handful of places where the obvious
implementation is wrong. Read the relevant section before "fixing" anything that
looks odd — most of the oddities are load-bearing.

## Data flow

```text
wp-admin                                     storefront
────────                                     ──────────
product edit screen ──REST──▶ /arkid-catalogue-link/v1/embeds ──▶ ARkid API
        │                     (manage_woocommerce only; the API key never
        │                      leaves the server)
        ▼ save_post_product
post meta snapshot  ─────────────────────────▶ Renderer / gallery injectors
  _arkid_embed_id                                (zero API calls per page view)
  _arkid_embed_url
  _arkid_embed_image
  _arkid_embed_position
        ▲
        └── Action Scheduler: a daily sweep fans out one async task per linked
            product (`arkid_catalogue_link_refresh_interval` filters the period).
            Changing the API key queues a full re-sync, because a new key can
            belong to a different ARkid account.
```

- **Product pages never call the API.** Each product stores a snapshot of its
  viewer's URL and thumbnail, so the storefront keeps working when ARkid is
  unreachable. Admin screens (the picker, the viewer list) always read live —
  there is no cache to go stale.
- **The block does the same.** `arkid-catalogue-link/embed` persists `embedUrl`
  in its attributes, so front-end rendering never needs the API either.
- **If ARkid is unreachable while a merchant switches viewers**, the new ID is
  saved but the URL/image snapshot is dropped rather than left describing the
  previous viewer, and a retry is queued. A snapshot must never describe a
  different embed than the stored ID.

## Embedding and the host allow-list

`IframeFactory::viewer_url()` is the single choke point for every render path.
It returns `''` for anything that is not `https` on an allowed host, and every
builder early-returns on an empty URL. Embed URLs arrive from post meta and from
block attributes in `post_content`, neither of which is host-checked on the way
in, so this check is what keeps an arbitrary iframe off the product page.

- Allowed by default: `catalogue.arkid.app` and its subdomains. Filterable via
  `arkid_catalogue_link_allowed_embed_hosts`.
- **Thumbnails come from `cloud.arkid.app`, which is deliberately not on the
  list.** The allow-list governs iframe sources, not images; applying it to
  thumbnails would blank every one of them. A test pins this.

## Where it hooks into WooCommerce

- **Bootstrap** waits for `plugins_loaded`, and bails with an admin notice if
  the Composer autoloader or WooCommerce is missing. HPOS and Cart/Checkout
  blocks compatibility is declared on `before_woocommerce_init`.
- **Classic gallery**: `woocommerce_single_product_image_thumbnail_html`
  replaces the first slide; `woocommerce_product_thumbnails` @ 30 appends the
  last one.
- **Block gallery**: `pre_render_block` observes the gallery blocks; the
  `render_block_woocommerce/product-gallery` and
  `…/product-gallery-large-image` filters (@ 20) update the Interactivity API
  state and inject the slide with `WP_HTML_Tag_Processor`. See
  [the injected gallery slide](#the-injected-gallery-slide).
- **Above / below / button**: `woocommerce_before_single_product_summary` @ 5,
  `woocommerce_after_single_product_summary` @ 5 and
  `woocommerce_single_product_summary` @ 35. Block themes reach these through
  WooCommerce's template compatibility layer, which is right for "above" and
  the button but wrong for "below" — see [below](#below-product-placement).
- **Settings** are a `WC_Integration`, so they live on WooCommerce's
  Integration tab.

## The ARkid API contract

- `GET /api/ecom/embed` returns `{"data": [...]}`.
  `GET /api/ecom/embed/{id}` returns a **bare object**, not a wrapper.
- Auth is HTTP Basic. The username is always `woocommerce`; the API key is the
  password.
- **`embed_url` already carries `?source=magento`.** `viewer_url()` uses
  `add_query_arg()`, which *replaces* the parameter, so the result has exactly
  one `source=woocommerce`. If that ever became an append, ARkid would credit
  WooCommerce traffic to Magento. Pinned by a test.
- **The API also sends `embed_code`**, a ready-made `<iframe>`. It is ignored:
  ours carries a different sandbox and a `referrerpolicy`.

How the contract is tested — the offline schema test against recorded fixtures,
and the opt-in live check — is in [CONTRIBUTING.md](../CONTRIBUTING.md#the-arkid-api-contract).

## Block themes: two contracts that are easy to get wrong

### "Below product" placement

Block themes never hook the data tabs onto
`woocommerce_after_single_product_summary`. The tabs are the
`woocommerce/product-details` block, and WooCommerce's
`SingleProductTemplateCompatibility` maps that action to `position => after` on
that same block — so the classic priority-5 callback would be buffered and
injected *below* the tabs and reviews.

`Renderer` therefore prepends to the block instead, using **two** hooks:

- `render_block` @ **5** only *claims* the block (`note_product_details_block`).
- `render_block_woocommerce/product-details` @ **20** does the prepending.

Why not just prepend at priority 5? WooCommerce puts two callbacks on
`render_block` at priority 10: `BlockTypesController::add_data_attributes`,
which stamps `data-block-name` onto the **first tag in the content**, and then
`inject_hooks`. Prepending ahead of the stamper moves `data-block-name` off
WooCommerce's wrapper and onto ours. There is no integer priority between the
two, so the work happens on the block-specific filter, which core applies after
every generic `render_block` callback.

The latch is keyed on **product ID**, not a boolean, so a request that renders
several products still gets one viewer each.

### The injected gallery slide

WooCommerce's `callbacks.toggleImageVisibility` reads `data-image-id` off the
element it is bound to, finds that value in `imageData`, and sets the slide's
`hidden` and — crucially — `style.order` from its index. A slide without that
attribute is never ordered, so it sorts to the front of the flex container no
matter what `imageData` says.

So the injected `<li>` carries `data-image-id` and `data-wp-watch`, and
`apply_sentinel()` also fixes up `selectedImageId`, `isDisabledPrevious` and
`isDisabledNext`. WooCommerce derives all of them from the selected image's
index, and adding a slide changes both the index and the length; without the
fix-up `isDisabledNext` stayed `true` on a one-image product and the viewer was
unreachable.

`selectedImageId` must always name a member of `imageData`, or the store's
`imageIndex` getter returns -1 and prev/next stop moving entirely.

`append_thumbnail()` is an **intentional no-op**. WooCommerce 11 renders
thumbnails as `<div class="…__scrollable">`, not a `<ul>`, and the store bails
cleanly when a slide has no thumbnail, so the slide stays reachable through
next/previous.

## Known limitations

- **Variation switching on the block gallery drops the viewer slide.**
  WooCommerce rebuilds `imageData` from the variation's images. This is not
  fixable from outside: WooCommerce's `isValidImageId` is
  `typeof id === 'number' && Number.isInteger(id) && id > 0`, and our sentinel is
  negative by necessity (WooCommerce itself uses `-1` for "no images"); any
  positive sentinel could collide with a real attachment ID. It needs an
  upstream extension point. The `above_product`, `below_product` and
  `button_only` positions are unaffected. Also documented in the readme FAQ.
- **The block gallery has no default browser coverage.** WooCommerce 11's
  default single-product template uses the *legacy* gallery, so
  `ClassicGalleryInjector` is what runs and `BlockGalleryInjector` never fires
  on a default install. [`tests/e2e/fixtures/`](../tests/e2e/fixtures/README.md)
  holds a template override that exercises it.
