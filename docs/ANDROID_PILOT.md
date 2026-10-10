# Citizen Android pilot: setup, build and safe shutdown

The Android app displays the existing citizen website. PHP, MySQL, the identity registry,
AI credentials and report logic stay on the PC. Use synthetic data only: this APK does
not activate real SMS delivery, government identity lookup or municipal integration.

## Security and data flow

The isolated citizen process uses `MOSHKELTI_CITIZEN_ONLY=true`. Its `APP_URL` and
`MOSHKELTI_CITIZEN_ORIGIN` must match exactly: private IPv4, HTTPS, port 8443.

Middleware rejects all admin routes and administrator sessions on that process.
User-agent strings, query flags and client headers cannot change the server mode.
Only the loopback proxy is trusted for forwarded client IP, protocol and port;
forwarded host is not trusted. Desktop/admin access remains unchanged.

Citizen sessions use a separate `__Host-moshkelti_citizen` cookie: Secure, HTTP-only,
SameSite=Lax, path `/`, no Domain attribute. Citizen responses are private/no-store.
Owner evidence links use the existing signed inline preview, with authorization and
safe-image checks intact. Location selection is optional and user-triggered.

National-ID/mobile/OTP registration, national-ID/password login, consent, restricted
read-only access, appeals, version checks, idempotency and queued analysis all reuse
the existing Laravel services. No database migration or Laravel dependency was added.

## Storage and files

The self-contained client source is in `android/`. Only allowlisted client files are
exported to the private repository. No Laravel source, `.env`, databases, registry,
logs or provider credentials are uploaded. The main application's Git remote is not pushed.

The folder name changes at export: local `android/` becomes the **root** of the private
Android repository. Java code is at `app/src/main/java/jo/moshkelti/citizen/`, the manifest
at `app/src/main/AndroidManifest.xml`, and CI at `.github/workflows/build-apk.yml`.
There is intentionally no enclosing `android/` folder in that separate repository.

Portable tools, the client export, HTTPS keys, encrypted signing backup and APK download
live in ignored `storage/app/citizen-pilot/`. Its Windows ACL permits only the current
user and SYSTEM. Do not publish this directory or delete the CA casually.

Keep new local artifacts below **500 MB** and free disk space above **12 GB**.
No Android Studio, SDK, emulator, local Gradle cache or Node installation is required.
Downloads stop when the storage checks fail; do not accumulate APKs from every build.

## Prepare HTTPS without starting services

From the Laravel project root, review and run:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File docs/Install-CitizenProxy.ps1
powershell -NoProfile -ExecutionPolicy Bypass -File docs/Start-CitizenPilot.ps1 -CitizenHost 10.35.190.193 -PrepareCertificateOnly
```

Replace the example address with the PC's current private Wi-Fi IPv4 address.
The execution-policy option affects only that PowerShell process, not machine settings.
Preparation validates Caddy and generates the local CA without opening listeners,
changing firewall rules, adding system-wide trust or starting the database.

The APK receives only the public
`storage/app/citizen-pilot/caddy-data/pki/authorities/local/root.crt` certificate.
The CA's private keys never leave the PC. Do not bypass SSL errors or enable cleartext.

## Repository and signed builds

Private client repository:
[Coder-511/moshkelti-citizen-android-pilot](https://github.com/Coder-511/moshkelti-citizen-android-pilot).

The publisher uses the existing Git Credential Manager account without displaying or
storing its token. It creates an isolated client export, a dedicated PKCS12 APK-signing
key, encrypted Actions secrets and public origin/CA variables. APK signing is separate
from HTTPS certificate signing. The signing-password backup is Windows DPAPI-encrypted
for the current account. Preserve that key for future APK updates.

For subsequent reviewed client updates:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File docs/Publish-CitizenAndroid.ps1 -CitizenHost 10.35.190.193 -GitHubUser Coder-511 -UpdateExisting
```

The manual workflow on `main` runs URL-policy tests, release lint and a non-debuggable
release build. It verifies the APK signature and uploads only the APK and SHA-256
checksum, retained seven days. Remote SDK/Gradle artifacts are not downloaded to this PC.

Limit repository write access to explicitly trusted people. You approved keeping
`mousabmt` as a write collaborator. Review every workflow change: someone who can
modify a trusted workflow may misuse its secrets. Add protected branch/environment
review rules when supported by the account plan. No paid runner, plan or spending setting
is enabled automatically. Maintain an approved encrypted off-PC signing backup before
depending on the APK long-term; do not paste passwords or private keys in chat.

Changing the Wi-Fi IP or CA requires updating Actions variables and rebuilding.
Changing the APK signing key requires reinstalling instead of updating the existing app.

## Start the pilot

1. Start the configured database normally. Keep the demo registry available for registration.
   The startup script checks migration status; it never silently starts, repairs or alters
   MySQL/MariaDB. Do not use real national IDs or sensitive reports in this pilot.
