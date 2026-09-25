#!/bin/sh
# shellcheck disable=SC2153 # variables set by compose
# Drupal's cron every CRON_INTERVAL seconds (queues, cleanup, Commerce's
# order and cart jobs), as www-data; a heartbeat file for the healthcheck.
set -eu
cd /opt/drupal
while :; do
    vendor/bin/drush --yes core:cron || echo "drush cron failed" >&2
    touch /tmp/cron-heartbeat
    sleep "${CRON_INTERVAL}"
done
