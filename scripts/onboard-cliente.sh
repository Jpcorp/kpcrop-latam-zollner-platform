#!/usr/bin/env bash
#
# Alta de un cliente con varias tiendas PrestaShop contra UN solo Bsale.
#
#   bash scripts/onboard-cliente.sh <url_api_bsale>
#   bash scripts/onboard-cliente.sh https://api.bsale.io/v1
#
# Que hace, por tienda: sube el plugin, corre las migraciones y deja la
# configuracion escrita. En bot-miki crea la licencia y registra las tiendas
# con el MISMO bsale_integration_id, que es lo que hace que un webhook de Bsale
# se abanique a las tres (ver webhooks.ts: "3 e-commerce tirando del mismo ERP").
#
# Idempotente: se puede correr de nuevo. Las migraciones y la config hacen
# upsert; el alta en bot-miki avisa si la tienda ya existe y sigue.
#
# NO pongas secretos en este archivo — se commitea. Van por entorno:
#
#   export BSALE_TOKEN=...        # token de la integracion Bsale
#   export ADMIN_KEY=...          # X-Admin-Key de bot-miki
#   export SSH_KEY=ssh/id_rsa     # clave para entrar a los hostings
#
set -euo pipefail

BSALE_API_URL="${1:?Uso: bash scripts/onboard-cliente.sh <url_api_bsale>   (ej: https://api.bsale.io/v1)}"

# ─────────────────────────────────────────────────────────────────────────────
# LAS TRES TIENDAS — editar aca y nada mas
#
#   nombre | usuario@host | raiz de PrestaShop | url publica | price_list | office
#
# price_list y office pueden ir vacios: son los de Bsale por tienda y se pueden
# dejar para despues desde el panel. El prefijo de tablas NO se declara aca:
# migrate.php lo toma de _DB_PREFIX_ solo, sea ps_, pr_ o el que sea.
# ─────────────────────────────────────────────────────────────────────────────
STORES=(
  "tienda-uno|usuario@host1|/home/usuario/public_html|https://tienda-uno.cl||"
  "tienda-dos|usuario@host2|/home/usuario/public_html|https://tienda-dos.cl||"
  "tienda-tres|usuario@host3|/home/usuario/public_html|https://tienda-tres.cl||"
)

TENANT_ID="${TENANT_ID:-cliente-demo}"
PLAN="${PLAN:-growth}"                                   # growth = max_stores 3
SUBSCRIPTION_ID="${SUBSCRIPTION_ID:-manual-$(date +%Y%m%d)}"
DAEMON_URL="${DAEMON_URL:-https://miki.keepcrop.com}"
BSALE_INTEGRATION_ID="${BSALE_INTEGRATION_ID:-}"         # cpnId; se detecta si no se pasa

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_SRC="$REPO_ROOT/packages/cms-prestashop/synkrop"
SSH_KEY="${SSH_KEY:-$REPO_ROOT/ssh/id_rsa}"
SSH_OPTS="-i $SSH_KEY -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null"

: "${BSALE_TOKEN:?Falta BSALE_TOKEN (token de la integracion Bsale)}"
: "${ADMIN_KEY:?Falta ADMIN_KEY (X-Admin-Key de bot-miki)}"
[ -f "$SSH_KEY" ] || { echo "No encuentro la clave SSH en $SSH_KEY" >&2; exit 1; }

say() { printf '\n\033[1m== %s\033[0m\n' "$*"; }

# ─── 1. Validar Bsale antes de tocar nada ────────────────────────────────────
# Fallar aca cuesta 2 segundos; fallar despues de configurar 3 tiendas, no.

say "1/5  Verificando el token contra $BSALE_API_URL"

bsale_probe="$(curl -sS --max-time 30 -w '\n%{http_code}' \
  -H "access_token: $BSALE_TOKEN" \
  "$BSALE_API_URL/users.json?limit=1" || true)"
bsale_code="$(printf '%s' "$bsale_probe" | tail -1)"

if [ "$bsale_code" != "200" ]; then
  echo "Bsale respondio $bsale_code — token o URL incorrectos. No sigo." >&2
  exit 1
fi
echo "   Bsale OK (HTTP 200)"

if [ -z "$BSALE_INTEGRATION_ID" ]; then
  # El cpnId es lo que llega en el webhook y lo que une a las 3 tiendas.
  BSALE_INTEGRATION_ID="$(printf '%s' "$bsale_probe" | sed -n 's/.*"cpnId"[: ]*\([0-9]*\).*/\1/p' | head -1)"
fi
[ -n "$BSALE_INTEGRATION_ID" ] || {
  echo "No pude detectar el cpnId. Pasalo a mano: export BSALE_INTEGRATION_ID=..." >&2
  exit 1
}
echo "   Integracion Bsale (cpnId): $BSALE_INTEGRATION_ID"

# ─── 2. Licencia en bot-miki ─────────────────────────────────────────────────

say "2/5  Licencia '$TENANT_ID' (plan $PLAN) en bot-miki"

lic_resp="$(curl -sS --max-time 30 -X POST "$DAEMON_URL/admin/tenants" \
  -H "X-Admin-Key: $ADMIN_KEY" -H 'Content-Type: application/json' \
  -d "{\"tenantId\":\"$TENANT_ID\",\"subscriptionId\":\"$SUBSCRIPTION_ID\",\"plan\":\"$PLAN\"}" || true)"

