# Changelog — ETechFlow Page Speed Optimizer

All notable changes to this module. Adheres to [Semantic Versioning](https://semver.org/).

---

## [1.0.0] — 2026-05-21 — Google PageSpeed Insights diagnostic + foundation

First commercial release. Ships the **PSI Diagnose** feature — the visible-feature that closes every "is your store fast?" conversation with merchants. Code optimization (CSS/JS/HTML minification, defer fonts, prioritize resource loading) follows in v1.1+.

### Why v1.0 ships Diagnose first

Every Amasty / Mageworx / Mirasvit page-speed module markets the same optimization features. What makes Amasty's $259 product feel premium is the **Diagnostic** tool — it shows merchants a real Google score before/after, with concrete recommendations. We ship that as v1.0 because:

1. It's the **highest-perceived-value** feature in the category
2. It gives merchants something to **measure** their other ETechFlow modules against (IO's WebP conversion, future PSO minification, etc.)
3. The code already exists — it was originally added to IO v1.2.0 then [moved here in IO v1.3.0](https://github.com/etechflow/module-image-optimizer/releases/tag/v1.3.0) because a measurement tool didn't belong in an image-optimization module.

### Added

**Foundation**
- `registration.php`, `composer.json` (proprietary licence, soft-deps on IO + suite modules via Bundle key).
- `etc/module.xml` setup_version `1.0.0`.
- **DB schema**: 1 table `etechflow_pso_diagnostic_log` — full audit of every PSI run with lab + field metrics + raw JSON for future re-parsing.
- **Admin config** (`etc/adminhtml/system.xml`) — License section + Google PageSpeed Insights section + Code Optimization section (placeholder toggles for v1.1+ features).

**Licensing + Infrastructure**
- `Model/LicenseValidator` — per-domain HMAC + bundle key. `MODULE_ID = page-speed-optimizer`. Shares `BUNDLE_SECRET_FRAGMENTS` byte-identical with every other ETechFlow module.
- `Model/Config` — license-aware `isEnabled()`. PSI API key + default strategy + timeout getters.
- `Model/Performance/Profiler` — Tideways span helper, tags `ETechFlow_PSO_*`.

**Google PageSpeed Insights Integration**
- `Model/Psi/PsiClient` — vanilla Curl client. Free tier: 25,000 requests/day per merchant's Google Cloud API key (no key works too, with Google's per-IP rate limit).
- `Model/Data/DiagnosticResult` — typed value object: lab Lighthouse score + Core Web Vital metrics + CrUX real-user field metrics + sorted recommendation list.
- `Model/Recommendation/Mapper` — curated mapping of 16 PSI audit IDs to the ETechFlow feature that fixes them. Drives the "ETechFlow can fix this" badge inline next to each Google recommendation.
- `Model/Psi/DiagnosticLogger` — best-effort persistence to the audit table.

**Admin UI**
- New page at **Stores → Settings → Page Speed Diagnose**:
  - Big colour-coded score card (green ≥ 90, orange 50-89, red < 50 — Google's own bands)
  - Lab metrics row (FCP, LCP, TBT, CLS)
  - Field metrics row (real-user CrUX data when available — LCP, INP, CLS + overall FAST/AVERAGE/SLOW category)
  - Sorted recommendation list (biggest LCP impact first) with HIGH/MEDIUM/LOW badges
  - **ETechFlow fix badge** on every recommendation we cover

**CLI**
- `bin/magento etechflow:pso:diagnose --url=... --strategy=mobile|desktop --json --pass-score=80` — headless diagnostic for CI gates.
- `bin/magento etechflow:pso:verify` — 10-check smoke test.

### Deferred to v1.1+

- **CSS minification** (build-time, stored in `pub/static`)
- **JavaScript minification** + defer
- **HTML minification**
- **Defer Fonts Loading** with exclusion list
- **Prioritize Resource Loading** (`<link rel="preload">` injection)
- **GZIP/Brotli** headers via .htaccess / nginx hints
- **Critical CSS extraction** (above-the-fold CSS inlining)
- **Performance budgets** (admin-set max JS/CSS/image total per page; flag offenders)
- **Score timeline graph** (chart of LCP / INP / CLS over time from the diagnostic_log table)
- **Hyvä Mode** (auto-detect Hyvä, skip optimizations Hyvä already handles)

### Setup (one-time, ~3 min)

1. Go to https://console.cloud.google.com/apis/credentials
2. Click "Create Credentials → API Key" (free)
3. Enable "PageSpeed Insights API" on the project (free, 25,000 requests/day)
4. Paste the key into *Stores → Configuration → eTechFlow → Page Speed Optimizer → Google PageSpeed Insights → PageSpeed Insights API Key*

Without a key it still works (Google's per-IP rate limit) but the key is strongly recommended.

### Compatibility

- Magento Open Source 2.4.4 – 2.4.8
- Adobe Commerce 2.4.4 – 2.4.8
- PHP 8.1 / 8.2 / 8.3 / 8.4
- Hyvä Theme + Hyvä Checkout (PSO is admin-side; theme-agnostic for now — Hyvä-aware optimizations land in v1.1+)
