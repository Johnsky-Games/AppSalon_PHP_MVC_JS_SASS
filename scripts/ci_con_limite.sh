#!/usr/bin/env bash
# Uso: scripts/ci_con_limite.sh <segundos> <comando...>
# Ejecuta el comando en su propio grupo de procesos. Si supera el límite, captura el estado (procesos, consultas y bloqueos de MySQL) MIENTRAS el proceso
# sigue vivo, y solo después lo termina (TERM y, 20 s más tarde, KILL a todo el grupo) y sale con 124. Si termina antes, devuelve su código de salida.
# Al terminar el paso por tiempo, el ejecutor mata el árbol de procesos sin dejar ver quién estaba colgado: por eso la captura va aquí, no en un paso posterior.
limite="${1:?segundos}"; shift
setsid "$@" &
pid=$!
inicio=$SECONDS
while kill -0 "$pid" 2>/dev/null; do
  if (( SECONDS - inicio >= limite )); then
    echo "::error::Tiempo agotado (${limite} s): se captura el estado antes de terminar el proceso $pid"
    bash "$(dirname "$0")/ci_diagnostico.sh" || true
    kill -TERM -- "-$pid" 2>/dev/null || true
    for _ in 1 2 3 4; do sleep 5; kill -0 "$pid" 2>/dev/null || break; done
    kill -KILL -- "-$pid" 2>/dev/null || true
    wait "$pid" 2>/dev/null || true
    exit 124
  fi
  sleep 2
done
wait "$pid"
