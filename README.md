# Avito item statistics CLI

The project uses [`avito/oauth2-avito`](https://packagist.org/packages/avito/oauth2-avito) to obtain a client-credentials OAuth token and authenticate Avito API requests.

## Setup

Create `.env` in the project root:

```dotenv
AVITO_CLIENT_ID=your_client_id
AVITO_CLIENT_SECRET=your_client_secret
AVITO_USER_ID=your_avito_account_id
```

Install dependencies with `php composer.phar install`.

## Get advertisement statistics

Run the console interface and enter the advertisement number (Avito item ID) when prompted:

```bash
php bin/item-stats.php
```

The command prints the advertisement details and totals for views, contacts, and favorites for the last 30 complete days. API credentials are never printed.
