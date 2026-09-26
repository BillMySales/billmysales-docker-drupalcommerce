Drupal Commerce Docker stack
============================

Docker Compose stack for [Drupal Commerce](https://drupalcommerce.org)
(e-commerce on Drupal: products, cart, checkout, payments, taxes,
promotions), usable for local development and for simple production
deployments (a single server). Maintained by
[BillMySales](https://www.billmysales.com).

| Component   | Image                                                   | Default version                         |
|-------------|---------------------------------------------------------|-----------------------------------------|
| Web server  | `caddy:<ver>-alpine`                                    | 2.11                                    |
| Drupal      | own image (`image/`) on `drupal:<ver>-php<ver>-fpm-alpine` | Drupal 11.4.7, Commerce 3.3.10, Drush 13.8.0, PHP 8.5 |
| Database    | `mariadb:<ver>`                                         | 12.3 (LTS)                              |
| Mailpit     | `axllent/mailpit` (optional, dev)                       | v1.31                                   |

Drupal Commerce is a set of Drupal modules installed with Composer, so no
image ships it. `image/Dockerfile` extends the Docker Official Drupal image
(PHP-FPM on Alpine, with Drupal's Composer project in `/opt/drupal`):

- `composer require` of `drupal/core-recommended` pinned to
  `DRUPAL_VERSION` (with `drupal/core-composer-scaffold` pinned too: the
  base image's project requires it on its own, so its version wouldn't
  follow `DRUPAL_VERSION`), `drupal/commerce` (`COMMERCE_VERSION`), `drush/drush`
  (`DRUSH_VERSION`) and any `DRUPAL_EXTRA_PACKAGES`;
- the `bcmath` extension (required by Commerce) and APCu (recommended by
  Drupal's status report); the base image already has gd, opcache,
  pdo_mysql, zip...;
- about 1 minute to build, ~240 MB, amd64 and arm64 (tested on arm64).

PHP 8.5 and MariaDB 12.3: Drupal 11.4 supports PHP 8.3+ (8.5 included) and
MariaDB 10.6+. Rejected: the official image alone (no Commerce, Apache
variant by default), Commerce Kickstart (a distribution that lags behind
Commerce releases and adds demo content).

Requirements
------------

- Docker Engine 24+ with the Compose v2 plugin (`docker compose`, 2.20+).
- About 700 MB of disk for the images; 512 MB of RAM for the stack.
- Internet access on the first install (the Spanish translations are
  downloaded from localize.drupal.org).
- Development: ports 8117, 8417 and 8025 free on the host.
- Production: a server with ports 80 and 443 reachable, and a DNS record for
  the site's domain pointing to it.

Quick start (development)
-------------------------

```shell
cp .env.dev.example .env
docker compose up -d --build   # builds the image the first time (~1 minute)
docker compose logs -f setup   # wait for "==> Done"
```

- Shop: http://localhost:8117
- Admin: http://localhost:8117/user/login (user `admin`, password
  `admin12345`), then http://localhost:8117/admin/commerce
- Mailpit (every email Drupal sends): http://localhost:8025

Production
----------

```shell
cp .env.prod.example .env
# Required: DRUPAL_URL, SITE_ADDRESS, DRUPAL_HASH_SALT, DB_PASSWORD,
# DB_ROOT_PASSWORD, DRUPAL_ADMIN_EMAIL, DRUPAL_ADMIN_PASSWORD.
# Recommended: the SMTP_* values (without SMTP_HOST no emails are sent).
docker compose up -d --build
```

- With `SITE_ADDRESS` set to the domain, Caddy gets a Let's Encrypt certificate
  and renews it automatically (certificates live in the `caddy_data` volume).
- Behind another TLS-terminating proxy, use `SITE_ADDRESS=:80`.
- Compose refuses to start while a required value is missing.
- The `backup` profile is enabled by default in the production template.
- Behind an existing Traefik (no host ports), use `overrides/traefik.yaml`
  (see [Overrides](#overrides)).
- The image is built on the server (or build it elsewhere, push it to a
  registry and set `DRUPAL_IMAGE`).

Services
--------

| Service   | Profile   | Role                                                              |
|-----------|-----------|-------------------------------------------------------------------|
| `db`      |           | MariaDB, data in the `db_data` volume.                            |
| `setup`   |           | One-shot job (`scripts/setup.sh`), runs on every `up`.            |
| `php`     |           | PHP-FPM with Drupal (internal port 9000).                         |
| `cron`    |           | `drush core:cron` every `CRON_INTERVAL` (queues, cleanup, Commerce's jobs). |
| `caddy`   |           | TLS, static files, the only published ports (80, 443).            |
| `console` | `tools`   | Drush, as `www-data`.                                             |
| `backup`  | `backup`  | Database dump + public and private files on a schedule.           |
| `mailpit` | `mailpit` | Development SMTP server that catches all mail.                    |

The code (core, Commerce, contrib modules, vendor) lives in the image
(immutable); `config/drupal/settings.php` is mounted read-only as the site's
`settings.php`. Data: the database, public files (`files` volume, uploads,
image styles, aggregated CSS/JS) and private files (`private` volume, outside
the web root). Caddy serves static files from the `web` volume (a copy of the
image's `web/`, refreshed by `setup` when the image changes) plus the `files`
volume; everything else goes to `index.php` (PHP-FPM). No PHP file other
than `index.php` runs (no `install.php`, `update.php` or `rebuild.php`).

### What `setup` does

- Copies the image's web root to Caddy's volume when the image's build id
  changes (also after a same-version rebuild, e.g. new extra packages).
- Empty database: `drush site:install standard` in `DRUPAL_LOCALE` (the
  admin is `DRUPAL_ADMIN_USER`, `DRUPAL_ADMIN_EMAIL`,
  `DRUPAL_ADMIN_PASSWORD`), then Commerce's modules: product, cart,
  checkout, payment, tax, promotion. Otherwise: `drush updatedb` (every
  run; it only changes something after a new image).
- `scripts/configure.php`, on every run, each item created only if missing
  (then kept as edited in the admin; a new `COMMERCE_CURRENCY` or a renamed
  `COMMERCE_TAX_NAME` creates another one, so change them in the admin, not
  in `.env`, after the install):
  - the currency (`COMMERCE_CURRENCY`, CLP), with the symbol of the store's
    country (`$`; Drupal's `es` data says `CLP`);
  - the default store: `DRUPAL_SITE_NAME`, `COMMERCE_COUNTRY`,
    `COMMERCE_CITY`, the site's time zone, registered for taxes in the
    country, prices including tax (`COMMERCE_PRICES_INCLUDE_TAX`); with it,
    the site's default country and time zone;
  - a tax type "IVA" 19% for Chile (`COMMERCE_TAX_NAME`, `COMMERCE_TAX_RATE`,
    shown as "IVA" inside the prices);
  - a manual payment gateway "Transferencia bancaria" (the order is placed
    unpaid; the payment is recorded in the admin).
- `drush cache:rebuild`.

Common commands
---------------

```shell
docker compose ps                        # status: every service "healthy", setup "Exited (0)"
docker compose logs -f php               # logs (PHP errors go to stderr)
docker compose exec db mariadb -udrupal -p drupal     # SQL shell
docker compose run --rm console          # drush status (profile "tools")
docker compose run --rm console user:password admin 'new-password'
docker compose exec -u www-data php drush pm:install commerce_log   # any Drush command
docker compose exec -u www-data php drush watchdog:show            # Drupal's log
docker compose down                      # stop, keep data
docker compose down -v                   # stop and DELETE all data
```

Store and language
------------------

- The standard install profile with Olivero (shop) and Claro (admin), in
  Spanish: Drupal's `es` translation is Spain Spanish ("Añadir a la cesta",
  "Tramitar compra"); edit strings at `/admin/config/regional/translate`.
  A few Commerce strings are untranslated upstream (in the order receipt:
  "Order date", "Order Total").
- Prices are decimals (`9990` CLP is shown as `$9.990`); with IVA included
  in prices, Commerce computes the tax inside them (IVA $1.595 in $9.990).
- Guest checkout is enabled (Commerce's default checkout flow); customers can
  also register.
- **Shipping** is not part of Commerce core: add `drupal/commerce_shipping`
  to `DRUPAL_EXTRA_PACKAGES` (see [Customizing](#customizing-the-image)),
  then enable it and configure shipping methods in the admin.
- No product is created: add them at `/admin/commerce/products`.

Emails
------

Drupal and Commerce send them through core's Symfony Mailer: order receipts
(Commerce), account emails, password resets, contact forms. SMTP comes from
`SMTP_*` (`SMTP_SECURE`: `tls` = STARTTLS when the server offers it, `ssl` =
SMTPS, `none` = never TLS); without `SMTP_HOST`, Drupal uses PHP's `mail()`,
which the image can't deliver. The sender is `SMTP_FROM`: the site's email on
every request, the store's email only when the store is created (then edit
it in the store's settings); without it, `DRUPAL_ADMIN_EMAIL`. Emails sent
by cron build their links from `DRUPAL_URL`.

Backups
-------

With the `backup` profile, the `backup` service writes `<timestamp>-db.sql.gz`
and `<timestamp>-files.tar.gz` (public and private files) to the `backups`
volume (or `./data/backups` with `overrides/local-dirs.yaml`) at start and
then every `BACKUP_INTERVAL_HOURS`, and deletes files older than
`BACKUP_KEEP_DAYS`. Files are readable by their owner only.
`DRUPAL_HASH_SALT` is not in them: keep your `.env`.

```shell
docker compose run --rm --no-deps backup now                  # back up now
docker compose run --rm --no-deps backup list                 # list timestamps
docker compose stop php cron                                  # stop the app first
docker compose run --rm --no-deps backup restore <timestamp>  # database and files
docker compose up -d
docker compose exec -u www-data php drush cache:rebuild
```

`--no-deps` keeps the command from starting `setup` first (with damaged
data `setup` fails and the restore would never run); the database must
be running (`docker compose up -d db` if the stack is down).

A restore drops every table first, so nothing created after the backup
remains.

Upgrades
--------

Back up first, then change `DRUPAL_VERSION` and/or `COMMERCE_VERSION` in
`.env` and run `docker compose up -d --build`: the image is rebuilt and
`setup` runs `drush updatedb` and refreshes the web root before PHP-FPM
starts. Read the release notes of Drupal minor versions and of Commerce.
Rebuild regularly (`docker compose build --pull`) for PHP and Alpine
security fixes, and for Drupal security releases (bump the versions).

- Composer refuses versions with known security advisories: an old Drupal
  (e.g. 11.3.16) no longer builds, which is intended.
- Sites installed with Drupal 11.3 or older keep the modules of that
  version's standard profile; Drupal 11.4 marks one of them (`history`)
  deprecated, and the status report warns about it. Uninstall it
  (`drush pm:uninstall history`) if nothing uses it, or add its contrib
  version (`drupal/history`) before Drupal 12.

Customizing the image
---------------------

The code is built into the image, so modules and themes are not installed
from the admin: add Composer packages with `DRUPAL_EXTRA_PACKAGES` (space
separated, e.g. `drupal/commerce_shipping:^3 drupal/admin_toolbar`) and
rebuild (`docker compose up -d --build`), then enable them:

```shell
docker compose exec -u www-data php drush pm:install commerce_shipping
```

Configuration changes made in the admin live in the database (and are
backed up with it). For larger projects (own modules, patches, config
management with `config/sync`), extend `image/Dockerfile`.

A custom module under development can be mounted from the host with
`overrides/module.yaml`. A BillMySales integration would be a Drupal module
subscribing to Commerce's order events (`commerce_order.place.post_transition`)
or a client of Drupal's JSON:API.

Overrides
---------

Optional compose files in `overrides/`, enabled with `COMPOSE_FILE` in `.env`
(several are combined with `:`). Each file documents its variables.

```shell
COMPOSE_FILE=compose.yaml:overrides/traefik.yaml:overrides/local-dirs.yaml
```

| File                        | Purpose                                                            |
|-----------------------------|--------------------------------------------------------------------|
| `overrides/traefik.yaml`    | Publish through an existing Traefik on a shared external network:  |
|                             | no host ports, Traefik terminates TLS (`TRAEFIK_HOST`, ...).       |
| `overrides/local-dirs.yaml` | Database, files, private files, web root, Caddy and backups in     |
|                             | local directories (`DATA_DIR`, default `./data`) instead of volumes. |
| `overrides/module.yaml`     | A custom module from the host (`MODULE_PATH`, `MODULE_NAME`) in    |
|                             | `web/modules/custom/<name>`, with PHP checking files for changes.  |

A local `compose.override.yaml` (gitignored) is also loaded automatically by
Docker Compose, for changes specific to one machine.

Configuration
-------------

Every variable is documented in `.env.prod.example`. Main groups:

- **Site and network**: `DRUPAL_URL`, `DRUPAL_EXTRA_HOSTS`, `SITE_ADDRESS`,
  `HTTP_BIND`, `HTTP_PORT`, `HTTPS_PORT`, `TIMEZONE` (PHP's time zone on
  every start; the site's and the store's only when the store is created).
- **Credentials**: `DRUPAL_HASH_SALT`, `DB_PASSWORD`, `DB_ROOT_PASSWORD`,
  `DRUPAL_ADMIN_EMAIL`, `DRUPAL_ADMIN_PASSWORD` (required),
  `DRUPAL_ADMIN_USER`.
- **Site and store** (created if missing): `DRUPAL_SITE_NAME`,
  `DRUPAL_LOCALE`, `COMMERCE_CURRENCY`, `COMMERCE_COUNTRY`, `COMMERCE_CITY`,
  `COMMERCE_TAX_*`, `COMMERCE_PRICES_INCLUDE_TAX`.
- **Versions**: `DRUPAL_VERSION`, `COMMERCE_VERSION`, `DRUSH_VERSION`,
  `DRUPAL_EXTRA_PACKAGES`, `PHP_VERSION`, `MARIADB_VERSION`,
  `CADDY_VERSION`, ...
- **Mail**: `SMTP_HOST`, `SMTP_PORT`, `SMTP_SECURE`, `SMTP_USER`,
  `SMTP_PASSWORD`, `SMTP_FROM`.
- **PHP, resources and logs**: `DRUPAL_ERROR_LEVEL`, `PHP_*`, `PHP_FPM_*`,
  `*_MEMORY_LIMIT` per service, `UPLOAD_MAX_SIZE`, `CRON_INTERVAL`,
  `LOG_MAX_SIZE`, `LOG_MAX_FILE`.

Notes:

- Production settings: Drupal errors hidden (`DRUPAL_ERROR_LEVEL=hide`),
  PHP errors logged (never displayed unless `PHP_DISPLAY_ERRORS=On`),
  aggregated CSS/JS, OPcache without file checks, database isolation level
  `READ COMMITTED` (Drupal's recommendation), APCu. The status report
  (`/admin/reports/status`) shows no warnings on a fresh install.
- Drupal answers only to the host of `DRUPAL_URL` and `DRUPAL_EXTRA_HOSTS`
  (`trusted_host_patterns`); other hosts get HTTP 400.
- Behind Caddy (and Traefik), Drupal trusts the proxy headers from its only
  client, Caddy, which sends the real client IP and scheme: links and
  session cookies follow the public `https://` address (`SSESS...`,
  `Secure`). The trusted proxy is the direct peer (`REMOTE_ADDR`), not a
  list of private ranges: Caddy sets `REMOTE_ADDR` to the client's IP,
  which a range list wouldn't match for public clients, and links would be
  `http://` behind Traefik.
- From inside the containers, the host machine is reachable as
  `host.docker.internal`.

Security
--------

- Client IP headers: PHP gets only the real client IP (as Caddy sees it) in
  `REMOTE_ADDR`, `X-Forwarded-For` and `X-Real-IP`, and no `Client-Ip`,
  `Cf-Connecting-Ip` or `X-Forwarded-Port` (a client could forge them), like in the other PHP
  stacks. Drupal logs (sessions, watchdog, flood control) that IP.
- No default secrets: compose fails if the required passwords and the hash
  salt are missing. The development template uses public values; never use
  it on a server.
- PHP runs as `www-data`; only Caddy (and Mailpit in development) publishes
  ports; PHP-FPM and MariaDB are internal. `HTTP_BIND` defaults to
  `127.0.0.1`.
- Caddy only runs `index.php` and blocks what Drupal's `.htaccess` and nginx
  recipe block: dotfiles, source files (`*.module`, `*.inc`, `*.yml`,
  `*.twig`...), `composer.*`, `settings*.php`, and text files that tell the
  exact version (`CHANGELOG.txt`, `README.md`; uploaded `.txt` files are
  served). Security headers are added when Drupal doesn't send them; PHP's
  version is not exposed.
- `settings.php` is read-only; `update.php` is never reachable (updates run
  in `setup` with Drush); private files are outside the web root.
- Not included: a web application firewall or off-site backup copies.

Validation
----------

What was checked for this stack (2026-09-25):

- Clean start (`down -v` + `up -d`, image built) in about 55 s (translations
  included): every service `healthy`, `setup` `Exited (0)`; a second run
  makes no changes and keeps admin changes (store name, tax rate, slogan).
- Shop and admin in Spanish with all their CSS, JS and images; admin login
  through the form; status report without warnings.
- A full guest checkout through the forms: add to cart, cart, guest login
  step, order information (billing address in Chile), review, bank transfer:
  order completed at $9.990 with IVA $1.595 included; receipt email in
  Mailpit ("Pedido #1 confirmado").
- Image styles generated on first request (AVIF) and then served by Caddy;
  blocked paths answer 404 (`install.php`, `settings.php`, `CHANGELOG.txt`,
  `*.yml`, PHP in files).
- Backup and restore (deleted product, renamed store, a dropped table and a
  deleted file back; a table created after the backup gone).
- Upgrade Drupal 11.3.17 + Commerce 3.2.0 (PHP 8.4) → 11.4.7 + 3.3.10 (PHP
  8.5) with an order: database updates applied, no entity updates pending,
  order kept, a new checkout afterwards; the schema matches a fresh install
  except for the standard profile's differences between 11.3 and 11.4.
- `DRUPAL_URL` change and back (old host refused with 400, links on the new
  one).
- HTTPS with `SITE_ADDRESS=localhost` and the production template (links,
  image styles, login redirect and `Secure` session cookie on
  `https://localhost:8417`); overrides: Traefik v3.6 with no host ports (a
  public client IP, 8.8.8.8, in sessions and the log; `https` links and
  `Secure` cookies), local directories (fresh install, backup), custom
  module mounted from the host (PHP changes live, assets served);
  `DRUPAL_EXTRA_PACKAGES` with `commerce_shipping`.
- Not tested: issuing a real Let's Encrypt certificate (needs a public
  domain), SMTPS/STARTTLS with a real provider.

Resource usage
--------------

Idle, after a few requests: MariaDB ~160 MiB, PHP-FPM ~70 MiB, Caddy
~15 MiB, cron ~5 MiB (about 250 MiB in total). Image ~240 MB.

License
-------

[MIT](LICENSE).
