# About "Can They Make Snow?"
This application contains a webpage looks at your latitude and longitude and retrieves the current temperature and relative humidity from NOAA and uses them to calculate the wet bulb temperature to determine if snow could be made where you stand.

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

### 7. Add Google API Key
Add the following environment variable to your `.env` file:

```ini
GOOGLE_MAPS_API_KEY=your_api_key_here
```
