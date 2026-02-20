# Local plugin-check: PHP 8.4 + PHPCS (PHPCompatibility + WordPress Coding Standards)
# Matches .github/workflows/plugin-check.yml PHP jobs.
FROM php:8.4-cli-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    zip \
    rsync \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_HOME=/tmp/composer
RUN composer global config allow-plugins.dealerdirect/phpcodesniffer-composer-installer true \
    && composer global require --dev \
    squizlabs/php_codesniffer \
    dealerdirect/phpcodesniffer-composer-installer \
    phpcompatibility/phpcompatibility-wp:"*" \
    wp-coding-standards/wpcs:"^3.0" \
    && rm -rf /tmp/composer/cache

ENV PATH="/tmp/composer/vendor/bin:${PATH}"

WORKDIR /app
