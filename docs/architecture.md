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
  _arkid_embed_status  (absent, or `missing`)
        ▲
        └── Action Scheduler: a daily sweep fans out one async task per linked
            product (`arkid_catalogue_link_refresh_interval` filters the period).
            Changing the API key queues a full re-sync, because a new key can
            belong to a different ARkid account.
```

- **Product pages never call the API.** Each product stores a snapshot of its
  viewer's URL and thumbnail, so the storefront keeps working when ARkid is
  unreachable. Admin screens (the picker, the viewer list) always read live —
  there is no cache to go stale. (See [The viewers screen](#the-viewers-screen).)
- **The block does the same.** `arkid-catalogue-link/embed` persists `embedUrl`
  in its attributes, so front-end rendering never needs the API either. The
  editor matches a block to its viewer by `embedId` alone, never by comparing
  URLs: ARkid derives the URL from the ID and keeps URLs in its older ID format
  working, so a saved `embedUrl` that differs from the API's is not out of date.
- **If ARkid is unreachable while a merchant switches viewers**, the new ID is
  saved but the URL/image snapshot is dropped rather than left describing the
  previous viewer, and a retry is queued. A snapshot must never describe a
  different embed than the stored ID.

## Link states

A product that has a viewer selected is in one of four states
(`Links\LinkState`), told apart by what is stored, so a list of any size needs
no request:

| State | Stored | Meaning |
| --- | --- | --- |
| `None` | no id | no viewer selected |
| `Linked` | id and URL | the product page shows it |
| `NotSynced` | id, no URL, no status | selected but never fetched, or ARkid could not be reached when it was tried; the refresh retries |
| `Missing` | id, status `missing` | ARkid answered **404** for it: deleted, or it belongs to another account's key. It will not sync until the merchant acts |

`NotSynced` and `Missing` are what "needs attention" counts: the merchant chose
a viewer and the product page shows none.

- **`_arkid_embed_status` is written only by a live 404**, by the refresh job
  or by a save, through `Links\EmbedLookup`, which is the single place that
  reads an answer of ARkid as "gone" (404 only) or "could not ask" (a bad key, a
  limit, an outage, a body that cannot be read). It is never derived from a
  list of viewers: a list says nothing about a viewer created after it was
  read, and a viewer created a minute ago would be flagged as missing.
- **The live answer wins on the edit screen.** The meta box asks ARkid once per
  render. A viewer it has is shown as in order even if an earlier refresh left
  `missing` behind (the next refresh clears it); a 404 is shown as missing
  before the refresh has noticed; when ARkid cannot say, the stored state is
  shown, with a note that it could not be checked if the viewer is `Linked`.
- **A re-link never inherits a verdict about the old viewer.** Linking, unlinking
  and saving a different viewer while ARkid is down all clear the status.
  Saving the same viewer while ARkid is down keeps it. A save that gets a 404
  keeps the choice, drops the snapshot, sets `missing` and queues a retry,
  because a viewer created a moment ago may not be visible to the API yet.
- **An answer about a viewer that is no longer selected is dropped.** Asking
  ARkid takes up to a few seconds, and the merchant may save a different viewer
  in that time. The refresh job therefore re-reads the selection (bypassing its
  own meta cache, because the save happened in another process) before it writes
  anything, so neither a 404 nor a found viewer of the old choice lands under
  the new one.
- **A changed API key** queues a full resync. The viewers of the old account
  answer 404 for the new key (verified in ARkid's source: an id of another
  organisation is a 404, not a 403), so they become `Missing`; a rejected key
  leaves everything as it is.
- No migration: a link that was already deleted upstream before this status
  existed reads as `NotSynced` until the next daily refresh finds the 404.

## The viewers screen

WooCommerce → ARkid Viewers (`Admin\ViewersPage`, `Admin\ViewersListTable`) is
a `WP_List_Table`. A request asks ARkid for the list of viewers (`GET embed`)
and, if there are any, once for the traffic of the last 30 days
(`GET stats?days=30`), and reads the rest from the shop's own database. The
page blocks while it waits (5 s and 10 s at most); there is no cache. Each
call is made once per request.

- **Where each viewer is shown** comes from one query over the
  `_arkid_embed_id` meta (`Links\ProductLinks`), with the same predicate as the
  refresh job: products that are not in the trash or an auto-draft. A draft or
  private product is listed, with its status; the viewer is selected on it, but
  it does not show to visitors yet. "Live on a product" means selected on at
  least one such product. A viewer id is compared in lower case everywhere, the
  list's, the stored link's and the statistics'.
- **"+N more"** links to `edit.php?post_type=product&arkid_embed=<id>`
  (`ProductLinks::QUERY_VAR`). The Products list's own filter for that
  variable is the product list step's to provide; the link is built here.
- **Interactions** are joined from `stats.embeds` by viewer id
  (`StatsDto::interactions_by_embed()`). ARkid's `EcomStats` lists every live
  standalone viewer that had at least one impression in the period, with its
  interactions, in no way cut off at a number of rows. A viewer that is not
  listed was not viewed, and so had no interactions: it shows `0` and is in
  "No interactions, 30 days", as is one that was viewed and not interacted
  with. The statistics cover every viewer the list has (both leave out deleted
  viewers and those of a collection), so a viewer missing from them is not one
  they do not know. The window is fixed at 30 days.
- **If the statistics call fails** (an outage, a rejected key, an answer that
  cannot be read), the screen still renders. The interactions column, its sort
  and the "No interactions" view are left out, and a notice says why: a count
  of zero would claim that ARkid had been asked and answered "none". A
  bookmarked `?show=quiet` shows all viewers in that case.
- **"Total views"** is `embed.views`, which ARkid's source describes as the
  lifetime count (`StatsGeneral`: "Summed lifetime `embed`.`views`").
- **Times.** The API writes `created` and `modified` without a zone
  (`2025-07-16 02:51:43`). ARkid's database session runs in UTC
  (`ARKID_DB_TIME_ZONE`, which its migration notes expect to equal PHP's
  `date.timezone`, `UTC`), so `Support\ApiTime` reads a time without a zone as
  UTC and shows it in the site's time zone, as "3 hours ago" with the exact
  time as the tooltip. Treat that as an assumption to confirm with ARkid: if the
  zone were different, the relative times would be off by that difference. A
  date with no time of day (`2026-01-01`) names a day, not an instant, and is
  shown as that day whatever the site's zone is; moved into a zone west of UTC
  it would read as the day before. ARkid sends date-times, so this is for a
  payload that does not.
- **Screen Options.** WordPress handles the Screen Options form in
  `wp-admin/admin.php`, before the admin menu is built and before any `load-*`
  hook. The `set_screen_option_arkid_viewers_per_page` filter is therefore
  registered when the page registers (at plugin load), not from the screen's
  load hook, where it would be too late and the value would never save. A test
  submits the form through WordPress's own `set_screen_options()`. The page
  size defaults to 20; the "Created" column starts hidden
  (`default_hidden_columns`) and can be shown there.
- **The form.** The search box and the type and brand filters submit one GET
  form that carries `page` (and the selected view), so the screen survives a
  search. The views (the links above the table) reset the other filters, as
  those of a post list do.
- **The preview** is one native `<dialog>` per screen
  (`Admin\PreviewDialog`, `src/admin/preview-dialog.js`), empty until a button
  with `data-embed-url` is clicked: the viewer is WebGL and wp-admin must not
  start one per row. The address goes through `IframeFactory::viewer_url()` on
  the server (https, an allowed host, `source=woocommerce`) and through the
  same check in the browser (`src/shared/viewer-url.js`, given
  `IframeFactory::allowed_hosts()` as `arkidCatalogueLinkAdmin.allowedHosts`),
  so the two cannot disagree; a viewer that fails it gets no button.
  `showModal()` supplies the focus trap and Escape; the script returns focus to
  the button and empties the frame on close. The viewers list, the product
  meta box and, later, the product list use the same dialog.
- **Links, thumbnails and previews from API data must be https**
  (`Support\Url::https()`): `esc_url()` alone also lets `http:` and `ftp:`
  through.

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
- Every request carries `?source=woocommerce`. The API writes that value into
  `embed_url` and `embed_code`, so the viewer knows which shop system it runs
  in. `viewer_url()` still sets it through `add_query_arg()`, which *replaces*
  the parameter, so a stored URL from an older response ends up with exactly
  one `source=woocommerce`. Pinned by a test against fixtures recorded with
  `?source=magento`.
- **The API also sends `embed_code`**, a ready-made `<iframe>`. It is ignored:
  ours carries a different sandbox and a `referrerpolicy`.
- `colors` lists every colour of the product, each with a `variant_id`, its
  `rgb` and an `enabled` flag. `EmbedDto` keeps all of them in `all_colors`
  (and `shown_colors()` the enabled ones). Its `colors` property and the
  `colors` key of `to_array()` keep the legacy meaning, the colours the viewer
  shows with name and rgb only, because the picker and the block read that.
  `from_array( to_array() )` loses nothing.
- The same payload carries what editing needs, and `EmbedDto` keeps it:
  `product_id`, `variants` (the enabled variant ids in display order),
  `color_a`, `color_b`, `environment`, `can_edit`, `colors_editable` and
  `environment_editable`. Check the last three before offering a change: a
  viewer the key may not change, or whose skin fixes the look, answers a write
  with a 403 or 422.

### The client

`Client` implements `EmbedSource` and is the only thing that talks to ARkid.
Every call goes out when it is made: there is no cache, so an admin screen
always shows what ARkid says now. A **storefront request never calls any method
of it**; product pages render from the stored snapshot.

| Method | Request |
| --- | --- |
| `list_embeds()`, `get_embed( $id )`, `probe()` | `GET embed`, `GET embed/{id}` |
| `create_embed( EmbedInput )` | `POST embed`, JSON body, needs the key's write permission |
| `update_embed( $id, EmbedInput )` | `PUT embed/{id}`, needs the viewer's `can_edit` |
| `list_products()` | `GET product`, the catalogue products a viewer can be created for |
| `list_environments( $locale )` | `GET environment?lang=`, named in the language asked for |
| `stats( $days )` | `GET stats?days=`; 7, 30 or 90, anything else is read as 30 |

`create_embed` and `update_embed` exist for the viewer forms that follow; no
screen calls them yet, so no write request leaves the site today.

- **Query strings are encoded here, not by `add_query_arg()`**, which encodes
  only the query string it is handed and splices argument values in as they
  are. `source=woocommerce` always comes first and cannot be overridden.
  `days` is whitelisted, and `lang` is reduced to the two or three letters of a
  WordPress locale (`de_DE` gives `de`) or left out, so a locale can never add
  a parameter. A test pins that `de&source=magento` sends no `lang` at all.
- **Ids in a path** are 1 to 64 characters of `A-Za-z0-9_-` (a UUID, or the
  22-character form of viewer URLs) and are `rawurlencode`d. Anything else
  throws `NotFoundException` without a request. `product_id` and variant ids in
  a body must be UUIDs.
- **Writes** send `Content-Type: application/json` (ARkid answers 415 otherwise),
  wait up to 10 s instead of 5, and follow no redirect, which would replay the
  write as a body-less GET. The products and statistics reads also get 10 s.
  `EmbedInput` checks the rules of ARkid's `EcomEmbedInput` first and throws
  the same `ValidationException` a 422 would, so one handler serves both. Only
  fields that are set are sent; ARkid keeps the rest (or, on a create, takes
  the account's default). ARkid keeps a page URL a viewer already has, so
  `embedder_url` only ever fills an empty one.
- **What a failed answer means.** ARkid reports problems as
  `{"errors": {"field": "message"}}`.

  | Answer | Exception |
  | --- | --- |
  | 401 | `AuthException`: check the API key |
  | 403 on a read, or on a write whose `errors.auth` says "invalid API key", or without any explanation | `AuthException` |
  | 403 on a write with another explanation ("The user of this API key may not do this.", a plan limit) | `RefusedException` |
  | 402, 429 | `RefusedException`; `retry_after()` carries `Retry-After` of a 429 |
  | 422, 400 | `ValidationException` with `errors()` by field |
  | 404 | `NotFoundException`, its message names what was asked for |
  | 5xx, 405, 415, other, network error, non-JSON body | `TransportException` |

  ARkid answers a **bad key with 403**, not 401 (`EcomEmbed::api()` turns its
  `PermissionDenied` into `{"errors": {"auth": "Missing or invalid API key."}}`),
  so a 403 with a structured body cannot simply be taken as "not the key". A
  read can only fail on the key, which makes every 403 on a read an
  `AuthException`. On a write the same 403 carries "The user of this API key
  may not do this." when the key is fine and the action is not allowed. A
  plan limit or a rate limit therefore never reads as "check your API key".
  `ErrorReplyException::errors()` holds the messages exactly as ARkid sent them
  (bounded in number of characters): escape them where they are shown.
- **Timeouts and retries.** One attempt per call. `Retry-After` is exposed, never
  waited on.

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
