#!/usr/bin/env bash
# Host-side wrapper that drives the dev compose stack with the right engine + files.
# Autodetects podman (adds the rootless override compose.podman.yaml) else docker
# (base file only). Override with DC_ENGINE=podman|docker.
#
#   bin/dc.sh up -d        bin/dc.sh down [-v]        bin/dc.sh logs -f
#   bin/dc.sh exec -u www-data wordpress wp plugin list
set -euo pipefail

ROOT="$(cd -- "$(dirname -- "$0")/.." && pwd)"
BASE="$ROOT/.devcontainer/compose.yaml"
PODMAN_OVERRIDE="$ROOT/.devcontainer/compose.podman.yaml"

engine="${DC_ENGINE:-}"
if [ -z "$engine" ]; then
	if command -v podman >/dev/null 2>&1; then engine=podman; else engine=docker; fi
fi

if [ "$engine" = podman ]; then
	exec podman compose -f "$BASE" -f "$PODMAN_OVERRIDE" "$@"
else
	# Plain docker: skip the podman override (keep-id is podman-only).
	exec docker compose -f "$BASE" "$@"
fi
