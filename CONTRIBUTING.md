# Contributing to CHIP for Gravity Forms

Thank you for your interest in contributing! This document outlines how to set up the development environment, our coding standards, and the release process.

## Development Setup

### Prerequisites

- PHP 7.4 or higher (8.0+ recommended)
- Composer (for PHPUnit and PHPCS/WPCS)
- A local WordPress installation with Gravity Forms

### Installation

1. Clone the repository into your WordPress `plugins/` directory:
   ```bash
   cd wp-content/plugins
   git clone https://github.com/CHIPAsia/chip-for-gravity-forms.git
   cd chip-for-gravity-forms
   ```

2. Install PHP dependencies:
   ```bash
   composer install --no-interaction --prefer-dist
   ```

## Coding Standards

We follow the [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/). Run the linters before submitting a PR:

```bash
# PHP CodeSniffer (WordPress standards)
phpcs --standard=phpcs.xml .

# PHP Compatibility check
phpcs --standard=PHPCompatibilityWP --runtime-set testVersion 7.4 --extensions=php --ignore=vendor,node_modules,assets .
```

### Key Rules

- Use tabs for indentation (not spaces)
- Maximum line length: 120 characters
- All classes must use the `GravityFormsCHIP` namespace
- Always sanitize and escape output
- Include `defined( 'ABSPATH' ) || die();` guard at the top of every PHP file
- Text domain: `chip-for-gravity-forms`
- Never call `openssl_pkey_free()` — deprecated in PHP 8.0+

## Submitting Changes

1. Create a feature branch from `main`:
   ```bash
   git checkout -b feature/your-feature-name
   ```

2. Make your changes and write clear, concise commit messages

3. Ensure tests and linters pass:
   ```bash
   ./vendor/bin/phpunit
   phpcs --standard=phpcs.xml .
   ```

4. Push your branch and open a Pull Request against `main`

5. The PR summary workflow will auto-generate a description. Fill in any missing sections manually

## Release Process

### Automated Version Bump (Recommended)

Use the provided script to bump the version across all files:

```bash
./scripts/bump-version.sh 1.3.0
```

This will:
- Update version strings in all files (`chip-for-gravity-forms.php`, `readme.txt`, `package.json`, `changelog.txt`)
- Add a changelog entry template
- Stage changes for commit

After running the script, review the changes, write the changelog entry, commit, and push the tag:

```bash
git add -A
git commit -m "Bump version to 1.3.0"
git tag -a v1.3.0 -m "Release 1.3.0"
git push origin main --tags
```

The `deploy.yml` GitHub Actions workflow will then:
- Deploy to WordPress.org SVN (`trunk/` + `tags/1.3.0/` + `assets/`)
- Create a GitHub release with release notes from `changelog.txt`
- Attach the release ZIP

### Manual Version Bump

If you prefer not to use the script, follow this checklist:

- [ ] `chip-for-gravity-forms.php` — `Version: X.Y.Z` header
- [ ] `chip-for-gravity-forms.php` — `GF_CHIP_MODULE_VERSION` constant
- [ ] `readme.txt` — `Stable tag: X.Y.Z`
- [ ] `readme.txt` — `Tested up to:` updated if needed
- [ ] `package.json` — `version` field
- [ ] `changelog.txt` — Add new version entry with date
- [ ] `readme.txt` — Add new version entry in `== Changelog ==` section
- [ ] Run `./vendor/bin/phpunit` and fix any failures
- [ ] Run `phpcs --standard=phpcs.xml .` and fix any issues
- [ ] Run `git add -A && git commit -m "Bump version to X.Y.Z"`
- [ ] Push tag: `git tag -a vX.Y.Z -m "Release X.Y.Z" && git push origin vX.Y.Z`
- [ ] Verify GitHub Actions `deploy.yml` workflow succeeds
- [ ] Verify [wordpress.org plugin page](https://wordpress.org/plugins/chip-for-gravity-forms/) shows the new version

### Version Numbering

We follow [Semantic Versioning](https://semver.org/) adapted for WordPress plugins:

| Level | When to Bump | Example |
|---|---|---|
| **Major (X)** | Breaking changes, dropped PHP/WP support, major refactors | `2.0.0` |
| **Minor (Y)** | New features, new payment methods, new hooks | `1.3.0` |
| **Patch (Z)** | Bug fixes, security patches, compatibility bumps | `1.2.1` |

### WordPress.org SVN Notes

The deploy workflow manages three SVN directories:

- `trunk/` — Always contains the latest development code
- `tags/X.Y.Z/` — Immutable release snapshots
- `assets/` — Plugin page banners, icons, and screenshots (synced from `.wordpress-org/`)

**Important:** The `Stable tag` in `readme.txt` must match an existing tag directory. Never update `Stable tag` before the tag exists in SVN.

**Do NOT add `Requires Plugins: gravityforms` header.** Gravity Forms is not on WordPress.org SVN. The `Requires Plugins` header (WP 6.5+) only works for plugins in the wordpress.org repository and would break activation.

## Questions?

Open an issue on GitHub or reach out to the CHIP developer community.
