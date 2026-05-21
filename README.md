# ETechFlow Page Speed Optimizer

Magento 2 module that integrates with Google PageSpeed Insights — run real performance diagnostics from the admin, see Lighthouse lab data + real-user CrUX field data side-by-side, and get inline mappings from Google's recommendations to the ETechFlow setting that fixes each one.

Code optimization (CSS/JS/HTML minification, defer fonts, prioritize resource loading) is in development for v1.1+. v1.0 ships the visible-value feature first.

## Install

```bash
composer require etechflow/module-page-speed-optimizer:^1.0
bin/magento module:enable ETechFlow_PageSpeedOptimizer
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
# Restart php-fpm to clear OPcache (mandatory on prod with opcache.validate_timestamps=0)
```

## Set up the Google API key (~3 min, one-time)

1. https://console.cloud.google.com/apis/credentials → **Create Credentials → API Key** (free)
2. Enable **PageSpeed Insights API** on the project (free, 25,000 requests/day)
3. Paste the key into **Stores → Configuration → eTechFlow → Page Speed Optimizer → Google PageSpeed Insights → API Key**

Without a key it still works, but you'll hit Google's per-IP rate limit (~1 request/second).

## Activate the licence

```bash
php tools/generate-license.php --module=page-speed-optimizer --host=<your-domain>
```

Paste the key into **Configuration → eTechFlow → Page Speed Optimizer → License Key** (or use the **Bundle License Key** if you're an ETechFlow suite customer).

## Verify

```bash
bin/magento etechflow:pso:verify
```

Ten PASS lines means you're good to go.

## Run your first diagnostic

```bash
bin/magento etechflow:pso:diagnose --url=https://your-store.com/ --strategy=mobile
```

Or via the admin: **Stores → Settings → Page Speed Diagnose** → click **Run diagnostic**.

## Configuration

`Stores → Configuration → eTechFlow → Page Speed Optimizer`:

- **License Key** — per-module key (or use Bundle License Key for the suite)
- **Module Enabled** — toggle the whole module
- **Google PageSpeed Insights API Key** — your Google Cloud API key
- **Default Strategy** — mobile (Google's mobile-first indexing default) or desktop
- **API Timeout** — default 90s (PSI typically takes 15-45s per page)

## Compatibility

- Magento Open Source 2.4.4 – 2.4.8
- Adobe Commerce 2.4.4 – 2.4.8
- PHP 8.1 / 8.2 / 8.3 / 8.4
- Hyvä Theme + Hyvä Checkout — admin-only module, theme-agnostic

## Support

info@etechflow.com — include your license key + Magento version when reporting issues.
