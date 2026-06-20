# Guia de Deployment

---

## Produccion — Estado actual (2026-05-31)

| Componente | URL / Estado |
|---|---|
| **bot-miki** | `https://miki.keepcrop.com` |
| **Health** | `GET /health` → `{"status":"ok","version":"0.0.1"}` |
| **Swagger UI** | `GET /docs` |
| **PostgreSQL** | Railway managed — migracion auto-aplicada en startup |
| **Redis** | Railway managed |
| **Proyecto Railway** | `truthful-essence` (ID: `b6525894-63c0-47f5-9617-bcbd6ea05b0e`) |
| **Branch deployado** | `master` — Railway autodeploy en cada push |

### Tenant de produccion activo

| Campo | Valor |
|---|---|
| tenantId | `tienda-zollner-cl` |
| Plan | `growth` (3 tiendas, sync manual + auto + precios) |
| API Key | Ver `docs/deployment/secrets.md` (no en repo) |
| Estado | `active` — sin vencimiento |

---

## Plugin PrestaShop — Configuracion en cliente

Al instalar el modulo `synkrop` en el PrestaShop del cliente, configurar en
**Admin → Catalogo → Synkrop**:

| Campo | Valor para primer cliente |
|---|---|
| Token de acceso Bsale | *(token de la cuenta Bsale del cliente)* |
| API Key de licencia kpcrop | Ver `docs/deployment/secrets.md` |
| daemon_api_url (config avanzada) | `https://miki.keepcrop.com` |
| Lista de precios Bsale (config avanzada) | ID de lista del cliente (ej: `1`) |

> **Dominio activo:** `miki.keepcrop.com` con SSL gestionado por Railway.
> CNAME configurado en Cloudflare: `miki` → `aej8mwgs.up.railway.app`.

---

## Crear nuevo tenant (nuevo cliente)

```bash
curl -X POST https://miki.keepcrop.com/v1/admin/tenants \
  -H "X-Admin-Key: <ADMIN_KEY>" \
  -H "Content-Type: application/json" \
  -d '{
    "tenantId":       "nombre-cliente-cl",
    "subscriptionId": "manual-001",
    "plan":           "growth",
    "maxStores":      3,
    "features":       ["sync_manual","sync_auto","sync_prices"]
  }'
```

La respuesta incluye el `apiKey` — **guardarlo inmediatamente**, no se muestra de nuevo.

---

## Entorno Local (desarrollo)

### Requisitos
- Docker Desktop con WSL2 integration activa
- Node.js 22+ / pnpm 9+

### Levantar el stack

```bash
# 1. Instalar dependencias
pnpm install

# 2. Levantar bot-miki + PostgreSQL + Redis
docker compose up -d

# 3. bot-miki disponible en http://localhost:3000
# Swagger UI: http://localhost:3000/docs

# 4. PrestaShop local (para desarrollo del plugin)
cd packages/cms-prestashop
docker compose up -d
# Backoffice: http://localhost:8080/admin-dev
# Email: admin@kpcrop.local  Pass: Admin1234!
```

### Seed de desarrollo

El contenedor `kpcrop-ps178-init` corre `docker/post-install.sh` automaticamente
y aplica `docker/seed-dev.sql`. El seed configura:

- `daemon_api_url` → `http://host.docker.internal:3000` (bot-miki local)
- `daemon_api_key` → `kp_dev_api_key_para_desarrollo_local_no_usar_en_prod`
- 4 registros de log de ejemplo en `synkrop_log`

Para probar contra Railway en lugar de bot-miki local, editar `seed-dev.sql`
y cambiar `daemon_api_url` y `daemon_api_key` a los valores de produccion.

---

## Variables de entorno en Railway

| Variable | Descripcion |
|---|---|
| `DATABASE_URL` | Auto-generada por PostgreSQL plugin |
| `REDIS_URL` | Auto-generada por Redis plugin |
| `JWT_SECRET` | Minimo 32 chars — secreto para firmar JWTs |
| `ADMIN_KEY` | Minimo 16 chars — protege endpoint `/v1/admin/*` |
| `NODE_ENV` | `production` |
| `LOG_LEVEL` | `info` |
| `BSALE_RATE_LIMIT_RPS` | `10` (conservador hasta confirmar el real con Bsale) |

> `STRIPE_SECRET_KEY` y `STRIPE_WEBHOOK_SECRET` son opcionales en MVP.

---

## Migraciones

La migracion `001_initial_schema.sql` se aplica **automaticamente** al arrancar
el servicio (`src/index.ts` la ejecuta en startup, idempotente — ignora error 42P07).
No es necesario ejecutarla manualmente en Railway.

---

## Monitoreo

```bash
# Logs en tiempo real (Railway CLI)
railway logs --service kpcrop-latam-zollner-platform --tail

# Health check
curl https://miki.keepcrop.com/health
```

Railway reinicia automaticamente si el health check falla 3 veces consecutivas
(configurado en `railway.toml`: `restartPolicyType = "ON_FAILURE"`).

---

## Checklist Pre-Onboarding Cliente

- [x] `GET /health` devuelve `{"status":"ok"}`
- [x] Tenant creado via `POST /v1/admin/tenants`
- [x] API Key entregada al cliente (o configurada en el plugin)
- [x] `GET /v1/license/token` con la API Key devuelve JWT valido
- [ ] Plugin instalado en el PrestaShop del cliente → [plugin-install.md](./plugin-install.md)
- [ ] Primer sync manual exitoso con datos reales del cliente → [sync-manual.md](./sync-manual.md)
- [ ] Cliente puede repetir el sync sin duplicados
- [ ] Webhooks de Bsale registrados y sincronizacion automatica activa → [webhook-auto-sync.md](./webhook-auto-sync.md)

### Guias de referencia

| Guia | Descripcion |
|------|-------------|
| [plugin-install.md](./plugin-install.md) | Instalacion del modulo en PrestaShop |
| [sync-manual.md](./sync-manual.md) | Sincronizacion manual de productos, stock y precios |
| [webhook-auto-sync.md](./webhook-auto-sync.md) | Configuracion de sincronizacion automatica via webhooks |
| [production-config.md](./production-config.md) | Variables de entorno y configuracion de produccion |
| [secrets.md](./secrets.md) | Manejo de secretos (no versionado en repo) |
