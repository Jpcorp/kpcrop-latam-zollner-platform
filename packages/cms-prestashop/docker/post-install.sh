#!/bin/bash
# Ejecutado por el servicio ps-init después de que PrestaShop termina de instalarse.
# Instala las tablas del módulo synkrop y aplica el seed de desarrollo.
# Es idempotente: puede correrse más de una vez sin romper nada.

set -e

DB_HOST="${DB_HOST:-mysql}"
DB_USER="${DB_USER:-prestashop}"
DB_PASSWD="${DB_PASSWD:-prestashop_dev}"
DB_NAME="${DB_NAME:-prestashop}"
DB_PREFIX="${DB_PREFIX:-ps_}"

MYSQL="mysql -h $DB_HOST -u $DB_USER -p${DB_PASSWD} $DB_NAME"

# ── Esperar hasta que las tablas de PS existan ────────────────────────────────
echo "[ps-init] Esperando tablas de PrestaShop..."
until $MYSQL -e "SHOW TABLES LIKE '${DB_PREFIX}configuration'" 2>/dev/null | grep -q configuration; do
    echo "[ps-init]   → aún no listas, reintentando en 10s..."
    sleep 10
done
echo "[ps-init] ✓ PrestaShop listo"

# ── Instalar tablas del módulo synkrop ──────────────────────────────────────
echo "[ps-init] Instalando tablas synkrop..."
sed "s/PREFIX_/${DB_PREFIX}/g" /install.sql | $MYSQL
echo "[ps-init] ✓ Tablas synkrop instaladas"

# ── Aplicar seed de desarrollo ────────────────────────────────────────────────
echo "[ps-init] Aplicando seed de desarrollo..."
sed "s/PREFIX_/${DB_PREFIX}/g" /seed-dev.sql | $MYSQL
echo "[ps-init] ✓ Seed aplicado"

# ── Correo saliente → Mailhog (para poder ver el contenido real de los
#    emails de synkrop, ej. notifyDocumentEmitted, sin un MTA real) ───────────
echo "[ps-init] Configurando correo saliente hacia Mailhog..."
$MYSQL -e "
UPDATE ${DB_PREFIX}configuration SET value = '2'       WHERE name = 'PS_MAIL_METHOD';
UPDATE ${DB_PREFIX}configuration SET value = 'mailhog'  WHERE name = 'PS_MAIL_SERVER';
UPDATE ${DB_PREFIX}configuration SET value = '1025'     WHERE name = 'PS_MAIL_SMTP_PORT';
UPDATE ${DB_PREFIX}configuration SET value = ''         WHERE name = 'PS_MAIL_USER';
UPDATE ${DB_PREFIX}configuration SET value = ''         WHERE name = 'PS_MAIL_PASSWD';
UPDATE ${DB_PREFIX}configuration SET value = 'off'      WHERE name = 'PS_MAIL_SMTP_ENCRYPTION';
"
echo "[ps-init] ✓ Correo apuntando a Mailhog (UI: http://localhost:8025)"

echo ""
echo "═══════════════════════════════════════════════════════"
echo "  PrestaShop listo para desarrollo"
echo ""
echo "  Tienda:  http://localhost:8080"
echo "  Admin:   http://localhost:8080/admin-dev"
echo "  Email:   admin@kpcrop.local"
echo "  Pass:    Admin1234!"
echo ""
echo "  MySQL:   localhost:3307  (user: prestashop / prestashop_dev)"
echo ""
echo "  Módulo synkrop apunta a bot-miki en localhost:3000 (DEV)"
echo "  API Key: kp_dev_api_key_para_desarrollo_local_no_usar_en_prod"
echo ""
echo "  ⚠️  Para probar contra Railway (sin bot-miki local):"
echo "  Editar docker/seed-dev.sql → daemon_api_url y daemon_api_key"
echo "═══════════════════════════════════════════════════════"
