#!/usr/bin/env bash
# Linux/CI: recorrido real contra la imagen Apache y servicios efímeros propios.
# Requiere composer install, npm ci, npm run build, Docker y Chrome/Chromium.
set -Eeuo pipefail

repo_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
cd "$repo_dir"
command -v docker >/dev/null
command -v curl >/dev/null
node -e 'if (Number(process.versions.node.split(".")[0]) < 22) { throw new Error("E2E requiere Node 22 o superior para puppeteer-core"); }'
test -f vendor/autoload.php
test -d node_modules/puppeteer-core

run_id="$(date +%s)-$$-$RANDOM"
net="appsalon-e2e-net-$run_id"
db="appsalon-e2e-db-$run_id"
smtp="appsalon-e2e-smtp-$run_id"
web="appsalon-e2e-web-$run_id"
worker="appsalon-e2e-worker-$run_id"
profile_volume="appsalon-e2e-profiles-$run_id"
image=${E2E_WEB_IMAGE:-appsalon-web-e2e}
artifacts=${E2E_ARTIFACT_DIR:-"$repo_dir/storage/e2e/$run_id"}
mkdir -p "$artifacts"
artifacts=$(cd "$artifacts" && pwd)
mailbox="$artifacts/mailbox.json"
printf '[]\n' > "$mailbox"
chmod 666 "$mailbox" # SMTP escribe desde el contenedor; solo contiene datos ficticios.
db_name=appsalon_e2e_test
secret=${PAYMENT_WEBHOOK_SECRET:-appsalon_mock_webhook_secret_2026}

cleanup() {
    local result=$?
    trap - EXIT
    for container in "$web" "$smtp" "$worker" "$db"; do
        docker logs "$container" > "$artifacts/$container.log" 2>&1 || true
        docker rm -f "$container" >/dev/null 2>&1 || true
    done
    docker volume rm "$profile_volume" >/dev/null 2>&1 || true
    docker network rm "$net" >/dev/null 2>&1 || true
    printf 'E2E_EXIT_CODE=%s\nArtefactos: %s\n' "$result" "$artifacts"
    exit "$result"
}
trap cleanup EXIT

if [[ -z ${E2E_WEB_IMAGE:-} ]]; then
    docker build -t "$image" .
fi
docker network create "$net" >/dev/null
docker run -d --name "$db" --network "$net" \
    -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE="$db_name" mysql:8.0 >/dev/null
ready=0
for ((attempt=1; attempt<=90; attempt++)); do
    if docker exec "$db" mysqladmin ping -h 127.0.0.1 -uroot -proot --silent >/dev/null 2>&1; then
        ready=1
        break
    fi
    sleep 1
done
[[ $ready == 1 ]] || { echo 'MySQL E2E no está listo.' >&2; exit 1; }
docker exec -i "$db" mysql -uroot -proot --default-character-set=utf8mb4 "$db_name" < database/schema_base.sql

common_env=(
    -e APP_ENV=testing -e APP_TIMEZONE=America/Guayaquil
    -e DB_HOST="$db" -e DB_PORT=3306 -e DB_USER=root -e DB_PASS=root -e DB_NAME="$db_name"
    -e EMAIL_HOST="$smtp" -e EMAIL_PORT=2525 -e EMAIL_USER=test -e EMAIL_PASSWORD=test
    -e EMAIL_FROM=cuentas@appsalon.com -e EMAIL_FROM_NAME=AppSalon
    -e EMAIL_FORCE_SMTP=1 -e RESERVA_EXIGIR_PROFESIONAL=1
    -e PAYMENT_GATEWAY_PROVIDER=mock -e PAYMENT_WEBHOOK_SECRET="$secret"
)
docker run --rm --network "$net" -v "$repo_dir:/var/www/html" -w /var/www/html \
    "${common_env[@]}" "$image" php database/migrador.php up
docker run --rm --network "$net" -v "$repo_dir:/var/www/html" -w /var/www/html \
    "${common_env[@]}" "$image" php tests/setup_browser_e2e.php

docker run -d --name "$smtp" --network "$net" \
    -v "$repo_dir:/var/www/html:ro" -v "$artifacts:/e2e-artifacts" \
    -e TEST_MAILBOX_PATH=/e2e-artifacts/mailbox.json \
    -w /var/www/html "$image" php tests/smtp_mock_server.php >/dev/null
