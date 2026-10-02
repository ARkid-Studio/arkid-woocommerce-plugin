# Dev Container

A **self-contained** WordPress + WooCommerce dev stack that runs as **sibling
containers on the host engine** (docker or rootless podman) — no docker-in-docker, no
nested engine, no devcontainer Features. The devcontainer **is** the `wordpress`
service; you exec in to build, lint and test.

> **Why not nested podman/wp-env?** `wp-env` launches its own containers with
> *in-container* mount paths, which forced a heavyweight nested-podman setup (SELinux off,
> `SYS_ADMIN`, `unmask=ALL`) and depends on devcontainer Features (which fail to build
> under rootless podman: `cp: cannot access '/tmp/build-features-src/node_0': Permission
> denied`). Defining our own compose stack removes all of that: minimal privilege,
> SELinux stays on, works on any host.

## Architecture

- **`compose.yaml`** (engine-neutral): `wordpress` (official `wordpress:php8.4-apache`,
  extended by `Dockerfile` with Node 24 + Composer + wp-cli + git + Playwright's Chromium
  libraries) and `db` (`mariadb`).
  WordPress core + uploads live in the `wpdata` volume; the plugin repo is **bind-mounted**
  into `wp-content/plugins/arkid-catalogue-link` (live edits); `node_modules`/`vendor` are
  named volumes so container (Debian) builds don't clash with the host (Fedora).
  Those two volumes are mounted at **both** bind-mount paths (the workspace *and* the
  plugins dir). They have to be: WordPress loads the plugin from the plugins path, so a
  `vendor` mounted only at the workspace path leaves no autoloader where the plugin
  actually looks, and it degrades silently to its "missing Composer autoloader" notice.
  `provision.sh` detects that state, warns, and installs a temporary mu-plugin shim.
- Apache listens on **8080 and 8888** inside the container. The host publishes 8080 as
  8888, and WordPress stores `siteurl`/`home` as `http://localhost:8888` — serving both
  ports means that URL resolves identically inside and outside, which is what Playwright
  and Plugin Check's runtime checks need.
- **`compose.podman.yaml`** (rootless override): `userns_mode: keep-id:uid=33,gid=33` (host
  user ↔ container `www-data`) + `security_opt: label=disable`, so the containers read the
  repo bind without relabelling it. **SELinux stays enforcing on the host.**
