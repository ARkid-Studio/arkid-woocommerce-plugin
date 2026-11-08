# WordPress.org listing assets

Generates the images WordPress.org shows on the plugin page: five screenshots,
an icon and a banner. They are built, not drawn, so they track the real UI and a
re-run after a redesign is one click.

| File | Made by | Shows |
| --- | --- | --- |
| `screenshot-1.png` | `screenshots.spec.js` | Picking a viewer in the product edit screen |
| `screenshot-2.png` | `screenshots.spec.js` | The viewer below the product, storefront |
| `screenshot-3.png` | `screenshots.spec.js` | The viewer open in a modal from the gallery |
| `screenshot-4.png` | `screenshots.spec.js` | Integration settings |
| `screenshot-5.png` | `screenshots.spec.js` | The ARkid Viewers list |
| `icon-128x128.png`, `icon-256x256.png` | `render-brand-assets.js` | The ARkid "a" on navy |
| `banner-772x250.png`, `banner-1544x500.png` | `render-brand-assets.js` | Wordmark, tagline, three products |

The numbers match the list under `== Screenshots ==` in [`readme.txt`](../../readme.txt),
which is how WordPress.org pairs a caption with an image. Reorder one and you
must reorder the other.

## Getting the files

**In CI** — the easy way. Run the **Store assets** workflow (Actions → Store
assets → Run workflow) and download the `wordpress-org-assets` artifact. It also
runs on a pull request that touches this folder.

> `workflow_dispatch` only shows a *Run workflow* button once the workflow file
> is on the default branch. Until then, a pull request that changes anything
> under `tests/store-assets/` runs it.

**Locally** — needs a throwaway WordPress + WooCommerce site, because the run
renames the shop, adds a demo product and installs an API stub (global-teardown
puts it all back). That is the same site CI builds:

```sh
composer install --no-dev --optimize-autoloader
npm ci && npm run build
WP_PATH=/tmp/wp-assets bash bin/install-e2e-site.sh        # needs MariaDB, see ci.yml
WP_PATH=/tmp/wp-assets wp server --host=127.0.0.1 --port=8888 &
npm run assets                                             # → .wordpress-org/
```

Do not point it at a real store. `npm run assets:screenshots` and
`npm run assets:brand` run the two halves separately.

## How it works

- **Real viewer, stubbed API.** The ARkid API is answered by
  `mu-plugins/arkid-assets-api-stub.php` from the recorded
  `tests/fixtures/api/embed-list.json`, so no API key is needed. The 3D viewer
  and the cover images are *not* stubbed: they load from `catalogue.arkid.app`
  and `cloud.arkid.app`, which is the point of the picture. That is why this
  is its own Playwright config (`playwright.assets.config.js`) and not part of
  the e2e suite, which stays offline.
- **WebGL without a GPU.** CI runners have none; Chromium is started with
  SwiftShader flags (`config.CHROMIUM_ARGS`) and the viewer renders in software.
- **A blank viewer fails the run.** After the viewer's `load`, the shot waits for
  its model to draw, then refuses to continue if the iframe is almost uniform
  (`MIN_VIEWER_PNG_BYTES`). Better a red job than a white box on the plugin page.
- **The real flow.** Shot 1 attaches the viewer through the actual meta box and
  Update button, so the later shots show what that save produced.

## Changing what they show

Everything is in [`config.js`](./config.js).

- `HERO_VIEWER` — which catalogue entry the demo product is built around (its
  title, cover and the viewer). Must match a `product_name` in the fixture.
- `STORE_NAME`, `PRODUCT` — the demo shop's name and placeholder copy.
- Wording and layout of the banner: `bannerHtml()` in `render-brand-assets.js`.
- The logo: [`brand/`](./brand/README.md).

### Brands and logos

A viewer draws its brand's logo inside the 3D scene, and the recorded catalogue
holds products from several brands. The rule for anything published on
WordPress.org: **the only logos that may appear are ARkid's and Maxi-Cosi's.**
Other brands' products may appear in lists and as banner artwork (the covers
carry no logo), but never as the hero.

So `HERO_VIEWER` must name an ARkid or Maxi-Cosi model. The pipeline does not
enforce this: look at the screenshots before they go anywhere.

## After WordPress.org approves the plugin

The review needs only the zip. These images are not part of it — WordPress.org
serves them from the `assets/` folder at the **top level of the plugin's SVN
repository** (next to `trunk/` and `tags/`, not inside them), under exactly
these file names. The folder name `.wordpress-org/` is the one the common deploy
actions (`10up/action-wordpress-plugin-asset-update`) read from, which is why the
output goes there.
