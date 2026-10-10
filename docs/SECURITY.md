# Moshkelti security implementation

This is a defended pilot, not a guarantee against every vulnerability or a declaration of legal compliance. Laravel/backend and testing best-practice guidance informed the separation of policies, Form Requests, transactions, jobs and adversarial tests.

## Implemented controls

| Boundary | Enforced protection |
| --- | --- |
| Account restrictions | Banned/suspended accounts can authenticate and read; submission, edits and evidence uploads are blocked in middleware and again under a user-row lock in the service. Expiry uses server time. Risk flags alone do not block submissions. |
| Ownership | Policies protect report access, edits, appeals and attachment downloads. Public projections omit private descriptions, identity, coordinates, analyses and attachments. Banned owners receive limited status/guidance. Administrative private access requires MFA. |
| Authentication | Argon2id hashes; bounded password input; login/registration/reset/MFA throttles; verified citizen identity/mobile or legacy verified email for submission; session rotation; signed verification links; expiring reset tokens; other database sessions revoked after reset. |
| Citizen identity | Separate synthetic MySQL registry with SELECT-only application credentials; encrypted identity fields; independently keyed HMAC indexes; unique application ID/subject constraints. Registry name/email/mobile are server-owned. Signup requires matching ID/mobile, then OTP, then a separate password step. Citizen login uses only national ID/password; separate administrator email login cannot authenticate citizens. Consent, generic eligibility responses, session-bound hashed OTPs, five-minute expiry, five attempts, verified password-stage deadline, locked transactional consumption and registry re-check prevent simple impersonation/replay. National-ID knowledge alone never grants an account. |
| Administration | Native RFC 6238 TOTP tested against reference vectors; encrypted secrets; row locks and conditional updates prevent code replay; user-bound MFA sessions; recent password confirmation; auditable decisions and restriction reversals. |
| SQL | Eloquent/query bindings, allowlisted sorting/category/pagination, no citizen SQL execution or dynamic SQL identifiers, least-privilege runtime credentials. |
| Browser input/output | Plain-text validation, escaped Blade output, external scripts/styles, strict CSP, nosniff/frame/referrer/permissions headers; framework request-forgery protection remains active. No cross-origin API or user-selected redirect is exposed. |
| Attachments | JPEG/PNG/WebP only; MIME/content/extension checks; size/dimension/pixel/count limits; GD decode/re-encode removes metadata; random private paths; quarantine; scanner invocation uses argument arrays with a timeout. Signed downloads still require ownership/MFA. PDFs/SVG/archives/executables are intentionally unsupported. |
| Local vs production scans | Without a scanner, local files are explicitly marked local_sanitized, not malware-clean. Production cannot accept new unscanned uploads or download those local-only files. Configure a maintained ClamAV-compatible scanner before deployment. |
| AI | Untrusted text is separate from instructions; no tools, secrets, identity or executable actions; exact seven-field JSON schema; enum/type/length/confidence checks; authority suggestions checked but never used to authorize routing; model timeout and daily quota; malformed/unavailable results go to human review. |
| Outbound URLs | Only operator-configured HTTPS AI endpoints; exact host allowlist; IPv4/IPv6 non-public and metadata ranges blocked; DNS addresses validated and pinned for TLS transport; proxy/redirects disabled; connect/read/body limits. No report URL is fetched. |
| Business logic | Per-user/IP submission limits; idempotency key + canonical payload hash; database uniqueness; user/report locks; optimistic versions; explicit transitions; append-only application history; exact nearby duplicate linking without private-detail disclosure. |
| Dispatch | Human-approved category/jurisdiction/authority required; one outbox per report; deterministic simulated receipt; safe repeat delivery. No real government calls. |
| Citizen Android surface | Server-configured citizen-only process rejects admin routes and admin sessions; exact private HTTPS origin; narrowly trusted loopback proxy; isolated secure host-only session cookie; no-store private pages; existing signed/authorized JPEG previews. Desktop permissions remain unchanged. |
| Privacy | Encrypted private text/MFA/session data; public copies require explicit human approval and contact/link filtering; opt-in external analysis; consent withdrawal; appeals and privacy-request queues; configurable preview-first retention. |
| Logging | Generated correlation IDs; allowlisted audit metadata; auth, moderation, review, suspected prompt injection, AI failures and delivery audited without raw descriptions, passwords, tokens or files. Repeated blocked-submission audits are bounded. |

Append-only model hooks protect normal application writes; they are not tamper-proof against a compromised runtime/database administrator. Use an external append-only audit sink and reviewed database grants in production.

