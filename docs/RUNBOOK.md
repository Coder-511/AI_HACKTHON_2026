# Moshkelti local runbook

The implemented pilot uses Laravel 13, PHP, MySQL-compatible MariaDB, Blade, and local CSS/JavaScript. No npm build is needed for these pages.

## Already configured in this workspace

- XAMPP supplies MariaDB, configured for this pilot's loopback `127.0.0.1:3306` connection. It must be started separately; serving Laravel does not start it.
- The dedicated `moshkelti` database has been migrated and seeded with five simulated Amman departments and one clearly labelled pilot news item.
- The ignored `.env` uses `moshkelti_runtime`, with a generated password and only SELECT, INSERT, UPDATE and DELETE grants on this database.
- No administrator or default citizen password was seeded. The old SQLite database was not erased.
- The local desktop site is available on port 8000 only while its process runs. Use the host in `APP_URL` consistently (this workspace uses `http://localhost:8000`).
- The citizen APK uses a separate HTTPS origin on the PC Wi-Fi address, port 8443. See [ANDROID_PILOT.md](ANDROID_PILOT.md); its proxy forwards to loopback 8001 and never exposes desktop administration.

## Run locally

From the project root, in separate PowerShell terminals:

```powershell
Push-Location public
php -d upload_max_filesize=5M -d post_max_size=16M -S 127.0.0.1:8000 -t . ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
Pop-Location
```

In separate terminals at the project root:

```powershell
php artisan queue:work database --queue=default --sleep=2 --tries=3 --timeout=40 --no-interaction
php artisan schedule:work --no-interaction
```

If port 8000 is already serving this app, do not start another server. Keep the queue worker running; submissions return immediately and analysis runs asynchronously. Queue retry-after is 90 seconds; the worker timeout must remain lower.

The development router uses process-only upload limits consistent with Laravel's three-image,
5 MB-per-image validation. Do not expose this PHP server or the database to Wi-Fi; only the
scoped citizen HTTPS proxy belongs on the network. Windows lacks PCNTL timeout alarms;
the AI adapter also enforces bounded HTTP timeouts. Stop foreground processes with Ctrl+C;
stop background processes only after verifying their identity and letting jobs finish.

Create your own administrator interactively:

```powershell
php artisan moshkelti:create-admin your-email@example.com
```

Choose a real password of at least 12 characters. Sign in, confirm it, and enroll a TOTP authenticator using the displayed secret. Administrative changes require MFA and a password confirmation within 15 minutes. An operator-controlled recovery process is needed if the authenticator is lost; there is deliberately no public MFA-reset bypass.

Citizen registration has three steps: enter national ID and mobile; verify the SMS code; then choose and confirm a password. The mobile must match the registry-held number. Name/email come from the registry and cannot be supplied by the visitor. Citizen login accepts only national ID and password, not email. Operators use the separate administrator-only `/admin/login` email/password endpoint, followed by existing MFA.

The `moshkelti_registry` database has four synthetic records. Active test pairs are `0000000001 / 0790000001`, `0000000002 / 0790000002`, and `0000000003 / 0790000003`; `0000000004` is inactive. Numbers are demo data and must never be dialed or used to send real messages. Do not use real identity data in this pilot. `moshkelti_registry_reader` has SELECT-only access to this database and no application-database access.

Registration SMS is explicitly simulated: no real SMS is sent. Read the latest code in private `storage/logs/sms.log`; it expires in five minutes and allows five guesses. Once verified, the password stage lasts five minutes and cannot be extended by replaying the code. Restarting invalidates the old challenge. Codes and passwords are never returned in HTML or repopulated in forms. Use an approved SMS integration before real use; the current local SMS channel refuses production.

Password recovery still uses the registry email and private `storage/logs/mail.log` in this demo; mobile OTP does not mark email verified. Never share either log or expose it through the web server. Existing accounts with no national ID need an operator-reviewed identity-linking process; do not invent or silently assign IDs.

The mock adapter is disabled outside local/testing environments. An approved third-party adapter must implement `App\Contracts\CitizenRegistry`; government access, contact verification requirements, retention and privacy/legal review are not simulated authorization. This pilot has no official government identity connection.

Both `IDENTITY_LOOKUP_KEY` and `REGISTRY_LOOKUP_KEY` are independently generated secrets in the ignored `.env`. Back them up securely alongside `APP_KEY`; never regenerate them casually. Rotation requires a controlled fingerprint/encryption migration, or existing records will no longer resolve.

## Database migrations

The runtime user intentionally cannot migrate schemas. Supply separate migration credentials for a migration process; do not grant schema privileges to the web application. For this existing local XAMPP installation only, its separate root account has no password:

