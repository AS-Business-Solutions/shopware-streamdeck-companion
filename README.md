# Shopware Stream Deck Companion

Free, open-source Shopware 6 plugin (composer name `asbs/shopware-streamdeck`, technical
name `ASBusStreamDeckDashboard`) that exposes aggregated dashboard/metric endpoints for the
paid **Shopware Dashboard** Stream Deck plugin (`de.asbs.shopware`, available on the Elgato
Marketplace).

The companion works on its own — it just makes your shop's live metrics reachable by
the Stream Deck plugin. It is and stays free and MIT-licensed; the only paid part is the
Stream Deck plugin itself.

> ### ⚠️ Upgrading from 0.6.x or earlier
>
> The technical plugin name changed from `AsbsShopwareStreamDeck` to
> `ASBusStreamDeckDashboard` (Shopware does not permit the "Shopware" trademark in a
> technical plugin name). To Shopware this is a **different plugin**, so it does **not**
> upgrade in place:
>
> 1. Note your settings — plugin configuration stored under the old name does not carry over.
> 2. Uninstall the old `AsbsShopwareStreamDeck` extension.
> 3. Install this one and generate a fresh API key.
>
> Existing API keys are bound to the old plugin's table and will not be visible; issue new
> ones and update them in the Stream Deck plugin.

## Install (no build step required)

The compiled administration assets are committed under
`src/Resources/public/administration/`, so the plugin is **ready to use right after
installation** — no build step on the target shop.

### Option A — Upload the ZIP in the admin (recommended)

For installing directly from GitHub, without shell access:

1. Download the ready-to-install ZIP from **[`dist/`](dist/)** in this repository
   (`dist/ASBusStreamDeckDashboard-1.1.0.zip`) — the same artifact is also attached
   to the matching **[GitHub release](../../releases)**.
2. In your Shopware admin, open **Extensions → My extensions** and click
   **Upload extension** (top right); choose the downloaded ZIP.
3. Click **Install**, then toggle the extension **active**.

### Option B — CLI (shell access)

```bash
bin/console plugin:refresh
bin/console plugin:install --activate ASBusStreamDeckDashboard
bin/console cache:clear
```

> Requires **Shopware 6.7** and PHP 8.2+. Support for 6.6 was dropped in 0.6.1; the
> committed admin bundle is built against and verified on the 6.7 admin toolchain.

## Configure

After install + activate, open **Extensions → My extensions → KPI Dashboard for Elgato
Stream Deck → Configure**:

- **API access** card — generate / list / revoke API keys (the secret is shown once),
  plus a **Test connection** button that checks the key store, the metrics read path and,
  when you paste a key, a live round-trip over the companion API.
- **Order filter** card — pick which order/payment states feed the metrics.
- **Revenue exclusions** card — line items that are not real revenue (e.g. virtual
  surcharges) can be removed from every revenue metric by product or by label.

A CLI fallback exists but is not required: `bin/console asbs:streamdeck:key:create`.

## Uninstalling

Uninstalling with **"delete all data"** drops the plugin's API key table and removes its
configuration, so a later reinstall starts clean. Use `--keep-user-data` (or leave the
checkbox unticked in the admin) to keep your keys across an uninstall.

## Authentication model

- **Companion endpoints** (`ping`, `dashboard`, `metrics/*`) are authenticated **only**
  by the `X-Asbs-Streamdeck-Key` header (the shop-bound shared secret). They are marked
  `auth_required => false` so Shopware's admin OAuth does not block the Stream Deck
  plugin, which only holds the companion key.
- **Key management** (`/keys` CRUD, `/keys/test`) requires a logged-in **admin user** (not
  just any API token) — an integration token must not be able to mint or revoke keys.

## Endpoints

Authenticated via `X-Asbs-Streamdeck-Key`:

- `GET .../ping` — plugin version (capability detection).
- `GET .../metrics/latest-order`
- `GET .../metrics/top-order-today`
- `GET .../metrics/revenue-today`
- `GET .../metrics/aov-today`
- `GET .../metrics/revenue-by-day` (`?days=7…60`)
- `GET .../metrics/revenue-by-hour`
- `GET .../metrics/revenue-by-month` — current year, January → current month
- `GET .../metrics/shop-status`
- `GET .../dashboard` — aggregate DTO; currently ships placeholder zeros (not consumed by
  the Stream Deck plugin, which uses the individual `metrics/*` endpoints).

All paths are prefixed with `/api/_action/asbs-streamdeck`. Every time-based metric takes an
optional `?tz=<IANA zone>` and is DST-safe.

## Develop

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse
vendor/bin/php-cs-fixer fix --dry-run --diff
```

`composer install` will pull `shopware/core` (large). To pin the targeted Shopware version
for a single run:

```bash
composer require --no-update "shopware/core:^6.7"
composer update --no-interaction
```

### Rebuilding the administration assets

The committed JS in `src/Resources/public/administration/` only needs to be regenerated
when the admin sources under `src/Resources/app/administration/` change. Build them
against a real Shopware admin toolchain — the simplest reproducible way is
[`shopware-cli`](https://github.com/shopware/shopware-cli):

```bash
shopware-cli extension build .
rm -rf src/Storefront   # empty by-product of the build, not part of the plugin
```

Commit the resulting `src/Resources/public/administration/` (`.vite/entrypoints.json`,
`.vite/manifest.json`, `assets/*`). The output filename carries a content hash, so a
changed source produces a new hash; `git add -A` picks up the rename.

Note that green PHPUnit/PHPStan runs do **not** prove the admin UI works — the config card
is rendered by Vue in the browser. Open the plugin configuration in a real 6.7 admin after
touching anything under `src/Resources/app/administration/`.

## License

MIT — see [`LICENSE`](LICENSE).
