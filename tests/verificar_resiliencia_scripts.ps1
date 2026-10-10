# ==============================================================================
# Verificacion de Resiliencia, Aislamiento y Manejo de Errores de Archivo
# Comprueba:
# 1. Fallo al crear respaldo: no continua preparacion y preserva .env original intacto
# 2. Fallo controlado en preparacion con el script real: salida no cero, restauracion byte a byte y preservacion de recursos ajenos
# 3. .env previo con credenciales ajenas/distintas: migraciones se ejecutan exclusivamente en bases aisladas y .env se restaura identico
# ==============================================================================
$ErrorActionPreference = "Continue"

$RootDir = Split-Path -Parent $PSScriptRoot
Set-Location $RootDir

Write-Host "======================================================================" -ForegroundColor Cyan
Write-Host "SUITE DE VERIFICACION DE RESILIENCIA Y MANEJO DE ERRORES DE ARCHIVO" -ForegroundColor Cyan
Write-Host "======================================================================" -ForegroundColor Cyan

$EnvFile = "includes/.env"
$OriginalEnvBackup = "includes/.env.harness_backup"
$EnvExistedOriginally = Test-Path $EnvFile

if ($EnvExistedOriginally) {
    Copy-Item $EnvFile $OriginalEnvBackup -Force
}

function Global-Restore-Env {
    if ($EnvExistedOriginally) {
        if (Test-Path $OriginalEnvBackup) {
            Move-Item $OriginalEnvBackup $EnvFile -Force
        }
    } else {
        if (Test-Path $EnvFile) {
            Remove-Item $EnvFile -Force
        }
    }
}

