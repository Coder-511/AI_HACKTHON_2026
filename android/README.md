# Moshkelti citizen Android pilot

This private repository contains only the Java Android client and its build workflow.
The Laravel application, database, registry, logs and AI credentials stay on the PC.
Use synthetic test data only. This is not a government service or production identity app.

## Where the Android code lives

In the Laravel workspace this project is under `android/`. In this separate repository,
that folder's contents are exported directly to the root; there is no second `android/`
folder. The Activity and URL policy are in `app/src/main/java/jo/moshkelti/citizen/`, the
manifest is `app/src/main/AndroidManifest.xml`, and CI is `.github/workflows/build-apk.yml`.

## What the app does

The app displays the existing citizen website through the device WebView. Registration
uses the existing national-ID/mobile/OTP flow; sign-in uses national ID and password.
Reports, consent, attachment validation, AI queues, appeals and restrictions remain
server-controlled. No separate mobile authentication or report logic is introduced.

Admin access is unavailable on the isolated citizen server. The client also blocks
administration paths, but this is only an additional control—not the permission boundary.

## Build remotely

The project targets Android 16 (API 36), supports Android 8+ (API 26), and uses Java 17,
Android Gradle Plugin 8.13.2 and Gradle 8.13. No Android Studio or SDK is needed on the PC.
Runtime Android libraries are not added; JUnit is used only for URL-policy tests.

In the private repository, configure these Actions variables:

| Variable | Value |
| --- | --- |
| `CITIZEN_ORIGIN` | Exact private IPv4 HTTPS origin, port 8443, no trailing slash |
| `PILOT_CA_BASE64` | Base64 of the PC proxy's **public** `root.crt` |

Configure these encrypted repository Actions secrets:

| Secret | Purpose |
| --- | --- |
| `SIGNING_STORE_BASE64` | Base64 PKCS12 APK signing store, **not** the HTTPS CA |
| `SIGNING_STORE_PASSWORD` | Signing-store password |
| `SIGNING_KEY_ALIAS` | Signing alias `moshkelti-pilot` |
| `SIGNING_KEY_PASSWORD` | Signing-key password |

Run **Signed citizen pilot APK** manually on `main`. The workflow runs URL-policy tests,
Android lint, builds a non-debuggable release, verifies its signature and publishes only
the signed APK and SHA-256 checksum for seven days. It does not publish signing material,
private reports or source from the Laravel project. Action versions are pinned to commits.
Private repository minutes are subject to the account's existing quota; no paid plan or
spending setting is enabled by this project.

Keep write access limited to explicitly trusted people. The owner approved `mousabmt`
as a write collaborator; that access is intentional. Configure protected-branch/environment
review rules when supported by your GitHub plan. Anyone able to change a trusted workflow
could misuse its build secrets. Do not run unreviewed workflows or grant access casually.

## Security boundaries

- HTTPS only. Custom CA trust is limited to the configured pilot IP; other connections use
  system CA trust. Invalid certificates are cancelled, never bypassed.
- In-app navigation is exact-origin only; unsafe schemes, admin paths and encoded path
  bypasses are blocked. User-clicked ordinary external HTTPS links open in a browser.
- Only approved OpenStreetMap image tile requests may leave the origin inside WebView.
- No JavaScript-native bridge, arbitrary file navigation, third-party cookies, downloads,
  app-data backup, screenshots, WebView debugging or saved authenticated history bundles.
- Private pages use no-store responses and disabled WebView HTTP caching. Login clears
  history/cache/form data and web storage; server logout invalidates the session.
- Images are selected using the system document picker, checked for content URI, type,
  signature, count and size. Laravel repeats authoritative checks and sanitizes images.
- Location permission is requested only following interaction on the report form. Denial
  retains map/manual selection; location is never collected on application startup.

These controls reduce risk; they do not guarantee protection from every vulnerability.
Keep Android System WebView and the backend patched. A compromised/rooted phone is outside
the pilot's security assurances.

## PC and physical-phone requirements

The PC, database, citizen-only loopback server, local HTTPS proxy and existing queue worker
must be available. Connect the phone to the same Wi-Fi; some networks isolate devices.
The PC setup and safe startup/shutdown commands are in `docs/ANDROID_PILOT.md` in the
**Laravel repository**, not in this client repository.

Changing the PC IP or local CA requires updating the Actions variables and rebuilding.
Preserve the APK signing key across updates. Install the APK from Actions, not an unsigned
debug build. No Play Store publishing, offline submission or push notifications are included.

Before declaring the APK usable, test on a physical phone: Arabic/English, national-ID
registration/OTP, login/logout, expired session, restricted accounts/appeals, attachment
selection/private inline viewing, keyboard/insets, map/location denial, Wi-Fi failure,
untrusted certificate rejection, and Back navigation after logout. CI cannot prove those
device behaviors or Wi-Fi reachability.

Primary references: [Android WebView](https://developer.android.com/develop/ui/views/layout/webapps/webview),
[Android network security](https://developer.android.com/privacy-and-security/security-config),
[AGP compatibility](https://developer.android.com/build/releases/agp-8-13-0-release-notes),
[GitHub Actions secrets](https://docs.github.com/en/actions/security-for-github-actions/security-guides/using-secrets-in-github-actions).
