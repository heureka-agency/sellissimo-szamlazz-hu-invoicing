# Install Számlázz.hu for Sellissimo

From your Statamic 6 project, install the addon and its Sellissimo Core dependency. It requires PHP 8.3 or later and the PHP DOM, libxml and SimpleXML extensions. For an existing shop, back up the commerce database, private files and application encryption key before updating.

```sh
composer require sellissimo/szamlazz-hu-invoicing
```

On a new store, run `php please sellissimo:install` to set up Sellissimo. On an existing store, run the pending Core migrations. Then run the invoicing migrations on either installation.

```sh
php artisan migrate --database=sellissimo --path=vendor/sellissimo/core/database/migrations --force
php artisan migrate --database=sellissimo --path=vendor/sellissimo/szamlazz-hu-invoicing/database/migrations --force
```

The migration command uses Sellissimo's default commerce connection, `sellissimo`. If the store sets `SELLISSIMO_CONNECTION` or overrides `sellissimo.connection`, use that connection's name in `--database`. Keep `APP_KEY` stable across deployments and queue workers. It protects account credentials, fiscal snapshots and document history.

## Free and Pro

In the site's `config/statamic/editions.php`, add this entry inside the existing `addons` array.

```php
'sellissimo/szamlazz-hu-invoicing' => filter_var(
    env('SELLISSIMO_SZAMLAZZ_PRO', false),
    FILTER_VALIDATE_BOOLEAN
) ? 'pro' : 'free',
```

Free is the default. For a Pro installation set `SELLISSIMO_SZAMLAZZ_PRO=true` in the deployment environment. To return to Free, set it to `false`. After either change rebuild configuration and restart long-running queue workers. Cache the routes if the site uses a route cache.

```sh
php artisan config:cache
php artisan route:cache
php artisan queue:restart
```

Give the site's staff the relevant view, issue and setup permissions. In Store Settings, open Számlázz.hu and select the intended site. Confirm the edition and test/live mode at the top. Enter the Agent key for the dedicated test account, select a VAT code for each applicable tax class, and enter the invoice label for each payment method. Save before placing a new test order. Pro merchants can enable the site's trigger after verifying a manual invoice. Configure the site's Laravel scheduler and queue worker for background processing. Use the [English operator guide](guide-en.md) or [Hungarian operator guide](guide-hu.md) for the merchant workflow.
