[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)][string]$CitizenHost,
    [switch]$PrepareCertificateOnly
)
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$runtime = Join-Path $projectRoot 'storage/app/citizen-pilot'
$caddy = Join-Path $runtime 'caddy.exe'
$configFile = Join-Path $PSScriptRoot 'Citizen.Caddyfile'
$stateFile = Join-Path $runtime 'processes.json'
$origin = 'https://' + $CitizenHost + ':8443'
$address = $null
if (![Net.IPAddress]::TryParse($CitizenHost, [ref]$address) -or $address.AddressFamily -ne [Net.Sockets.AddressFamily]::InterNetwork -or
    $CitizenHost -notmatch '^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.)') { throw 'Use an explicit private Wi-Fi IPv4 address.' }
if (!(Get-NetIPAddress -AddressFamily IPv4 -ErrorAction Stop | Where-Object IPAddress -eq $CitizenHost)) { throw 'This address is not assigned to this PC.' }
if ((Get-PSDrive C).Free -lt 12GB) { throw 'Keep at least 12 GB free before starting the pilot.' }
if (!(Test-Path -LiteralPath $caddy)) { throw 'Run Install-CitizenProxy.ps1 first.' }
if (Test-Path -LiteralPath (Join-Path $runtime 'config.php')) { throw 'Remove the unused pilot config cache deliberately before starting; cached citizen mode must not be reused.' }
$php = (Get-Command php -ErrorAction Stop).Source
$saved = @{}
$variables = @{
    MOSHKELTI_CITIZEN_ONLY='true'; MOSHKELTI_CITIZEN_ORIGIN=$origin; APP_URL=$origin; APP_DEBUG='false';
    APP_CONFIG_CACHE=(Join-Path $runtime 'config.php'); CITIZEN_HOST=$CitizenHost; PILOT_DATA=(Join-Path $runtime 'caddy-data')
}
try {
    foreach ($key in $variables.Keys) {
        $saved[$key] = [Environment]::GetEnvironmentVariable($key, 'Process')
        [Environment]::SetEnvironmentVariable($key, $variables[$key], 'Process')
    }
    & $caddy validate --config $configFile --adapter caddyfile
    if ($LASTEXITCODE -ne 0) { throw 'HTTPS configuration validation failed.' }
    $publicRoot = Join-Path $runtime 'caddy-data/pki/authorities/local/root.crt'
    if (!(Test-Path -LiteralPath $publicRoot)) { throw 'Public CA was not generated; do not substitute or bypass TLS.' }
    Write-Output ('Public CA for APK trust: ' + $publicRoot)
    Write-Output ('Citizen origin: ' + $origin)
    if ($PrepareCertificateOnly) { return }
    if (Test-Path -LiteralPath $stateFile) { throw 'Pilot state already exists. Run Stop-CitizenPilot.ps1 or inspect the recorded processes.' }
    foreach ($port in @(8001,8443,2021)) {
        if (Get-NetTCPConnection -State Listen -LocalPort $port -ErrorAction SilentlyContinue) { throw "Port $port is already occupied; no existing process was stopped." }
    }
    & $php (Join-Path $projectRoot 'artisan') migrate:status --no-interaction
    if ($LASTEXITCODE -ne 0) { throw 'Database is unavailable or schema is incomplete. Start the configured database deliberately; no database service was started automatically.' }
    $router = Join-Path $projectRoot 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'
    $webProcess = Start-Process -FilePath $php -ArgumentList @('-d','upload_max_filesize=5M','-d','post_max_size=16M','-S','127.0.0.1:8001','-t',('"'+(Join-Path $projectRoot 'public')+'"'),('"'+$router+'"')) -WorkingDirectory (Join-Path $projectRoot 'public') -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $runtime 'web.stdout.log') -RedirectStandardError (Join-Path $runtime 'web.stderr.log')
    $proxyProcess = $null
    try {
        $proxyProcess = Start-Process -FilePath $caddy -ArgumentList @('run','--config',('"'+$configFile+'"'),'--adapter','caddyfile') -WorkingDirectory $projectRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $runtime 'proxy.stdout.log') -RedirectStandardError (Join-Path $runtime 'proxy.stderr.log')
        Start-Sleep -Milliseconds 1500
        $webProcess.Refresh(); $proxyProcess.Refresh()
        if ($webProcess.HasExited -or $proxyProcess.HasExited) { throw 'Pilot startup failed. Inspect private runtime logs.' }
        $state = @{web=@{pid=$webProcess.Id; start=$webProcess.StartTime.ToUniversalTime().Ticks}; proxy=@{pid=$proxyProcess.Id; start=$proxyProcess.StartTime.ToUniversalTime().Ticks}}
        $state | ConvertTo-Json -Depth 3 | Set-Content -LiteralPath $stateFile -Encoding UTF8
    } catch {
        if ($proxyProcess -and !$proxyProcess.HasExited) { & $caddy stop --address 127.0.0.1:2021 }
        if (!$webProcess.HasExited) { Stop-Process -Id $webProcess.Id }
        throw
    }
    Write-Output 'Citizen HTTPS pilot started. Desktop/admin services and database were not changed.'
    Write-Output 'Run the existing queue worker separately for report analysis. Stop this pilot with Stop-CitizenPilot.ps1.'
} finally {
    foreach ($key in $saved.Keys) { [Environment]::SetEnvironmentVariable($key, $saved[$key], 'Process') }
}