API_KEY="$(printf '%s' "$lic_resp" | sed -n 's/.*"apiKey"[: ]*"\([^"]*\)".*/\1/p')"

if [ -z "$API_KEY" ]; then
  # Probablemente ya existia: el plugin necesita SU api_key, no sirve inventarla.
  echo "   No obtuve apiKey. Respuesta: $lic_resp" >&2
  echo "   Si el tenant ya existe, recupera su api_key y pasala en API_KEY_OVERRIDE." >&2
  API_KEY="${API_KEY_OVERRIDE:?Falta API_KEY_OVERRIDE para continuar con un tenant existente}"
fi
echo "   Licencia lista"

# ─── 3-5. Cada tienda ────────────────────────────────────────────────────────

for entry in "${STORES[@]}"; do
  IFS='|' read -r NAME HOST PS_ROOT URL PRICE_LIST OFFICE <<< "$entry"

  say "Tienda $NAME  ($URL)"

  # 3. Registrarla en bot-miki con el MISMO cpnId que las otras dos.
  echo "   3/5  alta en bot-miki"
  store_resp="$(curl -sS --max-time 30 -X POST "$DAEMON_URL/admin/tenants/$TENANT_ID/stores" \
    -H "X-Admin-Key: $ADMIN_KEY" -H 'Content-Type: application/json' \
    -d "{\"storeName\":\"$NAME\",\"cmsType\":\"prestashop\",\"cmsUrl\":\"$URL\",
         \"bsaleIntegrationId\":$BSALE_INTEGRATION_ID,\"bsaleAccessToken\":\"$BSALE_TOKEN\"
         ${PRICE_LIST:+,\"bsalePriceListId\":$PRICE_LIST}${OFFICE:+,\"bsaleOfficeId\":$OFFICE}}" || true)"

  case "$store_resp" in
    *STORE_LIMIT_REACHED*) echo "        el plan no permite mas tiendas. Sube a agency." >&2; exit 1 ;;
    *'"id"'*)              echo "        registrada" ;;
    *)                     echo "        aviso: $store_resp" ;;
  esac

  # 4. Plugin + migraciones. rsync sin --delete: no borra nada del servidor.
  echo "   4/5  subiendo plugin y migrando"
  rsync -az -e "ssh $SSH_OPTS" "$PLUGIN_SRC/" "$HOST:$PS_ROOT/modules/synkrop/"
  ssh $SSH_OPTS "$HOST" "cd $PS_ROOT/modules/synkrop/sql && php migrate.php" | sed 's/^/        /'

  # 5. Configuracion. Se genera al vuelo y se borra: no deja un .php con el
  #    token colgando en el servidor. Escribe con el Db de PrestaShop, asi que
  #    usa el _DB_PREFIX_ correcto sea cual sea el prefijo de esta tienda.
  echo "   5/5  escribiendo configuracion"
  ssh $SSH_OPTS "$HOST" "cat > $PS_ROOT/modules/synkrop/_cfg.php" <<PHP
<?php
require_once '$PS_ROOT/config/config.inc.php';
\$db  = Db::getInstance();
\$t   = _DB_PREFIX_ . 'synkrop_config';
\$sid = (int) Context::getContext()->shop->id;
\$row = array(
    'id_shop'              => \$sid,
    'bsale_api_token'      => pSQL('$BSALE_TOKEN'),
    'bsale_integration_id' => (int) $BSALE_INTEGRATION_ID,
    'daemon_api_url'       => pSQL('$DAEMON_URL'),
    'daemon_api_key'       => pSQL('$API_KEY'),
);
${PRICE_LIST:+\$row['bsale_price_list_id'] = (int) $PRICE_LIST;}
${OFFICE:+\$row['bsale_office_id'] = (int) $OFFICE;}
if (\$db->getValue('SELECT id FROM \`'.\$t.'\` WHERE id_shop = '.\$sid)) {
    \$db->update('synkrop_config', \$row, 'id_shop = '.\$sid);
    echo "config actualizada (id_shop \$sid)\n";
} else {
    \$db->insert('synkrop_config', \$row);
    echo "config creada (id_shop \$sid)\n";
}
PHP
  ssh $SSH_OPTS "$HOST" "cd $PS_ROOT && php modules/synkrop/_cfg.php; rm -f modules/synkrop/_cfg.php" | sed 's/^/        /'
done

say "Listo — ${#STORES[@]} tiendas sobre la integracion Bsale $BSALE_INTEGRATION_ID"

cat <<EOF

Queda por hacer a mano (requiere decisiones, no se automatiza a ciegas):

  1. En Bsale, apuntar el webhook a  $DAEMON_URL/v1/webhooks/bsale
     Es uno solo para las tres: bot-miki lo abanica por cpnId.
  2. Por tienda, en el panel de Synkrop: lista de precios, sucursal y que
     flujos activar (productos / precios / stock / ventas).
  3. Revisar stock de seguridad. Las tres tiendas comparten el stock de un
     mismo Bsale y Synkrop lo REFLEJA, no lo reserva: si las tres venden la
     ultima unidad en la misma ventana, se vende tres veces.
EOF
