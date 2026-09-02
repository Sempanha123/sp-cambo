# Approved Bakong token pool

SP Cambo supports an ordered pool of Bakong credentials that Bakong has
approved for the same production deployment.

## Behaviour

- `BAKONG_TOKEN` is the first slot and remains backward compatible.
- `BAKONG_TOKENS` contains comma-separated fallback slots.
- A request consumes one local daily counter from the first available slot.
- When Bakong returns `errorCode: 17` (daily request limit), that slot is marked
  unavailable until the next configured daily reset and the same lookup advances
  to the next slot.
- Authentication, network, and unexpected upstream errors do not rotate tokens.
- Token values are never written to logs, cache keys, database rows, or API
  responses.

## Production environment

Edit `backend/.env` without committing the real secrets:

```dotenv
CACHE_STORE=redis

BAKONG_TOKEN=approved-token-1
BAKONG_TOKENS="approved-token-2,approved-token-3"
BAKONG_TOKEN_DAILY_LIMIT=100
BAKONG_QUOTA_TIMEZONE=Asia/Phnom_Penh

BAKONG_RECONCILE_INTERVAL_SECONDS=300
BAKONG_CUSTOMER_AUTO_CHECK_INTERVAL_SECONDS=120
```

You may instead leave `BAKONG_TOKEN` empty and put all approved tokens in
`BAKONG_TOKENS`. Duplicate values are removed automatically while preserving
their order.

`CACHE_STORE=redis` is recommended in production so PHP-FPM, the queue worker,
and the scheduler share the same atomic counters.

## Apply after pulling the code

```bash
cd /var/www/sp-cambo/backend
php artisan optimize:clear
php artisan config:cache

php artisan tinker --execute='dump([
    "configured_tokens" => count(app(\App\Services\Payments\BakongTokenPool::class)->configuredTokens()),
    "daily_limit" => config("services.bakong.token_daily_limit"),
    "quota_timezone" => config("services.bakong.quota_timezone"),
    "cache_store" => config("cache.default"),
]);'
```

Expected values for three approved tokens:

```text
configured_tokens: 3
daily_limit: 100
quota_timezone: Asia/Phnom_Penh
cache_store: redis
```

Restart the long-running backend processes after finding the installed PHP-FPM
unit name:

```bash
systemctl list-unit-files 'php*-fpm.service'
sudo systemctl restart php8.3-fpm
sudo systemctl restart sp-cambo-queue
```

Replace `php8.3-fpm` with the unit shown by the first command. This change has no
database migration and does not require a frontend rebuild solely for token
selection. The included frontend error-contract update does require the normal
frontend build/restart when deployed.

## Included account-session compatibility fix

This package also makes `GET /api/v1/me/sessions` and password changes safe in
both Sanctum modes. A cookie-authenticated request carries a transient token
without a database ID; SP Cambo now lists/revokes only persisted bearer tokens
instead of trying to read an ID from that transient object and returning HTTP
500.
