# Court Lights: local implementation and handover

For the ordered remote-server hand-off, use [lights-production-deployment.md](lights-production-deployment.md).

Implemented 2026-09-03 following approval to resume the lights work. This is a working **local simulation**, not a live PayFast/Shelly deployment.

## Open the portal

- Local WAMP home: <http://localhost/proshop/public/> — redirects to the isolated demo server below. This shortcut applies only to loopback visitors on localhost or 127.0.0.1; start the local Lights server first. WAMP's `/login` remains the original shop login.
- Shared home page: <http://127.0.0.1:8097/> — opens Lights first, with a discreet **POS staff access** link to the existing shop login.
- Direct portal: <http://127.0.0.1:8097/lights/>
- Player: `player@lights.test`
- Administrator: `admin@lights.test`
- Both synthetic accounts initially use `LocalLights!2026`. Never reuse that password for a real account.
- New players can register a separate lights account. Shop credentials do not sign into Lights.
- Demo courts start at **R60/hour**. This is a configurable example, not an approved live tariff. Wallets start at zero: use the simulated top-up flow to add demo credit.

The shop header links to Court Lights in the local acceptance environment. Separate authentication and storage are implemented inside this repository; a separately deployed service/domain has not been provisioned.

The shared home sends signed-in lights members to their wallet and guests to the lights sign-in page. POS keeps its existing `/login`, staff credentials, permissions and `/home` dashboard; its login page links back to Court Lights. This is a shared entrance, not shared authentication or balances. When Lights is disabled or unavailable in the deployment environment, the original shop welcome page remains unchanged.

Shared-entry follow-up verification: 26 focused PHP tests / 194 assertions passed, and all 8 Lights browser tests passed, including the home → POS login → Lights return journey. These are focused follow-up results, not a rerun of the full baseline below.

## Start, stop and test

```powershell
# First lights setup; preserves existing records on repeated runs.
.\scripts\start-local-lights.ps1 -Setup

# Subsequent starts: reuse database, portal and worker.
.\scripts\start-local-lights.ps1

# Existing application + lights logic and access checks (SQLite).
.\scripts\test-application.ps1

# Separate MySQL application checks, including Lights races.
.\scripts\test-local-acceptance.ps1

# Lights browser journeys only.
npm run test:lights

# Shop and lights browser suites, sequentially.
npm test

# Stop only the dedicated local lights accounting worker gracefully.
C:\wamp64\bin\php\php8.2.29\php.exe -d xdebug.mode=off -d disable_functions=curl_exec,curl_multi_exec -d allow_url_fopen=0 scripts/local-acceptance/lights-worker.php --stop

# Read the local worker heartbeat.
C:\wamp64\bin\php\php8.2.29\php.exe -d xdebug.mode=off -d disable_functions=curl_exec,curl_multi_exec -d allow_url_fopen=0 scripts/local-acceptance/lights-worker.php --status
```

Start the original acceptance harness with `-Initialize` first if its MySQL data directory does not yet exist. The lights starter does not initialize or reset the shop database. A process lock prevents duplicate lights workers. Worker logs are isolated under `.local-acceptance/lights-worker-*.log`; startup verifies a fresh heartbeat. Stop requests identify the worker PID and are consumed by that worker, not arbitrary PHP processes.

Do not run browser tests concurrently with the MySQL suite or manually edit their fixtures during a run. Browser tests create their own synthetic member and retain its history for inspection; cleanup stops that member's session. Race tests create/remove only dedicated fixture members and courts.

## What is implemented

