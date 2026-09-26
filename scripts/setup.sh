#!/bin/sh
# shellcheck disable=SC2153,SC2016 # variables set by compose; PHP code in single quotes
# Installs or updates Drupal Commerce on every `docker compose up`; safe to
# repeat. Runs as root (volumes' owners); Drush runs as www-data:
# - web/ (the image's web root, static files for Caddy) copied to the `web`
#   volume when the image's build id changes.
# - Empty database: `drush site:install standard` (DRUPAL_LOCALE: its
#   translations are downloaded from localize.drupal.org) and Commerce's
#   modules. Otherwise: `drush updatedb` (every run) and cache rebuild.
# - scripts/configure.php: Commerce store settings (see there).
set -eu
cd /opt/drupal

drush() { su-exec www-data /opt/drupal/vendor/bin/drush --yes "$@"; }

echo "==> Web root for Caddy"
build="$(cat web/.build)"
if [ "$(cat /srv/web/.build 2>/dev/null || true)" != "${build}" ]; then
    echo "Copying the image's web root (${build})"
    find /srv/web -mindepth 1 -maxdepth 1 ! -name sites -exec rm -rf {} +
    # modules/custom: overrides/module.yaml mounts modules there from the host.
    tar -C web --exclude=./sites/default/files --exclude=./modules/custom --exclude=./.build -cf - . | tar -C /srv/web -xf -
    # Caddy mounts the files volume here (read-only volume: needs the mount point).
    mkdir -p /srv/web/sites/default/files
    # Build id last: an interrupted copy is repeated.
    cp web/.build /srv/web/.build
fi
# Mount point of a module from the host in Caddy's read-only web root
# (overrides/module.yaml).
if [ -n "${MODULE_NAME:-}" ]; then
    mkdir -p "/srv/web/modules/custom/${MODULE_NAME}"
fi
for dir in web/sites/default/files /var/www/private; do
    find "${dir}" ! -user www-data -exec chown www-data:www-data {} +
done

for _ in $(seq 60); do
    php -r 'try { new PDO("mysql:host=" . getenv("DB_HOST") . ";port=" . getenv("DB_PORT"), getenv("DB_USER"), getenv("DB_PASSWORD")); } catch (Exception $e) { exit(1); }' 2>/dev/null && break
    sleep 2
done

# Separate assignment: with `set -e`, a failing check stops setup here.
installed="$(php -r '
    $db = new PDO("mysql:host=" . getenv("DB_HOST") . ";port=" . getenv("DB_PORT") . ";dbname=" . getenv("DB_NAME"), getenv("DB_USER"), getenv("DB_PASSWORD"));
    echo $db->query("SHOW TABLES LIKE \"users_field_data\"")->rowCount();')"
if [ "${installed}" = 0 ]; then
    echo "==> Installing Drupal ${DRUPAL_VERSION} (${DRUPAL_LOCALE}) with Commerce ${COMMERCE_VERSION}"
    drush site:install standard \
        --locale="${DRUPAL_LOCALE}" \
        --site-name="${DRUPAL_SITE_NAME}" \
        --site-mail="${SMTP_FROM:-${DRUPAL_ADMIN_EMAIL}}" \
        --account-name="${DRUPAL_ADMIN_USER}" \
        --account-mail="${DRUPAL_ADMIN_EMAIL}" \
        --account-pass="${DRUPAL_ADMIN_PASSWORD}"
    drush pm:install commerce_product commerce_cart commerce_checkout commerce_payment commerce_tax commerce_promotion
else
    echo "==> Database updates"
    drush updatedb
fi

echo "==> Store settings"
drush php:script /usr/local/share/stack/scripts/configure.php
drush cache:rebuild

echo "==> Done: Drupal ${DRUPAL_VERSION}, Commerce ${COMMERCE_VERSION}"
echo "    Site:  ${DRUPAL_URL}"
echo "    Admin: ${DRUPAL_URL}/user/login (${DRUPAL_ADMIN_USER})"
