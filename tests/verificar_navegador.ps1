# ==============================================================================
# Verificador de Recorridos en Navegador Web (UI Admin + Cliente) - AppSalon
# ==============================================================================
# Prepara un entorno Docker aislado (red, MySQL 8, web en puerto dinamico libre),
# inicializa base de datos appsalon_browser_test, siembra admin, cliente y servicios,
# ejecuta la suite en navegador real headless (tests/browser_e2e_test.js),
# comprueba persistencia en base de datos y garantiza restauracion estricta de .env.
# ==============================================================================

$ErrorActionPreference = "Continue"

$RUN_ID = [System.Guid]::NewGuid().ToString("N")
$RootDir = Split-Path -Parent $PSScriptRoot
Set-Location $RootDir

$NetName = "appsalon-brw-net-$RUN_ID"
$DbContainer = "appsalon-brw-db-$RUN_ID"
$MailContainer = "appsalon-brw-mail-$RUN_ID"
$WebContainer = "appsalon-brw-web-$RUN_ID"
$DbName = "appsalon_browser_test"

$EnvFile = "includes/.env"
$OriginalEnvBackup = "includes/.env.brw_backup.$RUN_ID"
$EnvExistedOriginally = Test-Path $EnvFile
$OriginalInitialHash = $null

Write-Host "======================================================================" -ForegroundColor Cyan
Write-Host "VERIFICACION EN NAVEGADOR WEB (HEADLESS CHROME) - APPSALON FASE 2A" -ForegroundColor Cyan
Write-Host "Identificador de ejecucion (RUN_ID): $RUN_ID" -ForegroundColor Cyan
Write-Host "Red Docker aislada: $NetName" -ForegroundColor Cyan
Write-Host "Base de datos aislada: $DbName" -ForegroundColor Cyan
Write-Host "======================================================================" -ForegroundColor Cyan

# 1. Respaldo previo estricto de includes/.env
if ($EnvExistedOriginally) {
    try {
        $OriginalInitialHash = (Get-FileHash -Path $EnvFile -Algorithm SHA256 -ErrorAction Stop).Hash
        Copy-Item -Path $EnvFile -Destination $OriginalEnvBackup -Force -ErrorAction Stop
        $backupHash = (Get-FileHash -Path $OriginalEnvBackup -Algorithm SHA256 -ErrorAction Stop).Hash
        if ($backupHash -ne $OriginalInitialHash) {
            throw "Hash de respaldo no coincide con archivo original."
        }
        Write-Host " -> Respaldo de includes/.env asegurado ($OriginalInitialHash)." -ForegroundColor Green
    } catch {
        $msg = $_.Exception.Message
        Write-Host "[ERROR CRITICO] No se pudo asegurar el respaldo de includes/.env: $msg" -ForegroundColor Red
        exit 1
    }
} else {
    Write-Host " -> Sin includes/.env previo; se garantizara limpieza al finalizar." -ForegroundColor Cyan
}

$testSuccess = $false
$testExitCode = 1

function Cleanup-BrowserEnvironment {
    Write-Host ""
    Write-Host "[LIMPIEZA DE ENTORNO DOCKER] Deteniendo contenedores y red ($RUN_ID)..." -ForegroundColor Yellow
    docker rm -f $WebContainer $MailContainer $DbContainer 2>&1 | Out-Null
    docker network rm $NetName 2>&1 | Out-Null
}