- Mobile-friendly separate login/registration, member wallet, court availability, ON/OFF controls, countdown, recent session and top-up history.
- A PayFast-labelled **simulator**, with explicit successful/cancelled/failed outcomes. It collects no card details, contacts no provider and moves no real money. There is no live payment callback or automatic live-credit path.
- Integer-cent wallet balances and append-only application ledger writes. Successful top-ups are posted once; pending/failed/cancelled top-ups do not credit the wallet. Repeated checkout confirmations are idempotent.
- One active session per member and per court, with a shared database lock and unique active-owner indexes. An ON request UUID prevents an old request from restarting a completed session.
- Server-time billing and a background worker. Per-session rates are frozen. Charge calculation uses cumulative elapsed whole seconds, rounded up to a cent once on the cumulative total; worker ticks post only the difference. A backwards clock adjustment never reverses an already recorded charge.
- ON requests include the displayed rate: if an administrator changes it before the start is accepted, the request is rejected for review rather than silently charging the new rate. Reusing a top-up request with a different amount is also rejected.
- The initial wallet funds a cutoff of `floor(balance_cents × 3600 / hourly_rate_cents)` seconds, capped at four hours. Very small residual credit may remain if it cannot fund another whole second. Top-ups during play do not extend the current deadline; stop/restart to use new credit.
- OFF settles usage and releases the court. A late worker caps billing at the original cutoff, rather than charging through downtime. Repeated OFF calls cannot charge again.
- Simulated relay state/deadline and audit events for ON/OFF; no wireless, cloud, LAN, MQTT or physical device command is issued.
- Admin rates, court availability, unique device-label/channel assignments, active sessions, emergency stop, wallet ledger and recent audit events. Active courts must be stopped before editing their settings.
- CSRF-protected mutations, fresh member eligibility checks, explicit administrator checks, ownership checks and escaped output. Registration cannot assign an admin role or opening balance.
- A phone-app manifest and scoped, network-only service worker. No authenticated page, wallet or payment state is cached. The offline page cannot start/stop a session. Browser/OS installation behavior still needs physical-phone acceptance.

The displayed balance between polls is a labelled estimate; the server ledger is authoritative. Connection failures disable new starts. The OFF control remains available to attempt reconnection, but an unconfirmed network request is not presented as a confirmed physical action.

## Isolation and persistence

- Lights tables use connection `lights` and database **`proshop_lights_acceptance`**, distinct from **`proshop_acceptance`**. Both are on the existing disposable loopback MySQL server at port **13317**, whose exact `@@datadir` is checked before setup.
- Lights migrations are under `database/migrations/lights`, applied explicitly to the lights connection. The shop's 279-migration history is unchanged; the lights database has its own two migrations.
- All models/queries in the lights domain use its explicit connection. No shop contacts, users, payment records, inventory balances or real `.env` credentials are copied into Lights.
- Authentication has a separate `lights` guard and members table, but currently shares the application's HTTP runtime/session infrastructure. A dedicated host/cookie/process deployment is a future hosting task, not an existing independent service.
- `config/lights.php` is disabled by default. The local harness explicitly enables simulation. Middleware and domain code reject production environments and any non-simulation mode. Do not bypass these guards to turn a simulator into a production service.
- The harness uses fake mail/notifications/queues and disables PHP cURL/URL file access. Never insert live credentials into these demo accounts or deploy the local harness.

## Verification

- Default application suite: **184 tests / 1,500 assertions passed**, including 15 focused lights tests.
- Full MySQL acceptance suite: **33 tests / 380 assertions passed** across the shop and separate Lights databases.
- Four real two-process MySQL races passed: two members/one court, one member/two courts, duplicate top-up confirmation, and concurrent stop requests.
- Browser checks cover registration, mobile wallet, simulated checkout/replay, ON/countdown, billing while the page is closed, OFF, member/admin boundaries and the offline shell.
- Combined shop/Lights browser run: **19 tests passed** (including two parent tests). After the final rate/request safeguards, all **7 Lights browser tests** passed again.
- Screenshots: `.local-acceptance/screenshots/lights-mobile.png` and `lights-desktop.png`.
- Graceful worker stop, stale-heartbeat detection and a healthy restart were verified. Logic tests separately simulate overdue cutoff/recovery, transaction rollback, clock rollback and maximum session duration.

No real provider/hardware behavior is claimed from these tests. No application dependency update was needed for this milestone. Existing unrelated working-tree changes were preserved; no commit, push or deployment was performed.

## Remaining before real money or lights

### Production path implemented but intentionally disabled