- **`Dockerfile`**: one PHP 8.4 image for runtime *and* tooling. (`composer.lock` is
  resolved for the plugin's PHP 8.1 minimum, so it installs on 8.4 as well.)
- **`provision.sh`**: idempotent first-run installer — `wp core install`, install +
  activate WooCommerce and the plugin, permalinks, disable WooCommerce "Coming soon".
  Runs `wp` **directly** inside the `wordpress` service (no compose/socket).

## Open it

- **Zed:** set `{ "dev_containers": { "use_podman": true } }` in `settings.json`, then
  accept **Open in Container** (or command palette → **Project: Open Remote** →
  **Connect Dev Container**). Host access comes from the compose `ports:` mapping
  (8888→8080); `devcontainer.json` deliberately sets **no `forwardPorts`** (see gotcha).
- **VS Code / devcontainer CLI:** "Reopen in Container", or
  `devcontainer up --workspace-folder . --docker-path podman`.
- **Plain CLI (no editor):** `npm run dev:up` (brings the stack up + provisions via
  `bin/dc.sh`, which auto-picks podman or docker).

Storefront → <http://localhost:8888>, admin → <http://localhost:8888/wp-admin>
(`admin` / `password`).

## Develop

Inside the container: `npm run build` / `npm run start` (webpack), `composer test`
(Brain Monkey unit tests — no live WP needed), `composer phpstan`, `composer lint`,
`npm run cli -- plugin list`, `npm run makepot`. The plugin is bind-mounted, so PHP edits
are live and `npm run build` output (`build/`) appears in the running site immediately.

What you can and can't do in there:

- You are `www-data`: **no root, no sudo, no container engine or socket.** Changes to
  the compose files or `Dockerfile` need a rebuild from the host (see below).
- `:8080` answers with a single 301 to `:8888`. That's correct, not a loop — WordPress
  stores its URL as `:8888`.
- Database accounts: `wordpress` has `GRANT ALL` on `wordpress.*` only and cannot create
  databases; `mysql -h db -u root -pwordpress` can. The integration suite's `wptest` user
  (created by `npm run test:php:setup`) is granted on `wordpress_test` alone.

Manage the stack from the **host**: `npm run dev:up` / `dev:down` / `dev:logs` /
`dev:reset` (wipes DB + WordPress volumes).

## Docker vs podman

`devcontainer.json` lists both compose files, so **rootless podman** works out of the box.
On a **plain docker** host, the podman override's `keep-id`/`label=disable` are podman-only — bring
the stack up with just the base file:

```sh
docker compose -f .devcontainer/compose.yaml up -d
```

or remove `compose.podman.yaml` from `dockerComposeFile` in `devcontainer.json`.
`bin/dc.sh` (used by the `dev:*` npm scripts) already does this autodetection.

## Apply config changes (Zed has no "rebuild" button)

From a **host** terminal (`podman ps`/`docker ps` to find the id):

- **`compose*.yaml` / `devcontainer.json` mounts/ports** → `bin/dc.sh down && bin/dc.sh up -d`,
  or remove the container + reopen. Named volumes (`wpdata`, `dbdata`, …) survive.
- **`Dockerfile`** → rebuild the image: `bin/dc.sh build` (or `down` then `up -d --build`),
  then reopen. `build` never deletes named volumes.
- **Wipe the site/DB on purpose** → `npm run dev:reset` (or `podman volume rm` the
  `*_wpdata` / `*_dbdata` volumes).

## Gotchas (host-side)

- **Zed-flatpak `libselinux.so.1: no version information available`** (zed-industries/zed#53129):
  the flatpak leaks its bundled lib dir onto `LD_LIBRARY_PATH`, so host `podman` loads a
  stale `libselinux` and container creation aborts. Fix:
  `flatpak override --user --unset-env=LD_LIBRARY_PATH dev.zed.Zed`, then fully quit +
  relaunch Zed (revert: `--reset`). Or run Zed natively.
- **`updateRemoteUserUID`** is set `false` (zed-industries/zed#53081): `keep-id` already
  aligns the uid, and the CLI's re-chown of an image/compose service can fail.
- **uid mapping is the thing to verify on first boot.** We use `keep-id:uid=33,gid=33` so
  everything is `www-data` (uid 33) inside ↔ your host user. If the repo is read-only or
  wp-cli can't write, adjust the `keep-id` uid/gid in `compose.podman.yaml`. On rootful
  Linux docker, bind-mount ownership may need a `user:` tweak; Docker Desktop handles it.
- **No `forwardPorts`.** Under podman, Zed/the CLI publishes `forwardPorts` as an
  *additional* `8888->8888` mapping on top of the compose `8888->8080` publish, so host
  8888 gets double-bound → `rootlessport conflict with ID 1` and the wordpress container
  is stuck in `Created`. Host access already comes from the compose `ports:`, so we omit
  `forwardPorts` entirely.
- **WordPress version** tracks the latest `wordpress:php8.4-apache` tag. Pin a
  versioned tag (e.g. `wordpress:<version>-php8.4-apache`) in `Dockerfile` if you want a
  fixed runtime.
- **Stale stacks block a fresh open.** A failed start can leave containers/networks that
  hold port 8888 → `rootlessport conflict`. Clear them from a host terminal:
  `podman ps -aq --filter name=arkid | xargs -r podman rm -f` then
  `podman network rm -f arkid-woocommerce-plugin_devcontainer_default`.
