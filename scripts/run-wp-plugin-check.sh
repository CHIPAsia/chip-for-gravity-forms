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

echo "==> Preparing clean plugin directory..."
mkdir -p "dist/${PLUGIN_SLUG}"
git archive HEAD | tar -x -C "dist/${PLUGIN_SLUG}"
echo "✅ Clean plugin folder prepared"

echo ""
echo "==> Updating .wp-env.json to use clean build..."
cp .wp-env.json .wp-env.json.bak
python3 -c "
import json, os
slug = '${PLUGIN_SLUG}'
with open('.wp-env.json', 'r') as f:
    data = json.load(f)
old_key = 'wp-content/plugins/' + slug
data['mappings'].pop(old_key, None)
data['mappings']['wp-content/plugins/' + slug] = os.path.abspath('dist/' + slug)
with open('.wp-env.json', 'w') as f:
    json.dump(data, f, indent='\t')
    f.write('\n')
"

echo "==> Starting wp-env (WordPress + Plugin Check)..."
npx --yes @wordpress/env start

echo ""
echo "==> Running WordPress Plugin Check for ${PLUGIN_SLUG}..."
npx --yes @wordpress/env run cli wp plugin activate "$PLUGIN_SLUG"
npx --yes @wordpress/env run cli wp plugin check "$PLUGIN_SLUG" --format=table --exclude-directories=vendor,node_modules

echo ""
echo "==> Stopping wp-env..."
npx --yes @wordpress/env stop

echo "==> Restoring .wp-env.json..."
mv .wp-env.json.bak .wp-env.json
rm -rf "dist/${PLUGIN_SLUG}"

echo "==> Plugin check finished."
