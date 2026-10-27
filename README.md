# Cable a Tierra Datos

WordPress plugin that shows NoFires fire-risk data for Nuble, Biobio and La Araucania on the cableatierrachile.com home page. It fetches and caches the data, summarizes it per region, and renders the whole "El territorio, medido." band through one shortcode. It also exposes a small PHP API for the theme.

**Status:** version 0.7.0. the home band, region detail and methodology shortcodes are implemented but not yet verified in production, so the plugin stays on 0.x.

## Quick path

1. Copy the folder to `wp-content/plugins/cable-a-tierra-datos` (no Composer needed in production).
2. Activate the plugin. This schedules the recurring refresh.
3. Put `[cat_datos_home_band]` where the band should appear (see [Theme integration](#theme-integration-for-the-cable-a-tierra-theme-session)).

## Requirements

| Item | Value |
|------|-------|
| PHP | 8.2 or newer |
| WordPress | 6.4 or newer |
| Outbound HTTPS | to the NoFires API (`nofires-backend-production.up.railway.app`) |
| JavaScript | none required. Everything is server-rendered; `assets/js/tabs.js` only upgrades the detail panels into tabs |

## What it provides

| Piece | Details |
|-------|---------|
| Shortcode | `[cat_datos_home_band]` renders the full `<section id="datos">`. It takes no attributes (any given are ignored), never throws, and falls back to a "Datos no disponibles por el momento" band if anything fails. |
| Shortcode | `[cat_datos_detalle]` renders the region detail tables (one panel per region: Ñuble, Biobío, La Araucanía), with tabs when JavaScript is available. Same failure rules as the band. |
| Shortcode | `[cat_datos_metodologia]` renders the static methodology text. It reads no data and enqueues only the stylesheet. |
| Stylesheet | `assets/css/band.css`, versioned by file modification time. Enqueued on the front page and by the shortcode. `assets/css/detail.css` serves the detail and methodology shortcodes. |
| PHP API v1 | `cat_datos_api_version()`, `cat_datos_get_region_summary( $region )`, `cat_datos_get_comunas( $region )`. Region ids: `nuble`, `biobio`, `araucania`. |
| Cron | `cat_datos_refresh`, every 15 minutes (custom schedule `cat_datos_15min`). |

### Public PHP API (v1)

Contract, return shapes and guarantees are documented next to the functions in [`includes/functions.php`](includes/functions.php). In short: arrays only (never `WP_Error`), never throws, strings are raw so escape them on output, missing data is `null` (never a made-up `0`). Check `function_exists( 'cat_datos_api_version' ) && cat_datos_api_version() >= 1` before calling.

### Filters

| Filter | Default | Effect |
|--------|---------|--------|
| `cat_datos_methodology_url` | `''` | URL of the methodology page. The "Como se calcula" link renders only when this is non-empty. |
| `cat_datos_explore_url` | URL of the `/datos/` page when it passes the gate, else `''` | Target of the "Explorar los datos" button and base of the region links on the band cards (`{url}#nuble`, `#biobio`, `#araucania`). Both render only when the final value is non-empty. |

Only `http(s)` and relative URLs are accepted; anything else is dropped.

**Explore gate.** By default the band links to the detail page only when the page with path `datos` exists, is published, and its content contains `[cat_datos_detalle]` (attributes allowed; `[cat_datos_metodologia]` alone does not count). The lookup runs once per request and only when a band renders. The filter receives that detected URL and has the last word: a non-empty value wins even when the page fails the gate, and `''` hides the button and the card links. A URL that is not `http(s)` or site-relative also hides them.

### Actions

| Action | Arguments | Effect |
|--------|-----------|--------|
| `cat_datos_detail_region_after_summary` | `string $region` (`nuble`, `biobio`, `araucania`), `RegionDetail $detail` | Fires inside each `[cat_datos_detalle]` panel, between the summary and the table, including unavailable panels. Echo markup (escape it yourself). With no listener nothing extra is rendered. |

## Caching and request budget

| Item | Value |
|------|-------|
| Risk data | transient `cat_datos_v1_risk_{region}`, TTL 15 min |
| Burned area | transient `cat_datos_v1_burned_{region}`, TTL 24 h |
| Last-good copy | option `cat_datos_v1_lastgood_{dataset}_{region}` (autoload off), served when a refresh fails, whatever its age |
| Locks / backoff | `cat_datos_v1_lock_*` (30 s), `cat_datos_v1_backoff_*` (5 min), `cat_datos_v1_backoff_api` (5 min, any transport or HTTP failure pauses all requests) |
| Steady state | about 288 risk + 3 burned requests per day, all from WP-Cron |
| Page requests | serve cached or last-good data; a blocking 3 s fetch happens only with no last-good at all (or one older than its TTL + 6 h), at most once per PHP request |

Cron cadence is 15 minutes because that equals the risk TTL. WP-Cron reschedules on a fixed grid while the transient is written a little later, so a run can find the previous transient still alive; the cron path therefore refreshes a pair once its last-good is at least TTL minus 2 minutes old (24 h minus 2 minutes for burned area, so burned is not refetched on every run). Page requests are unaffected. WP-Cron fires on traffic, so a system cron hitting `wp-cron.php` makes it punctual on a quiet site.

The recurring event is also re-created on `init` if it is missing (failed activation, wiped cron table). **Single-site only:** the schedule and stored data are per site; multisite network activation is not supported.

## Data sources and licenses

| Source | Use | Terms |
|--------|-----|-------|
| Plugin code | this repository | GPL-2.0-or-later (see `LICENSE`) |
| NoFires (`nofires-latam.vercel.app`) | risk levels and heat detections | Credit recommended. The band footer links to it. Heat detections come from NASA FIRMS and are not confirmed fires. |
| MapBiomas Chile | burned area (hectares) | CC BY 4.0. The full citation is mandatory and always visible in the footer whenever a burned figure is shown. The API's own citation is used; the official wording is the fallback. |

## Logging

With `WP_DEBUG` on, failures go to the PHP error log, prefixed `[cable-a-tierra-datos]`: transport errors, non-200 responses, payloads that fail validation, and unexpected exceptions. Only the dataset, region and status or error text are logged. Response bodies and secrets never are.

## Deactivation and uninstall

| Action | Result |
|--------|--------|
| Deactivate | Unschedules `cat_datos_refresh`. Stored data is kept, so re-activating resumes with last-good payloads. |
| Delete (uninstall) | Removes every known transient and option, clears the cron hook, and sweeps the options table for names starting with `cat_datos_v1_` (underscore-escaped prefix, nothing else is touched). |

Re-activating never creates duplicate events: the hook is cleared before it is scheduled.

## Development

```bash
composer install     # PHPUnit 11 and Brain Monkey (dev only)
bin/test             # runs PHPUnit on the PHP the site runs
bin/test-js          # runs tests/js with Node's built-in runner (Node 22+, no npm, no packages)
```

`assets/js/tabs.js` is a plain deferred script (no build step) that upgrades the `[cat_datos_detalle]` panels into accessible region tabs; without JS the panels stay stacked. It is loaded only where that shortcode renders. Only the first `[data-cat-tabs]` on a page is enhanced. Browser shortcuts (Alt/Ctrl/Meta/Shift combos, middle and ctrl-clicks) are left alone; a hash that is not a region slug (such as `#metodologia`) keeps the current tab; a region hash on load scrolls its panel into view once, and focus is never moved automatically. Budget: 3.5 KB raw. The script exports its helpers and `enhance(root, win)` to Node only when there is no `document`, so the browser never touches `module`; `tests/js/tabs.dom.test.mjs` runs it against a hand-written fake DOM.

`bin/test` reads machine-specific paths from `bin/test.env` (gitignored):

```bash
PHP_BIN="/c/path/to/php-8.2/php.exe"
PHP_INI="C:\path\to\php\conf"   # optional, exported as PHPRC
```

`composer test` runs on Composer's own PHP and is only a convenience. Layout: `src/` (pure core plus thin `Wp/` adapters behind four ports), `templates/` (markup), `assets/` (CSS), `includes/` (public API, autoloader), `tests/`.

## Theme integration (for the cable-a-tierra theme session)

The plugin never edits the theme. The integration below is implemented in the theme (theme PR #1, merged 2026-10-27); this section documents it.

1. `patterns/home-data.php` calls the shortcode from PHP, inside a `<!-- wp:html -->` block, and falls back to a short notice when the plugin is inactive:

   ```php
   <?php if ( shortcode_exists( 'cat_datos_home_band' ) ) : ?>
   <!-- wp:html -->
   <?php echo do_shortcode( '[cat_datos_home_band]' ); ?>
   <!-- /wp:html -->
   <?php else : ?>
   <!-- short fallback notice -->
   <?php endif; ?>
   ```

   Do not use a core Shortcode block inside a pattern. Block templates run `do_shortcode()` before `do_blocks()`, so a Shortcode block that lives in a pattern prints the raw `[cat_datos_home_band]` tag. Also never paste the rendered band HTML into post or template content: the output is baked in and never refreshes. Always call the shortcode.
2. Do not wrap it in `.cat-data__inner`. The plugin outputs the whole `<section class="cat-data cat-band" id="datos">`, and that theme grid would break the layout.
3. Keep `.cat-data`, `.cat-kicker`, `.cat-data__title`, the buttons and the tokens in the theme: the band reuses them.
4. Keep the `/#datos` anchor working (the plugin sets `id="datos"` on the first band of a page).
5. Brand case: the kicker reads "NoFires" in the plugin; fix any lowercase "nofires" left in the theme.

### Handoff status

- [x] Fake map removed from the pattern.
- [x] Hardcoded "Explorar los datos" button removed from the pattern; the plugin's gated button replaces it.
- [x] Dead `.cat-data__inner`, `.cat-map*` and `.cat-stat*` CSS removed from the theme.
- [x] `/datos/` exists in production with `[cat_datos_detalle]` and `[cat_datos_metodologia]`, so the explore gate is open there.
- [ ] `/metodologia/` does not exist yet. The link is omitted until a page exists; then set `cat_datos_methodology_url`.
- [ ] Check the stylesheet lands in `<head>` on the home page (block themes render the template before `wp_head`).
- [ ] Check the band at 1355x635, with JavaScript off, stacked at 960px and below, and at 320px.