The remaining payment and customer-control code is now present behind deployment kill switches.
It has **not** been activated or tested against real money or physical relays:

- Live PayFast checkout creates a signed server-side form using environment-only merchant
  credentials. The browser return URL never credits the wallet. Credit is posted only after an
  ITN passes the signature, configured merchant, exact amount, current PayFast source-network and
  server-to-server `VALID` checks. Provider references and ledger references are unique so callback
  retries cannot credit twice. Temporary validation transport failures return a retryable response;
  malformed or mismatched notifications are rejected.
- Live member sessions reuse the durable safety state machine. They reserve one member and Shelly
  channel, require a fresh worker heartbeat, send an explicit ON with a device-side cutoff, and
  begin billing only after fresh matching ON/timer evidence. ON is never retried after ambiguity;
  stop freezes billing before requesting explicit OFF. Uncertain state keeps the channel reserved
  and visible to the member/admin for intervention.
- The home screen now distinguishes pending/uncertain hardware state, lists hardware-session
  history, and labels real PayFast separately from the simulator. The admin screen lists live
  customer sessions with an emergency-stop path.
- Public registration records terms acceptance. Live mode requires email verification before
  payment or court control; verification and password-reset links are hashed, expiring, single-use
  tokens. The login screen includes non-enumerating password recovery, and public terms/privacy
  pages identify the venue-specific legal and retention review still required.
- Admin client management can enable/disable an account and make an idempotent credit/debit
  correction with a required reason. Corrections use the wallet ledger and audit event stream;
  normal customer top-ups remain PayFast-only. Recent real PayFast requests are shown for
  reconciliation.
- `lights:tick` provides the production reconciliation command and is scheduled every second as a
  background command when Lights is enabled in live mode. The deployment must run Laravel's scheduler continuously and
  alert on missing heartbeats. `php artisan lights:health --json` reports the worker, cached Shelly
  state, unresolved commands, old pending PayFast requests, midnight cutoff, and launch gates. Its
  exit code is successful only when runtime health is current, no PayFast request has remained
  pending for over an hour, HTTPS and a separate Lights database are configured, the mail sender
  is valid, PayFast is live with all credentials present, the Shelly key is present, and every
  customer/Shelly launch switch is enabled. The report exposes booleans only and never emits secrets.
- New migrations add PayFast provider metadata and link hardware-control sessions to configured
  courts. `production.env.example` documents every required setting without containing secrets.

Four independent switches default to false: `LIGHTS_ENABLED`, `LIGHTS_PAYFAST_ENABLED`, and both
Shelly control flags. Leave them false until the migrations, HTTPS callback, PayFast sandbox ITNs,
worker monitoring, supervised relay pilot and physical fail-safe checks pass. Enabling code is not
acceptance evidence.

The local admin-control page exposes direct ON and OFF buttons for Court 3/channel 0 and Court
4/channel 1. Admin commands do not require wallet credit, an arming checklist, or release of a
legacy billing-review row. Each button press is first stored in `lights_manual_commands`; the web
request never contacts Shelly. The background worker dispatches it exactly once through a dedicated
CLI helper and records the outcome in the activity log. A newer unsent command for the same channel
supersedes the older one. Merely opening or reloading the page sends no relay command.

The admin ON, OFF, command-activity and live-status controls use AJAX. Button presses remain on
the same page, show queue/worker progress inline and update both relay cards from stored worker
status. Configuration and credential forms intentionally retain full-page POST/redirect handling.

Every direct admin ON still carries a server-selected device-side cutoff of at most 300 seconds,
uses an explicit channel and `on=true` rather than Toggle, and is never retried after an ambiguous
outcome. OFF is an explicit `on=false` command and has no command capable of switching the relay
back on. These are hard relay invariants rather than UI approval gates.

All ON durations are also capped at the next **00:00 Africa/Johannesburg** boundary. The worker
records one venue-wide cutoff per local calendar day and queues explicit OFF commands for both
channels when that date changes. If the worker was down at midnight, it performs the missed cutoff
as soon as it restarts. The migration initializes the current date so deploying it during the day
does not unexpectedly switch a court. A conservative ten-second cloud-dispatch margin is applied
and recalculated by the worker, so an ON request that is too close to midnight is not sent.

