param([switch]$Setup, [switch]$Hardware, [switch]$CustomerControl)
$ErrorActionPreference = 'Stop'
$project = Split-Path -Parent $PSScriptRoot
$php = 'C:\wamp64\bin\php\php8.2.29\php.exe'
$runtime = Join-Path $project '.local-acceptance'
$phpOptions = @('-d', 'xdebug.mode=off', '-d', 'disable_functions=curl_exec,curl_multi_exec', '-d', 'allow_url_fopen=0')
Push-Location -LiteralPath $project
try {
    if ($CustomerControl -and -not $Hardware) { throw '-CustomerControl requires -Hardware.' }
    $previousHardware = $env:LIGHTS_HARDWARE_COMMISSIONING
    $previousCustomer = $env:LIGHTS_CUSTOMER_HARDWARE_ACCEPTANCE
    $env:LIGHTS_HARDWARE_COMMISSIONING = if ($Hardware) { '1' } else { '0' }
    $env:LIGHTS_CUSTOMER_HARDWARE_ACCEPTANCE = if ($CustomerControl) { '1' } else { '0' }
    & "$PSScriptRoot\start-local-acceptance.ps1" -RestartWeb
    if ($Setup) {
        & $php @phpOptions scripts/local-acceptance/lights-setup.php
        if ($LASTEXITCODE -ne 0) { throw 'Lights setup failed; worker not started.' }
    }
    $pidPath = Join-Path $runtime 'lights-worker.pid'
    if (Test-Path -LiteralPath $pidPath) {
        $oldPid = Get-Content -LiteralPath $pidPath -Raw
        if ($oldPid -match '^\d+$') {
            $oldProcess = Get-CimInstance Win32_Process -Filter "ProcessId = $oldPid" -ErrorAction SilentlyContinue
            if ($oldProcess -and $oldProcess.ExecutablePath -eq $php -and $oldProcess.CommandLine -match 'scripts[/\\]local-acceptance[/\\]lights-worker\.php') {
                & $php @phpOptions scripts/local-acceptance/lights-worker.php --stop
                for ($attempt = 0; $attempt -lt 450; $attempt++) {
                    if (-not (Get-Process -Id ([int]$oldPid) -ErrorAction SilentlyContinue)) { break }
                    Start-Sleep -Milliseconds 100
                }
                if (Get-Process -Id ([int]$oldPid) -ErrorAction SilentlyContinue) { throw 'Existing Lights worker did not stop safely.' }
            }
        }
    }
    # The PHP worker holds an exclusive OS file lock; duplicate launches exit safely.
    $launchId = [guid]::NewGuid().ToString('N')
    Start-Process -FilePath $php -WindowStyle Hidden -WorkingDirectory $project -ArgumentList ($phpOptions + @('scripts/local-acceptance/lights-worker.php')) -RedirectStandardOutput "$runtime\lights-worker-$launchId.log" -RedirectStandardError "$runtime\lights-worker-$launchId.error.log" | Out-Null
    $healthy = $false
    for ($attempt = 0; $attempt -lt 10; $attempt++) {
        & $php @phpOptions scripts/local-acceptance/lights-worker.php --status
        if ($LASTEXITCODE -eq 0) { $healthy = $true; break }
        Start-Sleep -Milliseconds 300
    }
    if (-not $healthy) { throw 'Lights worker is not healthy. Inspect the lights-worker logs in .local-acceptance.' }
    $mode = if ($CustomerControl) { 'customer hardware acceptance' } elseif ($Hardware) { 'admin hardware commissioning; customer portal remains simulated' } else { 'simulation only' }
    Write-Output "Lights portal: http://127.0.0.1:8097/lights/ ($mode)."
} finally {
    if ($null -eq $previousHardware) { Remove-Item Env:LIGHTS_HARDWARE_COMMISSIONING -ErrorAction SilentlyContinue } else { $env:LIGHTS_HARDWARE_COMMISSIONING = $previousHardware }
    if ($null -eq $previousCustomer) { Remove-Item Env:LIGHTS_CUSTOMER_HARDWARE_ACCEPTANCE -ErrorAction SilentlyContinue } else { $env:LIGHTS_CUSTOMER_HARDWARE_ACCEPTANCE = $previousCustomer }
    Pop-Location
}
