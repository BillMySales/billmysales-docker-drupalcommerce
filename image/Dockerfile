# Drupal Commerce on the Docker Official Drupal image: the image's Composer
# project (/opt/drupal, drupal/recommended-project) plus Commerce and Drush,
# and the bcmath extension Commerce needs. The code lives in the image;
# settings come from the stack (config/drupal/settings.php, environment).
ARG DRUPAL_VERSION=11.4.7
ARG PHP_VERSION=8.5
FROM drupal:${DRUPAL_VERSION}-php${PHP_VERSION}-fpm-alpine

ARG DRUPAL_VERSION
ARG COMMERCE_VERSION=3.3.10
ARG DRUSH_VERSION=13.8.0
# More Composer packages (contrib modules, themes), space separated, e.g.
# "drupal/commerce_shipping:^3 drupal/admin_toolbar".
ARG DRUPAL_EXTRA_PACKAGES=""

# bcmath: required by Commerce; APCu: recommended by Drupal (status report).
RUN set -eux; \
    apk add --no-cache su-exec; \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS; \
    docker-php-ext-install -j"$(nproc)" bcmath; \
    pecl install apcu; \
    docker-php-ext-enable apcu; \
    apk del --no-network .build-deps; \
    rm -rf /tmp/pear; \
    php -m | grep -q '^bcmath$'; \
    php -m | grep -q '^apcu$'

WORKDIR /opt/drupal
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN set -eux; \
    composer require --no-interaction --update-with-all-dependencies \
        "drupal/core-recommended:${DRUPAL_VERSION}" \
        "drupal/core-composer-scaffold:${DRUPAL_VERSION}" \
        "drupal/commerce:${COMMERCE_VERSION}" \
        "drush/drush:${DRUSH_VERSION}" \
        ${DRUPAL_EXTRA_PACKAGES}; \
    composer show drupal/commerce | grep -q "^versions : \* ${COMMERCE_VERSION}$"; \
    composer clear-cache; \
    # Writable mount points owned by www-data (82): new volumes inherit it.
    mkdir -p web/sites/default/files /var/www/private /var/www/config/sync; \
    chown www-data:www-data web/sites/default/files /var/www/private /var/www/config/sync; \
    # The web root for Caddy (copied to the `web` volume by setup), with a
    # build id.
    echo "${DRUPAL_VERSION} ${COMMERCE_VERSION} $(date -u +%Y%m%dT%H%M%SZ)" > web/.build

ENV PATH=/opt/drupal/vendor/bin:${PATH} \
    DRUPAL_VERSION=${DRUPAL_VERSION} \
    COMMERCE_VERSION=${COMMERCE_VERSION}
