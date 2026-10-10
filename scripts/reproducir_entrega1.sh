#!/usr/bin/env bash
# ==============================================================================
# Script de Reproducción Integral de Entrega 1 desde Entorno Docker Limpio
# ==============================================================================
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT_DIR}"

RUN_ID="$(date +%s)-$$"
NET_NAME="appsalon-net-${RUN_ID}"
DB_CONTAINER="appsalon-db-${RUN_ID}"
MAIL_CONTAINER="appsalon-mail-${RUN_ID}"
WEB_CONTAINER="appsalon-web-${RUN_ID}"
DB_SUFFIX="${RUN_ID//-/_}"
DB_TEST="appsalon_${DB_SUFFIX}_test"
DB_FUNC="appsalon_${DB_SUFFIX}_func_test"

ENV_FILE="includes/.env"
ENV_EXISTED=0
ENV_BACKUP="includes/.env.bak.${RUN_ID}"

if [ -f "${ENV_FILE}" ]; then
    ENV_EXISTED=1
fi

echo "======================================================================"
echo "REPRODUCCIÓN INTEGRAL DE PRUEBAS — APPSALON ENTREGA 1"
echo "Directorio de trabajo: ${ROOT_DIR}"
echo "Identificador de ejecución (RUN_ID): ${RUN_ID}"
echo "Red Docker aislada: ${NET_NAME}"
echo "======================================================================"

cleanup() {
    echo ""
    echo "[LIMPIEZA FINAL] Deteniendo y eliminando recursos exclusivos de esta ejecución (${RUN_ID})..."
    docker rm -f "${WEB_CONTAINER}" "${MAIL_CONTAINER}" "${DB_CONTAINER}" 2>/dev/null || true
    docker network rm "${NET_NAME}" 2>/dev/null || true

    if [ "${ENV_EXISTED}" -eq 1 ]; then
        if [ -f "${ENV_BACKUP}" ]; then
            echo " -> Restaurando includes/.env original..."
            mv -f "${ENV_BACKUP}" "${ENV_FILE}"
        fi
    else
        if [ -f "${ENV_FILE}" ]; then
            echo " -> Eliminando includes/.env temporal generado..."
            rm -f "${ENV_FILE}"
        fi
    fi
}
trap cleanup EXIT

# 1. Respaldo de configuración preexistente
if [ "${ENV_EXISTED}" -eq 1 ]; then
    echo "[1/7] Respaldando includes/.env preexistente en ${ENV_BACKUP}..."
    cp "${ENV_FILE}" "${ENV_BACKUP}"
else
    echo "[1/7] Sin includes/.env preexistente; se eliminará al terminar la prueba..."
fi

# 2. Instalación estricta según lockfiles y construcción de assets
echo "[2/7] Instalando dependencias según archivos de bloqueo y compilando assets..."
echo " -> Instalando dependencias Composer según composer.lock..."
docker run --rm -v "${PWD}:/app" -w /app composer:2 install --ignore-platform-reqs --no-interaction

echo " -> Instalando dependencias npm según package-lock.json y compilando assets..."
docker run --rm -v "${PWD}:/app" -w /app node:18 sh -c "npm ci && npm run build"

# 3. Creación de red y MySQL 8 exclusivo
echo "[3/7] Creando red aislada ${NET_NAME} e iniciando MySQL 8 (${DB_CONTAINER})..."
docker network create "${NET_NAME}"
docker run -d --name "${DB_CONTAINER}" --network "${NET_NAME}" \
    -e MYSQL_ROOT_PASSWORD=root \
    mysql:8.0

echo " -> Esperando disponibilidad de MySQL..."
READY=0
for i in $(seq 1 60); do
    if docker exec "${DB_CONTAINER}" mysqladmin ping -h 127.0.0.1 -u root -proot --silent >/dev/null 2>&1; then
        READY=1
        echo " -> MySQL 8 disponible tras ${i}s."
        break
    fi
    sleep 1
done

if [ "$READY" -ne 1 ]; then
    echo "[ERROR] Tiempo de espera agotado para disponibilidad de MySQL en ${DB_CONTAINER}." >&2
    exit 1
fi

# 4. Construcción de imagen de pruebas PHP 8.2
echo "[4/7] Construyendo imagen de prueba de PHP 8.2..."
docker build -t appsalon-php-test -f Dockerfile.test .

