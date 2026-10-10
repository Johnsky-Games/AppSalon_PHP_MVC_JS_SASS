# ==============================================================================
# Verificacion de Resiliencia, Aislamiento y Manejo de Errores de Archivo
# Comprueba:
# 1. Fallo al crear respaldo: no continua preparacion y preserva .env original intacto
# 2. Fallo controlado en preparacion con el script real: salida no cero, restauracion byte a byte y preservacion de recursos ajenos
# 3. .env previo con credenciales ajenas/distintas: migraciones se ejecutan exclusivamente en bases aisladas y .env se restaura identico
# 4. Fallo en restauracion: preserva exclusivamente el nuevo respaldo en disco, respeta respaldos previos y sale con codigo no cero
# ==============================================================================
param(
    [switch]$SimularFalloRespaldoHarness
)

$ErrorActionPreference = "Stop"

$HARNESS_ID = [System.Guid]::NewGuid().ToString("N")
$RootDir = Split-Path -Parent $PSScriptRoot
Set-Location $RootDir

Write-Host "======================================================================" -ForegroundColor Cyan
Write-Host "SUITE DE VERIFICACION DE RESILIENCIA Y MANEJO DE ERRORES DE ARCHIVO" -ForegroundColor Cyan
Write-Host "Identificador unico de ejecucion del arnes (HARNESS_ID): $HARNESS_ID" -ForegroundColor Cyan
Write-Host "======================================================================" -ForegroundColor Cyan

$EnvFile = "includes/.env"
$OriginalEnvBackup = "includes/.env.harness_backup.$HARNESS_ID"
$EnvExistedOriginally = Test-Path $EnvFile -ErrorAction Stop
$OriginalInitialHash = $null
$HarnessBackupValid = $false
$harnessExitCode = 1
$harnessSuccess = $false

# Registro seguro de recursos creados exclusivamente por este arnes
$registeredContainers = [System.Collections.Generic.List[string]]::new()
$registeredNetworks = [System.Collections.Generic.List[string]]::new()

function Register-HarnessContainer([string]$name) {
    if (-not $registeredContainers.Contains($name)) {
        $registeredContainers.Add($name)
    }
}

function Register-HarnessNetwork([string]$name) {
    if (-not $registeredNetworks.Contains($name)) {
        $registeredNetworks.Add($name)
    }
}

function Cleanup-HarnessResources {
    foreach ($c in $registeredContainers.ToArray()) {
        docker rm -f $c 2>&1 | Out-Null
    }
    $registeredContainers.Clear()

    foreach ($n in $registeredNetworks.ToArray()) {
        docker network rm $n 2>&1 | Out-Null
    }
    $registeredNetworks.Clear()
}

# 1. Respaldo inicial estricto antes de modificar cualquier archivo o iniciar preparacion
if ($EnvExistedOriginally) {
    try {
        $OriginalInitialHash = (Get-FileHash -Path $EnvFile -Algorithm SHA256 -ErrorAction Stop).Hash

        if ($SimularFalloRespaldoHarness -or ($env:APPSALON_HARNESS_SIMULAR_FALLO -eq "respaldo")) {
            throw "Simulacion controlada de fallo al crear el respaldo inicial del arnes ($OriginalEnvBackup)."
        }

        Copy-Item -Path $EnvFile -Destination $OriginalEnvBackup -Force -ErrorAction Stop

        if (-not (Test-Path $OriginalEnvBackup -ErrorAction Stop)) {
            throw "El archivo de respaldo del arnes ($OriginalEnvBackup) no existe tras la copia."
        }

        $backupHash = (Get-FileHash -Path $OriginalEnvBackup -Algorithm SHA256 -ErrorAction Stop).Hash
        if ($backupHash -ne $OriginalInitialHash) {
            throw "El hash del respaldo del arnes ($backupHash) no coincide con el archivo original ($OriginalInitialHash)."
        }

        $HarnessBackupValid = $true
        Write-Host " -> Respaldo previo del arnes asegurado y verificado ($backupHash)." -ForegroundColor Green
    } catch {
        Write-Host ""
        Write-Host "[ERROR CRITICO EN ARNES] $($_.Exception.Message)" -ForegroundColor Red
        Write-Host "Operacion cancelada antes de modificar $($EnvFile) o iniciar cualquier preparacion." -ForegroundColor Yellow
        if (Test-Path $OriginalEnvBackup) {
            Remove-Item -Path $OriginalEnvBackup -Force -ErrorAction SilentlyContinue
        }
        exit 1
    }
} else {
    Write-Host " -> Sin includes/.env previo; se garantizara limpieza al finalizar." -ForegroundColor Cyan
}

