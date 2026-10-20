# About "Can They Make Snow?"
cantheymakesnow.com tells you whether snowmakers can run at a place right now, and when they next can. Search for a city, a ski resort, or use your location (US only). The site pulls NOAA's hourly grid forecast, calculates the wet-bulb temperature for each hour, and shows:

- a YES/NO answer, the air temperature and humidity behind it, and a gauge showing where the wet-bulb sits against the snowmaking tiers
- a 48-hour wet-bulb chart and the next snowmaking window ("tonight 9 PM – 8 AM")
- the nearest weather station's latest observation as a reality check
- an interactive map of ski resorts and recently checked places, coloured by their last known conditions

Results load without a page reload and every result has a shareable URL (`?resort=…`, `?address=…` or `?lat=…&lon=…`) with its own share card. Visitors can switch °F/°C and keep favourite and recent places in their browser.

## Where things live
- `plugins/albrightlabs/cantheymakesnow`: weather logic and data
  - `classes/WeatherService.php`: wet-bulb math, tiers, hourly series, snowmaking windows (unit-tested)
  - `classes/NoaaClient.php`, `classes/SnowReport.php`: cached NOAA requests, geocoding, and the full report for a lookup
  - `classes/ForecastChart.php`, `classes/ShareImage.php`: chart geometry and the share card image
  - `components/WeatherData.php` (lookups, `onLookup` AJAX handler) and `components/Locations.php` (map and search data)
  - Backend: **Locations** menu, with lookup locations and the curated **Resorts** list (seeded from `updates/seeds/resorts.json`; base-area coordinates are approximate, so correct them here)
- `themes/site`: `pages/home.htm`, partials `result`, `gauge`, `forecast`, `map`, and `assets/js/site.js` (no build step)

Run the unit tests with `php vendor/bin/phpunit -c plugins/albrightlabs/cantheymakesnow/phpunit.xml`.

# Installation Guide

## Requirements
Ensure the server hosting this application meets all requirements to run an October CMS V3 project.

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
MAIL_ENCRYPTION=null
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

### Share card font (optional)
The per-result share image (`/share.png`) is drawn with GD and needs a TrueType font. Put one at `plugins/albrightlabs/cantheymakesnow/assets/fonts/share.ttf`, or point `OG_FONT_PATH` at a `.ttf` file. Without a font, pages omit `og:image` and fall back to a text-only preview.

### 8. Run migrations
```bash
php artisan october:migrate
```