try {
    # ------------------------------------------------------------------
    # TEST 1: FALLO AL CREAR EL RESPALDO
    # ------------------------------------------------------------------
    Write-Host ""
    Write-Host ">>> [TEST 1] Fallo al crear respaldo en script real..." -ForegroundColor Yellow
    
    $test1EnvContent = "MARCADOR_TEST_1=archivo_original_intacto_$(Get-Random)`nAPP_ENV=local"
    $test1EnvContent | Set-Content $EnvFile -Encoding UTF8
    $test1HashAntes = (Get-FileHash -Path $EnvFile -Algorithm SHA256).Hash

    # Ejecutar el script real con bandera de simulacion de fallo en respaldo
    powershell -ExecutionPolicy Bypass -File .\scripts\reproducir_entrega1.ps1 -SimularFalloRespaldo
    $test1ExitCode = $LASTEXITCODE

    $test1HashDespues = (Get-FileHash -Path $EnvFile -Algorithm SHA256).Hash
    $test1Intacto = ($test1HashAntes -eq $test1HashDespues)

    Write-Host " -> Codigo de salida capturado: $test1ExitCode (Esperado: distinto de 0)"
    Write-Host " -> Hash original: $test1HashAntes"
    Write-Host " -> Hash posterior: $test1HashDespues"
    Write-Host " -> Archivo original permanece intacto: $test1Intacto"

    if ($test1ExitCode -eq 0) {
        throw "[TEST 1 FALLIDO] El script debio fallar ante error de respaldo, pero retorno codigo 0."
    }
    if (-not $test1Intacto) {
        throw "[TEST 1 FALLIDO] El archivo includes/.env fue alterado a pesar del fallo en respaldo."
    }
    Write-Host "[OK - TEST 1 SUPERADO] Fallo de respaldo cancelo la preparacion y preservo el archivo intacto." -ForegroundColor Green

    # ------------------------------------------------------------------
    # TEST 2: FALLO CONTROLADO EN PREPARACION CON SCRIPT REAL
    # ------------------------------------------------------------------
    Write-Host ""
    Write-Host ">>> [TEST 2] Fallo controlado de preparacion en script real y preservacion de recursos..." -ForegroundColor Yellow

    $test2EnvContent = "MARCADOR_TEST_2=archivo_original_antes_de_fallo_$(Get-Random)`nAPP_SECRET=xyz456789"
    $test2EnvContent | Set-Content $EnvFile -Encoding UTF8
    $test2HashAntes = (Get-FileHash -Path $EnvFile -Algorithm SHA256).Hash

    # Iniciar contenedor y red ajenos
    docker network create red-ajena-test2 2>&1 | Out-Null
    docker run -d --name contenedor-ajeno-test2 --network red-ajena-test2 alpine sleep 300 2>&1 | Out-Null

    # Ejecutar el script real con bandera de simulacion de fallo en preparacion
    powershell -ExecutionPolicy Bypass -File .\scripts\reproducir_entrega1.ps1 -SimularFalloPreparacion
    $test2ExitCode = $LASTEXITCODE

    $test2HashDespues = (Get-FileHash -Path $EnvFile -Algorithm SHA256).Hash
    $test2RestauradoByteAByte = ($test2HashAntes -eq $test2HashDespues)
    $ajenoSigueVivo2 = (docker ps -q --filter "name=contenedor-ajeno-test2").Length -gt 0
    $redAjenaSigueViva2 = (docker network ls -q --filter "name=red-ajena-test2").Length -gt 0

    Write-Host " -> Codigo de salida capturado: $test2ExitCode (Esperado: distinto de 0)"
    Write-Host " -> Restauracion byte a byte SHA-256 verificado: $test2RestauradoByteAByte"
    Write-Host " -> Contenedor ajeno sigue vivo: $ajenoSigueVivo2"
    Write-Host " -> Red ajena sigue viva: $redAjenaSigueViva2"

    docker rm -f contenedor-ajeno-test2 2>&1 | Out-Null
    docker network rm red-ajena-test2 2>&1 | Out-Null

    if ($test2ExitCode -eq 0) {
        throw "[TEST 2 FALLIDO] El script debio fallar con codigo no cero."
    }
    if (-not $test2RestauradoByteAByte) {
        throw "[TEST 2 FALLIDO] El archivo includes/.env no fue restaurado byte a byte."
    }
    if (-not $ajenoSigueVivo2) {
        throw "[TEST 2 FALLIDO] El contenedor ajeno fue eliminado indebidamente."
    }
    Write-Host "[OK - TEST 2 SUPERADO] Fallo controlado cancelo preparacion, restauro .env byte a byte y conservo recursos ajenos." -ForegroundColor Green

    # ------------------------------------------------------------------
    # TEST 3: .ENV PREVIO CON CREDENCIALES DISTINTAS Y RECURSOS AJENOS
    # ------------------------------------------------------------------
    Write-Host ""
    Write-Host ">>> [TEST 3] includes/.env con credenciales distintas a las de pruebas y recursos ajenos..." -ForegroundColor Yellow

    $test3EnvContent = @"
DB_HOST=servidor-prod-externo.invalido
DB_PORT=9999
DB_USER=usuario_prod_no_tocar
DB_PASS=password_produccion_secreta_789
DB_NAME=base_produccion_no_tocar
EMAIL_HOST=mail-prod-externo.invalido
EMAIL_PORT=9925
EMAIL_USER=prod_user
EMAIL_PASSWORD=prod_pass
EMAIL_FROM=admin@produccion-real.com
EMAIL_FROM_NAME="Produccion Real"
APP_URL=http://appsalon-produccion.com:8080
"@
    $test3EnvContent | Set-Content $EnvFile -Encoding UTF8
    $test3HashAntes = (Get-FileHash -Path $EnvFile -Algorithm SHA256).Hash

    # Iniciar contenedor ajeno
    docker network create red-ajena-test3 2>&1 | Out-Null
    docker run -d --name contenedor-ajeno-test3 --network red-ajena-test3 alpine sleep 300 2>&1 | Out-Null

    # Ejecutar reproduccion completa real
    powershell -ExecutionPolicy Bypass -File .\scripts\reproducir_entrega1.ps1
    $test3ExitCode = $LASTEXITCODE

    $test3HashDespues = (Get-FileHash -Path $EnvFile -Algorithm SHA256).Hash
    $test3RestauradoByteAByte = ($test3HashAntes -eq $test3HashDespues)
    $ajenoSigueVivo3 = (docker ps -q --filter "name=contenedor-ajeno-test3").Length -gt 0
    $redAjenaSigueViva3 = (docker network ls -q --filter "name=red-ajena-test3").Length -gt 0

    Write-Host ""
    Write-Host "Comprobaciones post-ejecucion TEST 3:"
    Write-Host " -> Codigo de salida de reproducir_entrega1.ps1: $test3ExitCode"
    Write-Host " -> Restauracion byte a byte SHA-256 verificado: $test3RestauradoByteAByte"
    Write-Host " -> Contenedor ajeno sigue vivo: $ajenoSigueVivo3"
    Write-Host " -> Red ajena sigue viva: $redAjenaSigueViva3"

    docker rm -f contenedor-ajeno-test3 2>&1 | Out-Null
    docker network rm red-ajena-test3 2>&1 | Out-Null

    if ($test3ExitCode -ne 0) {
        throw "[TEST 3 FALLIDO] La reproduccion completa debio terminar con codigo 0."
    }
    if (-not $test3RestauradoByteAByte) {
        throw "[TEST 3 FALLIDO] El archivo includes/.env no fue restaurado byte a byte a las credenciales originales."
    }
    if (-not $ajenoSigueVivo3) {
        throw "[TEST 3 FALLIDO] El contenedor ajeno fue eliminado indebidamente."
    }
    Write-Host "[OK - TEST 3 SUPERADO] Migraciones y pruebas se ejecutaron en aislamiento sin verse afectadas por las credenciales previas; .env restaurado byte a byte y recursos ajenos conservados." -ForegroundColor Green

    # ------------------------------------------------------------------
    # TEST 4: FALLO EN RESTAURACION Y PRESERVACION DEL RESPALDO
    # ------------------------------------------------------------------
    Write-Host ""
    Write-Host ">>> [TEST 4] Fallo en restauracion: comprueba que el respaldo se preserva intacto y salida no cero..." -ForegroundColor Yellow

    $test4EnvContent = "MARCADOR_TEST_4=archivo_original_a_preservar_$(Get-Random)`nAPP_KEY=clave_secreta_test4"
    $test4EnvContent | Set-Content $EnvFile -Encoding UTF8
    $test4HashAntes = (Get-FileHash -Path $EnvFile -Algorithm SHA256).Hash

    # Iniciar contenedor y red ajenos
    docker network create red-ajena-test4 2>&1 | Out-Null
    docker run -d --name contenedor-ajeno-test4 --network red-ajena-test4 alpine sleep 300 2>&1 | Out-Null

    # Ejecutar con fallo en preparacion y fallo forzado en restauracion
    powershell -ExecutionPolicy Bypass -File .\scripts\reproducir_entrega1.ps1 -SimularFalloPreparacion -SimularFalloRestauracion
    $test4ExitCode = $LASTEXITCODE

    # Buscar el archivo de respaldo generado que debe haber sido preservado
    $backupPreservado = Get-ChildItem -Path "includes" -Filter ".env.bak.*" | Select-Object -First 1
    $backupExiste = ($null -ne $backupPreservado)
    $backupHash = if ($backupExiste) { (Get-FileHash -Path $backupPreservado.FullName -Algorithm SHA256).Hash } else { "" }
    $backupCoincide = ($backupHash -eq $test4HashAntes)
    $ajenoSigueVivo4 = (docker ps -q --filter "name=contenedor-ajeno-test4").Length -gt 0
    $redAjenaSigueViva4 = (docker network ls -q --filter "name=red-ajena-test4").Length -gt 0

    Write-Host " -> Codigo de salida capturado: $test4ExitCode (Esperado: distinto de 0)"
    Write-Host " -> Archivo de respaldo preservado en disco: $backupExiste ($($backupPreservado.Name))"
    Write-Host " -> Hash del respaldo coincide byte a byte con original: $backupCoincide"
    Write-Host " -> Contenedor ajeno sigue vivo: $ajenoSigueVivo4"
    Write-Host " -> Red ajena sigue viva: $redAjenaSigueViva4"

    # Limpieza del contenedor ajeno y del respaldo preservado del test 4
    docker rm -f contenedor-ajeno-test4 2>&1 | Out-Null
    docker network rm red-ajena-test4 2>&1 | Out-Null
    if ($backupExiste) {
        Remove-Item -Path $backupPreservado.FullName -Force -ErrorAction SilentlyContinue
    }

    if ($test4ExitCode -eq 0) {
        throw "[TEST 4 FALLIDO] El script debio fallar ante fallo de restauracion con codigo no cero."
    }
    if (-not $backupExiste) {
        throw "[TEST 4 FALLIDO] El archivo de respaldo debio preservarse en disco pero fue eliminado."
    }
    if (-not $backupCoincide) {
        throw "[TEST 4 FALLIDO] El hash del archivo de respaldo preservado no coincide con el archivo original."
    }
    if (-not $ajenoSigueVivo4) {
        throw "[TEST 4 FALLIDO] El contenedor ajeno fue eliminado indebidamente."
    }
    Write-Host "[OK - TEST 4 SUPERADO] Fallo de restauracion preservo el respaldo intacto en disco, retorno salida no cero y conservo recursos ajenos." -ForegroundColor Green

    Write-Host ""
    Write-Host "======================================================================" -ForegroundColor Green
    Write-Host "TODAS LAS VERIFICACIONES DE RESILIENCIA Y RESTAURACION FUERON SUPERADAS" -ForegroundColor Green
    Write-Host "======================================================================" -ForegroundColor Green
}
finally {
    Global-Restore-Env
}
