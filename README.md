# Shopware Stream Deck Companion

Free, open-source Shopware 6 plugin (composer name `asbs/shopware-streamdeck`) that
exposes aggregated dashboard/metric endpoints for the paid **Shopware Dashboard**
Stream Deck plugin (`de.asbs.shopware`, available on the Elgato Marketplace).

The companion works on its own — it just makes your shop's live metrics reachable by
the Stream Deck plugin. It is and stays free and MIT-licensed; the only paid part is the
Stream Deck plugin itself.

## Install (no build step required)

The compiled administration assets are committed under
`src/Resources/public/administration/`, so the plugin is **ready to use right after
installation** — no build step on the target shop.

### Option A — Upload the ZIP in the admin (recommended)

For installing directly from GitHub, without shell access:

1. Download the ready-to-install ZIP from **[`dist/`](dist/)** in this repository
   (e.g. `dist/AsbsShopwareStreamDeck-0.6.0.zip`) — the same artifact is also attached
   to the matching **[GitHub release](../../releases)**.
2. In your Shopware admin, open **Extensions → My extensions** and click
   **Upload extension** (top right); choose the downloaded ZIP.
3. Click **Install**, then toggle the extension **active**.

### Option B — CLI (shell access)

```bash
bin/console plugin:refresh
bin/console plugin:install --activate AsbsShopwareStreamDeck
bin/console cache:clear
```

> The committed assets were built against the Shopware **6.7** admin toolchain and the
> admin UI was verified on 6.7. The PHP backend is CI-tested on 6.6 + 6.7, but the
> prebuilt admin bundle has **not** been booted on a 6.6 admin yet — verify there (or
> rebuild the assets against 6.6) before relying on the config UI on Shopware 6.6.

## Configure

After install + activate, open **Settings → Extensions → Shopware Stream Deck
Companion → Configuration**:

- **API access** card — generate / list / revoke API keys (the secret is shown once).
- **Order filter** card — pick which order/payment states feed the metrics.

A CLI fallback exists but is not required: `bin/console asbs:streamdeck:key:create`.

## Authentication model

- **Companion endpoints** (`ping`, `dashboard`, `metrics/*`) are authenticated **only**
  by the `X-Asbs-Streamdeck-Key` header (the shop-bound shared secret). They are marked
  `auth_required => false` so Shopware's admin OAuth does not block the Stream Deck
  plugin, which only holds the companion key.
- **Key management** (`/keys` CRUD) requires a logged-in **admin user** (not just any API
  token) — an integration token must not be able to mint or revoke keys.

## Endpoints

Authenticated via `X-Asbs-Streamdeck-Key`:

- `GET .../ping` — plugin version + shop id (capability detection).
- `GET .../metrics/latest-order`
- `GET .../metrics/top-order-today`
- `GET .../metrics/revenue-today`
- `GET .../metrics/aov-today`
- `GET .../metrics/revenue-by-day` (`?days=7…60`)
- `GET .../metrics/revenue-by-hour`
- `GET .../metrics/shop-status`
- `GET .../dashboard` — aggregate DTO; currently ships placeholder zeros (not consumed by
  the Stream Deck plugin, which uses the individual `metrics/*` endpoints).

All paths are prefixed with `/api/_action/asbs-streamdeck`.

## Develop

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse
vendor/bin/php-cs-fixer fix --dry-run --diff
```

`composer install` will pull `shopware/core` (large). For a CI-equivalent matrix run
locally:

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
```

Commit the resulting `src/Resources/public/administration/` (`.vite/entrypoints.json`,
`.vite/manifest.json`, `assets/*`). The output filename carries a content hash, so a
changed source produces a new hash; `git add -A` picks up the rename.

## License

MIT — see [`LICENSE`](LICENSE).
