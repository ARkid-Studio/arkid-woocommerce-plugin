#!/usr/bin/env bash
#
# Provision the database used by the integration test suite.
#
# The WordPress test bootstrap DROPS every table in whatever database it is
# pointed at, so it must never see the dev store's `wordpress` database. Three
# independent guards stop that:
#
#   1. this script refuses to target `wordpress`
#   2. tests/integration/bootstrap.php refuses the same
#   3. the `wptest` user is GRANTed on `wordpress_test` ONLY, so even a
#      completely broken bootstrap physically cannot reach the dev data
#
# (3) is the one that actually matters — the first two are conventions, that one
# is a mechanism.
#
# The devcontainer's `wordpress` user has GRANT ALL on `wordpress`.* only and
# cannot create a schema, so this uses the MariaDB root account once at setup.
set -euo pipefail

DB_HOST="${WP_TESTS_DB_HOST:-db}"
DB_NAME="${WP_TESTS_DB_NAME:-wordpress_test}"
DB_USER="${WP_TESTS_DB_USER:-wptest}"
DB_PASS="${WP_TESTS_DB_PASS:-wptest}"
ROOT_PASS="${MARIADB_ROOT_PASSWORD:-wordpress}"

if [ "${DB_NAME}" = "wordpress" ] || [ -z "${DB_NAME}" ]; then
	echo "Refusing to use '${DB_NAME}' as the test database — that is the dev store." >&2
	exit 1
fi

# The devcontainer ships the MariaDB client as `mysql`; GitHub's runner images
# have moved between `mysql` and `mariadb` across releases. Pick whichever is
# there and say so plainly when neither is, rather than failing as
# "mysql: command not found" three layers into provisioning.
MYSQL_BIN=''
for candidate in mysql mariadb; do
	if command -v "${candidate}" >/dev/null 2>&1; then
		MYSQL_BIN="${candidate}"
		break
	fi
done

if [ -z "${MYSQL_BIN}" ]; then
	echo "No 'mysql' or 'mariadb' client found on PATH — cannot provision '${DB_NAME}'." >&2
	exit 1
fi

echo "==> Provisioning test database '${DB_NAME}' on ${DB_HOST} (via ${MYSQL_BIN})…"

"${MYSQL_BIN}" -h "${DB_HOST}" -u root -p"${ROOT_PASS}" <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`
	DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'%' IDENTIFIED BY '${DB_PASS}';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM '${DB_USER}'@'%';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'%';
FLUSH PRIVILEGES;
SQL

echo "==> Done. '${DB_USER}' can reach '${DB_NAME}' and nothing else."
