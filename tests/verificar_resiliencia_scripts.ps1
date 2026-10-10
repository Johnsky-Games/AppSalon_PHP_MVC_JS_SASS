# ==============================================================================
# Verificacion de Resiliencia y Aislamiento de Scripts de Reproduccion
# Prueba:
# 1. Fallo deliberado durante la preparacion (termina con error, limpia sus recursos y restaura configuracion)
# 2. Preservacion de recursos ajenos y restauracion de configuracion previa
# ==============================================================================
$ErrorActionPreference = "Continue"

$RootDir = Split-Path -Parent $PSScriptRoot
Set-Location $RootDir

Write-Host "======================================================================" -ForegroundColor Cyan
Write-Host "VERIFICACION DE ESCENARIOS DE RESILIENCIA Y AISLAMIENTO DE SCRIPTS" -ForegroundColor Cyan
Write-Host "======================================================================" -ForegroundColor Cyan

$EnvFile = "includes/.env"
$OriginalEnvBackup = "includes/.env.test_original_backup"
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
    # ESCENARIO A: FALLO DELIBERADO DURANTE LA PREPARACION
    # ------------------------------------------------------------------
    Write-Host ""
    Write-Host ">>> Probando Escenario A: Fallo deliberado durante la preparacion..." -ForegroundColor Yellow
    
    # 1. Configurar estado previo
    $fakeOriginalEnv = "MARCADOR_CONFIG_ORIGINAL=valor_secreto_12345`nAPP_ENV=local_dev"
    $fakeOriginalEnv | Set-Content $EnvFile -Encoding UTF8

    # 2. Iniciar contenedor y red ajenos
    docker network create red-ajena-a 2>&1 | Out-Null
    docker run -d --name contenedor-ajeno-a --network red-ajena-a alpine sleep 300 2>&1 | Out-Null

    # 3. Crear script de prueba que falla deliberadamente en el paso de preparacion
    $testFailScript = "scripts/test_fail_deliberate.ps1"
    $failScriptContent = @"
`$ErrorActionPreference = "Continue"
`$RUN_ID = "fail-$(Get-Random)"
`$NetName = "appsalon-net-`$RUN_ID"
`$DbContainer = "appsalon-db-`$RUN_ID"
`$EnvFile = "includes/.env"
`$EnvExisted = Test-Path `$EnvFile
`$EnvBackup = "includes/.env.bak.`$RUN_ID"

function Cleanup-Resources {
    Write-Host " -> Ejecutando Cleanup-Resources..."
    docker rm -f `$DbContainer 2>&1 | Out-Null
    docker network rm `$NetName 2>&1 | Out-Null
    if (`$EnvExisted) {
        if (Test-Path `$EnvBackup) {
            Move-Item -Path `$EnvBackup -Destination `$EnvFile -Force
        }
    } else {
        if (Test-Path `$EnvFile) {
            Remove-Item -Path `$EnvFile -Force
        }
    }
}

