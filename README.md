# About "Can They Make Snow?"
cantheymakesnow.com tells you whether snowmakers can run at a place right now, and when they next can. Search for a city, a ski resort, or use your location (US only). The site pulls NOAA's hourly grid forecast, calculates the wet-bulb temperature for each hour, and shows:

- a YES/NO answer, the air temperature and humidity behind it, and a gauge showing where the wet-bulb sits against the snowmaking tiers
- a 48-hour wet-bulb chart and the next snowmaking window ("tonight 9 PM – 8 AM")
- right-now weather (sky, feels-like, wind, precipitation chance) and the nearest station's latest observation
- a snow forecast: 24/48/72-hour new-snow totals, a 72-hour snowfall chart, and a powder line when 6″+ is coming
- a 7-day outlook with NOAA's snow accumulation ranges
- an interactive map of ski resorts and recently checked places, coloured by their last known conditions

Results load without a page reload and every result has a shareable URL (`?resort=…`, `?address=…` or `?lat=…&lon=…`) with its own share card. Visitors can switch °F/°C and keep favourite and recent places in their browser.

Ski resorts are checked at the **base and the summit**. The summit uses NOAA's forecast for the summit's own grid cell (summit coordinates are stored per resort: the top of the highest lift, found from OpenStreetMap lift data and editable in the backend). When a resort has no summit point, or it falls in the base's grid cell, the summit is estimated from the base forecast with standard lapse rates (air −6.5 °C/km, dewpoint −1.8 °C/km) and marked "est." The answer is YES when either can make snow, map pins use the colder of the two, and snowmaking alerts consider both.

For ski resorts, visitors can sign up for **email alerts**: snowmaking windows (any, or great+) and/or powder (3″, 6″ or 12″ forecast within 48 hours). Signup is double opt-in per resort; every email has manage and one-click unsubscribe links (`/alerts`).

## Where things live
- `plugins/albrightlabs/cantheymakesnow`: weather logic and data
  - `classes/WeatherService.php`: wet-bulb math, tiers, hourly series, snowmaking windows (unit-tested)
  - `classes/NoaaClient.php`, `classes/SnowReport.php`: cached NOAA requests, geocoding, and the full report for a lookup
  - `classes/ForecastChart.php`, `classes/SnowChart.php`, `classes/ShareImage.php`: chart geometry and the share card image
  - `classes/AlertRules.php` (when to email, unit-tested) and `console/SendAlerts.php` (`ctms:send-alerts`, scheduled hourly)
  - `components/WeatherData.php` (lookups, `onLookup` AJAX handler), `components/Locations.php` (map and search data), `components/Alerts.php` (signup, manage, unsubscribe)
  - `views/mail/`: confirm, manage and alert email templates
  - Backend: **Locations** menu, with lookup locations, the curated **Resorts** list (seeded from `updates/seeds/resorts.json`; base-area coordinates are approximate, so correct them here) and **Alert subscribers**
- `themes/site`: `pages/home.htm`, `pages/alerts.htm`, `pages/privacy.htm`, partials `result`, `gauge`, `forecast`, `conditions`, `snowfall`, `outlook`, `alerts-form`, `alerts-manage`, `map`, and `assets/js/site.js` (no build step)

Run the unit tests with `php vendor/bin/phpunit -c plugins/albrightlabs/cantheymakesnow/phpunit.xml`.

# Installation Guide

## Requirements
October CMS 4 (Laravel 12) on PHP 8.2 or newer, MySQL, and Composer with the October CMS gateway credentials in `auth.json`.

## Installation Steps

### 1. Clone the Repository
Clone the repository from the provided source.

```bash
git clone https://github.com/joebuonocore/cantheymakesnow.com
```

### 2. Add `auth.json`
Ensure you have an `auth.json` file in the project root directory containing the necessary authentication credentials for private packages.

### 3. Install Dependencies
Run the following command in the project root directory to install all dependencies:

```bash
composer install
```

