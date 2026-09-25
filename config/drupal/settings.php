<?php

/**
 * @file
 * Drupal settings of the Docker stack, from environment variables.
 *
 * Bind-mounted read-only at web/sites/default/settings.php in every Drupal
 * container. Drupal's own default.settings.php documents every option.
 */

$env = static function (string $name, string $default = ''): string {
  $value = getenv($name);
  return $value === FALSE || $value === '' ? $default : $value;
};

$databases['default']['default'] = [
  'driver' => 'mysql',
  'namespace' => 'Drupal\\mysql\\Driver\\Database\\mysql',
  'autoload' => 'core/modules/mysql/src/Driver/Database/mysql/',
  'host' => $env('DB_HOST', 'db'),
  'port' => $env('DB_PORT', '3306'),
  'database' => $env('DB_NAME', 'drupal'),
  'username' => $env('DB_USER', 'drupal'),
  'password' => $env('DB_PASSWORD'),
  'prefix' => '',
  'collation' => 'utf8mb4_general_ci',
  // Drupal's recommended level (the status report warns about MariaDB's
  // default, REPEATABLE READ).
  'isolation_level' => 'READ COMMITTED',
];

// Required (compose refuses to start without it): used for one-time login
// links, form tokens, cache keys...
$settings['hash_salt'] = $env('DRUPAL_HASH_SALT');

$settings['config_sync_directory'] = '/var/www/config/sync';
// Private files (invoices, order documents...): outside the web root, in the
// `private` volume.
$settings['file_private_path'] = '/var/www/private';
$settings['file_temp_path'] = '/tmp';

// The site's host (DRUPAL_URL) and any extra ones (DRUPAL_EXTRA_HOSTS, comma
// separated); requests for other hosts are refused.
$hosts = array_filter(array_map('trim', explode(',', $env('DRUPAL_EXTRA_HOSTS'))));
$hosts[] = (string) parse_url($env('DRUPAL_URL', 'http://localhost'), PHP_URL_HOST);
$settings['trusted_host_patterns'] = array_map(
  static fn (string $host): string => '^' . preg_quote($host) . '$',
  array_unique($hosts)
);

// Behind Caddy, the only client of PHP-FPM: it passes the real client IP
// (REMOTE_ADDR, X-Forwarded-For) and the scheme (X-Forwarded-Proto) and
// never forwards what a client sends, so the direct peer is trusted as is.
$settings['reverse_proxy'] = TRUE;
$settings['reverse_proxy_addresses'] = [$_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'];
// The port comes from the Host header (Caddy drops X-Forwarded-Port).
$settings['reverse_proxy_trusted_headers'] = \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_FOR
  | \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_PROTO;

// Mail through SMTP (core's Symfony Mailer backend) from SMTP_*; without
// SMTP_HOST, core's default (PHP mail(), which the image can't deliver).
if ($env('SMTP_HOST') !== '') {
  $secure = strtolower($env('SMTP_SECURE', 'tls'));
  $config['system.mail']['interface']['default'] = 'symfony_mailer';
  $config['system.mail']['mailer_dsn'] = [
    'scheme' => $secure === 'ssl' ? 'smtps' : 'smtp',
    'host' => $env('SMTP_HOST'),
    'user' => $env('SMTP_USER') !== '' ? $env('SMTP_USER') : NULL,
    'password' => $env('SMTP_PASSWORD') !== '' ? $env('SMTP_PASSWORD') : NULL,
    'port' => (int) $env('SMTP_PORT', '587'),
    // none: plain (no STARTTLS even if offered).
    'options' => $secure === 'none' ? ['auto_tls' => FALSE] : [],
  ];
}
if ($env('SMTP_FROM') !== '') {
  $config['system.site']['mail'] = $env('SMTP_FROM');
}

// Production defaults: errors logged, never displayed; aggregated CSS/JS.
$config['system.logging']['error_level'] = $env('DRUPAL_ERROR_LEVEL', 'hide');
$config['system.performance']['css']['preprocess'] = TRUE;
$config['system.performance']['js']['preprocess'] = TRUE;

// Update the site from `drush updb` only (setup), not from the browser.
$settings['update_free_access'] = FALSE;
$settings['file_scan_ignore_directories'] = ['node_modules', 'bower_components'];
$settings['entity_update_batch_size'] = 50;
$settings['entity_update_backup'] = TRUE;
$settings['state_cache'] = TRUE;
// Browser (HTML5) form validation off: the default from Drupal 12, and the
// status report asks to set it; Drupal validates every form on the server.
$settings['enable_html5_validation'] = FALSE;