ready=0
for ((attempt=1; attempt<=30; attempt++)); do
    if docker exec "$smtp" php -r '$c = @fsockopen("127.0.0.1", 2525); if (!$c) exit(1); fwrite($c, "QUIT\r\n"); fclose($c);' >/dev/null 2>&1; then
        ready=1
        break
    fi
    sleep 1
done
[[ $ready == 1 ]] || { echo 'SMTP mock E2E no está listo.' >&2; exit 1; }

# Private uploads are the sole writable app directory; ephemeral volume never touches user portraits.
mkdir -p "$repo_dir/storage/private/perfiles"
docker volume create "$profile_volume" >/dev/null
docker run --rm -v "$profile_volume:/profiles" "$image" sh -c 'chown www-data:www-data /profiles && chmod 700 /profiles'

# Elegir un puerto libre sin reutilizar el 3000 de la aplicación del usuario.
port=$(node -e 'const s=require("net").createServer();s.listen(0,"127.0.0.1",()=>{console.log(s.address().port);s.close();});')
app_url="http://127.0.0.1:$port"
docker run -d --name "$web" --network "$net" -p "127.0.0.1:$port:80" \
    -v "$repo_dir:/var/www/html:ro" -v "$profile_volume:/var/www/html/storage/private/perfiles" "${common_env[@]}" -e APP_URL="$app_url" "$image" >/dev/null
ready=0
for ((attempt=1; attempt<=30; attempt++)); do
    if curl --silent --fail "$app_url/crear-cuenta" >/dev/null; then
        ready=1
        break
    fi
    sleep 1
done
[[ $ready == 1 ]] || { echo 'Apache MVC E2E no está listo.' >&2; exit 1; }

# Las notificaciones salen desde el worker real del outbox, nunca con simular-envio.
docker run -d --name "$worker" --network "$net" -v "$repo_dir:/var/www/html:ro" -w /var/www/html \
    "${common_env[@]}" -e APP_URL="$app_url" "$image" \
    bash -c 'while true; do php scripts/procesar_notificaciones.php --reintentos --limite=100 || exit; sleep 1; done' >/dev/null

if [[ -z ${CHROME_BIN:-} ]]; then
    CHROME_BIN=$(command -v google-chrome || command -v chromium || command -v chromium-browser)
fi
export CHROME_BIN APP_URL="$app_url" PAYMENT_WEBHOOK_SECRET="$secret"
export TZ=America/Guayaquil
export TEST_DB_CONTAINER="$db" TEST_DB_NAME="$db_name" TEST_REQUIRE_DB_ASSERTIONS=1
export TEST_MAILBOX_PATH="$mailbox" TEST_BROWSER_REPORT_PATH="$artifacts/browser_test_report.json"
node tests/browser_e2e_test.js 2>&1 | tee "$artifacts/browser.log"
docker run --rm --network "$net" -v "$repo_dir:/var/www/html:ro" -w /var/www/html \
    -v "$artifacts:/e2e-artifacts:ro" "${common_env[@]}" "$image" \
    php tests/assert_browser_e2e.php /e2e-artifacts/browser_test_report.json

# El worker debe seguir vivo; un fallo de procesamiento no debe quedar oculto.
[[ $(docker inspect -f '{{.State.Running}}' "$worker") == true ]]

# Contratos HTTP y regresiones visuales sobre las mismas fixtures aisladas.
node tests/error_routes_http_checks.js 2>&1 | tee "$artifacts/error-routes.log"
TEST_ERROR_OUTPUT_DIR="$artifacts/errors" node tests/error_visual_test.js 2>&1 | tee "$artifacts/error-layout.log"
node tests/ui_navigation_test.js 2>&1 | tee "$artifacts/navigation.log"
TEST_RESPONSIVE_OUTPUT_DIR="$artifacts/responsive" node tests/responsive_views_test.js 2>&1 | tee "$artifacts/responsive.log"

# Previsualizaciones exclusivamente ficticias: renderizado real sin enviar correos.
docker run --rm --network "$net" -v "$repo_dir:/var/www/html:ro" -w /var/www/html \
    -v "$artifacts:/e2e-artifacts" "${common_env[@]}" -e APP_URL="$app_url" "$image" \
    php tests/fixtures/email_previews.php /e2e-artifacts/email-previews
node tests/email_visual_test.js "$artifacts/email-previews" 2>&1 | tee "$artifacts/email-layout.log"
