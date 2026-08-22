# PHP runtime for the integration suite. Mirrors the CI matrix: pdo_pgsql for the
# database, intl/zip/gd for the export and documentation code paths the bundles
# touch while booting.
FROM php:8.5-cli

COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions pdo_pgsql intl zip gd opcache

WORKDIR /app
