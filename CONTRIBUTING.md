# Contributing

Thanks for helping out. This guide covers setting up a dev environment, the
checks every change has to pass, and the few things in this repo that look
wrong but are deliberate. For how the plugin itself works, see
[docs/architecture.md](docs/architecture.md).

## Setting up

The dev environment is a self-contained compose stack — WordPress, WooCommerce
and MariaDB — that runs on the host's Docker or rootless podman. You work
*inside* the `wordpress` container, which has PHP 8.4, Node 24, Composer,
wp-cli and Playwright's Chromium libraries.

- **In an editor:** open the repo in a dev container (VS Code "Reopen in
  Container", Zed "Open in Container"). WordPress and WooCommerce are
  provisioned and dependencies installed automatically.
- **From the CLI:** `npm install` (also runs `composer install`), then
  `npm run dev:up` to bring the stack up and provision it.

The site is at <http://localhost:8888> (admin: `admin` / `password`). The
plugin is bind-mounted, so PHP edits are live; `npm run start` watches the JS
and SCSS. See [.devcontainer/README.md](.devcontainer/README.md) for podman,
SELinux and editor specifics, and for facts about the container itself.

Without the container you need PHP 8.1+, Composer 2, Node 24 and npm; the
integration and E2E suites additionally need a WordPress install, a MariaDB
server and wp-cli (see how CI provisions them in `.github/workflows/ci.yml`).

> **Storefront shows a placeholder?** WooCommerce enables "Coming soon" mode on
> new stores. `provision.sh` turns it off; to do it again:
> `npm run cli -- option update woocommerce_coming_soon no`.

## Checks

Everything below runs in CI on every pull request. Run the relevant ones before
pushing.

| Command | What it does |
| --- | --- |
| `composer lint` | PHPCS: WordPress, WordPress-Extra and PHPCompatibilityWP (8.1+). `composer lint:fix` autofixes. |
| `composer phpstan` | PHPStan level 9 with strict and deprecation rules. |
| `composer test` | Unit tests (Brain Monkey — no WordPress needed). |
| `composer test:integration` | Integration tests against real WordPress + WooCommerce. See below. |
| `npm run lint:js` | ESLint. Fails on any warning, including unresolved imports. |
| `npm run lint:css` | Stylelint. |
| `npm run test:js` | Jest + jsdom. |
| `npm run test:e2e` | Playwright, against both theme families. See below. |
| `npm run check` | PHPStan, PHPCS, unit, integration, ESLint and Stylelint in one go. |

`npm run format` applies Prettier to JS.

When fixing a bug, add a test that fails on the code before the fix.

### Integration tests

```bash
npm run test:php:setup      # once per machine: creates the test database
composer test:integration
```

They run against the container's WordPress at `/var/www/html` but a
**separate** `wordpress_test` database, because the WordPress test bootstrap
drops every table in the database it is pointed at. Three guards keep it away
from the dev store: `bin/install-wp-tests.sh` and
`tests/integration/bootstrap.php` both refuse a database named `wordpress`, and
— the one that actually matters — the `wptest` user is granted on
`wordpress_test` alone.

CI runs the suite across a WordPress/WooCommerce/PHP matrix (including the
declared WordPress and PHP minimums, and a multisite leg); bump its 7.x legs
whenever `Tested up to` in `readme.txt` moves.

### The ARkid API contract

Two halves:

| Test | Runs | Guards |
| --- | --- | --- |
| `EmbedSchemaTest` | always, offline | that *our parser* still reads a real payload |
| `LiveApiContractTest` | opt-in | that *ARkid still sends* one |

`tests/fixtures/api/*.json` are verbatim recorded responses, so the offline half
runs in CI and under QIT's network isolation with no key. The live half carries
`@group live-api`, which `phpunit-integration.xml.dist` excludes by default, and
skips itself unless `ARKID_API_KEY` is set:

```bash
npm run test:contract       # dotenvx supplies ARKID_API_KEY from .env
```

It fails loudly on drift in either direction — a field changing type, or the API
growing one the fixtures don't know ("The API grew field(s): …. Re-record the
fixtures.").

### End-to-end tests

```bash
npm run e2e:install         # once: downloads Chromium
npm run test:e2e
```

- **Projects are theme families, not browsers.** Classic and block themes build
  the product page through entirely different machinery, so `block-theme`
  (Twenty Twenty-Five) runs everything and `classic-theme` (Twenty Twenty-One)
  reruns the storefront spec. Only Chromium runs: the container lacks the
  system libraries Firefox and WebKit need.
- **The suite cleans up after itself.** Global setup seeds two products, a test
  API key and an mu-plugin that stubs the ARkid API; teardown removes all three
  and restores the settings. If a run is killed mid-way, check
  `wp-content/mu-plugins/` for a leftover stub before trusting "live" API calls.
- **The block editor canvas is its own iframe.** Reach inserted blocks through
  `page.frameLocator('[name="editor-canvas"]')`.
- **A new *page* opens behind a "Choose a pattern" modal** that covers the
  inserter. A new *post* does not.
- To exercise the block-based product gallery, install the template in
  [`tests/e2e/fixtures/`](tests/e2e/fixtures/README.md).

In CI, WordPress runs on the runner itself (`bin/install-e2e-site.sh`), because
the suite drives it through wp-cli and the filesystem as well as over HTTP.

## Secrets: dotenvx

`.env` is committed **public-key encrypted** with
[dotenvx](https://dotenvx.com/encryption). `.env.keys`, which decrypts it, is
gitignored and shared out of band — never commit it. Without it nothing breaks;
you just can't run the live contract check.

- Only `ARKID_API_KEY` (a test-account key) is encrypted. `WP_BASE_URL`,
  `WP_PATH`, `E2E_WP_USER` and `E2E_WP_PASS` are plaintext local dev config.
- `npm run env:set FOO bar` adds an encrypted value; add `--plain` for one that
  isn't secret. `npm run env:decrypt FOO` reads one back.
- `npm run test:e2e` and `npm run test:contract` go through `dotenvx run`.
- Without `.env.keys`, dotenvx does not fail — it passes the ciphertext through
  verbatim, so `ARKID_API_KEY` starts with `encrypted:`. `LiveApiContractTest`
  detects that and skips with an explanation rather than failing with a 403.
- To run the contract check in CI, add the contents of `.env.keys` as a
  `DOTENV_PRIVATE_KEY` secret.

## Plugin Check

The weekly **Store gates** workflow runs WordPress.org's
[Plugin Check](https://wordpress.org/plugins/plugin-check/) across every
category against the staged release payload. To run it locally, check the
**artifact**, not the repo — the repo contains dev files the zip never ships —
and pass `--slug`, because Plugin Check derives the expected text domain from
the directory name:

```bash
npm run plugin-zip
wp --path=/var/www/html plugin install plugin-check --activate
unzip -q arkid-catalogue-link.zip -d /tmp/ac && \
  cp -r /tmp/ac/arkid-catalogue-link /var/www/html/wp-content/plugins/ac-check
wp --path=/var/www/html plugin check ac-check --slug=arkid-catalogue-link
rm -rf /var/www/html/wp-content/plugins/ac-check /tmp/ac
```

One warning is expected: `load_plugin_textdomain` (see below).

## Deliberate pins — check here before "fixing" them

- **PHPUnit is pinned to 9.6.** WordPress core's test suite supports PHPUnit 9
  only, and Brain Monkey and the polyfills cap there. Upgrading breaks the
  integration suite. Dependabot ignores its major updates.
- **`@wordpress/scripts` stays on the 31.x line** (the `wp-7.0` dist-tag
  neighbourhood), matching `Requires at least: 7.0`. Its build config decides
  which imports the bundles make, and the dependency-extraction plugin turns
  those into the `wp-*` script handles each `*.asset.php` declares; a newer
  major can produce manifests naming handles older WordPress doesn't register —
  a green build and a blank block editor on the merchant's site. Don't run
  `npm run packages-update`, which jumps to `latest`. Dependabot ignores its
  major updates; move it deliberately, together with `Requires at least`.
- **The dependency-extraction plugin underneath WooCommerce's is pinned too.**
  `webpack.config.js` swaps `@wordpress/scripts`' extraction plugin for
  `@woocommerce/dependency-extraction-webpack-plugin`, which extends
  `@wordpress/dependency-extraction-webpack-plugin` — but depends on it through
  the `next` dist-tag, a nightly prerelease. `overrides` in `package.json` pins
  it to the release of the `wp-7.0` dist-tag instead; move it with
  `@wordpress/scripts`.
- **`@woocommerce/eslint-plugin` stays below 4.x** for as long as
  `@wordpress/scripts` ships ESLint 8 (31.x does): 4.x is a flat config that
  needs ESLint 9+. Dependabot ignores 4.x and later; it moves when
  `@wordpress/scripts` reaches the line with ESLint 10 (32.x, past the `wp-7.0`
  dist-tag).
- **`composer.json` pins `config.platform.php` to 8.1**, the declared minimum.
  Without it the lock resolves packages that need a newer PHP, and the 8.1 CI
  leg can't install.
- **`load_plugin_textdomain()` is kept** even though Plugin Check warns about
  it. WordPress.org loads translations itself; the WooCommerce Marketplace
  build needs the call.
- **Playwright's system libraries live in `.devcontainer/Dockerfile`.** Bump
  that list together with `@playwright/test`.

## Translations

`languages/*.pot` and `*.po` are the committed sources. Regenerate the template
with `npm run makepot`; the `.mo` and `.json` files are compiled at release time
by `bin/build-plugin-zip.sh` and are gitignored.

## Releasing

1. Bump the version in all five places: the `Version:` header and
   `ARKID_CATALOGUE_LINK_VERSION` in `arkid-catalogue-link.php`,
   `package.json`, `src/blocks/embed/block.json`, and `Stable tag:` in
   `readme.txt`. CI fails if they disagree.
2. Add a changelog entry (and an upgrade notice if it matters) to `readme.txt`.
3. Tag `vX.Y.Z` on `main` and push the tag. The **Release** workflow reruns
   the gates, checks the tag matches the plugin version, builds the zip with
   `bin/build-plugin-zip.sh` and publishes it with a checksum to GitHub
   Releases.

The zip contains only what runs on a merchant's site — the main plugin file,
`uninstall.php`, `includes/`, `build/`, production `vendor/`, `composer.json`,
`languages/`, `assets/`, `readme.txt` and `license.txt`. Build it locally with
`npm run plugin-zip` (needs Node, PHP, Composer and wp-cli, so run it inside the
container).
