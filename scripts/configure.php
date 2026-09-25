<?php

/**
 * @file
 * Commerce store settings of the Docker stack (drush php:script, run by
 * setup.sh after the install or the database updates; safe to repeat).
 *
 * Each item is created only if missing, so later changes in the admin are
 * kept and a run that failed halfway is completed by the next one:
 * - the store currency (COMMERCE_CURRENCY, CLP) imported from CLDR, with
 *   the symbol used in the store's country ("$" in es-CL);
 * - the store (DRUPAL_SITE_NAME, COMMERCE_COUNTRY, the site's time zone,
 *   prices including tax: COMMERCE_PRICES_INCLUDE_TAX), as the default one;
 * - a tax type for the country (COMMERCE_TAX_NAME, COMMERCE_TAX_RATE %);
 * - a manual payment gateway "Transferencia bancaria" (the order is placed
 *   unpaid; the payment is recorded in the admin when it arrives);
 * - the site's default country and time zone (with the store).
 */

use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_store\Entity\Store;
use Drupal\commerce_tax\Entity\TaxType;

$env = static function (string $name, string $default = ''): string {
  $value = getenv($name);
  return $value === FALSE || $value === '' ? $default : $value;
};

$entities = \Drupal::entityTypeManager();
$currency = strtoupper($env('COMMERCE_CURRENCY', 'CLP'));
$country = strtoupper($env('COMMERCE_COUNTRY', 'CL'));
$timezone = $env('TZ', 'America/Santiago');

if ($entities->getStorage('commerce_currency')->load($currency) === NULL) {
  $imported = \Drupal::service('commerce_price.currency_importer')->import($currency);
  // The importer takes the symbol of the site's language ("es": "CLP");
  // use the one of the language in the store's country ("es-CL": "$").
  $locale = substr($env('DRUPAL_LOCALE', 'es'), 0, 2) . '-' . $country;
  $symbol = (new \CommerceGuys\Intl\Currency\CurrencyRepository())->get($currency, $locale)->getSymbol();
  $imported->setSymbol($symbol)->save();
  echo "Currency {$currency} imported (symbol {$symbol})\n";
}

$stores = $entities->getStorage('commerce_store');
if ($stores->getQuery()->accessCheck(FALSE)->count()->execute() == 0) {
  // Site-wide regional settings, with the first store.
  \Drupal::configFactory()->getEditable('system.date')
    ->set('country.default', $country)
    ->set('timezone.default', $timezone)
    ->set('first_day', 1)
    ->save();

  Store::create([
    'type' => 'online',
    'uid' => 1,
    'name' => $env('DRUPAL_SITE_NAME', 'Drupal Commerce'),
    'mail' => $env('SMTP_FROM', $env('DRUPAL_ADMIN_EMAIL')),
    'default_currency' => $currency,
    'timezone' => $timezone,
    'address' => [
      'country_code' => $country,
      'locality' => $env('COMMERCE_CITY', 'Santiago'),
    ],
    'billing_countries' => [$country],
    // Taxes only apply where the store is registered to collect them.
    'tax_registrations' => [$country],
    'prices_include_tax' => $env('COMMERCE_PRICES_INCLUDE_TAX', 'true') === 'true',
    'is_default' => TRUE,
  ])->save();
  echo "Store created ({$country}, {$currency})\n";
}

$rate = $env('COMMERCE_TAX_RATE');
$name = $env('COMMERCE_TAX_NAME', 'IVA');
$tax_id = strtolower(preg_replace('/[^A-Za-z0-9]+/', '_', $name));
if ($rate !== '' && TaxType::load($tax_id) === NULL) {
  TaxType::create([
    'id' => $tax_id,
    'label' => $name,
    'plugin' => 'custom',
    'configuration' => [
      'display_inclusive' => $env('COMMERCE_PRICES_INCLUDE_TAX', 'true') === 'true',
      'display_label' => 'vat',
      'round' => TRUE,
      'rates' => [
        [
          'id' => 'standard',
          'label' => $name,
          'percentage' => (string) ((float) $rate / 100),
        ],
      ],
      'territories' => [
        ['country_code' => $country],
      ],
    ],
  ])->save();
  echo "Tax type {$name} ({$rate}%) created\n";
}

if (PaymentGateway::load('bank_transfer') === NULL) {
  PaymentGateway::create([
    'id' => 'bank_transfer',
    'label' => 'Transferencia bancaria',
    'weight' => 0,
    'plugin' => 'manual',
    'configuration' => [
      'display_label' => 'Transferencia bancaria',
      'mode' => 'n/a',
      'instructions' => [
        'value' => 'Te enviaremos los datos de la cuenta por correo; despacharemos tu pedido cuando recibamos el pago.',
        'format' => 'plain_text',
      ],
      'collect_billing_information' => TRUE,
    ],
  ])->save();
  echo "Payment gateway Transferencia bancaria created\n";
}

echo "Store settings OK\n";
