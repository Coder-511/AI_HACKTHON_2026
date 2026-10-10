# Moshkelti (مشكلتي)

A bilingual civic-reporting pilot for Jordan. Citizens document public infrastructure problems, follow their report's progress, and request a review of decisions. Administrators review private evidence, select an approved pilot authority, and decide what information is safe to publish.

**Current scope:** independent demonstration, not an official government service. Identity lookup, SMS delivery, municipalities, and authority acknowledgements are simulated. Gemini analysis is real when configured and consented to; it never approves a report or sends a government request. This application is not an emergency service.

## Contents

Citizen-only Android pilot: [setup, signed builds and safe shutdown](docs/ANDROID_PILOT.md).
The Android client reuses Laravel's citizen workflow; desktop administration remains separate.
Local client source is in `android/`; in the [private Android repository](https://github.com/Coder-511/moshkelti-citizen-android-pilot),
those contents are at the root, with Java code under `app/src/main/java/jo/moshkelti/citizen/`.

- [Features and boundaries](#features-and-boundaries)
- [Technology](#technology)
- [Local setup on Windows](#local-setup-on-windows)
- [Citizen registration and login](#citizen-registration-and-login)
- [Administrator access](#administrator-access)
- [Report lifecycle](#report-lifecycle)
- [AI analysis](#ai-analysis)
- [Permissions](#permissions)
- [Security and privacy](#security-and-privacy)
- [Project structure and data](#project-structure-and-data)
- [Operations and troubleshooting](#operations-and-troubleshooting)
- [Testing](#testing)
- [Production readiness](#production-readiness)

## Features and boundaries

| Area | Implemented behavior | Boundary |
| --- | --- | --- |
| Citizen identity | National ID and matching mobile lookup in a separate synthetic registry | No official identity-provider integration |
| Registration | Six-digit OTP, followed by password creation | Local OTP is written to a private SMS log; no real SMS is sent |
| Civic reporting | Description, category, governorate, map selection, and optional images | Coordinate bounds are not verified municipal jurisdiction polygons |
| Triage | Local rules, Gemini, or an approved remote adapter; validated structured output | Recommendations, not verified facts or final decisions |
| Review | MFA-protected administrator review, version checks, reasons, and authority selection | Pilot authority directory, initially Amman |
| Delivery | Queued, idempotent simulated delivery with a receipt | No HTTP or email dispatch to government |
| Tracking | Private owner status, event history, and periodic browser polling | Requires a running queue worker; not real-time push |
| Public information | News and separately reviewed report titles/summaries | Original descriptions, exact locations, and evidence are not public |
| Language and layout | Arabic RTL and English LTR, mobile navigation, map fallback | Citizen-authored text is not automatically translated |
| Appeals and privacy | Requests and audited review acknowledgements | An appeal does not automatically reopen a report; acknowledgement is not legal fulfillment |

There is no general comments or voting feature. Citizen evidence changes are permitted only when a reviewer requests more information.

## Technology

- PHP **8.3+**, Composer, Laravel **13.x**. The installed framework at the 2026-10-10 audit was **13.35.0**; `composer.lock` is the reproducible version source.
- Laravel's `mysql` connection for application data. XAMPP may supply **MariaDB**, a separate database engine using this driver; the connection name does not mean the server is Oracle MySQL.
- A separate `registry` database connection for synthetic identity records.
- Blade templates, vanilla JavaScript, and directly served CSS/JS in `public/` for the current interface.
- Database-backed sessions, cache, queues, and notifications.
- PHPUnit for automated tests. The default test database is in-memory SQLite, not the local application database.

Vite/Tailwind tooling is present in `package.json`, but the current main layout loads the `public/css` and `public/js` assets directly. Running the current UI does not require a Vite development server.

## Local setup on Windows

### 1. Prerequisites

Install PHP 8.3+, Composer, and a running MySQL-compatible database server. Enable the PHP extensions needed by Laravel and this application, including PDO MySQL, mbstring, fileinfo, OpenSSL, cURL, and GD. PHPUnit also needs its XML/DOM dependencies and PDO SQLite for the default tests.

Check the PHP executable used by your terminal. XAMPP's PHP and a separate CLI PHP installation may use different versions or `php.ini` files:

```powershell
Get-Command php
php --version
php --ini
```

Run commands below from the project root. `php artisan serve` does **not** start the database. Start the database through XAMPP or its configured Windows service; use one server instance, not competing processes on the same port.

### 2. Install dependencies and configure the environment

```powershell
composer install
composer check-platform-reqs
```

On a **new installation only**, copy `.env.example` to `.env` if `.env` does not already exist:

```powershell
Copy-Item .env.example .env
php artisan key:generate --no-interaction
```

Do not overwrite an existing `.env` or regenerate an existing `APP_KEY`: encrypted data depends on the original key. Back it up securely, outside the repository.

Provision two separate databases and their database users before migrating:

| Database | Purpose | Access |
| --- | --- | --- |
| `moshkelti` | Accounts, reports, queues, sessions, and audit records | Dedicated runtime user; separate migration credentials where practical |
| `moshkelti_registry` | Synthetic third-party identity records | Read-only lookup credentials at runtime; privileged setup credentials for migrations/seeding |

The application does not create database servers, databases, or database users. Provision these with your database administration tools. The mock registry must not share the application database.

Configure these values in `.env`, using your own passwords:

```dotenv
APP_ENV=local
APP_DEBUG=false
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=moshkelti
DB_USERNAME=moshkelti_runtime
DB_PASSWORD=your-local-database-password

IDENTITY_DRIVER=mock_database
IDENTITY_SMS_DRIVER=local
IDENTITY_LOOKUP_KEY=replace-with-an-independent-generated-secret
REGISTRY_LOOKUP_KEY=replace-with-another-independent-generated-secret
REGISTRY_DB_HOST=127.0.0.1
REGISTRY_DB_PORT=3306
REGISTRY_DB_DATABASE=moshkelti_registry
REGISTRY_DB_USERNAME=moshkelti_registry_reader
REGISTRY_DB_PASSWORD=your-local-registry-password

SESSION_DRIVER=database
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=false
CACHE_STORE=database
QUEUE_CONNECTION=database
MOSHKELTI_AI_DRIVER=local
MAIL_MAILER=log
```

Generate each identity lookup secret independently, for example by running the following command twice and copying each result into its corresponding variable:

```powershell
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

These values must be at least 32 characters. Preserve them across deployments: changing a lookup key changes identity fingerprints and requires a controlled migration. Never commit keys, credentials, logs, or citizen data.

### 3. Migrate and seed

Use credentials with the required schema/write permissions **during setup**; a registry reader cannot create tables or insert seed records. Restore least-privilege runtime credentials afterward and clear cached configuration.

```powershell
php artisan config:clear --no-interaction
php artisan migrate --no-interaction
php artisan migrate --database=registry --path=database/migrations/registry --no-interaction
php artisan db:seed --class=DatabaseSeeder --no-interaction
php artisan db:seed --class=MockCitizenRegistrySeeder --no-interaction
```

`DatabaseSeeder` creates simulated Amman category authorities and welcome news. `MockCitizenRegistrySeeder` creates synthetic identities, not registered application accounts, and is restricted to local/testing environments. Registry migrations are in a subdirectory and must be run explicitly.

Do not use `migrate:fresh` on an existing installation: it deletes tables and their data.

### 4. Run the web server and queue worker

In one terminal:

```powershell
Push-Location public
php -d upload_max_filesize=5M -d post_max_size=16M -S 127.0.0.1:8000 -t . ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
Pop-Location
```

Open `http://localhost:8000`. Keep `APP_URL` aligned with the host/port you actually use; use that same host consistently for sessions and signed attachment links.

The `public/` working directory is required by Laravel's bundled development router.
This sets upload limits for this process
only: 5 MB per file and 16 MB per request, consistent with the citizen server. PHP defaults
may be lower than the app's limits. `artisan serve` is also supported when the PHP server
configuration already has sufficient upload limits; command-line `-d` options on its parent
process do not automatically propagate to its child server. Neither command starts the database.

In a **second terminal**, from the same project directory:

```powershell
php artisan queue:work database --queue=default --sleep=2 --tries=3 --timeout=40 --no-interaction
```

The worker processes report analysis, database notifications, and simulated delivery. Serving pages alone does not process these jobs. Job-specific retry settings still apply. HTTP provider timeouts bound AI calls; production worker supervision is still required, and process timeout behavior differs on Windows without PCNTL.

The database queue's default reservation is 90 seconds; keep job/worker timeouts below it.
Run only one shared pilot worker unless deliberately testing concurrency. For scheduled
OTP cleanup and retention previews, use `php artisan schedule:work --no-interaction` in
another terminal. The scheduler does not enable retention erasure.

Stop each foreground process with `Ctrl+C`. After changing worker code or environment configuration, stop and start the local worker. In a supervised environment, `queue:restart` requests a graceful exit and the supervisor must start it again.

## Citizen registration and login

1. Open `/register` and enter a national ID and mobile number.
2. The ID must identify an active registry record, and the mobile must match that record. Registration gives a generic response rather than confirming that an identity exists.
3. Enter the six-digit verification code within five minutes. A challenge permits up to five verification attempts and is tied to the browser registration session.
4. After verification, choose and confirm a password within a further five minutes. Passwords must be 12–128 characters.
5. The application creates a verified citizen account from registry details. Subsequent citizen login uses **national ID + password**, not an OTP on every login.

National IDs are normalized and must be ten digits. Jordanian mobile formats such as `0790000001` normalize to `+962790000001`. Unique identity fingerprints and registry-subject constraints prevent duplicate registration.

### Synthetic test identities

Only use these in local development; they are not real identity records:

| National ID | Mobile input | Governorate | Eligibility |
| --- | --- | --- | --- |
| `0000000001` | `0790000001` | Amman | Active |
| `0000000002` | `0790000002` | Irbid | Active |
| `0000000003` | `0790000003` | Zarqa | Active |
| `0000000004` | `0790000004` | Amman | Inactive; cannot register |

For a straightforward authority-routing demo, use the Amman citizen and an Amman issue. Other governorates do not imply that approved routing authorities exist for them.

### Where is the OTP?

Local SMS does not reach a phone. For an eligible request, read the latest code locally in:

```text
storage/logs/sms.log
```

It is a separate log, not `laravel.log`. Never expose it through a web route, share it, or retain it as production OTP logging. No code is delivered for an inactive, mismatched, already-registered, or unavailable registry identity.

Password recovery currently uses the email-based reset flow. It is not SMS account recovery; synthetic `example.test` email addresses cannot receive real mail. With local log mail transport, inspect the private mail log configured in `config/logging.php`.

## Administrator access

Administrators sign in at `/admin/login` using **email + password**, separately from citizen login. No default administrator password is seeded.

An operator creates an account with the interactive command:

```powershell
php artisan moshkelti:create-admin admin@example.test
```

This intentionally prompts for a name, hidden password, and confirmation. Do not pass passwords on the command line. Use a real controlled email address outside a local demo.

On first administrative access, confirm the password and enroll the displayed setup key in a TOTP authenticator. Enter the authenticator's current code to finish enrollment. Admin codes are **not sent by SMS or email**; keep the device clock accurate and protect the setup key.

Private review requires an unrestricted admin account and MFA verification in the current session. Sensitive decisions also require password confirmation within 15 minutes. The UI displays verification status and requests confirmation when needed. Successful verification returns to the relevant page; it does not silently replay a pending decision. Review the current evidence and explicitly submit the action again.

## Report lifecycle

### Submission and triage

1. Authentication, account permission, validation, and rate limits are checked server-side.
2. The citizen submits plain text, governorate/category, a map-selected location, consent, an idempotency key, and optional images.
3. Images are validated, scanned when configured, re-encoded to JPEG, and stored privately.
4. A transaction creates the report and advances it from `received` to `processing`; analysis is queued after commit. The initial request does not wait for the AI provider.
5. Analysis validates structured recommendations and applies deterministic rules. The usual outcome is `needs_review`; emergency and personal-service requests receive guidance. Exact nearby duplicate candidates can be linked without disclosing the original private case.
6. A human reviews location, category, evidence, authority eligibility, and any missing information.

### Decisions and follow-up

| Status | Meaning |
| --- | --- |
| `received`, `processing` | Recorded and awaiting automated triage |
| `needs_review` | Awaiting human review |
| `needs_more_information` | Owner may provide additional information/evidence |
| `accepted` | Human approval; an approved authority is required |
| `routed` | Queued for simulated pilot delivery |
| `acknowledged` | Simulated authority acknowledgement, not government confirmation |
| `in_progress`, `resolved`, `closed` | Workflow states for handling and completion; not all have dashboard actions |
| `rejected` | Human rejection, with a recorded reason and an appeal route |
| `duplicate` | Associated with an existing candidate case |
| `emergency_guidance`, `service_guidance` | Guidance rather than infrastructure delivery |
| `dispatch_failed` | Delivery retries exhausted; requires operator review |

The dashboard offers only decisions permitted by the current status. Acceptance is usually followed immediately by `routed`; do not expect `accepted` to remain visible for long. Delivery creates one outbox record per report and a simulated receipt, without contacting a government endpoint.

Reports use version checks to prevent stale decisions or edits. Additional information and new attachments change the version. A conflict means the report changed: reload and review current data rather than repeatedly submitting the old form.

Citizen updates are locked after acceptance/rejection and in other states except `needs_more_information`. Acceptance does not mean the infrastructure problem is resolved. Appeals remain a separate review request, including for restricted account owners; they do not automatically reopen the report or lift account restrictions.

## AI analysis

Choose the adapter through `MOSHKELTI_AI_DRIVER`:

| Driver | Behavior |
| --- | --- |
| `local` | Deterministic keyword-based triage; no external model or fine-tuning |
| `gemini` | Real Gemini API call when the report has external-AI consent and budget remains |
| `remote` | Structured analysis from an explicitly allowlisted HTTPS endpoint |

For Gemini, configure `.env`:

```dotenv
MOSHKELTI_AI_DRIVER=gemini
GEMINI_API_KEY=your-private-provider-key
GEMINI_MODEL=gemini-3.8-flash
MOSHKELTI_AI_DAILY_BUDGET=200
```

The model above is the current repository default, not a guarantee of provider availability or project access. Use a model compatible with the adapter's structured-output and generation options. If Windows needs a CA bundle, configure `GEMINI_CA_BUNDLE` with a trusted local certificate-bundle path; do not disable TLS verification.

The remote adapter uses `MOSHKELTI_AI_ENDPOINT`, `MOSHKELTI_AI_HOST`, and `MOSHKELTI_AI_TOKEN`. No arbitrary citizen- or AI-provided URL is fetched.

Important behavior:

- Only minimized/redacted title, description, category, and governorate are passed to the external adapter. Automatic redaction is not a guarantee that all personal information has been removed.
- Images, OCR, and account fields such as names, national IDs, passwords, and mobile numbers are not included in the current AI analysis context. Sensitive details can still appear in citizen-authored text despite redaction. Image evidence is for human review; this is not an implemented computer-vision pipeline.
- Local rules run first. Recognized emergency/personal-service guidance is not overridden by remote recommendations.
- Without external consent, triage stays local. The daily budget is a call-count cap, not a currency spending limit or provider billing guarantee.
- Gemini questions are returned as validated Arabic/English pairs. Some historic records may still contain original-language questions without a reviewed translation.
- AI cannot execute SQL/code, ban users, approve reports, choose an authoritative URL, or dispatch a government request. A blank AI authority suggestion means the responsible authority is awaiting human selection, not necessarily an analysis error.
- Malformed/unavailable AI responses fall back to review. Exhausted analysis jobs also move eligible reports to human review rather than leaving them indefinitely processing.

View recorded results in the private admin report page under **Automated triage results**. The database stores the driver, report version, validation state, structured recommendation, and timestamp in `report_analyses`. Security logs record limited events, not complete private prompts or provider credentials.

## Permissions

| Account | Public news/reports | Own report tracking | Submit/update evidence | Private admin review |
| --- | --- | --- | --- | --- |
| Guest | Yes | No | No | No |
| Verified active citizen | Yes | Yes | Submit; updates only when more information is requested | No |
| Suspended citizen | Yes | Yes | No | No |
| Banned citizen | Yes | Limited status/guidance; no private evidence/original text | No | No |
| Active administrator | Yes | No citizen workflow | No | Yes, after MFA; recent password for sensitive actions |
| Restricted administrator | Yes | No citizen workflow | No | No |

Suspension/ban does not disable login. `account_state`, spam `risk_flag`, and calculated submission permission are separate concepts. A risk flag alone does not prove a report false or automatically ban a citizen. Restrictions are enforced in middleware, policies, and relevant transactional service paths, not by hiding buttons alone.

## Security and privacy

The implementation includes defense in depth; it is not a guarantee against every vulnerability:

- Eloquent/bound queries, allowlisted sort fields, validated input, and server-owned identity/status fields.
- Escaped Blade content, plain-text validation, CSRF protection, restrictive security headers, and HTTP-only encrypted sessions.
- Per-IP/account throttling for authentication, identity challenges, reads, submissions, and appeals. Form visits use the read limit rather than consuming submission quota.
- Row locks, report versions, owner-scoped idempotency keys, controlled transitions, and duplicate-delivery protection.
- Signed, short-lived private evidence links plus authorization on each access. Signing alone is not permission. Admin preview also requires verified MFA.
- Strict outbound HTTPS host allowlisting, redirect refusal, DNS validation/pinning, reserved IPv4/IPv6 rejection, timeouts, and response-size limits.
- Separation of untrusted citizen content from AI instructions, strict output validation, limited AI capabilities, and human review.
- Encryption of selected sensitive database fields, including national IDs, mobile numbers, report text, MFA secrets, and analysis results. Not every column or file is encrypted; encrypted disks/backups remain deployment responsibilities.
- Append-only report-event/analysis/audit model behavior and sanitized audit metadata. Reports themselves change through the controlled workflow. Database access controls are still necessary to protect these records outside the application.

### Attachments

Only JPEG, PNG, and WebP inputs are supported—not PDF, SVG, archives, or executable files. Limits are three images per report, 5 MB per input, dimensions up to 4096 × 4096, and a decoded image pixel budget. Re-encoding removes original metadata and the original filename is not used for storage paths.

Files live under `storage/app/private`, outside the public web root. Admins can view authorized sanitized JPEGs inline; ordinary evidence downloads remain private. Do not link private storage into `public/`. Production uploads fail closed without configured malware scanning; local sanitized images are not equivalent to a malware scan.

### Publication and privacy requests

Publishing requires separately reviewed public text. Contact-detail checks help reject unsafe summaries, but do not replace human privacy review. Public visitors do not receive original descriptions, owner contacts, precise coordinates, AI results, or private attachments. Future publication dates are excluded from public listings and counters.

Privacy requests support access, correction, erasure, and external-AI consent withdrawal. Withdrawal disables external consent on existing reports; it does not undo data already processed by a provider. The dashboard records acknowledgement or a need for verification, not completed legal fulfillment.

The configured retention default is 180 days. Preview retention without changing data:

```powershell
php artisan moshkelti:retention --no-interaction
```

Only after approving the policy and backups, an operator may run:

```powershell
php artisan moshkelti:retention --apply --no-interaction
```

**The `--apply` command deletes expired evidence and removes eligible closed-report private content/AI records.** It retains account linkage and summary/history for audit; it is not full account erasure. The scheduled daily retention command is currently a preview, not automatic deletion. Expired registration challenges older than one day are scheduled for hourly cleanup. Both require the scheduler to run.

## Project structure and data

| Location | Responsibility |
| --- | --- |
| `routes/web.php` | Public, citizen, authentication, and protected admin routes |
| `app/Http/Requests` | Input validation |
| `app/Http/Middleware`, `app/Policies` | Locale, permissions, verification, and security boundaries |
| `app/Services/RegistryRegistration.php` | Session-bound identity/OTP registration |
| `app/Services/ReportSubmission.php`, `ReportWorkflow.php` | Transactional submission and allowed state changes |
| `app/Services/Ai`, `app/Jobs/AnalyzeReport.php` | Analysis adapters, output validation, and queued triage |
| `app/Services/AuthorityDispatcher.php`, `app/Jobs/DispatchReport.php` | Simulated outbox delivery and terminal failure handling |
| `app/Services/AttachmentStorage.php` | Scanning, image sanitization, and private storage |
| `app/Services/SecurityAudit.php`, `PublicReportPublisher.php` | Sanitized audit events and reviewed publication |
| `resources/views`, `lang`, `public/css`, `public/js` | Bilingual UI, responsive styles, navigation, polling, and map picker |
| `database/migrations/registry` | Separate synthetic-registry schema |
| `app/Console/Commands`, `routes/console.php` | Operator commands and scheduled maintenance |
| `tests/Feature`, `tests/Unit` | Regression and security tests |
| `android/` | Citizen-only Android client, URL-policy tests and remote signed-build workflow |
| `docs/ANDROID_PILOT.md`, `docs/*Citizen*.ps1` | Isolated HTTPS citizen setup, network access, client export and shutdown |

Application tables include users/restrictions, reports/attachments/events/analyses, authority units/dispatch outboxes, appeals, news, privacy requests, audit logs, identity challenges, notifications, and Laravel sessions/cache/jobs. `registry_citizens` lives in the separate registry database.

UUID report IDs and tracking codes reduce guessability but do not replace authorization. Public/private data separation is enforced independently of identifiers.

## Operations and troubleshooting

| Symptom | Check |
| --- | --- |
| Report remains `processing` | Start/check the queue worker; inspect failed jobs and private application logs |
| No OTP on the phone | Local SMS is simulated; use `sms.log` and a matching eligible synthetic identity |
| AI shows `local` instead of Gemini | Check driver, external consent, daily cap, credentials, and restart the worker after configuration changes |
| No AI result yet | Analysis is queued; receipt of the report is not completion of analysis |
| No suggested authority | Human selection is deliberate; verify reviewed category/governorate against the pilot directory |
| Admin decision conflicts | Reload the latest report version and explicitly resubmit; do not reuse a stale form |
| Attachment link expires | Refresh the authorized report page to obtain a fresh signed link; do not remove signature checks |
| Map tiles fail | Use manual coordinates; map bounds do not verify the governorate or jurisdiction |
| Database connection/500 error | Confirm the database server, port, credentials, both schemas, and migration status; inspect logs privately |
| Old configuration remains active | Clear cached config, then restart the worker; do not regenerate encryption keys |

Useful diagnostics:

```powershell
php artisan migrate:status --no-interaction
php artisan migrate:status --database=registry --path=database/migrations/registry --no-interaction
php artisan route:list --except-vendor --no-interaction
php artisan queue:failed --no-interaction
php artisan schedule:list --no-interaction
php artisan moshkelti:security-check --no-interaction
```

Run `schedule:work` locally in another terminal if scheduled maintenance is needed. Production needs a configured scheduler and supervised queue workers. Do not retry failed delivery jobs blindly: confirm the report/outbox state first; there is no completed dashboard retry workflow for `dispatch_failed`.

The production readiness command returns failure when prerequisites are unmet. This is expected for the local pilot; do not switch to production or enable secure-only cookies merely to silence checks while serving over local HTTP.

## Testing

```powershell
php artisan config:clear --no-interaction
php artisan test --compact
php artisan test --compact tests/Feature/FinalFlowAuditTest.php
composer audit
```

The default suite uses isolated in-memory SQLite, fake storage/notifications, and controlled external adapters where appropriate. If configuring MySQL integration tests, use a dedicated `moshkelti_test_*` database; the test bootstrap rejects other MySQL application database names. Never point tests or synthetic registry seeders at real citizen data.

At the **2026-10-10 audit**, the suite passed **301 tests / 1,833 assertions**, including the isolated citizen surface. Composer found no known dependency advisories and installed platform requirements passed. The private Android CI passed URL-policy tests, release lint, compilation and APK-signature verification. These are a dated verification snapshot, not a permanent security certification. Physical-phone visual testing, Wi-Fi reachability and production infrastructure validation remain separate checks.

## Production readiness

This repository is **not ready for real citizen onboarding or official government delivery**. Before a production release:

- Implement and approve real identity and SMS providers; current bindings are local simulations, and changing environment names alone does not implement integrations.
- Replace log mail with a real transport; define account-recovery and MFA-recovery procedures.
- Configure HTTPS, secure cookies, an appropriate public hostname, production environment, and disabled debug output.
- Configure and validate malware scanning and signature updates; protect private storage and logs.
- Provision least-privilege database credentials, separated migration access, encrypted backups, and a tested restore procedure. Preserve encryption/fingerprint keys securely.
- Supervise queues, operate the scheduler, monitor failures/budgets, and define dispatch-failure recovery procedures.
- Approve the authority/jurisdiction directory and any official delivery integration. Simulated receipts must not be represented as official acknowledgements.
- Review data minimization, retention/erasure, provider processing terms, consent, and applicable Jordanian privacy obligations with qualified reviewers.
- Complete Arabic/English accessibility and responsive real-browser/device testing, deployment checks, and an independent security assessment proportionate to the release.

No software or checklist can guarantee protection against all present or future threats. Report suspected application vulnerabilities privately to the project maintainers; do not publish citizen data, exploit credentials, or sensitive logs.
