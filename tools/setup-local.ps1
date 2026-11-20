$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
Set-Location -LiteralPath $projectRoot
npm.cmd ci
if ($LASTEXITCODE -ne 0) { throw 'JavaScript installation failed.' }
Set-Location -LiteralPath (Join-Path $projectRoot 'apps/billing-api')
composer install --no-interaction
if ($LASTEXITCODE -ne 0) { throw 'Composer installation failed.' }
if (-not (Test-Path -LiteralPath '.env')) {
    $config = Get-Content -LiteralPath '.env.example' -Raw
    $databaseFile = (Join-Path $PWD 'database/database.sqlite').Replace('\','/')
    $config = $config.Replace('APP_ENV=production','APP_ENV=local').Replace('APP_DEBUG=false','APP_DEBUG=true').Replace('DB_CONNECTION=mysql','DB_CONNECTION=sqlite').Replace('DB_DATABASE=mfaro_billing',"DB_DATABASE=$databaseFile").Replace('LOCAL_AUTH_ENABLED=false','LOCAL_AUTH_ENABLED=true').Replace('SESSION_SECURE_COOKIE=true','SESSION_SECURE_COOKIE=false').Replace('SESSION_COOKIE=__Host-mfaro_billing','SESSION_COOKIE=mfaro_local').Replace('MAIL_MAILER=smtp','MAIL_MAILER=log').Replace('OIDC_ISSUER=https://accounts.mfarosoftware.africa/realms/mfaro','OIDC_ISSUER=').Replace('OIDC_ACCOUNT_URL=https://accounts.mfarosoftware.africa/realms/mfaro/account','OIDC_ACCOUNT_URL=').Replace('https://api.billing.mfarosoftware.africa','http://127.0.0.1:8000').Replace('https://admin.billing.mfarosoftware.africa','http://127.0.0.1:5174').Replace('https://billing.mfarosoftware.africa','http://127.0.0.1:5173')
    Set-Content -LiteralPath '.env' -Value $config -Encoding utf8
    New-Item -ItemType File -Path 'database/database.sqlite' -Force | Out-Null
    php artisan key:generate
}
php artisan migrate --seed
if ($LASTEXITCODE -ne 0) { throw 'Database setup failed.' }
Set-Location -LiteralPath $projectRoot
Write-Output 'Setup complete. Create a local account using php artisan billing:dev-user from apps/billing-api. See README.md.'