### Customer-control and billing engine

The durable billed-session engine is connected to the customer portal behind its deployment gate, separately
from the direct admin controls at `/lights/admin/control`.

- Court 3 maps to Shelly channel 0 and Court 4 to channel 1. A new, separately migrated
  `lights_control_sessions` table holds durable request IDs, immutable rate/budget/duration,
  active user/channel reservations, command states, timer evidence, stop requests, billing,
  uncertainty and operator-review state.
- The default **rehearsal** driver never performs network or physical operations. It exercises
  the same durable state machine, worker cutoff, wallet billing, replay protection and review
  UI. Rehearsals use simulated wallet credit and post `pilot_usage` ledger entries.
- The real Shelly driver is coded but double-locked. `lights.control.live_enabled` defaults to
  false for deployments; the isolated local acceptance bootstrap opts into pilot capability.
  ON still requires a short-lived `.local-acceptance/private/shelly/pilot-approval.json`
  for one exact device/channel, expiring within 15 minutes, recording confirmation of an empty
  court/on-site operator and checked reboot/override settings. Installation, migration,
  read-only checks and tests never create that file. The approval is consumed before ON is
  attempted, so it cannot be reused after an uncertain request. Forging the HTML mode selection cannot
  bypass server-side checks.
- Every real ON uses the exact channel plus `toggle_after`; no Toggle command exists. It first
  requires cloud status to report the channel online, fault-free and OFF. After the command,
  billing starts only when the cloud reports ON plus a new matching device-side timer. The
  command is never sent inside a retried database transaction.
- ON has no automatic retry. Missing/ambiguous timer evidence, a process interruption or a
  transport failure freezes billing and schedules only an idempotent OFF. A definite preflight
  rejection sends neither ON nor OFF and charges nothing. This distinction avoids turning off
  an unrelated pre-existing session merely because a preflight failed.
- A member/admin stop request is durable and freezes billing immediately, including if it
  arrives during the ON handoff. OFF never has a flip-back timer. Physical OFF acknowledgement
  or any uncertain outcome remains reserved for operator review; release requires an on-site
  OFF confirmation after the funded timer and in-flight-command safety window. A blocked court
  cannot be claimed by another pilot.
- Billing uses cumulative elapsed seconds and posts only the difference. It never starts before
  timer evidence, never bills after the earliest of member stop/frozen budget deadline, never
  exceeds the frozen wallet budget, and never reverses earlier usage after a clock rollback.
  Late workers cannot bill beyond the device cutoff. Demo and safety sessions cannot use the
  same wallet at the same time.
- The real CLI helper accepts only `on|off` plus an existing UUID. It reloads and validates the
  active DB session, driver, state, channel, age and approval; the key is read from private
  encrypted storage and is never an argument/environment value. Cloud host, device and API
  paths are fixed. TLS verification, redirect/proxy blocking, response limits and request
  pacing remain enforced.
- The existing worker now ticks both the simulator and safety-session engine. It was restarted
  gracefully and verified healthy after migration. No physical session existed, the enable flag
  remained false by default outside the local harness, the one-shot approval remained absent,
  and no Shelly control command was sent during implementation.

The older supervised-customer acceptance flow can still be armed from the CLI below when testing
the billed customer state machine. It is not required by the direct admin buttons.

```powershell
C:\wamp64\bin\php\php8.2.29\php.exe -d xdebug.mode=off `
  scripts/local-acceptance/arm-shelly-pilot.php court3 `
  --empty-court-confirmed --operator-onsite --reboot-off-checked --overrides-checked
```

Use `court4` for the corresponding customer-flow acceptance test. This legacy approval does not
change or unlock the direct admin control page.