2. Start the citizen surface:

   ```powershell
   powershell -NoProfile -ExecutionPolicy Bypass -File docs/Start-CitizenPilot.ps1 -CitizenHost 10.35.190.193
   ```

   Caddy listens on that Wi-Fi address, TCP 8443, and forwards only to `127.0.0.1:8001`.
   Its control API is loopback-only, port 2021. PHP uses Laravel's development router.
   The isolated PHP process allows 5 MB per upload and a 16 MB request; this does not
   rewrite global `php.ini`. Laravel still enforces three images and content validation.
   This is a local pilot, not a production deployment.
3. Run the existing shared queue worker in a separate terminal so AI analysis progresses:

   ```powershell
   php artisan queue:work database --queue=default --sleep=2 --tries=3 --timeout=40 --no-interaction
   ```

   Do not duplicate workers unnecessarily. Desktop/admin serving remains separate and
   loopback-only. Do not expose ports 8000, 8001, 2021 or 3306 to the network.
   The database queue reservation is 90 seconds; keep worker and job timeouts shorter.
   Windows does not provide PCNTL worker alarms, so provider HTTP timeouts remain essential.
4. Connect the phone to the same Wi-Fi and install the signed APK from the successful
   private Actions run. Some Wi-Fi networks block traffic between devices. Enable install
   permission only for the file app you use, then disable that permission again.

No firewall rule is created automatically. If Windows blocks 8443, approve a rule for
the verified Caddy executable on **Private networks only**, scoped to the phone or local
subnet. Do not disable the firewall or permit Public-network access.

After explicitly approving a trusted Wi-Fi network, an **Administrator PowerShell** can
run the reviewed, narrowly scoped helper:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File docs/Configure-CitizenNetwork.ps1 -CitizenHost 10.35.190.193
```

It changes only the Wi-Fi profile to Private and allows the exact Caddy executable on
that address/port, Wi-Fi only, from `LocalSubnet`. It refuses to overwrite an existing
named rule. This rule persists after stopping the pilot; remove it when the pilot is no
longer needed with `Remove-NetFirewallRule -Name Moshkelti-Citizen-Pilot-8443` in an
Administrator PowerShell. Do not mark an untrusted network Private. Restore the original
Wi-Fi profile deliberately if desired, using Windows network settings.

Process-specific environment values and a separate config-cache path keep citizen and
desktop configuration isolated. Root `.env` is not rewritten. Do not cache configuration
to the reserved pilot `config.php`; startup refuses that cached file deliberately.

## Stop safely before turning off the PC

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File docs/Stop-CitizenPilot.ps1
```

The script verifies the recorded PID, start time and executable, gracefully drains Caddy,
then stops only the recorded pilot PHP process. It preserves certificates and does not
stop the ordinary desktop server, shared queue or database.

Stop the queue worker in its terminal with Ctrl+C after its current job, or use
`php artisan queue:restart` and wait for the application's workers to exit. Stop ordinary
`artisan serve` terminals with Ctrl+C. Then stop MySQL/MariaDB through XAMPP or its documented
graceful shutdown and wait for it to finish. Never force-terminate `mysqld.exe` to save time.

## Verification and remaining physical-phone checks

Laravel tests cover all registered admin endpoints, injected admin sessions, HTTPS/proxy
checks, secure cookies, submissions/idempotency, restricted reads/appeals, signed private
evidence and unchanged desktop admin access. Existing regression tests cover registration,
AI consent, stale decisions, attachment validation and workflow authorization.

CI checks URL policy, compilation, lint and signing. These checks do not prove phone
behavior, Wi-Fi reachability or protection against every vulnerability. On Android 16, test:

- Arabic/English, native errors, keyboard, system-bar insets and form scrolling.
- Synthetic registration/OTP, password login, expired sessions, logout and app relaunch.
- Map selection and current-location permission granted/denied, approximate location and
  out-of-Jordan results. Use current location only when it is the actual issue location.
- Image picker cancel, valid/invalid/oversize images, submission and private inline viewing.
- Suspended/banned read-only access and appeals; direct submission/upload denial.
- Admin URLs refused in the app; desktop admin login and review still work.
- Offline PC, blocked Wi-Fi, untrusted certificates and expired signed attachments.
- Back navigation/relaunch after logout does not reveal previous private reports/evidence.

Keep Android System WebView and Laravel patched. No push notifications, offline submissions,
production onboarding or Play Store release is included.

Primary references: [Android WebView](https://developer.android.com/develop/ui/views/layout/webapps/webview),
[Android network security](https://developer.android.com/privacy-and-security/security-config),
[file-picker URI safety](https://developer.android.com/reference/android/webkit/WebChromeClient.FileChooserParams),
[Caddy local HTTPS](https://caddyserver.com/docs/automatic-https),
[AGP compatibility](https://developer.android.com/build/releases/agp-8-13-0-release-notes).
