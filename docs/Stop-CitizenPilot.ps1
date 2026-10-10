[CmdletBinding()]
param()
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$runtime = Join-Path $projectRoot 'storage/app/citizen-pilot'
$stateFile = Join-Path $runtime 'processes.json'
if (!(Test-Path -LiteralPath $stateFile)) { Write-Output 'No recorded citizen pilot processes.'; return }
$state = Get-Content -LiteralPath $stateFile -Raw | ConvertFrom-Json
$matched = @{}
foreach ($name in @('proxy','web')) {
    $record = $state.$name
    $process = Get-Process -Id $record.pid -ErrorAction SilentlyContinue
    if (!$process) { continue }
    if ($process.StartTime.ToUniversalTime().Ticks -ne $record.start) { throw 'Recorded PID was reused. No process was stopped.' }
    $details = Get-CimInstance Win32_Process -Filter ("ProcessId="+$record.pid)
    $expectedPath = if ($name -eq 'proxy') { Join-Path $runtime 'caddy.exe' } else { (Get-Command php).Source }
    if ($details.ExecutablePath -ne $expectedPath -or ($name -eq 'web' -and $details.CommandLine -notmatch '127\.0\.0\.1:8001')) { throw 'Recorded process identity does not match the pilot. No process was stopped.' }
    $matched[$name] = $process
}
if ($matched['proxy']) {
    & (Join-Path $runtime 'caddy.exe') stop --address 127.0.0.1:2021
    if ($LASTEXITCODE -ne 0) { throw 'Graceful proxy shutdown failed; do not shut down the PC yet.' }
    if (!$matched['proxy'].WaitForExit(15000)) { throw 'Proxy still draining. Wait before stopping the backend.' }
}
if ($matched['web']) { Stop-Process -Id $matched['web'].Id }
$resolvedState = (Resolve-Path -LiteralPath $stateFile).Path
if ($resolvedState -ne (Join-Path $projectRoot 'storage\app\citizen-pilot\processes.json')) { throw 'Unexpected state-file path.' }
Remove-Item -LiteralPath $resolvedState
Write-Output 'Stopped only recorded pilot web/proxy processes. Certificates were retained; database, desktop server and shared queue were not stopped.'
