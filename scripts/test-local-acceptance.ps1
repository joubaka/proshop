$ErrorActionPreference = 'Stop'
$project = Split-Path -Parent $PSScriptRoot
Push-Location -LiteralPath $project
try {
    & C:\wamp64\bin\php\php8.2.29\php.exe -d xdebug.mode=off -d disable_functions=curl_exec,curl_multi_exec -d allow_url_fopen=0 vendor/phpunit/phpunit/phpunit -c phpunit.acceptance.xml --do-not-cache-result
    $result = $LASTEXITCODE
} finally { Pop-Location }
exit $result
