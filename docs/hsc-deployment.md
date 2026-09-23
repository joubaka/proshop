# HSC deployment command

`deploy-hsc` is the controlled release command for this repository. It follows
the established Cape Tennis process while adding the Proshop frontend build,
database backup, explicit application/Lights migration boundaries, and an
optional public HTTPS health check.

## One-time server setup

Clone the repository into the private application directory. The default
configuration assumes that directory and `public_html` are siblings. If that is
not true on the HSC host, set the correct absolute `PUBLIC_HTML` in
`deploy.config` before committing the hosting-specific value.

Keep the real `.env` only on the server. At minimum, confirm `APP_ENV`,
`APP_DEBUG=false`, `APP_URL`, database credentials, mail, queue, and session
settings. PayFast and Lights credentials must never be committed.

From the deployed repository, install the shortcut once:

```sh
chmod +x deploy.sh bin/deploy
./deploy.sh --install-command
```

Reconnect to the terminal if the installer added `$HOME/bin` to `PATH`, then
confirm the command:

```sh
deploy-hsc --help
```

Configure the web server so only the Laravel `public` directory is exposed. If
shared hosting requires a separate `public_html`, preserve its hosting-specific
`index.php` and `.htaccess`; the deployment command synchronizes generated
assets but deliberately does not overwrite those entry files or runtime
uploads.

## Normal deployment

Push the reviewed release to GitHub `main`, then run on the server:

```sh
deploy-hsc main
```

The command refuses non-`main` and dirty checkouts. It fetches GitHub, creates a
database-only backup, enters maintenance mode, fast-forwards, installs the
locked production Composer dependencies, performs a clean frontend build, runs
only migration files allowlisted in `deploy.config`, rebuilds caches,
synchronizes static assets, restarts queues, checks the scheduler, and restores
the site. A failure after maintenance mode automatically attempts to bring the
existing site back online. Completion is reported with the deployed commit.
The synchronized folders include the dedicated `public/lights-assets` CSS, JavaScript,
manifest, icons, and service-worker assets, plus the native shop's
`public/shop-assets` styles. Public folders that already resolve
to the same symlink target are detected and skipped safely.
The deploy also removes the exact legacy static `public_html/lights` bundle after
validating every contained filename. That old directory collides with Laravel's
authenticated `/lights` route on LiteSpeed.

When the shared host has no Node.js/npm runtime, the command verifies and
extracts the versioned `deployment/frontend-assets.tar.gz` release bundle. This
is a reviewed build of the same commit, not a `--skip-build` bypass. Maintainers
regenerate the bundle locally with `scripts/build-deploy-assets.ps1` whenever
frontend inputs change.

Set `DEPLOY_HEALTH_URL` in the terminal invocation to an HTTPS page such as the
login screen when the final deployment should also fail on a bad public HTTP
response:

```sh
DEPLOY_HEALTH_URL=https://shop.example.com/login deploy-hsc main
```

## First Lights database deployment

First configure the separate, least-privilege Lights database in the server
`.env`. Keep PayFast in sandbox and all Shelly/customer-control switches off.
Then run:

```sh
deploy-hsc main --with-lights-migrations
php artisan lights:health
```

The flag uses the separately allowlisted `database/migrations/lights` files. It
does not enable Lights, PayFast, or hardware controls. Follow
`docs/lights-production-deployment.md` for staged acceptance.

## Emergency options

The skip flags exist for diagnosed recovery only:

```sh
deploy-hsc main --skip-backup
deploy-hsc main --skip-migrations
deploy-hsc main --skip-deps
deploy-hsc main --skip-build
```

Do not use them for a normal release. Never use `--skip-backup` for a deployment
that may change schema or financial data.
