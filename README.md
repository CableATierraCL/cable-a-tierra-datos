# Cable a Tierra Datos

WordPress plugin that shows NoFires fire-risk data for Nuble, Biobio and La Araucania on the cableatierrachile.com home page. It fetches and caches the data, summarizes it per region, and renders the whole "El territorio, medido." band through one shortcode. It also exposes a small PHP API for the theme.

**Status:** version 0.6.0. Stage 1 (the band) is code-complete and stays on 0.x until it has been verified on the production site.

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
| JavaScript | none, the band is server-rendered |

## What it provides

| Piece | Details |
|-------|---------|
| Shortcode | `[cat_datos_home_band]` renders the full `<section id="datos">`. It takes no attributes (any given are ignored), never throws, and falls back to a "Datos no disponibles por el momento" band if anything fails. |
| Stylesheet | `assets/css/band.css`, versioned by file modification time. Enqueued on the front page and by the shortcode. |
| PHP API v1 | `cat_datos_api_version()`, `cat_datos_get_region_summary( $region )`, `cat_datos_get_comunas( $region )`. Region ids: `nuble`, `biobio`, `araucania`. |
| Cron | `cat_datos_refresh`, every 15 minutes (custom schedule `cat_datos_15min`). |

### Public PHP API (v1)

Contract, return shapes and guarantees are documented next to the functions in [`includes/functions.php`](includes/functions.php). In short: arrays only (never `WP_Error`), never throws, strings are raw so escape them on output, missing data is `null` (never a made-up `0`). Check `function_exists( 'cat_datos_api_version' ) && cat_datos_api_version() >= 1` before calling.

### Filters

| Filter | Default | Effect |
|--------|---------|--------|
| `cat_datos_methodology_url` | `''` | URL of the methodology page. The "Como se calcula" link renders only when this is non-empty. |
| `cat_datos_explore_url` | `home_url( '/datos/' )` | Target of the "Explorar los datos" button. The button renders only when this is non-empty. |

Only `http(s)` and relative URLs are accepted; anything else is dropped.

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
```

`bin/test` reads machine-specific paths from `bin/test.env` (gitignored):

```bash
PHP_BIN="/c/path/to/php-8.2/php.exe"
PHP_INI="C:\path\to\php\conf"   # optional, exported as PHPRC
```

`composer test` runs on Composer's own PHP and is only a convenience. Layout: `src/` (pure core plus thin `Wp/` adapters behind four ports), `templates/` (markup), `assets/` (CSS), `includes/` (public API, autoloader), `tests/`.

## Theme integration (for the cable-a-tierra theme session)

The plugin never edits the theme. These steps are for the theme session; do them in the theme repository.

1. In `patterns/home-data.php`, replace the band body. Primary option, no PHP needed: a Shortcode block inside the pattern markup.

   ```html
   <!-- wp:shortcode -->[cat_datos_home_band]<!-- /wp:shortcode -->
   ```

   If the plugin is deactivated, WordPress prints the raw text `[cat_datos_home_band]`. For a graceful fallback, prefer the PHP variant in `patterns/home-data.php` (patterns are PHP files), which degrades to the existing markup:

   ```php
   <?php if ( shortcode_exists( 'cat_datos_home_band' ) ) : ?>
   <?php echo do_shortcode( '[cat_datos_home_band]' ); ?>
   <?php else : ?>
   <!-- existing fallback markup -->
   <?php endif; ?>
   ```

   Caveat: if the band is inserted as raw block markup into post or template content (for example by pasting the rendered HTML), the output is baked in and never refreshes. Always insert the shortcode, never its HTML.
2. Do not wrap it in `.cat-data__inner`. The plugin outputs the whole `<section class="cat-data cat-band" id="datos">`, and that theme grid would break the layout.
3. Delete the dead `.cat-map*` and `.cat-stat*` CSS, but first confirm the selector names in the theme (`rg "cat-map|cat-stat"`), because only those exact names are dead. Keep `.cat-data`, `.cat-kicker`, `.cat-data__title`, the buttons and the tokens: the band reuses them.
4. Keep the `/#datos` anchor working (the plugin sets `id="datos"` on the first band of a page).
5. Brand case: the kicker reads "NoFires" in the plugin; fix any lowercase "nofires" left in the theme.

### Pending before launch

- [ ] Remove the fake map from the pattern.
- [ ] Confirm `/datos/` exists in production (local page ID 1734), or set `cat_datos_explore_url`.
- [ ] `/metodologia/` does not exist yet. The link is omitted until a page exists; then set `cat_datos_methodology_url`.
- [ ] Check the stylesheet lands in `<head>` on the home page (block themes render the template before `wp_head`).
- [ ] Check the band at 1355x635, with JavaScript off, stacked at 960px and below, and at 320px.
