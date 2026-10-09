# Development image for working on the package: PHP CLI + Composer + the
# extensions the package and its test suite need. Not a production image.
#
# Build a different PHP version to reproduce a CI matrix failure:
#   PHP_VERSION=8.5 docker compose build php
ARG PHP_VERSION=8.3

FROM composer:2 AS composer

FROM php:${PHP_VERSION}-cli-bookworm

# install-php-extensions handles build deps and cleanup for PECL extensions.
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer /usr/bin/composer /usr/local/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions redis pcntl intl zip pcov

# Run as a regular user whose UID/GID match the host user, so files created in
# the mounted project (vendor/, caches) are not owned by root on Linux hosts.
ARG UID=1000
ARG GID=1000
RUN groupadd --gid "${GID}" dev \
    && useradd --uid "${UID}" --gid "${GID}" --create-home --shell /bin/bash dev

ENV COMPOSER_HOME=/home/dev/.composer \
    COMPOSER_CACHE_DIR=/home/dev/.composer/cache

USER dev
WORKDIR /app
