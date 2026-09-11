param(
    [ValidateSet('All', 'Regression', 'Audit')]
    [string]$Suite = 'All',
    [string]$PhpPath = ''
)

$ErrorActionPreference = 'Stop'
$projectPath = Split-Path -Parent $PSScriptRoot
if (-not $PhpPath) {
    $bundledPhp = 'C:\wamp64\bin\php\php8.2.29\php.exe'
    $PhpPath = if (Test-Path -LiteralPath $bundledPhp) { $bundledPhp } else { (Get-Command php -ErrorAction Stop).Source }
}
if (-not (Test-Path -LiteralPath (Join-Path $projectPath 'vendor/phpunit/phpunit/phpunit'))) {
    throw 'PHPUnit is missing. Install the project development dependencies before running tests.'
}
$arguments = @('-d', 'xdebug.mode=off', 'vendor/phpunit/phpunit/phpunit', '--do-not-cache-result')
if ($Suite -eq 'Regression') { $arguments += @('--testsuite', 'Unit,Feature') }
if ($Suite -eq 'Audit') { $arguments += @('--testsuite', 'Audit') }

Push-Location -LiteralPath $projectPath
try {
    & $PhpPath @arguments
    $testExitCode = $LASTEXITCODE
} finally {
    Pop-Location
}
exit $testExitCode