Public-copy filtering catches common contact details but is not a complete anonymizer. Human review must also remove names, faces, exact addresses, vehicle plates and contextual identification.

## Acceptance coverage

The feature tests cover:

- Allowed submission, suspended/banned reading and direct HTTP bypass attempts.
- Server-side expiry, expired suspension, risk/state separation and service-level checks.
- Private-report/attachment IDOR and public/owner/banned projections.
- SQL-like text as data, malicious sorts, escaped legacy HTML, executable-link rejection, protected ownership/status fields.
- Direct prompt-injection attempts, invalid AI JSON structure/enums/types, no administrative AI actions, consent/budget fallback and outbound address checks.
- Invalid/oversized uploads, private re-encoding, signed/authorized download and scanner-required failure.
- Idempotent requests, exact nearby duplicates, stale-job/version checks, emergency and personal-service guidance.
- MFA reference vectors/replay, session rotation, signed verification, reset/session revocation, login/MFA throttling.
- National-ID format/Arabic-digit normalization, encrypted storage, separate-registry isolation, server-owned identity, duplicate/restricted-account protection, generic unknown/inactive responses, OTP expiry/attempts/session binding/replay, contact changes, malformed records, mail/provider failure and production mock denial.
- Human-reviewed authority matching, auditable restrictions/reversal, private/public-copy separation, repeat dispatch.
- Privacy withdrawal, appeal/erasure-request acknowledgement, retention preview/application, unpublished news and active CSRF protection.

## Required before production

1. Use a supported, patched PHP/MySQL/Laravel deployment. This workstation's XAMPP MariaDB is local development infrastructure, not a production recommendation.
2. Serve only `public/` via HTTPS; deny access to `.env`, vendor/source/storage directories and dotfiles. Set APP_ENV=production, APP_DEBUG=false, SESSION_SECURE_COOKIE=true; configure trusted hosts/proxies narrowly.
3. Keep migration/runtime credentials separate. Rotate secrets and protect APP_KEY; losing it loses access to encrypted data. Never commit .env or authentication logs.
4. Configure a real mail transport, supervised durable queue worker and scheduler. Monitor failed jobs and audit/security events; never ship private exception/request bodies to telemetry.
5. Configure a patched malware scanner with maintained signatures; limit request size at the web server and PHP too. The app allows three files up to 5 MB each, subject to server limits.
6. Protect storage and backups with encryption, restricted filesystem access, documented retention and tested restoration. No backup service or disk-encryption policy has been installed by this code.
7. Complete Jordan privacy/legal review, identify the operator/contact, set response deadlines and implement verified fulfillment of access/correction/erasure requests. Current queues acknowledge requests only.
8. If enabling external AI, verify its data-processing/training/retention terms, finish PII review, perform provider-specific integration tests and adversarial model evaluations. Regex redaction and delimiters are not complete prompt-injection prevention.
9. Provide authoritative governorate/municipality boundaries before automatic correction. Keep ambiguous location, feasibility and jurisdiction human-reviewed.
10. Real government delivery needs an approved integration, endpoint allowlist, HMAC/timestamp/nonce verification, receiver idempotency, retries and privacy-approved payloads. None is silently simulated as a live integration.
11. Add risk-triggered challenges/CAPTCHA, stronger distributed abuse detection, external alerting and independent penetration testing as usage grows. Device tracking is deliberately not collected by default.
12. Run composer audit and dependency patching routinely. Existing npm/Vite scaffolding is not used by the implemented views; audit/build it separately if reintroduced.
13. Replace the local-only citizen registry with an approved identity-provider integration and verified contact/consent process. Local SMS OTPs are simulated in storage/logs/sms.log and do not prove access to a real handset, not official government identity assurance. There is no universal test code. Local SMS and mail logs contain secrets; do not publish them. Rotate identity HMAC keys only through a controlled data migration. Read-only checks on 2026-10-10 returned OK for the pilot tables and previously problematic XAMPP table-level grant table; this does not establish production fitness, patch currency or recoverability. Verify backups/restoration and use supported database infrastructure before production.

The `moshkelti:security-check` command reports configuration blockers but does not replace these operational reviews.

## Source references

- [Laravel authorization](https://laravel.com/docs/13.x/authorization) and [queues](https://laravel.com/docs/13.x/queues).
- [OWASP prompt injection guidance](https://genai.owasp.org/llmrisk/llm01-prompt-injection/).
- [OWASP file upload guidance](https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html).
- Emergency guidance uses Jordan's officially published [Public Security Directorate emergency contact](https://psd.gov.jo/en-us/civil-defense/contact-us/). It provides information only and does not contact responders.
