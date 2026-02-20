#!/usr/bin/env bash
# Run the same PHP checks as .github/workflows/plugin-check.yml (build-zip, php-compatibility, phpcs).
set -e

PLUGIN_SLUG="chip-for-gravity-forms"

echo "==> 1. Prepare plugin folder (build-zip)"
mkdir -p "dist/${PLUGIN_SLUG}"
git archive HEAD | tar -x -C "dist/${PLUGIN_SLUG}"
echo "    Prepared dist/${PLUGIN_SLUG}/"

echo ""
echo "==> 2. PHP 8.4 compatibility (PHPCompatibilityWP)"
phpcs --standard=PHPCompatibilityWP --runtime-set testVersion 8.4 --extensions=php --ignore=vendor,node_modules,assets .

echo ""
echo "==> 3. PHPCS (WordPress standards, phpcs.xml)"
phpcs --standard=phpcs.xml .

echo ""
echo "All checks passed."
