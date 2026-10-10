[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)][string]$CitizenHost,
    [string]$GitHubUser='Coder-511',
    [string]$RepositoryName='moshkelti-citizen-android-pilot',
    [switch]$UpdateExisting
)
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$runtime = Join-Path $projectRoot 'storage/app/citizen-pilot'
$client = Join-Path $runtime 'android-repository'
$ghFolder = Join-Path $runtime 'github-cli'
$gh = Join-Path $ghFolder 'bin/gh.exe'
$caFile = Join-Path $runtime 'caddy-data/pki/authorities/local/root.crt'
$origin = 'https://' + $CitizenHost + ':8443'
$ip = $null
if (![Net.IPAddress]::TryParse($CitizenHost,[ref]$ip) -or $ip.AddressFamily -ne [Net.Sockets.AddressFamily]::InterNetwork -or
    $CitizenHost -notmatch '^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.)') { throw 'Invalid private IPv4 origin.' }
if ($RepositoryName -notmatch '^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,80}$') { throw 'Invalid repository name.' }
if ($GitHubUser -notmatch '^[a-zA-Z0-9-]+$') { throw 'Invalid GitHub account name.' }
if (!(Test-Path -LiteralPath $caFile)) { throw 'Prepare the HTTPS public CA first.' }
if ((Get-PSDrive C).Free -lt 12GB) { throw 'Download paused: retain 12 GB free.' }
if ((Get-ChildItem $runtime -File -Recurse | Measure-Object Length -Sum).Sum -gt 400MB) { throw 'Inspect pilot storage before adding artifacts.' }
$clientSource = Join-Path $projectRoot 'android'
$sourceFiles = @(Get-ChildItem -LiteralPath $clientSource -Recurse -Force -File)
if (($sourceFiles | Measure-Object Length -Sum).Sum -gt 10MB) { throw 'Client export exceeds 10 MB; inspect local build output before copying.' }
if ($sourceFiles | Where-Object { $_.Name -match '^\.env' -or $_.Extension -match '^\.(php|sql|log|jks|keystore|key|p12|pem|crt)$' }) {
    throw 'Forbidden backend source or sensitive material in Android directory; no upload performed.'
}
if (!(Test-Path -LiteralPath $gh)) {
    $archive = Join-Path $runtime 'gh_2.102.0_windows_amd64.zip'
    if (!(Test-Path -LiteralPath $archive)) {
        Invoke-WebRequest -UseBasicParsing -Uri 'https://github.com/cli/cli/releases/download/v2.102.0/gh_2.102.0_windows_amd64.zip' -OutFile $archive -TimeoutSec 120
    }
    if ((Get-Item -LiteralPath $archive).Length -gt 25MB -or (Get-PSDrive C).Free -lt 12GB) { throw 'CLI download exceeds storage budget.' }
    if ((Get-FileHash -LiteralPath $archive -Algorithm SHA256).Hash.ToLowerInvariant() -ne 'ae64e556ecc240b200f7eba60d550e4bb60d78e860e69dd88c449405b86067f4') { throw 'GitHub CLI checksum mismatch.' }
    Expand-Archive -LiteralPath $archive -DestinationPath $ghFolder
}
$previousToken = $env:GH_TOKEN
$previousDebug = $env:GH_DEBUG
$previousPrompt = $env:GIT_TERMINAL_PROMPT
$previousInteractive = $env:GCM_INTERACTIVE
try {
    $env:GH_DEBUG = $null
    $env:GIT_TERMINAL_PROMPT='0'
    $env:GCM_INTERACTIVE='never'
    if (!$env:GH_TOKEN) {
        $lines = "protocol=https`nhost=github.com`nusername=$GitHubUser`n`n" | git credential fill 2>$null
        foreach ($line in $lines) {
            if ($line.StartsWith('password=')) { $env:GH_TOKEN = $line.Substring(9) }
        }
    }
    if (!$env:GH_TOKEN) { throw 'Connect an authenticated GitHub account. Never paste a token in chat.' }
    $owner = & $gh api user --jq '.login'
    if ($LASTEXITCODE -ne 0 -or $owner -ne $GitHubUser) { throw 'GitHub account lookup did not match the requested account.' }
    $repo = $owner + '/' + $RepositoryName
    if ($UpdateExisting) {
        if (!(Test-Path -LiteralPath (Join-Path $client '.git'))) { throw 'No isolated repository exists to update.' }
        $remote = git -C $client remote get-url origin
        if ($remote -ne ('https://github.com/'+$repo+'.git')) { throw 'Unexpected client repository remote; no upload performed.' }
        $private = & $gh api ('repos/'+$repo) --jq '.private'
        if ($LASTEXITCODE -ne 0 -or $private -ne 'true') { throw 'The client repository must remain private.' }
    } elseif (Test-Path -LiteralPath $client) { throw 'Client export already exists; inspect it or use -UpdateExisting for this exact private repository.' }
    New-Item -ItemType Directory -Path $client -Force | Out-Null
    foreach ($entry in @('app','.github','.gitignore','settings.gradle','build.gradle','gradle.properties','README.md')) {
        Copy-Item -LiteralPath (Join-Path $projectRoot ('android/'+$entry)) -Destination $client -Recurse -Force
    }
    foreach ($forbidden in @('artisan','.env','composer.json','composer.lock','vendor','storage','bootstrap','database','routes')) {
        if (Test-Path -LiteralPath (Join-Path $client $forbidden)) { throw 'Backend content found in isolated client export; no upload performed.' }
    }
    if (!(Test-Path -LiteralPath (Join-Path $client '.git'))) {
        git -C $client init --initial-branch=main
        git -C $client config user.name 'Moshkelti pilot build'
        git -C $client config user.email ($owner+'@users.noreply.github.com')
    }
    git -C $client config credential.https://github.com.username $GitHubUser
    git -C $client add --all
    $tracked = git -C $client ls-files
    if ($tracked -match '(^|/)(\.env[^/]*|[^/]*\.(jks|keystore|key|p12|sql|log|pem|crt))$') { throw 'Forbidden sensitive file staged; no upload performed.' }
    git -C $client diff --cached --quiet
    if ($LASTEXITCODE -eq 1) {
        git -C $client commit -m 'Implement secure citizen-only WebView pilot and remote signed build'
        if ($LASTEXITCODE -ne 0) { throw 'Client commit failed.' }
    }
    if (!$UpdateExisting) {
        & $gh repo create $repo --private --disable-issues --disable-wiki --source $client --remote origin --push --description 'Synthetic-data citizen-only Moshkelti Android pilot. No Laravel backend or citizen data.'
        if ($LASTEXITCODE -ne 0) { throw 'Private repository creation failed. Nothing was uploaded to the main application repository.' }
    } else {
        git -C $client push origin main
        if ($LASTEXITCODE -ne 0) { throw 'Client-only push failed.' }
    }
    $store = Join-Path $runtime 'pilot-signing.p12'
    $passwordFile = Join-Path $runtime 'signing-password.dpapi'
    if ((Test-Path -LiteralPath $store) -ne (Test-Path -LiteralPath $passwordFile)) { throw 'Incomplete signing backup; do not replace the existing key.' }
    if (!(Test-Path -LiteralPath $store)) {
        $randomBytes = New-Object byte[] 48
        $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
        $rng.GetBytes($randomBytes); $rng.Dispose()
        $password = [Convert]::ToBase64String($randomBytes)
        $rsa = [Security.Cryptography.RSACng]::new(2048)
        $request = [Security.Cryptography.X509Certificates.CertificateRequest]::new('CN=Moshkelti Citizen Pilot',$rsa,[Security.Cryptography.HashAlgorithmName]::SHA256,[Security.Cryptography.RSASignaturePadding]::Pkcs1)
        $cert = $request.CreateSelfSigned([DateTimeOffset]::UtcNow.AddMinutes(-5),[DateTimeOffset]::UtcNow.AddYears(25))
        $cert.FriendlyName='moshkelti-pilot'
        [IO.File]::WriteAllBytes($store,$cert.Export([Security.Cryptography.X509Certificates.X509ContentType]::Pkcs12,$password))
        $password | ConvertTo-SecureString -AsPlainText -Force | ConvertFrom-SecureString | Set-Content -LiteralPath $passwordFile -Encoding ASCII
        $cert.Dispose(); $rsa.Dispose()
    } else {
        $secure = (Get-Content -LiteralPath $passwordFile -Raw).Trim() | ConvertTo-SecureString
        $password = [Net.NetworkCredential]::new('', $secure).Password
    }
    $values = @{
        SIGNING_STORE_BASE64=[Convert]::ToBase64String([IO.File]::ReadAllBytes($store));
        SIGNING_STORE_PASSWORD=$password; SIGNING_KEY_PASSWORD=$password; SIGNING_KEY_ALIAS='moshkelti-pilot'
    }
    foreach ($key in $values.Keys) {
        $values[$key] | & $gh secret set $key --repo $repo
        if ($LASTEXITCODE -ne 0) { throw ('Could not store protected build secret '+$key) }
    }
    & $gh variable set CITIZEN_ORIGIN --repo $repo --body $origin
    if ($LASTEXITCODE -ne 0) { throw 'Could not configure citizen origin.' }
    $publicCa = [Convert]::ToBase64String([IO.File]::ReadAllBytes($caFile))
    & $gh variable set PILOT_CA_BASE64 --repo $repo --body $publicCa
    if ($LASTEXITCODE -ne 0) { throw 'Could not configure public CA.' }
    & $gh workflow run build-apk.yml --repo $repo --ref main
    if ($LASTEXITCODE -ne 0) { throw 'Remote build dispatch failed; inspect private repository Actions.' }
    Write-Output ('Private Android-only repository: https://github.com/'+$repo)
    Write-Output 'Remote signed build requested. HTTPS private keys, Laravel source and citizen data were not uploaded.'
} finally {
    $env:GH_TOKEN=$previousToken
    $env:GH_DEBUG=$previousDebug
    $env:GIT_TERMINAL_PROMPT=$previousPrompt
    $env:GCM_INTERACTIVE=$previousInteractive
}
