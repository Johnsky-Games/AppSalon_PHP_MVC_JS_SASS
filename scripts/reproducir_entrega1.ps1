# ==============================================================================
# Script de Reproduccion Integral de Entrega 1 desde Entorno Docker Limpio (PowerShell)
# ==============================================================================
param(
    [switch]$SimularFalloPreparacion,
    [switch]$SimularFalloRespaldo,
    [switch]$SimularFalloRestauracion
)

$ErrorActionPreference = "Continue"

$RootDir = Split-Path -Parent $PSScriptRoot
Set-Location $RootDir

$RUN_ID = "$([System.DateTimeOffset]::UtcNow.ToUnixTimeSeconds())-$((Get-Random -Minimum 1000 -Maximum 9999))"
$NetName = "appsalon-net-$RUN_ID"
$DbContainer = "appsalon-db-$RUN_ID"
$MailContainer = "appsalon-mail-$RUN_ID"
$WebContainer = "appsalon-web-$RUN_ID"
$DbSuffix = $RUN_ID -replace '-', '_'
$DbTest = "appsalon_${DbSuffix}_test"
$DbFunc = "appsalon_${DbSuffix}_func_test"

$EnvFile = "includes/.env"
$EnvExisted = Test-Path $EnvFile
$EnvBackup = "includes/.env.bak.$RUN_ID"
$OriginalFileHash = $null

if ($EnvExisted) {
    $OriginalFileHash = (Get-FileHash -Path $EnvFile -Algorithm SHA256).Hash
}

$FailPrep = $SimularFalloPreparacion -or ($env:APPSALON_SIMULAR_FALLO -eq "preparacion")
$FailBackup = $SimularFalloRespaldo -or ($env:APPSALON_SIMULAR_FALLO -eq "respaldo")
$FailRestore = $SimularFalloRestauracion -or ($env:APPSALON_SIMULAR_FALLO -eq "restauracion")

Write-Host "======================================================================" -ForegroundColor Cyan
Write-Host "REPRODUCCION INTEGRAL DE PRUEBAS -- APPSALON ENTREGA 1 (POWERSHELL)" -ForegroundColor Cyan
Write-Host "Directorio de trabajo: $RootDir" -ForegroundColor Cyan
Write-Host "Identificador de ejecucion (RUN_ID): $RUN_ID" -ForegroundColor Cyan
Write-Host "Red Docker aislada: $NetName" -ForegroundColor Cyan
Write-Host "======================================================================" -ForegroundColor Cyan

