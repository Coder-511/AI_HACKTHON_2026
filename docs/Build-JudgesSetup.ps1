[CmdletBinding()]
param()
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$outputFile = Join-Path $PSScriptRoot 'Moshkelti_Judges_Clone_and_Run.docx'
Add-Type -AssemblyName System.IO.Compression.FileSystem
Add-Type -AssemblyName System.IO.Compression
$script:paragraphs = New-Object 'Collections.Generic.List[string]'
$script:pages = 1
function Xml-Text([string]$value) { return [Security.SecurityElement]::Escape($value) }
& {
    function Add-Text([string]$value, [int]$size=10, [bool]$bold=$false, [string]$font='Calibri') {
        $spacing=if($font -eq 'Consolas'){20}else{70}
        $weight=if($bold){'<w:b/><w:color w:val="173F35"/>'}else{''}
        $keepNext=if($bold){'<w:keepNext/>'}else{''}
        $runs=@($value -split '\r?\n' | ForEach-Object {
            '<w:r><w:rPr><w:rFonts w:ascii="'+$font+'" w:hAnsi="'+$font+'"/><w:sz w:val="'+($size*2)+'"/>'+$weight+'</w:rPr><w:t xml:space="preserve">'+(Xml-Text $_)+'</w:t></w:r>'
        }) -join '<w:r><w:br/></w:r>'
        $script:paragraphs.Add('<w:p><w:pPr><w:spacing w:after="'+$spacing+'"/><w:keepLines w:val="0"/><w:widowControl w:val="0"/>'+$keepNext+'</w:pPr>'+$runs+'</w:p>')
    }
    function Heading([string]$value) { Add-Text $value 16 $true }
    function Body([string]$value) { Add-Text $value }
    function Code([string]$value) { Add-Text $value 9 $false 'Consolas' }
    function New-Page {
        $script:paragraphs.Add('<w:p><w:r><w:br w:type="page"/></w:r></w:p>')
        $script:pages++
    }

    Heading 'MOSHKELTI | CLONE & RUN FOR THE JUDGES'
    Body 'Windows + PowerShell | Fresh synthetic-data website demo | Checked: 10 October 2026'
    Body 'Use a NEW folder, NEW databases and port 8010. Leave your existing app, databases and Android pilot unchanged. Run one command at a time; stop at any error. Never run migrate:fresh, composer setup or composer update for this demo.'
    Heading '1. Check prerequisites and database'
    Code @'
Get-Command git, php, composer
php --version
php --ini
php -m
Get-NetTCPConnection -State Listen -LocalPort 3306 -ErrorAction SilentlyContinue
Get-NetTCPConnection -State Listen -LocalPort 8010 -ErrorAction SilentlyContinue
'@
    Body 'PHP must be 8.3+. Required here: pdo_mysql, mbstring, fileinfo, openssl, curl, gd, DOM/XML and pdo_sqlite for tests. Use the CLI PHP from Get-Command; XAMPP PHP may be older. Fix missing prerequisites before continuing.'
    Body 'If 3306 already has your database listener, do not start a second instance. Otherwise open XAMPP Control Panel and click Start beside MySQL once. Do not start Apache. Port 8010 must be free; do not kill an unknown process. These steps assume your database listens on 127.0.0.1:3306.'
    Heading '2. Clone and install locked dependencies'
    Code @'
Set-Location ([Environment]::GetFolderPath('Desktop'))
if (Test-Path 'Moshkelti-Judges') { throw 'Use a new empty demo folder.' }
git clone --branch main --single-branch https://github.com/Coder-511/AI_HACKTHON_2026.git Moshkelti-Judges
Set-Location .\Moshkelti-Judges
git log -1 --oneline
composer install --prefer-dist --no-interaction
composer check-platform-reqs
composer audit
if (!(Test-Path .env)) { Copy-Item .env.example .env }
php artisan key:generate --no-interaction
'@
    Body 'Reference commit checked: f293528. A later reviewed commit may differ. Authenticate through Git Credential Manager if prompted; never put a token in the clone URL. No npm install, Vite, Android SDK or storage:link is needed for this website.'
    Body 'Generate APP_KEY only once on this fresh clone. Never copy an existing app database into this clone with a different key.'

    New-Page
    Heading '3. Create separate demo databases and users'
    Body 'HIDE THE PROJECTOR for secrets. Run this generator FIVE times. Keep five independent outputs privately: SETUP_PASSWORD, APP_PASSWORD, REGISTRY_PASSWORD, IDENTITY_KEY, REGISTRY_KEY. Replace the uppercase placeholders below; do not use the placeholder words as passwords.'
    Code "php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'"
    Body 'Connect as your local database administrator (PowerShell). Enter its password at the prompt; press Enter only if your local root password is genuinely empty. Never put that password after -p.'
    Code '& C:\xampp\mysql\bin\mysql.exe -h 127.0.0.1 -P 3306 -u root -p'
    Body 'Run the following ONLY at the mysql/MariaDB prompt. If a database/user already exists, stop: do not delete it or reuse an unknown database. Adjust the client executable path only if XAMPP is installed elsewhere.'
    Code @'
CREATE DATABASE moshkelti_judges CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE moshkelti_registry_judges CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER 'msk_judges_setup'@'127.0.0.1' IDENTIFIED BY 'SETUP_PASSWORD';
GRANT ALL PRIVILEGES ON moshkelti_judges.* TO 'msk_judges_setup'@'127.0.0.1';
GRANT ALL PRIVILEGES ON moshkelti_registry_judges.* TO 'msk_judges_setup'@'127.0.0.1';

CREATE USER 'msk_judges_app'@'127.0.0.1' IDENTIFIED BY 'APP_PASSWORD';
GRANT SELECT, INSERT, UPDATE, DELETE ON moshkelti_judges.* TO 'msk_judges_app'@'127.0.0.1';

CREATE USER 'msk_judges_registry'@'127.0.0.1' IDENTIFIED BY 'REGISTRY_PASSWORD';
GRANT SELECT ON moshkelti_registry_judges.* TO 'msk_judges_registry'@'127.0.0.1';
exit
'@
    Body 'Setup permissions are limited to the two new databases. The running app will use a data-only account; its synthetic registry connection will be read-only. No global grants or application root login.'

    New-Page
    Heading '4. Configure .env privately'
    Body 'Back in PowerShell, from Desktop\Moshkelti-Judges, run: notepad .env. EDIT the existing values below; do not create duplicate lines. Keep the generated APP_KEY unchanged. Save and close the file. Preserve all three keys privately for this installation.'
    Code @'
APP_ENV=local
APP_DEBUG=false
APP_URL=http://localhost:8010
APP_LOCALE=ar
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=moshkelti_judges
DB_USERNAME=msk_judges_setup
DB_PASSWORD=SETUP_PASSWORD
IDENTITY_DRIVER=mock_database
IDENTITY_SMS_DRIVER=local
IDENTITY_LOOKUP_KEY=IDENTITY_KEY
REGISTRY_LOOKUP_KEY=REGISTRY_KEY
REGISTRY_DB_HOST=127.0.0.1
REGISTRY_DB_PORT=3306
REGISTRY_DB_DATABASE=moshkelti_registry_judges
REGISTRY_DB_USERNAME=msk_judges_setup
REGISTRY_DB_PASSWORD=SETUP_PASSWORD
SESSION_DRIVER=database
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=false
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
SESSION_DOMAIN=null
SESSION_COOKIE=moshkelti_judges_session
CACHE_STORE=database
QUEUE_CONNECTION=database
MAIL_MAILER=log
MOSHKELTI_AI_DRIVER=local
MOSHKELTI_CITIZEN_ONLY=false
MOSHKELTI_CITIZEN_ORIGIN=
'@
    Body 'SESSION_SECURE_COOKIE=false is only for this loopback HTTP demo. Do not expose this server to Wi-Fi or the internet. Do not add DB_URL or export environment overrides from another installation. The example keeps external AI off until step 9.'
    Heading '5. Create schema and synthetic seed data'
    Code @'
php artisan config:clear --no-interaction
php artisan migrate --no-interaction
php artisan migrate --database=registry --path=database/migrations/registry --no-interaction
php artisan db:seed --class=DatabaseSeeder --no-interaction
php artisan db:seed --class=MockCitizenRegistrySeeder --no-interaction
'@

    New-Page
    Heading '6. Switch to least-privilege runtime accounts'
    Body 'Privately open .env again. Replace only these four lines, then save:'
    Code @'
DB_USERNAME=msk_judges_app
DB_PASSWORD=APP_PASSWORD
REGISTRY_DB_USERNAME=msk_judges_registry
REGISTRY_DB_PASSWORD=REGISTRY_PASSWORD
'@
    Body 'Run in PowerShell from the clone root:'
    Code @'
php artisan config:clear --no-interaction
php artisan migrate:status --no-interaction
php artisan migrate:status --database=registry --path=database/migrations/registry --no-interaction
git check-ignore .env
'@
    Body 'Both migration lists must show Ran; git check-ignore must print .env. If anything fails, stop and correct credentials/schema. Do not run migrations using the read-only registry account.'
    Body 'Reconnect to the database as root using the command on page 2. Remove ONLY the temporary setup account, not a database or runtime user (SQL prompt):'
    Code @'
DROP USER 'msk_judges_setup'@'127.0.0.1';
exit
'@
    Heading '7. Create the administrator and verify tests'
    Code @'
php artisan moshkelti:create-admin judges-admin@example.test
'@
    Body 'This command is intentionally interactive. Enter a name and a unique password of 12+ characters, then confirm it. Password entry is hidden. There is no seeded admin password. Keep the password private.'
    Code 'php artisan test --compact'
    Body 'Keep the repository default phpunit.xml: tests use in-memory SQLite, not your demo data. Do not point tests at an existing MySQL database. If tests fail, read the error and stop; do not bypass checks. Passing tests do not prove model accuracy or complete security.'
    Body 'You can now restore projection. Do not open .env or private logs on the projected screen.'

    New-Page
    Heading '8. Start the website, worker and scheduler'
    Body 'Terminal A: from Desktop\Moshkelti-Judges, start the web server. Leave this terminal open.'
    Code @'
$demoRouter = (Resolve-Path vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php).Path
Push-Location public
php -d upload_max_filesize=5M -d post_max_size=16M -S 127.0.0.1:8010 -t . $demoRouter
Pop-Location
'@
    Body 'Terminal B: open a new PowerShell, navigate to the clone, and leave ONE queue worker running:'
    Code @'
Set-Location ([Environment]::GetFolderPath('Desktop'))
Set-Location .\Moshkelti-Judges
php artisan queue:work database --queue=default --sleep=2 --tries=3 --timeout=40 --no-interaction
'@
    Body 'Terminal C: navigate to the same clone, then run scheduled cleanup and retention previews:'
    Code 'php artisan schedule:work --no-interaction'
    Heading 'Check the citizen-to-review flow'
    Body '1. Open http://localhost:8010 in a clean citizen browser profile. Use localhost consistently, not a mixture of localhost and 127.0.0.1.'
    Body '2. Register: national ID 0000000001; mobile 0790000001. Both are synthetic. Read the latest code privately (projection OFF):'
    Code 'Get-Content storage/logs/sms.log -Tail 1'
    Body '3. Enter OTP within 5 minutes; create and confirm a 12+ character password. Citizen login afterward uses national ID + password. Local SMS does not reach a real phone.'
    Body '4. Submit an Amman roads report, pick its issue location on the map, and optionally attach a harmless JPEG/PNG/WebP (max 3; each max 5 MB). Use only synthetic text/photos. External-AI consent matters if Gemini is enabled.'
    Body '5. In a SECOND browser profile open http://localhost:8010/admin/login. Sign in with judges-admin@example.test and its password. Confirm the password; privately enroll the setup key in a TOTP authenticator, then enter its current code. Do not project the setup key or OTP.'
    Body '6. Review the report and recorded triage. In local mode the driver should be local, not Gemini. Select a matching approved Amman authority before accepting; record a reason and leave public publication off. If password confirmation interrupts a decision, return, review the latest version, then explicitly submit again.'
    Body '7. Refresh citizen tracking. Accepted/routed/acknowledged are workflow/simulated delivery, not a repaired road or official government receipt. Account restrictions must retain permitted read-only access.'

    New-Page
    Heading '9. Real Gemini: enable only with a working provider setup'
    Body 'Optional for initial setup; REQUIRED before claiming a real model demonstration. Hide projection. Privately edit .env:'
    Code @'
MOSHKELTI_AI_DRIVER=gemini
GEMINI_API_KEY=YOUR_PRIVATE_PROVIDER_KEY
GEMINI_MODEL=YOUR_TESTED_COMPATIBLE_MODEL_ID
MOSHKELTI_AI_DAILY_BUDGET=20
'@
    Body 'Use a model available to your provider project and compatible with this adapter (JSON schema + thinkingLevel). Do not assume .env.example model access. If your PHP needs a CA bundle, set GEMINI_CA_BUNDLE to a trusted CA file; never disable TLS verification. The cap is a call-count limit, not a cash spending guarantee.'
    Body 'Stop A/B/C with Ctrl+C first. From the clone root run the command below, then restart all three using page 5:'
    Code 'php artisan config:clear --no-interaction'
    Body 'Submit a NEW synthetic report with external-AI consent checked. Verify admin triage says gemini and validated before showing it as model output. Existing reports are not reanalysed by changing the setting. Provider failures go to human review; never label local/fallback output as Gemini.'
    Heading '10. Quick fixes: do not bypass security'
    Body 'Processing too long: confirm Terminal B is running from THIS clone; run php artisan queue:failed --no-interaction. Inspect storage/logs/laravel.log privately. Do not blindly retry dispatch jobs.'
    Body '500/database error: confirm one database server is running, both schemas exist and .env runtime passwords are correct. Clear config and restart workers. Do not regenerate keys or delete database logs.'
    Body '419/login loop: use localhost:8010 consistently, refresh the form, and check local secure-cookie=false. Use separate citizen/admin browser profiles. Do not disable CSRF or MFA.'
    Body '409/stale admin decision: reload the report, review its current data and submit the allowed decision again. Attachment expired: refresh the authorized report page.'
    Heading '11. Safe shutdown'
    Body 'Stop new submissions. Let the current analysis/dispatch finish, then press Ctrl+C in worker B, scheduler C and web server A. If a job is still active, wait for completion rather than force-killing PHP. Leave the databases and keys intact.'
    Body 'Stop MySQL through XAMPP ONLY after all apps/workers using that database server are stopped; wait until its running status clears before shutting down Windows. If it is a shared/pre-existing service, leave it running for its other users. Never use taskkill on mysqld or delete Aria/InnoDB log files.'
    Body 'Android boundary: a fresh clone has no ignored CA/private signing/runtime files. Do not point the existing APK at HTTP port 8010 or copy private keys to GitHub. Keep the already-configured Android pilot unchanged; a new phone deployment needs its own approved HTTPS origin/CA and matching APK build (docs/ANDROID_PILOT.md).'
    Body 'Pilot only: identity lookup, SMS and authority delivery are simulated. APP_DEBUG stays false; no real national IDs, no public exposure, no production-readiness claim.'

}
if ($script:pages -ne 6) { throw 'Expected six checklist sections separated by page breaks.' }
$documentXml='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'+($script:paragraphs -join '')+'<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="720" w:right="860" w:bottom="720" w:left="860" w:header="360" w:footer="360" w:gutter="0"/></w:sectPr></w:body></w:document>'
$parts=@{
    '[Content_Types].xml'='<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>'
    '_rels/.rels'='<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>'
    'word/document.xml'=$documentXml
}
$stream=[IO.File]::Open($outputFile,[IO.FileMode]::Create,[IO.FileAccess]::ReadWrite)
$archive=New-Object IO.Compression.ZipArchive($stream,[IO.Compression.ZipArchiveMode]::Create)
try {
    foreach($name in $parts.Keys){
        $null=[xml]$parts[$name]
        $entry=$archive.CreateEntry($name)
        $writer=New-Object IO.StreamWriter($entry.Open(),(New-Object Text.UTF8Encoding($false)))
        try { $writer.Write($parts[$name]) } finally { $writer.Dispose() }
    }
} finally { $archive.Dispose(); $stream.Dispose() }
'Created native editable DOCX: '+$outputFile
'Checklist sections: '+$script:pages