try {
    # ------------------------------------------------------------------
    # TEST 1: FALLO AL CREAR EL RESPALDO EN EL SCRIPT REAL
    # ------------------------------------------------------------------
    Write-Host ""
    Write-Host ">>> [TEST 1] Fallo al crear respaldo en script real..." -ForegroundColor Yellow
    
    $test1EnvContent = "MARCADOR_TEST_1=archivo_original_intacto_$(Get-Random)`nAPP_ENV=local"
    $test1EnvContent | Set-Content $EnvFile -Encoding UTF8 -ErrorAction Stop
    $test1HashAntes = (Get-FileHash -Path $EnvFile -Algorithm SHA256 -ErrorAction Stop).Hash

    # Ejecutar el script real con bandera de simulacion de fallo en respaldo
    powershell -ExecutionPolicy Bypass -File .\scripts\reproducir_entrega1.ps1 -SimularFalloRespaldo
    $test1ExitCode = $LASTEXITCODE

    $test1HashDespues = (Get-FileHash -Path $EnvFile -Algorithm SHA256 -ErrorAction Stop).Hash
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
    $test2EnvContent | Set-Content $EnvFile -Encoding UTF8 -ErrorAction Stop
    $test2HashAntes = (Get-FileHash -Path $EnvFile -Algorithm SHA256 -ErrorAction Stop).Hash

    $netTest2 = "red-ajena-t2-$HARNESS_ID"
    $contTest2 = "cont-ajeno-t2-$HARNESS_ID"

    docker network create $netTest2 2>&1 | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw "Fallo al crear red de prueba ajena $netTest2 (codigo $LASTEXITCODE)"
    }
    Register-HarnessNetwork $netTest2

    docker run -d --name $contTest2 --network $netTest2 alpine sleep 300 2>&1 | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw "Fallo al crear contenedor ajeno $contTest2 (codigo $LASTEXITCODE)"
    }
    Register-HarnessContainer $contTest2

    # Ejecutar el script real con bandera de simulacion de fallo en preparacion
    powershell -ExecutionPolicy Bypass -File .\scripts\reproducir_entrega1.ps1 -SimularFalloPreparacion
    $test2ExitCode = $LASTEXITCODE

    $test2HashDespues = (Get-FileHash -Path $EnvFile -Algorithm SHA256 -ErrorAction Stop).Hash
    $test2RestauradoByteAByte = ($test2HashAntes -eq $test2HashDespues)
    $ajenoSigueVivo2 = (docker ps -q --filter "name=$contTest2").Length -gt 0
    $redAjenaSigueViva2 = (docker network ls -q --filter "name=$netTest2").Length -gt 0

    Write-Host " -> Codigo de salida capturado: $test2ExitCode (Esperado: distinto de 0)"
    Write-Host " -> Restauracion byte a byte SHA-256 verificado: $test2RestauradoByteAByte"
    Write-Host " -> Contenedor ajeno sigue vivo: $ajenoSigueVivo2"
    Write-Host " -> Red ajena sigue viva: $redAjenaSigueViva2"

    # Limpieza inmediata y desregistro de recursos del Test 2
    docker rm -f $contTest2 2>&1 | Out-Null
    $registeredContainers.Remove($contTest2) | Out-Null
    docker network rm $netTest2 2>&1 | Out-Null
    $registeredNetworks.Remove($netTest2) | Out-Null

    if ($test2ExitCode -eq 0) {
        throw "[TEST 2 FALLIDO] El script debio fallar con codigo no cero."
    }
    if (-not $test2RestauradoByteAByte) {
        throw "[TEST 2 FALLIDO] El archivo includes/.env no fue restaurado byte a byte."
    }
    if (-not $ajenoSigueVivo2) {
        throw "[TEST 2 FALLIDO] El contenedor ajeno fue eliminado indebidamente."
    }
    if (-not $redAjenaSigueViva2) {
        throw "[TEST 2 FALLIDO] La red ajena fue eliminada indebidamente."
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
    $test3EnvContent | Set-Content $EnvFile -Encoding UTF8 -ErrorAction Stop
    $test3HashAntes = (Get-FileHash -Path $EnvFile -Algorithm SHA256 -ErrorAction Stop).Hash

    $netTest3 = "red-ajena-t3-$HARNESS_ID"
    $contTest3 = "cont-ajeno-t3-$HARNESS_ID"

    docker network create $netTest3 2>&1 | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw "Fallo al crear red de prueba ajena $netTest3 (codigo $LASTEXITCODE)"
    }
    Register-HarnessNetwork $netTest3

    docker run -d --name $contTest3 --network $netTest3 alpine sleep 300 2>&1 | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw "Fallo al crear contenedor ajeno $contTest3 (codigo $LASTEXITCODE)"
    }
    Register-HarnessContainer $contTest3

    # Ejecutar reproduccion completa real
    powershell -ExecutionPolicy Bypass -File .\scripts\reproducir_entrega1.ps1
    $test3ExitCode = $LASTEXITCODE

    $test3HashDespues = (Get-FileHash -Path $EnvFile -Algorithm SHA256 -ErrorAction Stop).Hash
    $test3RestauradoByteAByte = ($test3HashAntes -eq $test3HashDespues)
    $ajenoSigueVivo3 = (docker ps -q --filter "name=$contTest3").Length -gt 0
    $redAjenaSigueViva3 = (docker network ls -q --filter "name=$netTest3").Length -gt 0

    Write-Host ""
    Write-Host "Comprobaciones post-ejecucion TEST 3:"
    Write-Host " -> Codigo de salida de reproducir_entrega1.ps1: $test3ExitCode"
    Write-Host " -> Restauracion byte a byte SHA-256 verificado: $test3RestauradoByteAByte"
    Write-Host " -> Contenedor ajeno sigue vivo: $ajenoSigueVivo3"
    Write-Host " -> Red ajena sigue viva: $redAjenaSigueViva3"

    docker rm -f $contTest3 2>&1 | Out-Null
    $registeredContainers.Remove($contTest3) | Out-Null
    docker network rm $netTest3 2>&1 | Out-Null
    $registeredNetworks.Remove($netTest3) | Out-Null

    if ($test3ExitCode -ne 0) {
        throw "[TEST 3 FALLIDO] La reproduccion completa debio terminar con codigo 0."
    }
    if (-not $test3RestauradoByteAByte) {
        throw "[TEST 3 FALLIDO] El archivo includes/.env no fue restaurado byte a byte a las credenciales originales."
    }
    if (-not $ajenoSigueVivo3) {
        throw "[TEST 3 FALLIDO] El contenedor ajeno fue eliminado indebidamente."
    }
    if (-not $redAjenaSigueViva3) {
        throw "[TEST 3 FALLIDO] La red ajena fue eliminada indebidamente."
    }
    Write-Host "[OK - TEST 3 SUPERADO] Migraciones y pruebas se ejecutaron en aislamiento sin verse afectadas por las credenciales previas; .env restaurado byte a byte y recursos ajenos conservados." -ForegroundColor Green

    # ------------------------------------------------------------------
    # TEST 4: FALLO EN RESTAURACION Y PRESERVACION DEL RESPALDO
    # ------------------------------------------------------------------
    Write-Host ""
    Write-Host ">>> [TEST 4] Fallo en restauracion: comprueba que el respaldo se preserva intacto y salida no cero..." -ForegroundColor Yellow

    $test4EnvContent = "MARCADOR_TEST_4=archivo_original_a_preservar_$(Get-Random)`nAPP_KEY=clave_secreta_test4"
    $test4EnvContent | Set-Content $EnvFile -Encoding UTF8 -ErrorAction Stop
    $test4HashAntes = (Get-FileHash -Path $EnvFile -Algorithm SHA256 -ErrorAction Stop).Hash

    $netTest4 = "red-ajena-t4-$HARNESS_ID"
    $contTest4 = "cont-ajeno-t4-$HARNESS_ID"

    docker network create $netTest4 2>&1 | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw "Fallo al crear red de prueba ajena $netTest4 (codigo $LASTEXITCODE)"
    }
    Register-HarnessNetwork $netTest4

    docker run -d --name $contTest4 --network $netTest4 alpine sleep 300 2>&1 | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw "Fallo al crear contenedor ajeno $contTest4 (codigo $LASTEXITCODE)"
    }
    Register-HarnessContainer $contTest4

    # Crear deliberadamente un archivo de respaldo preexistente para comprobar que NO sea borrado ni alterado
    $preexistingBakFile = "includes/.env.bak.preexistente_$HARNESS_ID"
    "CONTENIDO_RESPALDO_PREEXISTENTE_TEST4_$(Get-Random)" | Set-Content $preexistingBakFile -Encoding UTF8 -ErrorAction Stop
    $preexistingBakHashAntes = (Get-FileHash -Path $preexistingBakFile -Algorithm SHA256 -ErrorAction Stop).Hash

    # 1. Registrar rutas y hashes de todos los respaldos existentes antes del subproceso
    $archivosPrevios = @(Get-ChildItem -Path "includes" -Filter ".env.bak.*" -ErrorAction SilentlyContinue)
    $rutasPrevias = @($archivosPrevios | ForEach-Object { $_.FullName })
    $hashesPrevios = @{}
    foreach ($prev in $archivosPrevios) {
        $hashesPrevios[$prev.FullName] = (Get-FileHash -Path $prev.FullName -Algorithm SHA256 -ErrorAction Stop).Hash
    }

    # 2. Ejecutar con fallo en preparacion y fallo forzado en restauracion
    powershell -ExecutionPolicy Bypass -File .\scripts\reproducir_entrega1.ps1 -SimularFalloPreparacion -SimularFalloRestauracion
    $test4ExitCode = $LASTEXITCODE

    # 3. Detectar exclusivamente el NUEVO respaldo creado
    $archivosPosteriores = @(Get-ChildItem -Path "includes" -Filter ".env.bak.*" -ErrorAction SilentlyContinue)
    $respaldosNuevos = @($archivosPosteriores | Where-Object { $_.FullName -notin $rutasPrevias })

    $cantidadNuevos = $respaldosNuevos.Count
    $nuevoRespaldo = if ($cantidadNuevos -eq 1) { $respaldosNuevos[0] } else { $null }
    $backupHash = if ($null -ne $nuevoRespaldo) { (Get-FileHash -Path $nuevoRespaldo.FullName -Algorithm SHA256 -ErrorAction Stop).Hash } else { "" }
    $backupCoincide = ($backupHash -eq $test4HashAntes)

    # 4. Verificar que todos los respaldos preexistentes permanezcan intactos con el mismo hash
    $preexistentesIntactos = $true
    foreach ($prevPath in $hashesPrevios.Keys) {
        if (-not (Test-Path $prevPath -ErrorAction SilentlyContinue)) {
            $preexistentesIntactos = $false
            break
        }
        $currentHash = (Get-FileHash -Path $prevPath -Algorithm SHA256 -ErrorAction Stop).Hash
        if ($currentHash -ne $hashesPrevios[$prevPath]) {
            $preexistentesIntactos = $false
            break
        }
    }

    $ajenoSigueVivo4 = (docker ps -q --filter "name=$contTest4").Length -gt 0
    $redAjenaSigueViva4 = (docker network ls -q --filter "name=$netTest4").Length -gt 0

    Write-Host " -> Codigo de salida capturado: $test4ExitCode (Esperado: distinto de 0)"
    Write-Host " -> Cantidad de respaldos nuevos detectados: $cantidadNuevos (Esperado: exactamente 1)"
    Write-Host " -> Archivo de nuevo respaldo preservado en disco: ($($nuevoRespaldo.Name))"
    Write-Host " -> Hash del nuevo respaldo coincide byte a byte con original: $backupCoincide"
    Write-Host " -> Respaldos preexistentes permanecen intactos: $preexistentesIntactos"
    Write-Host " -> Contenedor ajeno sigue vivo: $ajenoSigueVivo4"
    Write-Host " -> Red ajena sigue viva: $redAjenaSigueViva4"

    # 5. Limpieza de recursos ajenos y EXCLUSIVAMENTE del nuevo respaldo verificado
    docker rm -f $contTest4 2>&1 | Out-Null
    $registeredContainers.Remove($contTest4) | Out-Null
    docker network rm $netTest4 2>&1 | Out-Null
    $registeredNetworks.Remove($netTest4) | Out-Null

    if ($null -ne $nuevoRespaldo -and (Test-Path $nuevoRespaldo.FullName)) {
        Remove-Item -Path $nuevoRespaldo.FullName -Force -ErrorAction SilentlyContinue
    }
    if (Test-Path $preexistingBakFile) {
        Remove-Item -Path $preexistingBakFile -Force -ErrorAction SilentlyContinue
    }

    # 6. Aserciones estrictas
    if ($test4ExitCode -eq 0) {
        throw "[TEST 4 FALLIDO] El script debio fallar ante fallo de restauracion con codigo no cero."
    }
    if ($cantidadNuevos -ne 1) {
        throw "[TEST 4 FALLIDO] Se esperaba exactamente 1 nuevo respaldo preservado, pero se encontraron: $cantidadNuevos"
    }
    if (-not $backupCoincide) {
        throw "[TEST 4 FALLIDO] El hash del archivo de respaldo preservado ($backupHash) no coincide con el archivo original ($test4HashAntes)."
    }
    if (-not $preexistentesIntactos) {
        throw "[TEST 4 FALLIDO] Un archivo de respaldo preexistente fue alterado o eliminado indebidamente."
    }
    if (-not $ajenoSigueVivo4) {
        throw "[TEST 4 FALLIDO] El contenedor ajeno fue eliminado indebidamente."
    }
    if (-not $redAjenaSigueViva4) {
        throw "[TEST 4 FALLIDO] La red ajena fue eliminada indebidamente."
    }
    Write-Host "[OK - TEST 4 SUPERADO] Fallo de restauracion preservo exclusivamente el nuevo respaldo intacto en disco, respeto respaldos preexistentes, retorno salida no cero y conservo recursos ajenos." -ForegroundColor Green

    Write-Host ""
    Write-Host "======================================================================" -ForegroundColor Green
    Write-Host "TODAS LAS VERIFICACIONES DE RESILIENCIA Y RESTAURACION FUERON SUPERADAS" -ForegroundColor Green
    Write-Host "======================================================================" -ForegroundColor Green
    $harnessSuccess = $true
    $harnessExitCode = 0
} catch {
    Write-Host ""
    Write-Host "[FALLO EN ARNES DE PRUEBAS] $($_.Exception.Message)" -ForegroundColor Red
    $harnessSuccess = $false
    $harnessExitCode = 1
} finally {
    Write-Host ""
    Write-Host "[LIMPIEZA FINAL DEL ARNES] Restaurando estado y eliminando recursos propios ($HARNESS_ID)..." -ForegroundColor Cyan
    Cleanup-HarnessResources

    # Restaurar la configuracion original de forma estricta
    if ($EnvExistedOriginally) {
        if ($HarnessBackupValid -and (Test-Path $OriginalEnvBackup)) {
            try {
                Copy-Item -Path $OriginalEnvBackup -Destination $EnvFile -Force -ErrorAction Stop
                $restoredHash = (Get-FileHash -Path $EnvFile -Algorithm SHA256 -ErrorAction Stop).Hash
                if ($restoredHash -ne $OriginalInitialHash) {
                    throw "Discrepancia de integridad SHA-256 en la restauracion final del arnes ($restoredHash vs $OriginalInitialHash)."
                }
                # Eliminar el respaldo unicamente tras verificar la restauracion
                Remove-Item -Path $OriginalEnvBackup -Force -ErrorAction Stop
                Write-Host " -> includes/.env original restaurado con exito por el arnes (SHA-256 verificado)." -ForegroundColor Green
            } catch {
                Write-Host "[ERROR CRITICO] Fallo en la restauracion final del arnes: $($_.Exception.Message)" -ForegroundColor Red
                Write-Host "El respaldo fue preservado en: $OriginalEnvBackup para recuperacion manual." -ForegroundColor Yellow
                $harnessSuccess = $false
                $harnessExitCode = 1
            }
        } else {
            Write-Host "[ERROR CRITICO] El arnes no cuenta con un respaldo verificado valido para restaurar." -ForegroundColor Red
            $harnessSuccess = $false
            $harnessExitCode = 1
        }
    } else {
        if (Test-Path $EnvFile) {
            try {
                Remove-Item -Path $EnvFile -Force -ErrorAction Stop
                if (Test-Path $EnvFile) {
                    throw "El archivo temporal $($EnvFile) sigue existiendo tras intentar eliminarlo."
                }
                Write-Host " -> includes/.env temporal generado por el arnes eliminado correctamente." -ForegroundColor Green
            } catch {
                Write-Host "[ERROR CRITICO] No se pudo eliminar el archivo temporal $($EnvFile): $($_.Exception.Message)" -ForegroundColor Red
                $harnessSuccess = $false
                $harnessExitCode = 1
            }
        }
    }
}

if (-not $harnessSuccess) {
    exit $harnessExitCode
}