Verification for this milestone: **215 tests / 1,809 assertions** passed in the full default
suite, including state-machine cases for timer-before-billing, stop races, cutoff caps, replay,
definite preflight rejection, ambiguous ON, missing timer evidence, failed OFF, interrupted
commands, channel reservations, wallet isolation, access and real-control gates. The separate
MySQL suite passed **33 tests / 380 assertions**, including the existing multi-process wallet,
court and settlement races. The mobile browser check passed with both real-control selections
disabled and no Shelly contact. `git diff --check` passed.

The Shelly Cloud v2 documentation states that switch commands support `channel`, explicit `on`
and `toggle_after`, and considers HTTP 200 the command success response. This engine still treats
that as cloud evidence, not independent proof that a contactor or floodlight physically changed.
[Official Cloud Control v2 documentation](https://shelly-api-docs.shelly.cloud/cloud-control-api/communication-v2/)

### Shelly remote commissioning follow-up

The local administrator can now open `/lights/admin/shelly` (Admin → Set up Shelly connection).
This is a **real, read-only cloud status connector**, not real relay control. Server
`https://shelly-277-eu.shelly.cloud`, device `2cbcbba011f8` (decimal `49189113303544`),
model Shelly Pro 2 PM `SPSW-202PE12UL`, Court 3 channel 0 and Court 4 channel 1 were supplied
in the owner's screenshots (Court 3 confirmed in the 18:40 follow-up). Both share the same
device ID and LAN address `192.168.10.115`. The original simulation
court records are not remapped or connected to hardware.

- Save the cloud authorization key directly into the local password field, not chat.
  Saving does not make a network request. The key cannot be retrieved through the page.
- The separate **Check connection — status only** POST sends one request to the fixed
  HTTPS cloud host using `/v2/devices/api/get`. It cannot issue relay/configuration commands.
  It validates device/model identity and returns only allowlisted status fields. Cloud
  status can be cached; a successful check is not proof of live control or cutoff safety.
- Access requires the Lights admin guard, CSRF, loopback source address, explicit setup
  flag and acceptance/testing environment. Default deployments do not enable this form.
- Credentials are encrypted under `.local-acceptance/private/shelly/credentials.enc`
  using an independent random `encryption.key`, not the deterministic demo APP_KEY.
  Both are outside public files and ignored by Git. Protect this computer, filesystem
  permissions and backups; storing both files locally does not protect against an attacker
  who can read the private directory. This is not a production secret-management solution.
- Debug request rendering is disabled for these routes; the secret is excluded from
  flashed input and removed from the controller request. No raw cloud response, exception
  URL, key or HTTP diagnostics are logged or displayed.
- The existing demo HTTP/worker network restrictions are unchanged. A dedicated CLI
  `scripts/local-acceptance/shelly-status.php` boots only Composer's autoloader, never the
  shop app or `.env`. That subprocess alone has cURL enabled for the fixed status request:
  TLS verification on, redirects/proxies off, bounded response/timeouts, no retries.
  A shared file lock and five-second cooldown prevent overlapping/repeated probe requests.
- Follow-up: the owner saved the key privately and an authenticated, read-only cloud check
  was verified through the actual local web page. Shelly reported the expected device online
  and both outputs ON without reported errors. These are cloud-reported values, not independent
  proof of physical state or fresh readings. No key was printed or passed through chat.
  This does not activate real payments, billing against physical lights, switching, device timers,
  reboot settings or Wi-Fi changes. Automated PHP tests continue to use synthetic credentials.

Verification: **197 PHP tests / 1,674 assertions passed** in the full default application
suite, including **45 focused tests / 866 assertions** for Shelly setup, Lights behavior
and route access. The dedicated browser test passed on mobile and desktop (no credentials
saved and no hardware contacted): `node --test tests/Frontend/shelly-setup-browser.test.mjs`.
Screenshots: `.local-acceptance/screenshots/shelly-setup-mobile.png` and
`shelly-setup-desktop.png`. This follow-up did not rerun the MySQL concurrency suite.

Latest control-remediation verification: **222 PHP tests / 1,882 assertions**, **33 isolated
MySQL tests / 380 assertions**, and **20 browser tests** all pass. Browser requests now only queue
durable control work, the background worker owns relay execution and status reconciliation, and
the default browser suite explicitly refuses customer hardware mode.

Connection-recovery follow-up: the Windows web-to-CLI subprocess lost `SystemRoot` when
Symfony filtered environment variables against the HTTP request's `$_SERVER`. That caused
cURL hostname resolution to fail even though the standalone checker succeeded. The probe now
explicitly preserves the real OS `SystemRoot`, `WINDIR`, `TEMP` and `TMP` values (never request
input). Safe numeric cURL diagnostics distinguish DNS, network, TLS and timeout failures without
revealing URLs/keys, and failures now render as errors. The local web listener was restarted
outside the restricted tool sandbox with the approved `scripts/start-local-acceptance.ps1 -RestartWeb`
command. This flag validates port ownership, executable, router command and environment before
stopping only that listener; it does not restart MySQL or the Lights accounting worker. The
listener remains loopback-only and the main PHP HTTP process still has its network guards.

After connection recovery, the full default PHP suite passed **200 tests / 1,710 assertions**,
including 25 focused Shelly/Lights tests with 271 assertions. The existing accounting worker
remained healthy. The opt-in end-to-end live read-only browser check passed; default browser tests
do not contact Shelly. To repeat deliberately, with an existing privately saved key:

```powershell
$env:SHELLY_LIVE_READONLY_CHECK = '1'
node --test tests/Frontend/shelly-setup-browser.test.mjs
Remove-Item Env:SHELLY_LIVE_READONLY_CHECK
```

Do not disable TLS verification to resolve a connection failure. If launching via Codex,
network-capable helper execution may require approval; a script does not override sandbox policy.

Current Shelly web UI (3.77.15) puts the key under **Settings → Home → Access and
Permissions → Cloud Key**, as confirmed in its public app code; the older API guide's
User-settings navigation did not match the owner's UI. The server and key are paired;
server changes require an intentional code/configuration update, not arbitrary URL entry.

References: [Cloud API setup](https://shelly-api-docs.shelly.cloud/cloud-control-api/),
[Cloud status v2](https://shelly-api-docs.shelly.cloud/cloud-control-api/communication-v2/).

Local modes are deliberately separate. `scripts/start-local-lights.ps1` starts simulation only;
`-Hardware` restarts the loopback portal for admin-only Shelly commissioning, while
`-Hardware -CustomerControl` is the explicit customer hardware-acceptance mode. Default PHP and
browser regressions never enable either hardware flag and must not be run against customer hardware mode.

The following are **not activated or accepted** yet:

1. **PayFast acceptance:** the signed checkout and verified, idempotent ITN crediting path is coded but has no merchant credentials and has not run against the sandbox or live service. Refund administration and financial reconciliation procedures remain operational follow-up work. Browser return pages never directly credit wallets.
2. **Shelly acceptance:** the customer control state machine and production relay driver are coded but disabled. The one-court pilot, device-side auto-off, OFF-on-reboot, status reconciliation, lost-acknowledgement handling and independent physical confirmation still require supervised evidence. A database deadline alone cannot prove hardware is off.
3. **Production operations:** HTTPS domain/hosting, independent credentials and least-privilege databases, live mail delivery, backup/restore, external health alerting, worker supervision, documented retention and operator procedures still need deployment. The recovery and health-reporting application paths are implemented, but local fakes are not delivery/monitoring evidence. The current global lock is a small-venue design, not a load-tested multi-venue architecture.
4. **On-site acceptance:** wiring/load suitability assessed by the responsible qualified installer, manual override, tariff/rounding confirmation, phone/network loss, power loss, zero-credit cutoff, operator emergency stop and a controlled one-court pilot.

To proceed, the owner must provide the intended staging/hosting location, PayFast account/environment choice, court/device/channel mapping and network approach. Do not paste secrets into chat. Real payment tests, physical switching and production deployment require their own verified setup and approval.

On a physical phone, `127.0.0.1` refers to the phone itself. The supplied URL is for this computer; an approved HTTPS staging endpoint is needed for realistic phone installation/access. Do not expose the disposable database to the network.
