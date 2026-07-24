#!/usr/bin/env bash
# Gestiona el laboratorio local de docker-compose.roles.yml (#113: separacion
# de bot-miki en servicios api/worker/scheduler, como se haria en Railway).
# Menu interactivo — correr sin argumentos: ./scripts/roles-lab.sh
#
# OJO: sin "set -e" a proposito — es un menu de uso repetido, si un comando
# puntual falla (p.ej. probar /health antes de que la api termine de levantar)
# no debe tirar abajo todo el script, solo esa opcion.
set -u

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE="docker compose -f $ROOT_DIR/docker-compose.roles.yml"
PROJECT="kpcrop-roles-lab"

up()     { $COMPOSE up --build -d; }
down()   { $COMPOSE down; }
clean()  { $COMPOSE down -v; }
status() { docker ps --filter "name=$PROJECT" --format 'table {{.Names}}\t{{.Status}}\t{{.Ports}}'; }

logs() {
  read -rp "Servicio (api/worker/scheduler/postgres/redis, vacio = todos): " svc
  local target=""
  case "$svc" in
    api|worker|scheduler) target="bot-miki-$svc" ;;
    postgres|redis)       target="$svc" ;;
    "") ;; # todos
    *) echo "Servicio desconocido: $svc"; return 1 ;;
  esac
  $COMPOSE logs -f --tail=100 $target
}

health() {
  curl -s -w '\nhttp_status=%{http_code}\n' http://localhost:3001/health
}

test_isolation() {
  echo "--- antes: api responde ---"
  curl -s http://localhost:3001/health; echo
  echo "--- matando el worker ---"
  docker kill "${PROJECT}-bot-miki-worker-1"
  echo "--- despues: api sigue respondiendo (mismo uptime = no se vio afectada) ---"
  curl -s http://localhost:3001/health; echo
  echo "--- revive el worker ---"
  docker start "${PROJECT}-bot-miki-worker-1" >/dev/null
  docker ps --filter "name=$PROJECT" --format '{{.Names}}\t{{.Status}}'
}

restart_svc() {
  read -rp "Servicio a reiniciar (api/worker/scheduler): " svc
  case "$svc" in
    api|worker|scheduler) docker restart "${PROJECT}-bot-miki-${svc}-1" ;;
    *) echo "Servicio desconocido: $svc"; return 1 ;;
  esac
}

menu() {
  cat <<EOF

===== Laboratorio bot-miki — roles api/worker/scheduler (#113) =====
 1) Levantar (build + up)
 2) Ver estado
 3) Ver logs
 4) Probar /health
 5) Prueba de aislamiento (matar worker, confirmar api OK, revivirlo)
 6) Reiniciar un servicio puntual
 7) Bajar (conserva datos)
 8) Limpiar todo (borra volumenes/datos del lab)
 0) Salir
======================================================================
EOF
}

while true; do
  menu
  read -rp "Elegi una opcion: " opt
  case "$opt" in
    1) up ;;
    2) status ;;
    3) logs ;;
    4) health ;;
    5) test_isolation ;;
    6) restart_svc ;;
    7) down ;;
    8) clean ;;
    0) echo "Chau."; exit 0 ;;
    *) echo "Opcion invalida." ;;
  esac
  echo
  read -rp "[Enter para volver al menu] " _
done
