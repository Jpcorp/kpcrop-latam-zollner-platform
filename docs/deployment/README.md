# Guia de Deployment

---

## Entorno Local (desarrollo)

### Requisitos
- Docker Desktop
- Node.js 22+
- pnpm 9+
- PHP 8.1+ (solo para desarrollo del plugin PrestaShop)

### Levantar el stack

```bash
# 1. Clonar y entrar al repo
git clone <repo> && cd kpcrop-latam-zollner-platform

# 2. Instalar dependencias Node
pnpm install

# 3. Configurar variables de entorno
cp packages/bot-miki/.env.example packages/bot-miki/.env
# Editar packages/bot-miki/.env con tus valores

# 4. Levantar PostgreSQL + Redis
docker compose up postgres redis -d

# 5. Ejecutar migraciones
psql $DATABASE_URL -f packages/bot-miki/migrations/001_initial_schema.sql
psql $DATABASE_URL -f packages/bot-miki/migrations/002_seed_dev.sql

# 6. Compilar shared y arrancar bot-miki
pnpm --filter @kpcrop/shared build
pnpm dev
```

### Verificar que bot-miki esta corriendo

```bash
curl http://localhost:3000/health
# Esperado: {"status":"ok","version":"0.0.1","uptime":5}

curl -H "X-API-Key: kp_dev_api_key_para_desarrollo_local_no_usar_en_prod" \
     "http://localhost:3000/v1/license/token?tenantId=dev-tenant-001"
# Esperado: {"token":"eyJ...","expiresAt":"...","features":[...],"plan":"agency"}
```

---

## Staging y Produccion — Railway

### Estructura de servicios en Railway

```
Proyecto Railway: kpcrop-latam-zollner
├── bot-miki       (servicio Node.js — Dockerfile en packages/bot-miki)
├── PostgreSQL     (plugin oficial de Railway)
└── Redis          (plugin oficial de Railway)
```

### Deploy inicial

```bash
# Instalar Railway CLI
npm install -g @railway/cli
railway login

# Crear proyecto
railway init

# Agregar plugins de base de datos desde el dashboard de Railway:
# Settings > Add Plugin > PostgreSQL
# Settings > Add Plugin > Redis

# Vincular variables de entorno (en Railway dashboard o via CLI)
railway variables set JWT_SECRET=$(node -e "console.log(require('crypto').randomBytes(32).toString('hex'))")
railway variables set NODE_ENV=production
railway variables set LOG_LEVEL=info
railway variables set BSALE_RATE_LIMIT_RPS=10

# Deploy
railway up --service bot-miki
```

### Ejecutar migraciones en Railway

```bash
# Una sola vez al hacer deploy inicial
railway run --service bot-miki psql $DATABASE_URL -f packages/bot-miki/migrations/001_initial_schema.sql
```

### Variables de entorno requeridas en Railway

| Variable | Descripcion | Ejemplo |
|---|---|---|
| `DATABASE_URL` | Auto-generada por plugin PostgreSQL | `postgresql://...` |
| `REDIS_URL` | Auto-generada por plugin Redis | `redis://...` |
| `JWT_SECRET` | Minimo 32 chars — generar con crypto.randomBytes | `abc123...` |
| `NODE_ENV` | Ambiente | `production` |
| `LOG_LEVEL` | Nivel de logs | `info` |
| `BSALE_RATE_LIMIT_RPS` | Rate limit conservador hasta confirmar el real | `10` |
| `STRIPE_SECRET_KEY` | Para validar suscripciones | `sk_live_...` |
| `STRIPE_WEBHOOK_SECRET` | Para validar webhooks de Stripe | `whsec_...` |

### Configurar dominio personalizado

En Railway > Settings > Domains:
- Agregar `api.kpcrop.com`
- Configurar en Cloudflare: `CNAME api → <railway-domain>.up.railway.app`

### Configurar Cloudflare

```
# Cache de tokens de licencia en el edge (reduce latencia para clientes en Chile)
# Cloudflare Dashboard > Rules > Cache Rules

Rule: Cache License Tokens
  When: URI path contains "/v1/license/token"
  Cache: Cache everything
  Edge TTL: 4 minutes
```

---

## Checklist Pre-Deploy a Produccion

- [ ] `JWT_SECRET` es un secreto unico de al menos 32 chars
- [ ] `DATABASE_URL` apunta a la BD de produccion (no desarrollo)
- [ ] Migraciones ejecutadas: `001_initial_schema.sql`
- [ ] Seed de dev (`002_seed_dev.sql`) **NO** ejecutado en produccion
- [ ] Cloudflare configurado con CNAME a Railway
- [ ] `GET https://api.kpcrop.com/health` devuelve `{"status":"ok"}`
- [ ] Al menos una licencia real creada en la tabla `licenses`
- [ ] Stripe webhook configurado apuntando a `https://api.kpcrop.com/v1/webhooks/stripe`
- [ ] Primer cliente puede obtener JWT: `GET /v1/license/token?tenantId=<tenant>&X-API-Key=<key>`

---

## Monitoreo

### Logs en Railway

```bash
railway logs --service bot-miki --tail
```

### Health check automatico

Railway hace health check al endpoint `/health` cada 30 segundos. Si falla 3 veces consecutivas, reinicia el servicio automaticamente.

### Alertas recomendadas (Grafana Cloud)

| Alerta | Condicion | Canal |
|---|---|---|
| bot-miki caido | `/health` no responde > 1 min | Slack #alertas |
| Error rate alto | > 5% de requests 5xx en 5 min | Slack #alertas |
| Dead letter queue | > 5 jobs en DLQ en 1 hora | Slack #alertas |
| Licencias expiradas | `SELECT count(*) FROM licenses WHERE status='suspended'` aumenta | Email comercial |