function Cleanup-Resources {
    Write-Host ""
    Write-Host "[LIMPIEZA FINAL] Deteniendo y eliminando recursos exclusivos de esta ejecucion ($RUN_ID)..." -ForegroundColor Yellow
    docker rm -f $WebContainer $MailContainer $DbContainer 2>&1 | Out-Null
    docker network rm $NetName 2>&1 | Out-Null

    # Restaurar o eliminar includes/.env segun correspondiese originalmente
    if ($EnvExisted) {
        $currentFileHash = if (Test-Path $EnvFile) { (Get-FileHash -Path $EnvFile -Algorithm SHA256).Hash } else { $null }

        if ((-not $FailRestore) -and ($currentFileHash -eq $OriginalFileHash)) {
            Write-Host " -> includes/.env conserva su contenido original intacto." -ForegroundColor Green
            if (Test-Path $EnvBackup) {
                Remove-Item -Path $EnvBackup -Force -ErrorAction SilentlyContinue
            }
        } elseif (Test-Path $EnvBackup) {
            Write-Host " -> Restaurando includes/.env original desde $EnvBackup..." -ForegroundColor Yellow
            try {
                if ($FailRestore) {
                    throw "Fallo provocado deliberadamente al restaurar el archivo $EnvFile (simulacion controlada)."
                }
                Copy-Item -Path $EnvBackup -Destination $EnvFile -Force -ErrorAction Stop
                if (Test-Path $EnvFile) {
                    $RestoredHash = (Get-FileHash -Path $EnvFile -Algorithm SHA256).Hash
                    if ($RestoredHash -eq $OriginalFileHash) {
                        Remove-Item -Path $EnvBackup -Force -ErrorAction SilentlyContinue
                        Write-Host " -> includes/.env restaurado e integridad SHA-256 verificada byte a byte." -ForegroundColor Green
                    } else {
                        throw "La integridad del archivo restaurado no coincide byte a byte ($RestoredHash vs $OriginalFileHash)."
                    }
                } else {
                    throw "El archivo de destino $EnvFile no existe tras la restauracion."
                }
            } catch {
                Write-Host "[ERROR CRITICO EN RESTAURACION] $($_.Exception.Message)" -ForegroundColor Red
                Write-Host "El archivo de respaldo fue PRESERVADO intacto en: $EnvBackup para recuperacion manual." -ForegroundColor Yellow
                $script:scriptSuccess = $false
                if ($script:scriptExitCode -eq 0) { $script:scriptExitCode = 1 }
            }
        } else {
            Write-Host "[ERROR CRITICO] No se encontro el archivo de respaldo $EnvBackup y el archivo fue alterado." -ForegroundColor Red
            $script:scriptSuccess = $false
            if ($script:scriptExitCode -eq 0) { $script:scriptExitCode = 1 }
        }
    } else {
        if (Test-Path $EnvFile) {
            Write-Host " -> Eliminando includes/.env temporal generado..." -ForegroundColor Yellow
            try {
                Remove-Item -Path $EnvFile -Force -ErrorAction Stop
                if (Test-Path $EnvFile) {
                    throw "El archivo temporal $($EnvFile) permanece en disco tras la eliminacion."
                }
            } catch {
                Write-Host "[ERROR CRITICO] No se pudo eliminar el archivo temporal $($EnvFile): $($_.Exception.Message)" -ForegroundColor Red
                $script:scriptSuccess = $false
                if ($script:scriptExitCode -eq 0) { $script:scriptExitCode = 1 }
            }
        }
    }
}

function Run-StepChecked {
    param(
        [string]$Description,
        [scriptblock]$Action
    )
    Write-Host " -> $Description"
    & $Action
    $code = $LASTEXITCODE
    if ($code -ne 0) {
        throw "Fallo en paso '$Description' con codigo de salida: $code"
    }
}

$scriptSuccess = $false
$scriptExitCode = 0