try {
    # 2. Creacion de red y contenedor MySQL 8
    Write-Host ""
    Write-Host "[1/6] Creando red Docker aislada e iniciando MySQL 8 ($DbContainer)..." -ForegroundColor Cyan
    docker network create $NetName 2>&1 | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "Fallo al crear red Docker $NetName" }

    docker run -d --name $DbContainer --network $NetName -e MYSQL_ROOT_PASSWORD=root mysql:8.0 2>&1 | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "Fallo al iniciar MySQL 8 en $DbContainer" }

    Write-Host " -> Esperando disponibilidad de MySQL..."
    $ready = $false
    for ($i = 1; $i -le 60; $i++) {
        $null = docker exec $DbContainer mysqladmin ping -h 127.0.0.1 -u root -proot --silent 2>$null
        if ($LASTEXITCODE -eq 0) {
            $ready = $true
            Write-Host " -> MySQL 8 listo tras ${i}s." -ForegroundColor Green
            Start-Sleep -Seconds 2
            break
        }
        Start-Sleep -Seconds 1
    }
    if (-not $ready) { throw "Timeout esperando MySQL en $DbContainer" }

    # 3. Preparacion de base de datos aislada appsalon_browser_test
    Write-Host ""
    Write-Host "[2/6] Preparando base de datos de pruebas ($DbName)..." -ForegroundColor Cyan
    docker exec $DbContainer mysql -uroot -proot -e "CREATE DATABASE IF NOT EXISTS $DbName CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>$null | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "Fallo al crear base $DbName" }

    Get-Content database/schema_base.sql -Raw | docker exec -i $DbContainer mysql -uroot -proot $DbName 2>$null | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "Fallo al importar schema_base.sql en $DbName" }

    docker run --rm --network $NetName -v "${PWD}:/app" -w /app -e DB_HOST=$DbContainer -e DB_USER=root -e DB_PASS=root -e DB_NAME=$DbName -e DB_PORT=3306 appsalon-php-test php database/migrador.php up
    if ($LASTEXITCODE -ne 0) { throw "Fallo al aplicar migraciones en $DbName" }

    # 4. Semillero de servicios, usuario cliente y usuario administrador de prueba
    Write-Host ""
    Write-Host "[3/6] Sembrando catalogo de servicios, cliente y administrador de prueba..." -ForegroundColor Cyan
    $passHash = '$2y$10$5fbKwtKYcyFUSgV5wgWyMuvoxmR97oZYrvbEScIEWoqMNRZMaSp8q'
    $seedSql = "INSERT INTO servicios (id, nombre, precio) VALUES (1, 'Corte de Cabello Hombre', 80.00), (2, 'Corte de Cabello Mujer', 120.00), (3, 'Corte de Barba', 60.00) ON DUPLICATE KEY UPDATE nombre=VALUES(nombre); DELETE FROM usuarios WHERE email IN ('carlos@correo.com', 'admin@appsalon.com'); INSERT INTO usuarios (id, nombre, apellido, email, password, telefono, admin, confirmado, token, token_hash) VALUES (1, 'Carlos', 'Mendoza', 'carlos@correo.com', '$passHash', '1234567890', 0, 1, NULL, NULL), (2, 'Admin', 'Salon', 'admin@appsalon.com', '$passHash', '0987654321', 1, 1, NULL, NULL); DELETE FROM intentos_login;"

    $seedSql | docker exec -i $DbContainer mysql -uroot -proot --default-character-set=utf8mb4 $DbName 2>$null | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "Fallo al sembrar datos en $DbName" }
    Write-Host " -> Catalogo, cliente 'carlos@correo.com' y admin 'admin@appsalon.com' sembrados con exito." -ForegroundColor Green

    # 5. Generar configuracion temporal e iniciar servidor web en puerto dinamico libre (no colisiona con puerto 3000 local)
    Write-Host ""
    Write-Host "[4/6] Configurando variables e iniciando servidor web aislado..." -ForegroundColor Cyan

    docker run -d --name $MailContainer --network $NetName -v "${PWD}:/app" -w /app appsalon-php-test php tests/smtp_mock_server.php 2>&1 | Out-Null
    docker run -d --name $WebContainer --network $NetName -p 0:3000 -v "${PWD}:/app" -w /app appsalon-php-test php -S 0.0.0.0:3000 -t public 2>&1 | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "Fallo al iniciar contenedor web $WebContainer" }

    $portMapping = docker port $WebContainer 3000/tcp
    if (-not $portMapping) { throw "No se pudo obtener el puerto publicado de $WebContainer" }
    $hostPort = ($portMapping | Select-Object -First 1) -replace '^.*:(\d+)$', '$1'
    $appUrl = "http://localhost:$hostPort"

    $envLines = @(
        "DB_HOST=$DbContainer",
        "DB_PORT=3306",
        "DB_USER=root",
        "DB_PASS=root",
        "DB_NAME=$DbName",
        "EMAIL_HOST=$MailContainer",
        "EMAIL_PORT=2525",
        "EMAIL_USER=test",
        "EMAIL_PASSWORD=test",
        "EMAIL_FROM=cuentas@appsalon.com",
        'EMAIL_FROM_NAME="AppSalon.com"',
        "APP_URL=$appUrl"
    )
    $envLines | Set-Content -Path $EnvFile -Encoding UTF8 -ErrorAction Stop

    Write-Host " -> Verificando disponibilidad de $appUrl..."
    $webReady = $false
    for ($w = 1; $w -le 30; $w++) {
        try {
            $resp = Invoke-WebRequest -Uri "$appUrl/" -UseBasicParsing -TimeoutSec 2 -ErrorAction SilentlyContinue
            if ($resp.StatusCode -eq 200) {
                $webReady = $true
                Write-Host " -> Servidor web aislado respondiendo correctamente en $appUrl (HTTP 200) tras ${w}s." -ForegroundColor Green
                break
            }
        } catch { }
        Start-Sleep -Seconds 1
    }
    if (-not $webReady) { throw "Servidor web no respondio en $appUrl tras 30s" }

    # 6. Calcular fechas futuras dinámicas compartidas entre el runner y la comprobación MySQL
    Write-Host ""
    Write-Host "[5/6] Calculando fechas de prueba y ejecutando suite en navegador con JavaScript..." -ForegroundColor Cyan

    $baseDate = (Get-Date).Date
    $proximoSabado = $null
    $diaLaborable = $null

    for ($i = 1; $i -le 14; $i++) {
        $candidate = $baseDate.AddDays($i)
        $formatted = $candidate.ToString("yyyy-MM-dd")
        if ($candidate.DayOfWeek -eq [System.DayOfWeek]::Saturday -and $null -eq $proximoSabado) {
            $proximoSabado = $formatted
        }
        if ($candidate.DayOfWeek -eq [System.DayOfWeek]::Monday -and $null -eq $diaLaborable) {
            $diaLaborable = $formatted
        }
    }

    if (-not $proximoSabado -or -not $diaLaborable) {
        throw "No se pudieron calcular las fechas de prueba dinámicas."
    }

    Write-Host " -> Fechas dinámicas calculadas:" -ForegroundColor Cyan
    Write-Host "    - Sábado (rechazo en cliente): $proximoSabado" -ForegroundColor Cyan
    Write-Host "    - Día laborable (reserva válida): $diaLaborable" -ForegroundColor Cyan

    $env:APP_URL = $appUrl
    $env:TEST_REJECT_WEEKEND_DATE = $proximoSabado
    $env:TEST_VALID_BOOKING_DATE = $diaLaborable
    $env:TEST_DB_CONTAINER = $DbContainer
    $env:TEST_DB_NAME = $DbName

    $nodeCmd = "node tests/browser_e2e_test.js"
    Invoke-Expression $nodeCmd
    $nodeExitCode = $LASTEXITCODE

    if ($nodeExitCode -ne 0) {
        throw "La suite en navegador web finalizó con error (código $nodeExitCode)."
    }

    # 7. Validar persistencia real en base de datos del CRUD de servicios y la cita generada por el navegador
    Write-Host ""
    Write-Host "[6/6] Verificando persistencia estricta en base de datos ($DbName)..." -ForegroundColor Cyan

    $servicioEditado = docker exec $DbContainer mysql -uroot -proot --default-character-set=utf8mb4 -N -e "SELECT id, nombre, precio, duracion_minutos FROM servicios WHERE nombre = 'Masaje Capilar Premium';" $DbName 2>$null
    if (-not $servicioEditado -or -not ($servicioEditado -match "115\.00") -or -not ($servicioEditado -match "\b60\b")) {
        throw "El servicio creado/actualizado 'Masaje Capilar Premium' (115.00, 60 min) no se encontro en la base de datos: $servicioEditado"
    }
    Write-Host " -> Servicio CRUD verificado en BD (incluyendo duracion_minutos=60): $servicioEditado" -ForegroundColor Green

    $servicioEliminado = docker exec $DbContainer mysql -uroot -proot --default-character-set=utf8mb4 -N -e "SELECT COUNT(*) FROM servicios WHERE nombre = 'Servicio Temporal Borrar';" $DbName 2>$null
    if ([int]$servicioEliminado -ne 0) {
        throw "El servicio eliminado 'Servicio Temporal Borrar' aun existe en la base de datos."
    }

    $profCheck = docker exec $DbContainer mysql -uroot -proot --default-character-set=utf8mb4 -N -e "SELECT p.id, p.activo, (SELECT COUNT(*) FROM profesionales_servicios ps WHERE ps.profesionalId = p.id), (SELECT COUNT(*) FROM horarios_profesionales hp WHERE hp.profesionalId = p.id), (SELECT COUNT(*) FROM descansos_profesionales dp WHERE dp.profesionalId = p.id), (SELECT COUNT(*) FROM bloqueos_profesionales bp WHERE bp.profesionalId = p.id) FROM profesionales p WHERE p.id = 1 AND p.nombre LIKE 'Sof%Andrade';" $DbName 2>$null
    if (-not $profCheck) {
        throw "La profesional 'Sofia Andrade' y su agenda no se encontraron en la base de datos."
    }
    $profCols = -split $profCheck
    if ($profCols[1] -ne "1" -or [int]$profCols[2] -ne 2 -or [int]$profCols[3] -ne 2 -or [int]$profCols[4] -ne 1 -or [int]$profCols[5] -ne 1) {
        throw "Inconsistencia en agenda de profesional persistida en BD: $profCheck"
    }
    Write-Host " -> Profesional y agenda verificados en BD (id | activo | servicios | horarios | descansos | bloqueos): $profCheck" -ForegroundColor Green

    $querySql = "SELECT c.id, c.fecha, c.hora, c.usuarioId, c.profesionalId, c.hora_inicio, c.hora_fin, c.duracion_total_minutos, COUNT(cs.id) AS total_servicios FROM citas c LEFT JOIN citasservicios cs ON c.id = cs.citaId WHERE c.usuarioId = 1 GROUP BY c.id ORDER BY c.id DESC LIMIT 1;"
    $dbOutput = docker exec $DbContainer mysql -uroot -proot -N -e "$querySql" $DbName 2>$null
    Write-Host " -> Registro de cita en base de datos (id | fecha | hora | usuarioId | profesionalId | hora_inicio | hora_fin | duracion_total_minutos | servicios): $dbOutput"

    if (-not $dbOutput) {
        throw "No se encontró ningún registro de cita persistido en la base de datos para el usuario 1."
    }

    $dbCols = -split $dbOutput
    $citaId = $dbCols[0]
    $citaFecha = $dbCols[1]
    $citaHora = $dbCols[2]
    $citaUsuarioId = $dbCols[3]
    $citaProfesionalId = $dbCols[4]
    $citaHoraInicio = $dbCols[5]
    $citaHoraFin = $dbCols[6]
    $citaDuracionTotal = $dbCols[7]
    $citaTotalServicios = $dbCols[8]

    if ($citaUsuarioId -ne "1" -or $citaFecha -ne $diaLaborable -or $citaHora -ne "11:30:00" -or $citaProfesionalId -ne "2" -or $citaHoraInicio -ne "11:30:00" -or $citaHoraFin -ne "13:00:00" -or [int]$citaDuracionTotal -ne 90 -or [int]$citaTotalServicios -ne 2) {
        throw "Inconsistencia en datos persistidos en BD: ID=$citaId, Fecha=$citaFecha (esperada=$diaLaborable), Hora=$citaHora, UsuarioId=$citaUsuarioId, ProfesionalId=$citaProfesionalId, Intervalo=[$citaHoraInicio, $citaHoraFin), Duracion=$citaDuracionTotal, Servicios=$citaTotalServicios"
    }

    Write-Host " -> Cita ID $citaId verificada: fecha $citaFecha (coincide con fecha calculada compartida $diaLaborable), profesional $citaProfesionalId, intervalo [$citaHoraInicio, $citaHoraFin) ($citaDuracionTotal min), usuario $citaUsuarioId con $citaTotalServicios servicios asociados." -ForegroundColor Green

    $testSuccess = $true
    $testExitCode = 0

} catch {
    $err = $_.Exception.Message
    Write-Host ""
    Write-Host "[ERROR EN VERIFICACION DE NAVEGADOR] $err" -ForegroundColor Red
    $testSuccess = $false
    $testExitCode = 1
} finally {
    try {
        Cleanup-BrowserEnvironment
    } finally {
        # Restauracion estrictamente garantizada de includes/.env
        if ($EnvExistedOriginally) {
            if (Test-Path $OriginalEnvBackup) {
                try {
                    Copy-Item -Path $OriginalEnvBackup -Destination $EnvFile -Force -ErrorAction Stop
                    $restoredHash = (Get-FileHash -Path $EnvFile -Algorithm SHA256 -ErrorAction Stop).Hash
                    if ($restoredHash -ne $OriginalInitialHash) {
                        throw "Discrepancia SHA-256 en restauracion de .env ($restoredHash vs $OriginalInitialHash)"
                    }
                    Remove-Item -Path $OriginalEnvBackup -Force -ErrorAction Stop
                    Write-Host " -> includes/.env original restaurado con exito (SHA-256 verificado)." -ForegroundColor Green
                } catch {
                    $errRestore = $_.Exception.Message
                    Write-Host "[ERROR CRITICO] Fallo en restauracion de includes/.env: $errRestore" -ForegroundColor Red
                    Write-Host "Respaldo preservado en: $OriginalEnvBackup" -ForegroundColor Yellow
                    $testSuccess = $false
                    $testExitCode = 1
                }
            }
        } else {
            if (Test-Path $EnvFile) {
                Remove-Item -Path $EnvFile -Force -ErrorAction SilentlyContinue
                Write-Host " -> includes/.env temporal eliminado correctamente." -ForegroundColor Green
            }
        }
    }
}

if (-not $testSuccess) {
    exit $testExitCode
}
exit 0
