[CmdletBinding()]
param([Parameter(Mandatory=$true)][string]$CitizenHost)
$ErrorActionPreference = 'Stop'
$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = New-Object Security.Principal.WindowsPrincipal($identity)
if (!$principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) { throw 'Run this reviewed script in an Administrator PowerShell window, only after approving this trusted Wi-Fi network.' }
$address = $null
if (![Net.IPAddress]::TryParse($CitizenHost, [ref]$address) -or $address.AddressFamily -ne [Net.Sockets.AddressFamily]::InterNetwork -or
    $CitizenHost -notmatch '^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.)') { throw 'Use the verified private Wi-Fi IPv4 address.' }
$adapterAddress = Get-NetIPAddress -InterfaceAlias 'Wi-Fi' -AddressFamily IPv4 | Where-Object IPAddress -eq $CitizenHost
if (!$adapterAddress) { throw 'This address is not assigned to Wi-Fi. No settings changed.' }
$projectRoot = Split-Path -Parent $PSScriptRoot
$caddyPath = (Resolve-Path -LiteralPath (Join-Path $projectRoot 'storage/app/citizen-pilot/caddy.exe')).Path
$ruleName = 'Moshkelti-Citizen-Pilot-8443'
if (Get-NetFirewallRule -Name $ruleName -ErrorAction SilentlyContinue) { throw 'The named rule already exists. Inspect it explicitly before replacing it; no settings changed.' }
Set-NetConnectionProfile -InterfaceIndex $adapterAddress.InterfaceIndex -NetworkCategory Private
New-NetFirewallRule -Name $ruleName -DisplayName 'Moshkelti citizen pilot HTTPS (private Wi-Fi only)' -Direction Inbound -Action Allow -Enabled True -Profile Private -Program $caddyPath -Protocol TCP -LocalPort 8443 -LocalAddress $CitizenHost -RemoteAddress LocalSubnet -InterfaceAlias 'Wi-Fi' | Select-Object Name,Enabled,Profile,Direction,Action
Write-Output 'Only the verified Caddy executable may receive pilot HTTPS traffic on private Wi-Fi from the local subnet. Other service ports were not opened.'
