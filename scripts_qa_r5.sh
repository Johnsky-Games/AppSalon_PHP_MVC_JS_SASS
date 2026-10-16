#!/usr/bin/env bash
# QA independiente de la ronda 5 (q5): ejecuta las pruebas tests/Qa/R5Qa*.php contra un ÁRBOL de trabajo (p. ej. la rama integrada).
#
# Uso:   scripts_qa_r5.sh <ruta_del_arbol> [archivo_o_clase_R5Qa...] [-- opciones de phpunit]
#   ej.: scripts_qa_r5.sh /tmp/wt/integracion
#        scripts_qa_r5.sh /tmp/wt/integracion R5QaCobroTest R5QaAtencionTest
#        scripts_qa_r5.sh /tmp/wt/integracion R5QaCrmTest -- --filter K2
#
# Qué hace (sin tocar el código de producción del árbol ni los repositorios de otros agentes):
#   1. copia al árbol SOLO tests/Qa/R5Case.php, tests/Qa/R5QaNavegadorTest.php, tests/Qa/R5Qa*.php y tests/Qa/r5_qa_navegador.cjs (archivos nuevos, sin tracking);
#   2. recrea la BD appsalon_qa_q5_test con el esquema y TODAS las migraciones del árbol (schema_base + migrador up);
#   3. ejecuta cada clase con PHPUnit del repositorio principal pero con el autoload del árbol (nunca el del repositorio principal);
#   4. deja la salida literal en $SALIDA (por omisión /tmp/q5_resultados_<fecha>/) y un resumen por clase.
# Puertos: HTTP 8150-8159, SMTP 2590-2599, BD appsalon_qa_q5_test (reservados a q5). Chromium: CHROME_BIN y QA_EXIGIR_NAVEGADOR=1.
# No usar a la vez que otra ejecución sobre la misma BD. Código de salida: 0 si todas las clases pasan, 1 si alguna falla.
set -u
ARBOL="${1:-}"
[ -n "$ARBOL" ] && [ -d "$ARBOL/tests/Qa" ] || { echo "uso: $0 <ruta_del_arbol> [clases...] [-- opciones phpunit]"; exit 2; }
shift
ARBOL="$(cd "$ARBOL" && pwd)"
ORIGEN="$(cd "$(dirname "$0")" && pwd)"
CLASES=(); EXTRA=()
while [ $# -gt 0 ]; do
  if [ "$1" = "--" ]; then shift; EXTRA=("$@"); break; fi
  CLASES+=("$1"); shift
done
[ ${#CLASES[@]} -gt 0 ] || CLASES=(R5QaAtencionTest R5QaPortalesTest R5QaCobroTest R5QaCrmTest R5QaExportacionTest R5QaNavegadorTest R5bQaPermisosTest R5bQaAuditoriaTest R5bQaFinanzasTest R5bQaPendientesTest R5bQaFase2Test R5bQaFase3Test R5bQaNavegadorTest)
SALIDA="${SALIDA:-/tmp/q5_resultados_$(date +%Y%m%d_%H%M%S)}"
mkdir -p "$SALIDA"

export DB_HOST=127.0.0.1 DB_PORT=3306 DB_USER=root DB_PASS=root DB_NAME=appsalon_qa_q5_test APP_ENV=testing APP_TIMEZONE=America/Guayaquil
export EMAIL_HOST=127.0.0.1 EMAIL_PORT=2590 EMAIL_USER=t EMAIL_PASSWORD=t PAYMENT_WEBHOOK_SECRET=ci_test_webhook_secret_hmac_2026
export CHROME_BIN="${CHROME_BIN:-/opt/pw-browsers/chromium-1194/chrome-linux/chrome}" QA_EXIGIR_NAVEGADOR=1

echo "== Árbol: $ARBOL   ($(git -C "$ARBOL" log --oneline -1 2>/dev/null))"
echo "== 1/4 copiando pruebas R5Qa* y utilidades al árbol"
if [ "$ARBOL" != "$ORIGEN" ]; then for f in "$ORIGEN"/tests/Qa/R5Case.php "$ORIGEN"/tests/Qa/R5Qa*.php "$ORIGEN"/tests/Qa/r5_qa_navegador.cjs; do b=$(basename "$f"); [ -e "$ARBOL/tests/Qa/$b" ] && [ -z "${PISAR:-}" ] && { echo "   (se conserva $b del árbol)"; continue; }; cp "$f" "$ARBOL/tests/Qa/"; done; fi

[ -e "$ARBOL/vendor/autoload.php" ] || { echo "   (sin vendor/: se copia el vendor enlazado de q5)"; cp -a "$ORIGEN/vendor" "$ARBOL/vendor"; }
echo "== 2/4 recreando $DB_NAME con las migraciones del árbol"
mysql -uroot -proot -h127.0.0.1 -e "DROP DATABASE IF EXISTS $DB_NAME; CREATE DATABASE $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" 2>/dev/null || { echo "MySQL no responde"; exit 3; }
mysql -uroot -proot -h127.0.0.1 "$DB_NAME" < "$ARBOL/database/schema_base.sql" 2>/dev/null
( cd "$ARBOL" && php database/migrador.php up 2>&1 | tail -3 )
echo "   migraciones aplicadas: $(mysql -N -uroot -proot -h127.0.0.1 "$DB_NAME" -e 'select group_concat(substr(migracion,1,3)) from migraciones' 2>/dev/null)"

echo "== 3/4 ejecutor PHPUnit con el autoload del árbol"
RUNNER="$SALIDA/phpunit_arbol.php"
printf '<?php\n$GLOBALS["_composer_autoload_path"] = "%s/vendor/autoload.php";\nrequire "/home/user/AppSalon_PHP_MVC_JS_SASS/vendor/phpunit/phpunit/phpunit";\n' "$ARBOL" > "$RUNNER"
[ -e "$ARBOL/node_modules" ] || ln -s /home/user/AppSalon_PHP_MVC_JS_SASS/node_modules "$ARBOL/node_modules"

echo "== 4/4 ejecutando: ${CLASES[*]}"
FALLO=0
for C in "${CLASES[@]}"; do
  C="${C%.php}"
  ( cd "$ARBOL" && php -d variables_order=EGPCS "$RUNNER" --do-not-cache-result --colors=never --testdox --display-warnings --display-incomplete "${EXTRA[@]}" "tests/Qa/$C.php" ) > "$SALIDA/$C.txt" 2>&1
  RES="$(grep -E '^(OK|Tests:|FAILURES!|ERRORS!)' "$SALIDA/$C.txt" | tr '\n' ' ')"
  PASA=$(grep -cE '^ ✔' "$SALIDA/$C.txt"); FALLA=$(grep -cE '^ ✘' "$SALIDA/$C.txt")
  echo "-- $C: ✔ $PASA  ✘ $FALLA   $RES"
  grep -E '^ ✘' "$SALIDA/$C.txt" | sed 's/^/     /'
  grep -qE '^(FAILURES!|ERRORS!)' "$SALIDA/$C.txt" && FALLO=1
  grep -qE '^(OK|Tests:)' "$SALIDA/$C.txt" || { echo "     ¡la clase no llegó a ejecutarse! ver $SALIDA/$C.txt"; FALLO=1; }
done
echo "== Salida literal en $SALIDA/"
exit $FALLO
