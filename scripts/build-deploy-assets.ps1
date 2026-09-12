$ErrorActionPreference = 'Stop'
$project = Split-Path -Parent $PSScriptRoot
$archiveDirectory = Join-Path $project 'deployment'
$archive = Join-Path $archiveDirectory 'frontend-assets.tar.gz'
$checksum = Join-Path $archiveDirectory 'frontend-assets.sha256'

Push-Location -LiteralPath $project
try {
    npm ci --no-audit --no-fund
    if ($LASTEXITCODE -ne 0) { throw 'npm ci failed.' }
    npm run production
    if ($LASTEXITCODE -ne 0) { throw 'Frontend build failed.' }

    New-Item -ItemType Directory -Force -Path $archiveDirectory | Out-Null
    if (Test-Path -LiteralPath $archive) { Remove-Item -LiteralPath $archive }
    tar -czf $archive `
        public/js/init.js public/js/vendor.js `
        public/css/init.css public/css/vendor.css public/css/rtl.css public/css/images `
        public/fonts public/webfonts public/mix-manifest.json
    if ($LASTEXITCODE -ne 0) { throw 'Frontend archive creation failed.' }

    $hash = (Get-FileHash -Algorithm SHA256 -LiteralPath $archive).Hash.ToLowerInvariant()
    Set-Content -LiteralPath $checksum -Value "$hash  frontend-assets.tar.gz" -Encoding ascii
    Write-Host "Frontend deployment bundle created: $archive"
    Write-Host "SHA-256: $hash"
} finally {
    Pop-Location
}
