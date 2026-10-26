# Development image for working on the package: PHP CLI + Composer + the
# extensions the package and its test suite need. Not a production image.
#
# Build a different PHP version to reproduce a CI matrix failure:
#   PHP_VERSION=8.5.12 docker compose build php
#
# Pinned to patch releases, not to a minor tag like 8.3: Docker reuses a
# cached 8.3 image, which may be older than the zero-day fix (ADR 0003).
ARG PHP_VERSION=8.3.36

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

# The project is mounted from the host and owned by another UID; without this
# git refuses to read it and Composer cannot detect the package version.
RUN git config --global --add safe.directory /app