```powershell
$env:DB_USERNAME = 'root'
$env:DB_PASSWORD = '(empty)'
php artisan migrate --no-interaction
Remove-Item Env:DB_USERNAME, Env:DB_PASSWORD
```

Do not use that root/empty-password setup in production. Never run `migrate:fresh` against the application database.

Registry schema and synthetic seed data use a separate migration path and operator-only credentials. They are not part of the default app seeder. For this local installation:

```powershell
$env:REGISTRY_DB_USERNAME = 'root'
$env:REGISTRY_DB_PASSWORD = '(empty)'
php artisan migrate --database=registry --path=database/migrations/registry --no-interaction
php artisan db:seed --database=registry --class=MockCitizenRegistrySeeder --no-interaction
Remove-Item Env:REGISTRY_DB_USERNAME, Env:REGISTRY_DB_PASSWORD
```

Seeding is local/testing-only and creates no application accounts or default passwords. Run `php artisan schedule:work` locally for expired challenge cleanup. During the 2026-10-10 check, read-only table checks returned OK for both pilot databases and the previously problematic `mysql.tables_priv` table; startup reached ready-for-connections without Aria recovery errors. This is not a backup/restore test or production database certification. The registry's database-scoped SELECT-only grant works and write denial was previously verified.

## Verification and operations

```powershell
php artisan test --compact
php vendor/bin/pint --dirty --format agent
composer audit --locked
php artisan moshkelti:security-check
php artisan moshkelti:retention
```

The default tests use disposable in-memory SQLite. MySQL tests are guarded to accept only a dedicated database named `moshkelti_test_*`; they must never point at `moshkelti`. The implementation was also tested against an isolated MySQL database. A schema-capable test credential is required for that run.

Retention is a preview by default, including the scheduled daily command. Only after approving the retention policy should an operator run `php artisan moshkelti:retention --apply`. This erases expired evidence and minimizes old closed-report text; it does not fulfill full account deletion. Back up and verify restoration before enabling it.

## Optional external AI

### Gemini text analysis

The local workspace is configured for `MOSHKELTI_AI_DRIVER=gemini`. Add your private `GEMINI_API_KEY` to the ignored `.env` file, never to chat or source control. The default model is `gemini-3.8-flash`, configurable through `GEMINI_MODEL`. This adapter uses Google's generateContent REST API directly; no new Composer package is needed. Obtain a key from Google AI Studio and verify model access and billing before use.

After changing configuration, run `php artisan config:clear` and restart the queue worker with `php artisan queue:work database --queue=default --sleep=2 --tries=3 --timeout=40 --no-interaction`. Select the optional external-analysis consent checkbox when submitting a new report. Existing reports are not automatically resubmitted to Google; consent is never retroactively assumed.

Only bounded, best-effort-redacted report text and coarse category/governorate are sent. Photos, exact coordinates, account identity and national ID are not sent. This release does not perform image inference. Emergency and personal-service keyword safeguards still run locally first. Declined consent or an exhausted daily budget uses local triage; provider failure, missing credentials, blocked or invalid output goes to human review. Gemini never approves, publishes, bans, or dispatches.

Use synthetic reports until the provider data-processing arrangements are approved. Google distinguishes paid and unpaid service data use; do not send private citizen data on an unpaid tier. Text redaction cannot guarantee anonymization. A configured key alone does not prove model access: inspect the recorded driver and validation state on a consented report. Regression tests fake provider calls and do not verify live billing or quotas.

Default `MOSHKELTI_AI_DRIVER=local` is a deterministic Arabic/English keyword classifier, not a trained model. Attachments are evidence for human reviewers; no OCR or computer-vision inference is claimed.

The optional `remote` adapter is an integration boundary, not a configured provider SDK. It requires an operator-approved HTTPS JSON classification service, an exact approved DNS host, a token, and explicit report consent. Configure `MOSHKELTI_AI_ENDPOINT`, `MOSHKELTI_AI_HOST` and `MOSHKELTI_AI_TOKEN` only after verifying the provider contract and response schema in `AnalysisValidator`. It is not directly compatible with every vendor's API.

The service receives bounded, best-effort-redacted title/description and coarse report context, never account identity, exact coordinates or attachments. Free-text redaction cannot guarantee removal of every personal detail; complete a privacy review before real use. The provider's retention/training behavior must be independently verified. Daily request quotas are enforced; no AI result may approve, publish, ban or dispatch.

## Pilot limits

Government dispatch is simulated, with deterministic SIM receipts and no external government HTTP/email calls. Official integration requires new authorized implementation, endpoint validation, signed payloads and receiver-side idempotency. Governorate correction is reviewer-controlled; no authoritative boundary dataset is installed. Legal/erasure requests are recorded for manual handling, not marked fulfilled automatically.

See [SECURITY.md](SECURITY.md) for controls and production blockers.