### 4. Create and Configure October CMS Database
Create a new database for the October CMS project and configure your `.env` file with the appropriate database connection settings.

### 5. Finalize Setup
Run the following commands to finalize the installation:

```bash
php artisan key:generate
php artisan theme:use site
```

### 6. Email configuration
Add the Brevo credentials for email sending to the .env file.

```ini
MAIL_MAILER=smtp
MAIL_HOST=smtp-relay.brevo.com
MAIL_PORT=587
MAIL_USERNAME=support@albrightlabs.com
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=noreply@cantheymakesnow.com
MAIL_FROM_NAME="${APP_NAME}"
```

### 7. Add Google API Keys
Use two separate Google Maps API keys so the public browser key can be restricted by HTTP referrer while the server key stays unrestricted from the browser:

```ini
# Used server-side for the Geocoding API. Restrict by IP in Google Cloud.
GOOGLE_MAPS_SERVER_KEY=your_server_key_here

# Used in the browser for the Maps JS API. Restrict by HTTP referrer in Google Cloud.
GOOGLE_MAPS_BROWSER_KEY=your_browser_key_here
```

For backwards compatibility, if these are not set, the code falls back to `GOOGLE_MAPS_API_KEY`. You should migrate off the shared key — leaving it in the browser exposes your server quota.

The browser key needs the **Maps JavaScript API** and **Places API (New)** (for search suggestions). The server key needs the **Geocoding API**.

The map uses Advanced Markers, which need a Map ID. Create one in Google Cloud (Maps Management, JavaScript, vector) and set it; `DEMO_MAP_ID` is used if it is missing, which is fine locally but not for production:

```ini
GOOGLE_MAPS_MAP_ID=your_map_id_here
```

### Share cards
Share images (`/share.png` for the home page, `/share.png?resort=…` etc. for results) are drawn with PHP GD and need FreeType support (`php -r 'print_r(gd_info());'` should show `FreeType Support => 1`). The font is Inter Bold, bundled at `plugins/albrightlabs/cantheymakesnow/assets/fonts/share.ttf` under the SIL Open Font License; set `OG_FONT_PATH` to use another `.ttf`. Without GD/FreeType, pages simply omit `og:image`.

### Rate limits and proxies
Address lookups are limited to 20 geocodes per visitor per minute and map/coordinate lookups to 60 (`classes/Throttle.php`). Behind Cloudflare or another proxy, set the header that carries the visitor's IP, or every visitor shares one limit:

```ini
CLIENT_IP_HEADER=CF-Connecting-IP
```

### Links, robots and sitemap
`robots.txt` and `favicon.ico` are static files in the web root (nginx answers those names itself); `sitemap.xml` (home plus every enabled resort) is served by the plugin. Absolute links (share URLs, `og:image`, the sitemap) are built from the request, so in production set `APP_URL=https://cantheymakesnow.com` and `LINK_POLICY=force`.

### Alerts
Alerts are checked by `php artisan ctms:send-alerts`, registered with the scheduler to run hourly, so the server needs Laravel's scheduler (`* * * * * php artisan schedule:run`). Each run:

- builds one report per resort that has confirmed subscriptions (cached NOAA data, no Google calls);
- sends each subscriber at most one email covering every new alert, never during 10 PM–6 AM resort time, at most once per resort and alert type per 20 hours, and never twice for the same window or storm (a storm gets one follow-up if its forecast grows by half and at least 4″);
- records the result in the cache key `ctms:alerts:last_run` and the log ("Alerts: N resorts, …").

Check it with `php artisan ctms:send-alerts --dry-run`. `--resort=slug`, `--email=…` and `--force` (ignore quiet hours and spacing) help when testing. Email links are built from `APP_URL`, so it must be `https://cantheymakesnow.com` in production. Locally, run with `MAIL_MAILER=log` to write emails to the log instead of sending them.

### 8. Run migrations
```bash
php artisan october:migrate
```
