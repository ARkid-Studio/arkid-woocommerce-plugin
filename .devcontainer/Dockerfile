# Combined runtime + dev image. FROM the official WordPress image (php8.4-apache,
# Debian bookworm) so the live site and the dev tooling share ONE PHP. (The lock
# is resolved for the plugin's PHP 8.1 minimum via composer.json's
# config.platform, so it installs here too.) Apache + the WP entrypoint come from
# the base; we add Node 24, Composer, wp-cli, git and Playwright's system libraries.
# NO devcontainer Features (they fail to build under rootless podman/buildah).
# Fully-qualified image refs (docker.io/library/…) so rootless podman's enforced
# short-name resolution doesn't need a TTY prompt; works on docker too.
FROM docker.io/library/wordpress:php8.4-apache

RUN apt-get update \
	&& DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
		git zip unzip less default-mysql-client ca-certificates curl \
	&& rm -rf /var/lib/apt/lists/*

# Apache must listen on a NON-privileged port so it can run as www-data under podman
# `keep-id` (which starts the container as a non-root user — root can't be assumed).
# Host 8888 maps to 8080 (compose.yaml). Works on docker too (root can bind 8080).
#
# It ALSO listens on 8888 so the site answers on the same URL inside the container
# as outside. WordPress stores siteurl/home as http://localhost:8888; without this,
# an in-container request to :8080 gets a 301 to :8888 and nothing is listening
# there — which would break Playwright, wp-cli REST calls and Plugin Check's
# runtime checks. Same vhost, so both ports serve identical content.
RUN sed -ri 's/^Listen 80$/Listen 8080\nListen 8888/' /etc/apache2/ports.conf \
	&& sed -ri 's/<VirtualHost \*:80>/<VirtualHost *:8080 *:8888>/' /etc/apache2/sites-available/000-default.conf

# Chromium's system libraries, for the Playwright E2E suite. These MUST be
# installed here, at build time, as root: the container runs as www-data with no
# sudo, so `playwright install --with-deps` can never install them itself. The
# browser binary itself is downloaded at runtime by `npm run e2e:install` into
# the www-data-owned cache below. Bump this list and @playwright/test together.
RUN apt-get update \
	&& DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
		libnss3 libnspr4 libdbus-1-3 libatk1.0-0 libatk-bridge2.0-0 libcups2 \
		libdrm2 libxkbcommon0 libatspi2.0-0 libxcomposite1 libxdamage1 \
		libxfixes3 libxrandr2 libgbm1 libpango-1.0-0 libcairo2 libasound2 \
		fonts-liberation fonts-noto-color-emoji \
	&& rm -rf /var/lib/apt/lists/*
ENV PLAYWRIGHT_BROWSERS_PATH=/var/www/.cache/ms-playwright

# mbstring (wp-cli i18n + phpcs use it) — only if the base image lacks it.
RUN if ! php -m | grep -qi '^mbstring$'; then \
		apt-get update \
		&& DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends libonig-dev \
		&& docker-php-ext-install -j"$(nproc)" mbstring \
		&& rm -rf /var/lib/apt/lists/*; \
	fi

# Node 24 for @wordpress/scripts / webpack (NodeSource, multi-arch; no Feature).
RUN curl -fsSL https://deb.nodesource.com/setup_24.x | bash - \
	&& DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends nodejs \
	&& rm -rf /var/lib/apt/lists/*

# Composer 2 (from the official image) + wp-cli.
COPY --from=docker.io/library/composer:2 /usr/bin/composer /usr/local/bin/composer
RUN curl -fsSL -o /usr/local/bin/wp \
		https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
	&& chmod +x /usr/local/bin/wp

# Make www-data's home writable and pre-own the workspace + dep-volume mount points
# (named-volume copy-up inherits this ownership) so composer/npm/wp-cli/git all run
# as www-data (uid 33 == host user via keep-id) without permission errors.
ENV COMPOSER_HOME=/var/www/.composer
RUN chown www-data:www-data /var/www \
	&& mkdir -p /var/www/.composer /var/www/.npm \
		/workspaces/arkid-woocommerce-plugin/node_modules \
		/workspaces/arkid-woocommerce-plugin/vendor \
		/var/www/html/wp-content/plugins/arkid-catalogue-link/node_modules \
		/var/www/html/wp-content/plugins/arkid-catalogue-link/vendor \
	&& chown -R www-data:www-data /var/www/.composer /var/www/.npm \
		/workspaces/arkid-woocommerce-plugin \
		/var/www/html/wp-content/plugins/arkid-catalogue-link