# 5. Preparación y migración independiente de bases de datos
echo "[5/7] Preparando bases de datos (${DB_TEST} y ${DB_FUNC})..."

docker exec "${DB_CONTAINER}" mysql -uroot -proot -e "CREATE DATABASE IF NOT EXISTS ${DB_TEST} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
docker exec -i "${DB_CONTAINER}" mysql -uroot -proot "${DB_TEST}" < database/schema_base.sql
docker run --rm --network "${NET_NAME}" -v "${PWD}:/app" -w /app \
    -e DB_HOST="${DB_CONTAINER}" -e DB_USER=root -e DB_PASS=root -e DB_NAME="${DB_TEST}" -e DB_PORT=3306 \
    appsalon-php-test php database/migrador.php up

docker exec "${DB_CONTAINER}" mysql -uroot -proot -e "CREATE DATABASE IF NOT EXISTS ${DB_FUNC} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
docker exec -i "${DB_CONTAINER}" mysql -uroot -proot "${DB_FUNC}" < database/schema_base.sql
docker run --rm --network "${NET_NAME}" -v "${PWD}:/app" -w /app \
    -e DB_HOST="${DB_CONTAINER}" -e DB_USER=root -e DB_PASS=root -e DB_NAME="${DB_FUNC}" -e DB_PORT=3306 \
    appsalon-php-test php database/migrador.php up

docker exec "${DB_CONTAINER}" mysql -uroot -proot "${DB_FUNC}" -e "
    INSERT INTO servicios (id, nombre, precio) VALUES 
    (1, 'Corte de Cabello Hombre', 80.00),
    (2, 'Corte de Cabello Mujer', 120.00),
    (3, 'Corte de Barba', 60.00)
    ON DUPLICATE KEY UPDATE nombre=VALUES(nombre);
"

# 6. Configuración temporal y servicios auxiliares
echo "[6/7] Generando configuración e iniciando ${MAIL_CONTAINER} y ${WEB_CONTAINER}..."
cat << EOF > "${ENV_FILE}"
DB_HOST=${DB_CONTAINER}
DB_PORT=3306
DB_USER=root
DB_PASS=root
DB_NAME=${DB_FUNC}
EMAIL_HOST=${MAIL_CONTAINER}
EMAIL_PORT=2525
EMAIL_USER=test
EMAIL_PASSWORD=test
EMAIL_FROM=cuentas@appsalon.com
EMAIL_FROM_NAME=AppSalon.com
APP_URL=http://${WEB_CONTAINER}:3000
EOF

docker run -d --name "${MAIL_CONTAINER}" --network "${NET_NAME}" -v "${PWD}:/app" -w /app \
    appsalon-php-test php tests/smtp_mock_server.php

docker run -d --name "${WEB_CONTAINER}" --network "${NET_NAME}" -v "${PWD}:/app" -w /app \
    appsalon-php-test php -S 0.0.0.0:3000 -t public

echo " -> Esperando disponibilidad del servidor web (http://${WEB_CONTAINER}:3000)..."
docker run --rm --network "${NET_NAME}" -v "${PWD}:/app" -w /app \
    -e APP_URL="http://${WEB_CONTAINER}:3000" \
    appsalon-php-test php tests/wait_for_web.php

# 7. Ejecución de suites de prueba
echo ""
echo "[7/7] Ejecutando suite completa de PHPUnit..."
docker run --rm --network "${NET_NAME}" -v "${PWD}:/app" -w /app \
    -e DB_HOST="${DB_CONTAINER}" -e DB_USER=root -e DB_PASS=root -e DB_NAME="${DB_TEST}" -e DB_PORT=3306 \
    appsalon-php-test ./vendor/bin/phpunit --testdox

echo ""
echo "Ejecutando suite de verificación funcional HTTP..."
docker run --rm --network "${NET_NAME}" -v "${PWD}:/app" -w /app \
    -e APP_URL="http://${WEB_CONTAINER}:3000" \
    appsalon-php-test php tests/functional_test_suite.php

echo ""
echo "======================================================================"
echo "REPRODUCCIÓN COMPLETADA EXITOSAMENTE (TODOS LOS TESTS APROBADOS)"
echo "======================================================================"
