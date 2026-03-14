param(
    [string]$WslDistro = "Debian",
    [string]$OutputDir = "",
    [switch]$SkipDependencyInstall
)

$ErrorActionPreference = "Stop"

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
if ([string]::IsNullOrWhiteSpace($OutputDir)) {
    $OutputDir = Join-Path $ScriptDir "out"
}

if (-not (Get-Command wsl.exe -ErrorAction SilentlyContinue)) {
    throw "WSL nincs telepítve. Telepítsd: wsl --install"
}

$distros = wsl.exe -l -q | ForEach-Object { $_.Trim() } | Where-Object { $_ -ne "" }
if ($distros -notcontains $WslDistro) {
    throw "A(z) '$WslDistro' WSL disztró nem található. Elérhetők: $($distros -join ', ')"
}

New-Item -ItemType Directory -Path $OutputDir -Force | Out-Null

$scriptDirForWsl = $ScriptDir -replace '\\', '/'
$outputDirForWsl = $OutputDir -replace '\\', '/'
$scriptDirWsl = (wsl.exe -d $WslDistro -- wslpath -a "$scriptDirForWsl").Trim()
$outputDirWsl = (wsl.exe -d $WslDistro -- wslpath -a "$outputDirForWsl").Trim()
$wslScript = "$scriptDirWsl/build-live-image-wsl.sh"
$installDeps = if ($SkipDependencyInstall) { "false" } else { "true" }

Write-Host "[i] WSL distro: $WslDistro"
Write-Host "[i] Source: $scriptDirWsl"
Write-Host "[i] Output: $outputDirWsl"

wsl.exe -d $WslDistro -- bash -lc "chmod +x '$wslScript' && '$wslScript' '$scriptDirWsl' '$outputDirWsl' '$installDeps'"

Write-Host "[✓] Kész. ISO kimenet: $OutputDir"
