$ErrorActionPreference = 'Stop'
Set-Location (Split-Path $PSScriptRoot -Parent)

function Invoke-Docker {
    & docker @args
    if ($LASTEXITCODE -ne 0) { throw "Docker falhou: $args" }
}

if (!(Test-Path .env)) {
    $password = [Guid]::NewGuid().ToString('N') + [Guid]::NewGuid().ToString('N')
    (Get-Content .env.example -Raw).Replace('change-this-local-password', $password) | Set-Content .env -Encoding utf8
}
if (!(Test-Path backend/.env)) {
    Copy-Item backend/.env.example backend/.env
}

Invoke-Docker compose build app worker frontend
Invoke-Docker compose up -d --wait postgres
Invoke-Docker compose run --rm app composer install --no-interaction --prefer-dist
if (!(Select-String -Path backend/.env -Pattern '^APP_KEY=.+$' -Quiet)) {
    Invoke-Docker compose run --rm app php artisan key:generate
}
Invoke-Docker compose run --rm app php artisan migrate --seed --force
Invoke-Docker compose up -d --wait app worker
Invoke-Docker compose run --rm frontend npm ci
Invoke-Docker compose up -d --wait frontend
Write-Host 'API disponível em http://localhost:8000/up'
Write-Host 'Frontend disponível em http://localhost:5173'
Write-Host 'Crie seu administrador: docker compose exec app php artisan ged:create-admin'
