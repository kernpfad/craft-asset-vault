# Cloud Agent base image for the Asset Vault Craft CMS plugin.
#
# Ships the same PHP toolchain the CI matrix uses (PHP 8.2+, the extensions
# listed in .github/workflows/ci.yml) plus Composer. Project dependencies are
# installed from the checked-out source by the `install` command in
# .cursor/environment.json, not baked into the image.
FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        git \
        unzip \
        php-cli \
        php-bcmath \
        php-curl \
        php-gd \
        php-intl \
        php-mbstring \
        php-sqlite3 \
        php-xml \
        php-zip \
    && curl -sS https://getcomposer.org/installer \
        | php -- --install-dir=/usr/local/bin --filename=composer \
    && rm -rf /var/lib/apt/lists/*

# Ubuntu 24.04 ships a default non-root `ubuntu` user (uid 1000), which the
# environment runs as.
USER ubuntu
