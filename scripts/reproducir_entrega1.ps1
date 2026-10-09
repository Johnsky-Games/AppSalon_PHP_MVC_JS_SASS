# ==============================================================================
# Script de Reproduccion Integral de Entrega 1 desde Entorno Docker Limpio (PowerShell)
# ==============================================================================
$ErrorActionPreference = "Continue"

$RootDir = Split-Path -Parent $PSScriptRoot
Set-Location $RootDir

Write-Host "======================================================================" -ForegroundColor Cyan
Write-Host "REPRODUCCION INTEGRAL DE PRUEBAS -- APPSALON ENTREGA 1 (POWERSHELL)" -ForegroundColor Cyan
Write-Host "Directorio de trabajo: $RootDir" -ForegroundColor Cyan
Write-Host "======================================================================" -ForegroundColor Cyan

function Cleanup-Containers {
    Write-Host ""
    Write-Host "[LIMPIEZA FINAL] Deteniendo y eliminando contenedores temporales..." -ForegroundColor Yellow
    docker rm -f appsalon-web appsalon-mail appsalon-test-db 2>&1 | Out-Null
    docker network rm appsalon-net 2>&1 | Out-Null
}

try {
    # 1. Limpieza preventiva
    Write-Host "[1/8] Limpiando contenedores y redes anteriores..." -ForegroundColor Cyan
    docker rm -f appsalon-web appsalon-mail appsalon-test-db 2>&1 | Out-Null
    docker network rm appsalon-net 2>&1 | Out-Null

    # 2. Verificacion de Composer y npm
    Write-Host "[2/8] Verificando dependencias de Composer y npm..." -ForegroundColor Cyan
    if (-not (Test-Path "vendor/autoload.php")) {
        Write-Host " -> Instalando dependencias Composer..."
        docker run --rm -v "${PWD}:/app" -w /app composer:2 install --ignore-platform-reqs --no-interaction
    }
    if (-not (Test-Path "public/build")) {
        Write-Host " -> Construyendo assets npm..."
        docker run --rm -v "${PWD}:/app" -w /app node:18 sh -c "npm install && npm run build"
    }

    # 3. Red y MySQL 8
    Write-Host "[3/8] Creando red Docker e iniciando MySQL 8..." -ForegroundColor Cyan
    docker network create appsalon-net | Out-Null
    docker run -d --name appsalon-test-db --network appsalon-net -p 3307:3306 -e MYSQL_ROOT_PASSWORD=root mysql:8.0 | Out-Null

    Write-Host " -> Esperando disponibilidad de MySQL..."
    $ready = $false
    for ($i = 1; $i -le 60; $i++) {
        docker exec appsalon-test-db mysqladmin ping -h 127.0.0.1 -u root -proot --silent 2>&1 | Out-Null
        if ($LASTEXITCODE -eq 0) {
            $ready = $true
            Write-Host " -> MySQL 8 disponible tras ${i}s." -ForegroundColor Green
            break
        }
        Start-Sleep -Seconds 1
    }

    if (-not $ready) {
        throw "Tiempo de espera agotado para disponibilidad de MySQL."
    }

    # 4. Imagen PHP
    Write-Host "[4/8] Construyendo imagen de prueba de PHP 8.2..." -ForegroundColor Cyan
    docker build -t appsalon-php-test -f Dockerfile.test . | Out-Null

    # 5. Bases de datos
    Write-Host "[5/8] Preparando bases de datos de pruebas (appsalon_test y appsalon_func_test)..." -ForegroundColor Cyan
    docker exec appsalon-test-db mysql -uroot -proot -e "CREATE DATABASE IF NOT EXISTS appsalon_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" | Out-Null
    Get-Content database/schema_base.sql -Raw | docker exec -i appsalon-test-db mysql -uroot -proot appsalon_test | Out-Null
    docker run --rm --network appsalon-net -v "${PWD}:/app" -w /app -e DB_HOST=appsalon-test-db -e DB_USER=root -e DB_PASS=root -e DB_NAME=appsalon_test -e DB_PORT=3306 appsalon-php-test php database/migrador.php up | Out-Null

    docker exec appsalon-test-db mysql -uroot -proot -e "CREATE DATABASE IF NOT EXISTS appsalon_func_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" | Out-Null
    Get-Content database/schema_base.sql -Raw | docker exec -i appsalon-test-db mysql -uroot -proot appsalon_func_test | Out-Null
    docker run --rm --network appsalon-net -v "${PWD}:/app" -w /app -e DB_HOST=appsalon-test-db -e DB_USER=root -e DB_PASS=root -e DB_NAME=appsalon_func_test -e DB_PORT=3306 appsalon-php-test php database/migrador.php up | Out-Null

    docker exec appsalon-test-db mysql -uroot -proot appsalon_func_test -e "INSERT INTO servicios (id, nombre, precio) VALUES (1, 'Corte de Cabello Hombre', 80.00), (2, 'Corte de Cabello Mujer', 120.00), (3, 'Corte de Barba', 60.00) ON DUPLICATE KEY UPDATE nombre=VALUES(nombre);" | Out-Null

    # 6. Configuracion y servicios
    Write-Host "[6/8] Configurando includes/.env e iniciando appsalon-mail y appsalon-web..." -ForegroundColor Cyan
    $envLines = @(
        "DB_HOST=appsalon-test-db",
        "DB_PORT=3306",
        "DB_USER=root",
        "DB_PASS=root",
        "DB_NAME=appsalon_func_test",
        "EMAIL_HOST=appsalon-mail",
        "EMAIL_PORT=2525",
        "EMAIL_USER=test",
        "EMAIL_PASSWORD=test",
        "EMAIL_FROM=cuentas@appsalon.com",
        "EMAIL_FROM_NAME=AppSalon.com",
        "APP_URL=http://appsalon-web:3000"
    )
    $envLines | Set-Content -Path "includes/.env" -Encoding UTF8

    docker run -d --name appsalon-mail --network appsalon-net -v "${PWD}:/app" -w /app appsalon-php-test php tests/smtp_mock_server.php | Out-Null
    docker run -d --name appsalon-web --network appsalon-net -p 3000:3000 -v "${PWD}:/app" -w /app appsalon-php-test php -S 0.0.0.0:3000 -t public | Out-Null

    Write-Host " -> Esperando disponibilidad del servidor web (http://appsalon-web:3000)..."
    docker run --rm --network appsalon-net -v "${PWD}:/app" -w /app appsalon-php-test php tests/wait_for_web.php
    if ($LASTEXITCODE -ne 0) {
        throw "El servidor web real no respondio en appsalon-web:3000."
    }

    # 7. PHPUnit
    Write-Host ""
    Write-Host "[7/8] Ejecutando suite completa de PHPUnit..." -ForegroundColor Cyan
    docker run --rm --network appsalon-net -v "${PWD}:/app" -w /app appsalon-php-test ./vendor/bin/phpunit --testdox
    if ($LASTEXITCODE -ne 0) {
        throw "Fallo en la suite de PHPUnit (codigo de salida: $LASTEXITCODE)"
    }

    # 8. Suite Funcional
    Write-Host ""
    Write-Host "[8/8] Ejecutando suite de verificacion funcional HTTP..." -ForegroundColor Cyan
    docker run --rm --network appsalon-net -v "${PWD}:/app" -w /app appsalon-php-test php tests/functional_test_suite.php
    if ($LASTEXITCODE -ne 0) {
        throw "Fallo en la suite funcional HTTP (codigo de salida: $LASTEXITCODE)"
    }

    Write-Host ""
    Write-Host "======================================================================" -ForegroundColor Green
    Write-Host "REPRODUCCION COMPLETADA EXITOSAMENTE (TODOS LOS TESTS APROBADOS)" -ForegroundColor Green
    Write-Host "======================================================================" -ForegroundColor Green
}
finally {
    Cleanup-Containers
}
