# ARkid Catalogue Link

A WooCommerce extension that connects your store to the
[ARkid catalogue](https://catalogue.arkid.app) and embeds its 3D / AR viewers
on the product display page.

> Licensed under [GPL-3.0-or-later](./license.txt).

## Features

- **Settings** at WooCommerce → Settings → Integration → ARkid Catalogue Link:
  API key (with a reveal toggle), default viewer position, button text, and an
  optional WP Consent API gate.
- **Per-product viewer picker** on the product edit screen, fed live from the
  API — you always see the current catalogue, never a cached copy.
- **Five viewer positions** — first image, last image (opens a modal), above
  product, below product, or a standalone "View in 3D" button. A per-product
  override beats the site default.
- **Both product galleries**: the classic FlexSlider gallery and the
  block-based `woocommerce/product-gallery`, on classic and block themes alike.
- **A general-purpose block** (`arkid-catalogue-link/embed`) for embedding any
  viewer on any page or post.
- **Viewer list** at WooCommerce → ARkid Viewers, with type badges and
  Edit / Analytics links into ARkid.
- **No API calls on product pages.** Each product stores its own viewer link,
  so the storefront keeps working if ARkid is unreachable; a daily Action
  Scheduler job keeps those links current.
- **The API key stays on the server.** The picker and the editor block go
  through the plugin's own REST endpoints, restricted to store managers.
- **WP Consent API integration** (optional): the viewer loads only once the
  visitor grants `marketing` consent.

## Requirements

| | Minimum |
| --- | --- |
| WordPress | 7.0 |
| WooCommerce | 8.0 |
| PHP | 8.1 |

Tested up to WordPress 7.1 and WooCommerce 11.0. HPOS and the Cart/Checkout
blocks are declared compatible (the plugin touches neither orders nor
checkout).

## Installation

Download `arkid-catalogue-link.zip` from the
[Releases page](https://github.com/ARkid-Studio/arkid-woocommerce-plugin/releases)
and upload it via Plugins → Add New → Upload Plugin, then enter your API key under WooCommerce → Settings →
Integration → ARkid Catalogue Link. [`readme.txt`](./readme.txt) is the
merchant-facing documentation, including an FAQ and what data is sent to ARkid.

Don't install from a clone of this repository: the compiled assets and the
Composer autoloader are build output, not committed.

## Development

```sh
npm install      # also runs composer install
npm run dev:up   # WordPress + WooCommerce + MariaDB in containers (docker or podman)
npm run start    # watch JS / SCSS
```

The dev site is then at <http://localhost:8888> (`admin` / `password`). Opening
the repo in a dev container does all of this for you.

- [CONTRIBUTING.md](./CONTRIBUTING.md) — commands, the test suites, checks,
  secrets, deliberate dependency pins, and releasing.
- [docs/architecture.md](./docs/architecture.md) — how it works, and the
  places where the obvious implementation is wrong.
- [.devcontainer/README.md](./.devcontainer/README.md) — the dev stack.

## Project layout

```text
arkid-catalogue-link.php   entry point: header, constants, bootstrap, compat declarations
uninstall.php              removes settings and scheduled jobs (keeps per-product choices)
includes/                  PHP, PSR-4 → Arkid\CatalogueLink\
  Plugin.php               bootstrap
  PostMeta.php             per-product meta: registration, sanitisers, snapshot writes
  Api/                     HTTP client, DTO, exceptions
  Settings/                WooCommerce integration tab + typed options
  Admin/                   meta box, viewer list, notices, assets
  Frontend/                renderer, iframe builder, modal, consent, gallery injectors
  Blocks/                  server-rendered embed block
  Cron/                    Action Scheduler refresh
  Migrations/              schema-version upgrades
  Rest/                    /arkid-catalogue-link/v1/embeds proxy
src/                       JS / SCSS sources (built with @wordpress/scripts into build/)
languages/                 .pot and .po sources
tests/
  phpunit/                 unit tests (Brain Monkey)
  integration/             real WordPress + WooCommerce
  e2e/                     Playwright
  fixtures/api/            recorded ARkid API responses
bin/                       release zip, test provisioning, dev-stack wrapper
docs/                      architecture notes
readme.txt                 the WordPress.org plugin readme
```

## Publishing to WordPress.org

### Before submitting

- [x] **Source code is linked from `readme.txt`.** The zip ships the minified
      `build/` but not `src/`, and WordPress.org requires human-readable source
      for compiled code, or a link to it (plugin guideline 4). The "Source code"
      section in `readme.txt` points at this repository and the build command.
- [x] **`Contributors: arkid` is a real WordPress.org account** we control.
- [x] **The legal links in `readme.txt` resolve.** "External services" links the
      terms and privacy pages on arkid.app; the old `catalogue.arkid.app/terms`
      and `/privacy` were 404s.
- [x] **Screenshots, banner and icon are generated**, not drawn: run the **Store
      assets** workflow and download the artifact. See
      [tests/store-assets/README.md](./tests/store-assets/README.md). The
      `== Screenshots ==` captions in `readme.txt` match the five images.
- [ ] **Store gates is green** on the commit you submit (Plugin Check, every
      category, against the release payload).

### Submitting

1. Build the zip: `npm run plugin-zip`, or take `arkid-catalogue-link.zip` from
   the GitHub Release for the tag.
2. While logged in as `arkid`, upload it at
   <https://wordpress.org/plugins/developers/add/>. The plugin team replies by
   email to that account.

### After approval

The images are not in the zip. WordPress.org serves them from an `assets/`
folder at the top level of the plugin's SVN repository, next to `trunk/` and
`tags/` — see [tests/store-assets/README.md](./tests/store-assets/README.md).

## License

GPL-3.0-or-later. See [`license.txt`](./license.txt).
