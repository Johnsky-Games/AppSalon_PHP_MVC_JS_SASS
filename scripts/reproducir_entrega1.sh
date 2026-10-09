#!/usr/bin/env bash
# ==============================================================================
# Script de Reproducción Integral de Entrega 1 desde Entorno Docker Limpio
# ==============================================================================
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT_DIR}"

echo "======================================================================"
echo "REPRODUCCIÓN INTEGRAL DE PRUEBAS — APPSALON ENTREGA 1"
echo "Directorio de trabajo: ${ROOT_DIR}"
echo "======================================================================"

# 1. Limpieza preventiva de recursos previos
echo "[1/8] Limpiando contenedores y redes anteriores..."
docker rm -f appsalon-web appsalon-mail appsalon-test-db 2>/dev/null || true
docker network rm appsalon-net 2>/dev/null || true

# Trampa de salida para garantizar limpieza de contenedores
cleanup() {
    echo ""
    echo "[LIMPIEZA FINAL] Deteniendo y eliminando contenedores temporales..."
    docker rm -f appsalon-web appsalon-mail appsalon-test-db 2>/dev/null || true
    docker network rm appsalon-net 2>/dev/null || true
}
trap cleanup EXIT

# 2. Verificación de dependencias de Composer y npm
echo "[2/8] Verificando dependencias de Composer y npm..."
if [ ! -f "vendor/autoload.php" ]; then
    echo " -> vendor/autoload.php no encontrado. Instalando dependencias Composer..."
    docker run --rm -v "${PWD}:/app" -w /app composer:2 install --ignore-platform-reqs --no-interaction
fi

if [ ! -d "public/build" ]; then
    echo " -> public/build no encontrado. Construyendo assets npm..."
    docker run --rm -v "${PWD}:/app" -w /app node:18 sh -c "npm install && npm run build"
fi

# 3. Creación de red aislada e inicio de base de datos MySQL 8
echo "[3/8] Creando red Docker e iniciando MySQL 8..."
docker network create appsalon-net
docker run -d --name appsalon-test-db --network appsalon-net -p 3307:3306 \
    -e MYSQL_ROOT_PASSWORD=root \
    mysql:8.0

echo " -> Esperando disponibilidad de MySQL..."
READY=0
for i in $(seq 1 60); do
    if docker exec appsalon-test-db mysqladmin ping -h 127.0.0.1 -u root -proot --silent >/dev/null 2>&1; then
        READY=1
        echo " -> MySQL 8 disponible tras ${i}s."
        break
    fi
    sleep 1
done

if [ "$READY" -ne 1 ]; then
    echo "[ERROR] Tiempo de espera agotado para disponibilidad de MySQL." >&2
    exit 1
fi

# 4. Construcción de imagen de pruebas PHP 8.2
echo "[4/8] Construyendo imagen de prueba de PHP 8.2..."
docker build -t appsalon-php-test -f Dockerfile.test .

# 5. Preparación separada de appsalon_test y appsalon_func_test
echo "[5/8] Preparando bases de datos de pruebas (appsalon_test y appsalon_func_test)..."

# Preparar appsalon_test para PHPUnit
docker exec appsalon-test-db mysql -uroot -proot -e "CREATE DATABASE IF NOT EXISTS appsalon_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
docker exec -i appsalon-test-db mysql -uroot -proot appsalon_test < database/schema_base.sql
docker run --rm --network appsalon-net -v "${PWD}:/app" -w /app \
    -e DB_HOST=appsalon-test-db -e DB_USER=root -e DB_PASS=root -e DB_NAME=appsalon_test -e DB_PORT=3306 \
    appsalon-php-test php database/migrador.php up

# Preparar appsalon_func_test para la suite funcional
docker exec appsalon-test-db mysql -uroot -proot -e "CREATE DATABASE IF NOT EXISTS appsalon_func_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
docker exec -i appsalon-test-db mysql -uroot -proot appsalon_func_test < database/schema_base.sql
docker run --rm --network appsalon-net -v "${PWD}:/app" -w /app \
    -e DB_HOST=appsalon-test-db -e DB_USER=root -e DB_PASS=root -e DB_NAME=appsalon_func_test -e DB_PORT=3306 \
    appsalon-php-test php database/migrador.php up

# Sembrar servicios requeridos en appsalon_func_test
docker exec appsalon-test-db mysql -uroot -proot appsalon_func_test -e "
    INSERT INTO servicios (id, nombre, precio) VALUES 
    (1, 'Corte de Cabello Hombre', 80.00),
    (2, 'Corte de Cabello Mujer', 120.00),
    (3, 'Corte de Barba', 60.00)
    ON DUPLICATE KEY UPDATE nombre=VALUES(nombre);
"

# 6. Configurar variables de entorno y levantar servicios auxiliares
echo "[6/8] Configurando includes/.env e iniciando appsalon-mail y appsalon-web..."
cat << 'EOF' > includes/.env
DB_HOST=appsalon-test-db
DB_PORT=3306
DB_USER=root
DB_PASS=root
DB_NAME=appsalon_func_test
EMAIL_HOST=appsalon-mail
EMAIL_PORT=2525
EMAIL_USER=test
EMAIL_PASSWORD=test
EMAIL_FROM=cuentas@appsalon.com
EMAIL_FROM_NAME=AppSalon.com
APP_URL=http://appsalon-web:3000
EOF

docker run -d --name appsalon-mail --network appsalon-net -v "${PWD}:/app" -w /app \
    appsalon-php-test php tests/smtp_mock_server.php

docker run -d --name appsalon-web --network appsalon-net -p 3000:3000 -v "${PWD}:/app" -w /app \
    appsalon-php-test php -S 0.0.0.0:3000 -t public

# Espera verificable de disponibilidad HTTP del servidor web
echo " -> Esperando disponibilidad del servidor web (http://appsalon-web:3000)..."
docker run --rm --network appsalon-net -v "${PWD}:/app" -w /app \
    appsalon-php-test php tests/wait_for_web.php

# 7. Ejecución de la Suite Completa de PHPUnit (Unitarias e Integrales)
echo ""
echo "[7/8] Ejecutando suite completa de PHPUnit..."
docker run --rm --network appsalon-net -v "${PWD}:/app" -w /app \
    appsalon-php-test ./vendor/bin/phpunit --testdox

# 8. Ejecución de la Suite de Verificación Funcional HTTP
echo ""
echo "[8/8] Ejecutando suite de verificación funcional HTTP..."
docker run --rm --network appsalon-net -v "${PWD}:/app" -w /app \
    appsalon-php-test php tests/functional_test_suite.php

echo ""
echo "======================================================================"
echo "REPRODUCCIÓN COMPLETADA EXITOSAMENTE (TODOS LOS TESTS APROBADOS)"
echo "======================================================================"