try {
    # 1. Respaldo de configuracion preexistente
    if ($EnvExisted) {
        Write-Host "[1/7] Respaldando includes/.env preexistente en $EnvBackup..." -ForegroundColor Cyan
        if ($FailBackup) {
            throw "Fallo provocado deliberadamente al crear el respaldo de $EnvFile (simulacion controlada)."
        }
        try {
            Copy-Item -Path $EnvFile -Destination $EnvBackup -Force -ErrorAction Stop
        } catch {
            throw "Fallo al crear el respaldo de $($EnvFile): $($_.Exception.Message)"
        }

        # Comprobacion estricta de que el respaldo existe y es identico byte a byte
        if (-not (Test-Path $EnvBackup)) {
            throw "El archivo de respaldo $EnvBackup no fue creado. Operacion cancelada antes de modificar $EnvFile."
        }
        $BackupHash = (Get-FileHash -Path $EnvBackup -Algorithm SHA256).Hash
        if ($BackupHash -ne $OriginalFileHash) {
            throw "El hash del respaldo ($BackupHash) no coincide con el archivo original ($OriginalFileHash). Operacion cancelada antes de modificar $EnvFile."
        }
        Write-Host " -> Respaldo creado e integridad verificada ($BackupHash)." -ForegroundColor Green
    } else {
        Write-Host "[1/7] Sin includes/.env preexistente; se eliminara al terminar la prueba..." -ForegroundColor Cyan
    }

    # 2. Instalacion estricta segun lockfiles y construccion de assets
    Write-Host "[2/7] Instalando dependencias segun archivos de bloqueo y compilando assets..." -ForegroundColor Cyan
    Run-StepChecked "Instalacion de dependencias Composer segun composer.lock" {
        docker run --rm -v "${PWD}:/app" -w /app composer:2 install --ignore-platform-reqs --no-interaction
    }
    Run-StepChecked "Instalacion de dependencias npm segun package-lock.json y compilacion de assets" {
        docker run --rm -v "${PWD}:/app" -w /app node:18 sh -c "npm ci && npm run build"
    }

    # 3. Creacion de red y MySQL 8 exclusivo
    Write-Host "[3/7] Creando red aislada $NetName e iniciando MySQL 8 ($DbContainer)..." -ForegroundColor Cyan
    Run-StepChecked "Creacion de red Docker" {
        docker network create $NetName | Out-Null
    }
    Run-StepChecked "Inicio de contenedor MySQL 8" {
        docker run -d --name $DbContainer --network $NetName -e MYSQL_ROOT_PASSWORD=root mysql:8.0 | Out-Null
    }

    Write-Host " -> Esperando disponibilidad de MySQL..."
    $ready = $false
    for ($i = 1; $i -le 60; $i++) {
        docker exec $DbContainer mysqladmin ping -h 127.0.0.1 -u root -proot --silent 2>&1 | Out-Null
        if ($LASTEXITCODE -eq 0) {
            $ready = $true
            Write-Host " -> MySQL 8 disponible tras ${i}s." -ForegroundColor Green
            Start-Sleep -Seconds 2
            break
        }
        Start-Sleep -Seconds 1
    }
    if (-not $ready) {
        throw "Tiempo de espera agotado para disponibilidad de MySQL en $DbContainer."
    }

    # 4. Construccion de imagen PHP 8.2
    Write-Host "[4/7] Construyendo imagen de prueba de PHP 8.2..." -ForegroundColor Cyan
    Run-StepChecked "docker build appsalon-php-test" {
        docker build -t appsalon-php-test -f Dockerfile.test . | Out-Null
    }

    # 5. Preparacion y migracion independiente de bases de datos
    Write-Host "[5/7] Preparando bases de datos ($DbTest y $DbFunc)..." -ForegroundColor Cyan
    if ($FailPrep) {
        throw "Fallo provocado deliberadamente en fase de preparacion de bases de datos (simulacion controlada)."
    }

    Run-StepChecked "Creacion de base $DbTest" {
        docker exec $DbContainer mysql -uroot -proot -e "CREATE DATABASE IF NOT EXISTS $DbTest CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" | Out-Null
    }
    Run-StepChecked "Importacion de esquema base en $DbTest" {
        Get-Content database/schema_base.sql -Raw | docker exec -i $DbContainer mysql -uroot -proot $DbTest | Out-Null
    }
    Run-StepChecked "Migraciones up en $DbTest" {
        docker run --rm --network $NetName -v "${PWD}:/app" -w /app -e DB_HOST=$DbContainer -e DB_USER=root -e DB_PASS=root -e DB_NAME=$DbTest -e DB_PORT=3306 appsalon-php-test php database/migrador.php up
    }

    Run-StepChecked "Creacion de base $DbFunc" {
        docker exec $DbContainer mysql -uroot -proot -e "CREATE DATABASE IF NOT EXISTS $DbFunc CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" | Out-Null
    }
    Run-StepChecked "Importacion de esquema base en $DbFunc" {
        Get-Content database/schema_base.sql -Raw | docker exec -i $DbContainer mysql -uroot -proot $DbFunc | Out-Null
    }
    Run-StepChecked "Migraciones up en $DbFunc" {
        docker run --rm --network $NetName -v "${PWD}:/app" -w /app -e DB_HOST=$DbContainer -e DB_USER=root -e DB_PASS=root -e DB_NAME=$DbFunc -e DB_PORT=3306 appsalon-php-test php database/migrador.php up
    }
    Run-StepChecked "Semillero de servicios en $DbFunc" {
        docker exec $DbContainer mysql -uroot -proot $DbFunc -e "INSERT INTO servicios (id, nombre, precio) VALUES (1, 'Corte de Cabello Hombre', 80.00), (2, 'Corte de Cabello Mujer', 120.00), (3, 'Corte de Barba', 60.00) ON DUPLICATE KEY UPDATE nombre=VALUES(nombre);" | Out-Null
    }

    # 6. Configuracion temporal y servicios auxiliares
    Write-Host "[6/7] Generando configuracion e iniciando $MailContainer y $WebContainer..." -ForegroundColor Cyan
    $envLines = @(
        "DB_HOST=$DbContainer",
        "DB_PORT=3306",
        "DB_USER=root",
        "DB_PASS=root",
        "DB_NAME=$DbFunc",
        "EMAIL_HOST=$MailContainer",
        "EMAIL_PORT=2525",
        "EMAIL_USER=test",
        "EMAIL_PASSWORD=test",
        "EMAIL_FROM=cuentas@appsalon.com",
        "EMAIL_FROM_NAME=AppSalon.com",
        "APP_URL=http://${WebContainer}:3000"
    )
    try {
        $envLines | Set-Content -Path $EnvFile -Encoding UTF8 -ErrorAction Stop
    } catch {
        throw "Fallo al escribir la configuracion temporal en $($EnvFile): $($_.Exception.Message)"
    }
    if (-not (Test-Path $EnvFile)) {
        throw "No se pudo verificar la creacion del archivo temporal $EnvFile."
    }

    Run-StepChecked "Inicio de receptor SMTP mock ($MailContainer)" {
        docker run -d --name $MailContainer --network $NetName -v "${PWD}:/app" -w /app appsalon-php-test php tests/smtp_mock_server.php | Out-Null
    }
    Run-StepChecked "Inicio de servidor web ($WebContainer)" {
        docker run -d --name $WebContainer --network $NetName -v "${PWD}:/app" -w /app appsalon-php-test php -S 0.0.0.0:3000 -t public | Out-Null
    }

    Write-Host " -> Esperando disponibilidad del servidor web (http://${WebContainer}:3000)..."
    docker run --rm --network $NetName -v "${PWD}:/app" -w /app -e APP_URL="http://${WebContainer}:3000" appsalon-php-test php tests/wait_for_web.php
    if ($LASTEXITCODE -ne 0) {
        throw "El servidor web real no respondio en http://${WebContainer}:3000."
    }

    # 7. Ejecucion de suites de prueba
    Write-Host ""
    Write-Host "[7/7] Ejecutando suite completa de PHPUnit..." -ForegroundColor Cyan
    docker run --rm --network $NetName -v "${PWD}:/app" -w /app -e DB_HOST=$DbContainer -e DB_USER=root -e DB_PASS=root -e DB_NAME=$DbTest -e DB_PORT=3306 appsalon-php-test ./vendor/bin/phpunit --testdox
    if ($LASTEXITCODE -ne 0) {
        throw "Fallo en la suite de PHPUnit (codigo de salida: $LASTEXITCODE)"
    }

    Write-Host ""
    Write-Host "Ejecutando suite de verificacion funcional HTTP..." -ForegroundColor Cyan
    docker run --rm --network $NetName -v "${PWD}:/app" -w /app -e APP_URL="http://${WebContainer}:3000" appsalon-php-test php tests/functional_test_suite.php
    if ($LASTEXITCODE -ne 0) {
        throw "Fallo en la suite funcional HTTP (codigo de salida: $LASTEXITCODE)"
    }

    Write-Host ""
    Write-Host "======================================================================" -ForegroundColor Green
    Write-Host "REPRODUCCION COMPLETADA EXITOSAMENTE (TODOS LOS TESTS APROBADOS)" -ForegroundColor Green
    Write-Host "======================================================================" -ForegroundColor Green
    $scriptSuccess = $true
}
catch {
    $scriptSuccess = $false
    $scriptExitCode = 1
    if ($LASTEXITCODE -gt 0) {
        $scriptExitCode = $LASTEXITCODE
    }
    Write-Host ""
    Write-Host "[ERROR EN REPRODUCCION] $($_.Exception.Message)" -ForegroundColor Red
}
finally {
    Cleanup-Resources
}

if (-not $scriptSuccess) {
    exit $scriptExitCode
}
