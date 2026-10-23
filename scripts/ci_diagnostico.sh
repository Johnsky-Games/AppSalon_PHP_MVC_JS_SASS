#!/usr/bin/env bash
# Informa (no falla nunca) el estado del runner y de MySQL. Lo usan scripts/ci_con_limite.sh, ANTES de terminar un proceso que superó su tiempo, y el
# paso de diagnóstico del workflow cuando el job falla o se cancela. Solo lectura.
export MYSQL_PWD="${MYSQL_PWD:-root}"
MYSQL=(mysql -h "${DB_HOST:-127.0.0.1}" -P "${DB_PORT:-3306}" -uroot)
echo "== hora"; date -u
echo "== procesos (más antiguos primero)"; ps -eo pid,ppid,etimes,stat,pcpu,pmem,args --sort=-etimes | head -60 || true
echo "== memoria y disco"; free -m || true; df -h / "${RUNNER_TEMP:-/tmp}" || true
echo "== MySQL: conexiones"; "${MYSQL[@]}" -e "SHOW FULL PROCESSLIST" || true
echo "== MySQL: bloqueos en espera"; "${MYSQL[@]}" -e "SELECT * FROM performance_schema.data_lock_waits LIMIT 20" || true
echo "== MySQL: InnoDB"; "${MYSQL[@]}" -e "SHOW ENGINE INNODB STATUS\G" | head -120 || true
echo "== contenedores"; docker ps -a || true
echo "== registro del servicio MySQL (últimas 200 líneas)"; for c in $(docker ps -aq --filter ancestor=mysql:8.0 2>/dev/null); do docker logs --tail 200 "$c" 2>&1 || true; done
echo "== registro del kernel (OOM)"; sudo -n dmesg 2>/dev/null | tail -40 || true
exit 0