`$scriptSuccess = `$false
`$scriptExitCode = 0

try {
    if (`$EnvExisted) {
        Copy-Item -Path `$EnvFile -Destination `$EnvBackup -Force
    }
    # Crear red y contenedor propio
    docker network create `$NetName | Out-Null
    docker run -d --name `$DbContainer --network `$NetName alpine sleep 300 | Out-Null
    
    # Sobrescribir temporalmente .env
    "DB_NAME=appsalon_fail_test" | Set-Content `$EnvFile -Encoding UTF8
    
    # Provocar fallo deliberado durante la preparacion
    Write-Host " -> Provocando fallo deliberado con codigo de salida 42..."
    `$fakeCmdExitCode = 42
    if (`$fakeCmdExitCode -ne 0) {
        throw "Fallo simulado en paso de preparacion (codigo: `$fakeCmdExitCode)"
    }
    `$scriptSuccess = `$true
}
catch {
    `$scriptSuccess = `$false
    `$scriptExitCode = 42
    Write-Host "[ERROR CONTROLADO] `$(`$_.Exception.Message)"
}
finally {
    Cleanup-Resources
    if (-not `$scriptSuccess) {
        exit `$scriptExitCode
    }
}
"@
    $failScriptContent | Set-Content $testFailScript -Encoding UTF8

    # 4. Ejecutar script y capturar codigo de salida
    powershell -ExecutionPolicy Bypass -File $testFailScript
    $failExitCode = $LASTEXITCODE

    Remove-Item $testFailScript -Force -ErrorAction SilentlyContinue

    # 5. Comprobaciones de Escenario A
    $ajenoSigueVivoA = (docker ps -q --filter "name=contenedor-ajeno-a").Length -gt 0
    $redAjenaSigueVivaA = (docker network ls -q --filter "name=red-ajena-a").Length -gt 0
    $envRestauradoA = (Get-Content $EnvFile -Raw) -eq ($fakeOriginalEnv + "`r`n") -or (Get-Content $EnvFile -Raw) -eq $fakeOriginalEnv

    Write-Host " -> Codigo de salida obtenido: $failExitCode (Esperado: 42 o distinto de cero)"
    Write-Host " -> Contenedor ajeno conservado: $ajenoSigueVivoA"
    Write-Host " -> Red ajena conservada: $redAjenaSigueVivaA"
    Write-Host " -> Configuracion includes/.env restaurada: $envRestauradoA"

    docker rm -f contenedor-ajeno-a 2>&1 | Out-Null
    docker network rm red-ajena-a 2>&1 | Out-Null

    if ($failExitCode -eq 0) {
        throw "El script con fallo deliberado devolvio exitoso (codigo 0), debio fallar."
    }
    if (-not $ajenoSigueVivoA) {
        throw "El contenedor ajeno fue eliminado indebidamente."
    }
    if (-not $envRestauradoA) {
        throw "El archivo includes/.env no fue restaurado correctamente tras el fallo."
    }

    Write-Host "[OK] Escenario A superado exitosamente." -ForegroundColor Green

    # ------------------------------------------------------------------
    # ESCENARIO B: PRESERVACION DE RECURSOS AJENOS EN EJECUCION REAL
    # ------------------------------------------------------------------
    Write-Host ""
    Write-Host ">>> Probando Escenario B: Preservacion de recursos ajenos y restauracion de .env..." -ForegroundColor Yellow

    # 1. Configurar estado previo
    $previaConfig = "ORIGINAL_PROD_DATABASE=appsalon_produccion`nCLAVE_SECRETA_SISTEMA=xyz987654`nAPP_ENV=production"
    $previaConfig | Set-Content $EnvFile -Encoding UTF8

    # 2. Iniciar contenedor y red ajenos
    docker network create red-ajena-b 2>&1 | Out-Null
    docker run -d --name contenedor-ajeno-b --network red-ajena-b alpine sleep 300 2>&1 | Out-Null

    Write-Host " -> Contenedor ajeno 'contenedor-ajeno-b' creado antes de la prueba."

    # 3. Ejecutar script de reproduccion integral
    powershell -ExecutionPolicy Bypass -File .\scripts\reproducir_entrega1.ps1
    $reproExitCode = $LASTEXITCODE

    # 4. Comprobaciones de Escenario B
    $ajenoSigueVivoB = (docker ps -q --filter "name=contenedor-ajeno-b").Length -gt 0
    $redAjenaSigueVivaB = (docker network ls -q --filter "name=red-ajena-b").Length -gt 0
    $currentEnv = Get-Content $EnvFile -Raw
    $envRestauradoB = ($currentEnv -eq ($previaConfig + "`r`n")) -or ($currentEnv -eq $previaConfig)

    Write-Host ""
    Write-Host "Comprobaciones post-ejecucion Escenario B:"
    Write-Host " -> Codigo de salida de reproducir_entrega1.ps1: $reproExitCode"
    Write-Host " -> Contenedor ajeno 'contenedor-ajeno-b' sigue vivo: $ajenoSigueVivoB"
    Write-Host " -> Red ajena 'red-ajena-b' sigue presente: $redAjenaSigueVivaB"
    Write-Host " -> includes/.env restaurado identico: $envRestauradoB"

    # Limpieza de recursos ajenos de prueba
    docker rm -f contenedor-ajeno-b 2>&1 | Out-Null
    docker network rm red-ajena-b 2>&1 | Out-Null

    if ($reproExitCode -ne 0) {
        throw "La reproduccion integral devolvio codigo de salida no cero ($reproExitCode)."
    }
    if (-not $ajenoSigueVivoB) {
        throw "El contenedor ajeno 'contenedor-ajeno-b' fue eliminado indebidamente."
    }
    if (-not $envRestauradoB) {
        throw "El archivo includes/.env no fue restaurado a su contenido original."
    }

    Write-Host "[OK] Escenario B superado exitosamente." -ForegroundColor Green

    Write-Host ""
    Write-Host "======================================================================" -ForegroundColor Green
    Write-Host "TODOS LOS ESCENARIOS DE RESILIENCIA Y PRESERVACION FUERON SUPERADOS" -ForegroundColor Green
    Write-Host "======================================================================" -ForegroundColor Green
}
finally {
    Global-Restore-Env
}
