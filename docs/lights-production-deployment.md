# Court Lights production deployment

This is the hand-off for uploading the application to a remote server. Keep all launch switches
off until the corresponding acceptance step has passed. Never upload a local `.env`, test database,
`.local-acceptance` directory, or Shelly approval file.

## 1. Build the upload package locally

From the project directory:

```powershell
npm ci
npm run production
composer install --no-dev --optimize-autoloader
```

Upload the application code, generated `public` assets, and `vendor` directory if Composer cannot
run on the server. Do not upload `.env`, `.git`, `.local-acceptance`, test screenshots, logs, or
private test credentials. Point the public web root at the application's `public` directory.

## 2. Prepare the server safely

Back up the existing application files and shop database. Create a separate `proshop_lights`
database and a least-privilege database user for it. Start from `production.env.example`, preserving
all working shop settings, and generate a unique production `APP_KEY` if this is a new installation.

Use these safe commissioning values initially:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-real-domain.example
LIGHTS_ENABLED=false
LIGHTS_MODE=live
LIGHTS_PAYFAST_ENABLED=false
LIGHTS_PAYFAST_SANDBOX=true
LIGHTS_SHELLY_LIVE_ENABLED=false
LIGHTS_CUSTOMER_CONTROL_ENABLED=false
```

Supply the separate Lights database credentials, mail settings, support address, PayFast
credentials, and Shelly authorization key directly in the server environment. Do not send or store
those secrets in Git.

## 3. Install and migrate

Run from the deployed project directory:

```sh
php artisan down
php artisan migrate --force
php artisan migrate --database=lights --path=database/migrations/lights --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan migrate:status --database=lights --path=database/migrations/lights
php artisan route:list --path=lights
php artisan up
```

If any command fails, leave `LIGHTS_ENABLED=false`, restore service for the existing shop, and
investigate before retrying. Never roll back or delete wallet, ledger, top-up, or session rows to
clear a launch problem.

## 4. Commission without simulator fallback

Set `LIGHTS_ENABLED=true`, rebuild the configuration cache, and create the first administrator:

```sh
php artisan config:cache
php artisan lights:create-admin admin@example.com --name="Lights Administrator"
```

The command asks for the password without printing it. Sign in at `/lights/login`, then configure
exactly two active courts in the Lights admin screen, mapped to Shelly channels 0 and 1, with the
approved hourly rate. While either PayFast or customer control remains disabled, production rejects
that operation; it never falls back to simulated top-ups or simulated light sessions.

## 5. Run the required worker

Configure one monitored scheduler mechanism. A normal cron entry is:

```cron
* * * * * cd /absolute/path/to/proshop && php artisan schedule:run >> /dev/null 2>&1
```

Laravel keeps sub-minute tasks running within that minute. Alternatively supervise
`php artisan schedule:work` as a continuously restarted process. Only one scheduler strategy should
dispatch the task. Alert when `php artisan lights:health --json` returns a non-zero exit code.

## 6. Accept PayFast before live money

Keep `LIGHTS_PAYFAST_SANDBOX=true`. In the PayFast account, configure the notification URL as:

```text
https://your-real-domain.example/lights/payfast/notify
```

Verify a successful sandbox payment, cancelled payment, failed payment, repeated ITN, mismatched
amount, and delayed ITN. Only a valid PayFast ITN may credit the wallet; browser return URLs never
credit it. Reconcile the wallet ledger with the PayFast transaction reference.

After acceptance, set `LIGHTS_PAYFAST_SANDBOX=false` and `LIGHTS_PAYFAST_ENABLED=true`, then rebuild
the configuration cache.

## 7. Accept Shelly before customer switching

Keep both Shelly switches false while a qualified installer and an on-site operator verify:

- channel 0 controls only the first configured court;
- channel 1 controls only the second configured court;
- explicit OFF works for both channels;
- the device-side automatic cutoff works without the browser;
- reboot/power recovery leaves the relays safely off;
- manual override, network loss, emergency stop, and the midnight cutoff behave as agreed;
- the approved tariff and per-second rounding match the club's policy.

Enable `LIGHTS_SHELLY_LIVE_ENABLED=true` for supervised admin commissioning. Enable
`LIGHTS_CUSTOMER_CONTROL_ENABLED=true` only after both courts pass. Rebuild the configuration cache
after every environment change.

## 8. Final release check

Before inviting members, run:

```sh
php artisan lights:tick
php artisan lights:health --json
php artisan schedule:list
```

`lights:health` succeeds only when HTTPS, the separate database, mail sender, live PayFast
credentials, Shelly key, worker heartbeat, both online channels, all launch switches, and unresolved
payment/control checks are ready. Then complete one low-value real top-up and one short supervised
session per court. Confirm account creation, email verification, wallet credit, start, stop,
duration, final cost, remaining balance, and administrator audit history.

If physical OFF is uncertain, keep the affected court unavailable and confirm it on site before
allowing another session.

## Local hand-off verification

The upload package was rebuilt with `npm run production` on 2026-09-11. The full PHP suite passed
243 tests with 2,045 assertions. The focused Lights browser suite passed all 8 tests, covering the
shared entrance, registration, wallet top-up, replay rejection, court start, worker billing while
the page is closed, stop/settlement, duration-and-cost history, access boundaries, and the offline
shell. These results prove the local package; PayFast and physical Shelly acceptance must still be
performed on the remote environment as described above.
