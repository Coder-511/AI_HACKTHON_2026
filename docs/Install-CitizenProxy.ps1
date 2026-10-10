[CmdletBinding()]
param()
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$runtime = Join-Path $projectRoot 'storage/app/citizen-pilot'
if ((Get-PSDrive C).Free -lt 12GB) { throw 'Download paused: keep at least 12 GB free.' }
New-Item -ItemType Directory -Path $runtime -Force | Out-Null
$currentIdentity = [Security.Principal.WindowsIdentity]::GetCurrent().Name
& icacls.exe $runtime /inheritance:r /grant:r "${currentIdentity}:(OI)(CI)F" 'SYSTEM:(OI)(CI)F' | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Could not secure pilot certificate storage permissions.' }
$artifactName = 'caddy_2.11.7_windows_amd64.zip'
$archive = Join-Path $runtime $artifactName
$downloadUrl = 'https://github.com/caddyserver/caddy/releases/download/v2.11.7/' + $artifactName
# SHA-512 from the official release checksum manifest, verified during implementation.
$expected = 'c308154504e53755958ffc62d8a2657ab17eefd6dd25c45dbb2c3d93d29519971f77ca652f7d0f2bcb7f980b9ba3822478865e29969d0272d7613550a43152b1'
$existingSize = (Get-ChildItem $runtime -Recurse -File | Measure-Object Length -Sum).Sum
if ($existingSize -gt 400MB) { throw 'Pilot artifact storage is too large; inspect it before downloading.' }
if (!(Test-Path -LiteralPath $archive)) {
    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
    Invoke-WebRequest -UseBasicParsing -Uri $downloadUrl -OutFile $archive -TimeoutSec 120
}
if ((Get-PSDrive C).Free -lt 12GB -or (Get-Item -LiteralPath $archive).Length -gt 25MB) { throw 'Download exceeds the pilot storage budget.' }
if ((Get-FileHash -LiteralPath $archive -Algorithm SHA512).Hash.ToLowerInvariant() -ne $expected) { throw 'Caddy archive verification failed. Do not run it.' }
if (!(Test-Path -LiteralPath (Join-Path $runtime 'caddy.exe'))) {
    Expand-Archive -LiteralPath $archive -DestinationPath $runtime
}
& (Join-Path $runtime 'caddy.exe') version
if ($LASTEXITCODE -ne 0) { throw 'Verified Caddy could not run.' }
Write-Output 'Portable proxy installed. No service, firewall port, or system certificate trust was enabled.'
