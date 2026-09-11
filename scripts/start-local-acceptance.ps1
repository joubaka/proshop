param([switch]$Initialize, [switch]$RestartWeb)
$ErrorActionPreference = 'Stop'
$project = Split-Path -Parent $PSScriptRoot
$runtime = Join-Path $project '.local-acceptance'
$mysqlBase = 'C:\wamp64\bin\mysql\mysql8.4.7'
$php = 'C:\wamp64\bin\php\php8.2.29\php.exe'
function Test-AcceptancePort([int]$Port) {
    $client = [System.Net.Sockets.TcpClient]::new()
    try { $client.Connect('127.0.0.1', $Port); return $true } catch { return $false } finally { $client.Dispose() }
}
$data = Join-Path $runtime 'mysql'
New-Item -ItemType Directory -Path $data -Force | Out-Null
if (-not (Test-Path -LiteralPath (Join-Path $data 'mysql'))) {
    if (-not $Initialize) { throw 'First run requires -Initialize to create the separate test database.' }
    & "$mysqlBase\bin\mysqld.exe" --no-defaults --initialize-insecure "--basedir=$mysqlBase" "--datadir=$data" --console
    if ($LASTEXITCODE -ne 0) { throw 'Acceptance MySQL initialization failed.' }
}
if (-not (Test-AcceptancePort 13317)) {
    Start-Process -FilePath "$mysqlBase\bin\mysqld.exe" -WindowStyle Hidden -WorkingDirectory $project -ArgumentList @(
        '--no-defaults', "--basedir=`"$mysqlBase`"", "--datadir=`"$data`"", '--port=13317',
        '--bind-address=127.0.0.1', '--mysqlx=OFF', '--skip-log-bin', '--local-infile=OFF', '--secure-file-priv=NULL', '--console'
    ) -RedirectStandardOutput "$runtime\mysql-stdout.log" -RedirectStandardError "$runtime\mysql-stderr.log" | Out-Null
}
$ready = $false
for ($attempt = 0; $attempt -lt 30; $attempt++) {
    if (Test-AcceptancePort 13317) { $ready = $true; break }
    Start-Sleep -Milliseconds 500
}
if (-not $ready) { throw 'Acceptance MySQL did not start; inspect .local-acceptance/mysql-stderr.log.' }
$phpOptions = @('-d', 'xdebug.mode=off', '-d', 'disable_functions=curl_exec,curl_multi_exec', '-d', 'allow_url_fopen=0')
Push-Location -LiteralPath $project
try {
    if ($Initialize) {
        & $php @phpOptions scripts/local-acceptance/console.php migrate
        if ($LASTEXITCODE -ne 0) { throw 'Acceptance migrations failed.' }
        & $php @phpOptions scripts/local-acceptance/console.php seed
        if ($LASTEXITCODE -ne 0) { throw 'Acceptance fixtures failed.' }
    }
    & $php @phpOptions scripts/local-acceptance/console.php check
    if ($LASTEXITCODE -ne 0) { throw 'Acceptance database identity/health check failed. No shop database was selected.' }
    if ($RestartWeb -and (Test-AcceptancePort 8097)) {
        # Restart only the verified loopback demo HTTP listener, never MySQL or accounting workers.
        $currentHealth = Invoke-RestMethod -Uri 'http://127.0.0.1:8097/__local_acceptance_health' -TimeoutSec 3
        if ($currentHealth.environment -ne 'local-acceptance' -or $currentHealth.database -ne 'proshop_acceptance') {
            throw 'Refusing to restart an unrecognized service on port 8097.'
        }
        $listeners = @(Get-NetTCPConnection -LocalPort 8097 -State Listen | Where-Object { $_.LocalAddress -eq '127.0.0.1' })
        if ($listeners.Count -ne 1) { throw 'Expected exactly one loopback demo listener.' }
        $webProcessId = $listeners[0].OwningProcess
        $webProcess = Get-CimInstance Win32_Process -Filter "ProcessId = $webProcessId"
        if ($webProcess.ExecutablePath -ne $php -or $webProcess.CommandLine -notmatch '-S\s+"?127\.0\.0\.1:8097"?\s' -or
            $webProcess.CommandLine -notmatch 'scripts/local-acceptance/router\.php"?\s*$') {
            throw 'Refusing to stop a process that is not the expected local acceptance PHP server.'
        }
        Stop-Process -Id $webProcessId -Force -ErrorAction Stop
        for ($attempt = 0; $attempt -lt 20; $attempt++) {
            if (-not (Test-AcceptancePort 8097)) { break }
            Start-Sleep -Milliseconds 100
        }
        if (Test-AcceptancePort 8097) { throw 'The local web port did not become available.' }
    }
    if (-not (Test-AcceptancePort 8097)) {
        Start-Process -FilePath $php -WindowStyle Hidden -WorkingDirectory $project -ArgumentList ($phpOptions + @(
            '-S', '127.0.0.1:8097', '-t', 'public', 'scripts/local-acceptance/router.php'
        )) -RedirectStandardOutput "$runtime\web-stdout.log" -RedirectStandardError "$runtime\web-stderr.log" | Out-Null
    }
    $webReady = $false
    for ($attempt = 0; $attempt -lt 10; $attempt++) {
        try {
            $health = Invoke-RestMethod -Uri 'http://127.0.0.1:8097/__local_acceptance_health' -TimeoutSec 2
            if ($health.environment -eq 'local-acceptance' -and $health.database -eq 'proshop_acceptance') { $webReady = $true; break }
        } catch { }
        Start-Sleep -Milliseconds 300
    }
    if (-not $webReady) { throw 'Port 8097 is not serving the verified acceptance sandbox. No other process was stopped.' }
    Write-Output 'Local test portal: http://127.0.0.1:8097/login (synthetic data only).'
} finally { Pop-Location }
