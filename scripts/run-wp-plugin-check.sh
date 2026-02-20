#!/usr/bin/env bash
# Run WordPress Plugin Check locally (requires Node.js and Docker on the host).
# Mirrors the plugin-check job in .github/workflows/plugin-check.yml.
set -e

PLUGIN_SLUG="chip-for-gravity-forms"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(dirname "$SCRIPT_DIR")"

cd "$REPO_ROOT"

if ! command -v npx &>/dev/null; then
  echo "Error: npx (Node.js) is required." >&2
  echo "This script must be run on your host machine, not inside the plugin-check Docker container." >&2
  echo "Install Node.js, then from the plugin directory run: ./scripts/run-wp-plugin-check.sh" >&2
  exit 1
fi

if ! command -v docker &>/dev/null; then
  echo "Error: Docker is required for wp-env. Install Docker and try again." >&2
  exit 1
fi

if [[ ! -f .wp-env.json ]]; then
  echo "Error: .wp-env.json not found in repo root." >&2
  exit 1
fi

echo "==> Starting wp-env (WordPress + Plugin Check)..."
npx --yes @wordpress/env start

echo ""
echo "==> Running WordPress Plugin Check for ${PLUGIN_SLUG}..."
npx --yes @wordpress/env run cli wp plugin activate "$PLUGIN_SLUG"
npx --yes @wordpress/env run cli wp plugin check "$PLUGIN_SLUG" --format=table --exclude-directories=dist,.github

echo ""
echo "==> Stopping wp-env..."
npx --yes @wordpress/env stop

echo "==> Plugin check finished."
