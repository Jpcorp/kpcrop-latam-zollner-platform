# CLAUDE.md

Guía para agentes de IA (Claude Code) que trabajan en este repositorio. Documenta qué es el
proyecto, su historia, su stack, su arquitectura y las convenciones a respetar. Complementa al
`README.md` (que está orientado a onboarding de desarrolladores); aquí prima el contexto que **no
es obvio leyendo el código**.

---

## 1. Qué es este proyecto

**Producto comercial: Synkrop** — un plugin/servicio que sincroniza **productos, stock y precios**
desde **Bsale** (ERP/POS chileno) hacia tiendas e-commerce en distintos CMS.

> ⚠️ **Nomenclatura:** el producto se llama **Synkrop**. Antes se llamaba *BsaleSync* / *bsalesync*
> (quedan referencias históricas en migraciones SQL: `migrate-from-bsalesync.sql`). Usa siempre
> "Synkrop" en código, docs y comunicación nueva.

El repositorio es la **plataforma completa** (`kpcrop-latam-zollner-platform`): un monorepo
hub-and-spoke que conecta cualquier CMS con Bsale, con dos modos de sincronización:

- **Manual** — plugin instalado en cada CMS (sync bajo demanda + CLI).
- **Automática** — servicio central (`bot-miki`) con webhooks de Bsale, cola y reintentos.

**Modelo de negocio:** SaaS por licencia (planes Starter / Growth / Agency), con foco en un canal
de **agencias** (white-label) en Chile y expansión a Perú. La documentación de negocio vive en
`docs/business/`.

---

## 2. Historia y estado

- **Inicio:** ~19 de mayo de 2026. Desarrollo activo desde entonces (74 commits al 26-jun-2026).
- **Primer cliente en producción:** tienda **PrestaShop en `strainmachine.com`** (servidor cPanel
  propio, no Railway). Es el cliente piloto real; el plugin PrestaShop es el paquete más maduro.
- **Rename BsaleSync → Synkrop:** migración ya aplicada (`sql/migrate-from-bsalesync.sql`).
- **Trazabilidad end-to-end:** se añadió correlación `job_id` entre `bot-miki` y el plugin
  (`X-Synkrop-Job-Id` → `synkrop_log.job_id` / `sync_events.idempotency_key`).
- **Auditoría multi-agente (jul-2026):** se revisó todo el código (seguridad, arquitectura,
  correctitud, BD, tests). Los 25 hallazgos están respaldados como **GitHub Issues #91–#115**
  con el label `audit: synkrop-2026-07`. Ver §8.

**Madurez por paquete:**

| Paquete | Estado |
|---|---|
| `cms-prestashop` (synkrop) | ✅ En producción (strainmachine.com) |
| `bot-miki` | ✅ En producción (miki.keepcrop.com / Railway) |
| `shared` | 🟡 Base mínima |
| `cms-shopify` | ⛔ Vacío / planificado |
| `cms-wordpress` | ⛔ Vacío / planificado |

---

## 3. Stack tecnológico

| Componente | Stack real (verificado en manifests) |
|---|---|
| **bot-miki** | Node.js ≥22 (ESM), TypeScript 5.4, **Fastify 5**, **BullMQ 5** (+ ioredis), **Kysely 0.27** sobre **PostgreSQL** (`pg` 8), **Zod** 3, `jsonwebtoken` 9, Swagger/OpenAPI, **Vitest** 2 |
| **cms-prestashop** (synkrop) | **PHP ≥7.4**, **PrestaShop 1.7+** (compat 1.7.8 / 8.x en ramas `fix/*`), PSR-4 `Kpcrop\Synkrop\`, **PHPUnit 9.6**, phpcs PSR-12 |
| **shared** (`@kpcrop/shared`) | TypeScript 5, ESM (ES2022 / NodeNext), Zod. Modelos canónicos y contratos compartidos |
| **Monorepo** | **pnpm 9** workspaces + **Turborepo 2** |
| **Infra** | Docker / docker-compose (local), **Railway** (prod bot-miki, Dockerfile), **Cloudflare** (DNS + edge cache), cPanel (prod PrestaShop) |

> 🚫 **No migrar `bot-miki` a otro stack (p.ej. Spring Boot) salvo petición explícita del usuario.**
> El stack Node/TS/Fastify es una decisión tomada (ver `docs/adr/ADR-002-technology-stack.md`).

---

## 4. Arquitectura y flujo de datos

Patrón **hub-and-spoke**: Bsale es la fuente de verdad; `bot-miki` es el hub central; cada plugin
CMS es un spoke.

### Flujo de sincronización automática (webhook)

```
Bsale  ──webhook──▶  bot-miki  ──resuelve recurso──▶  Bsale API
(cambio de stock)    POST /v1/webhooks/bsale         (GET del recurso cambiado)
                          │
                          ▼
                     BullMQ (Redis)  ──worker──▶  fetch  ──▶  plugin CMS
                     idempotencia,               X-Synkrop-*    webhook.php
                     attempts:5, backoff 30s                        │
                                                                    ▼
                                                          sync quirúrgico (una variante)
                                                          POST /v1/sync/report (cierra el loop)
```

- **`bot-miki`** (hexagonal *nominal* — ver nota): `routes/` (API), `workers/` (BullMQ),
  `scheduler/` (polling de fallback vía cron casero), `adapters/` (traducción de webhooks Bsale
  v1/v2), `infrastructure/` (Kysely/Postgres, cliente HTTP Bsale), `domain/license.ts` (JWT).
- **`cms-prestashop/synkrop`**: `webhook.php` (entrada autenticada con `hash_equals`),
  `classes/SynkropService.php` (lógica de sync: `syncSingle` quirúrgico, `syncProducts/syncStock`
  bulk, `upsertVariant`, mapeo variante↔producto en `ps_synkrop_product_map`),
  `controllers/admin/AdminSynkropController.php` (panel), `cli/sync.php` (sync por cron).

### Conceptos de dominio clave

- **Surgical vs bulk:** un webhook de `stock` resuelve **solo esa variante** y actualiza solo ese
  producto (quirúrgico). Los `topic` de precio/manual disparan sync bulk (itera listas completas).
- **Mapeo variante→producto:** `bot-miki` no conoce el catálogo del CMS; envía el `variantId` de
  Bsale y el plugin lo resuelve contra `synkrop_product_map`. **El mapa se puebla solo en el sync
  de productos** → un producto nuevo requiere product-sync antes de que su stock funcione (no es
  self-healing; ver issue #114).
- **Idempotencia:** `jobId = webhook_{store}_{topic}_{resourceId}_{send}` (BullMQ deduplica
  reintentos de Bsale) + `sync_events.idempotency_key UNIQUE`.
- **Licenciamiento:** `bot-miki` emite un JWT de licencia por `X-API-Key`; el plugin lo cachea. Ver
  `docs/licensing/`.
- **Trazabilidad:** `job_id` correlaciona el evento de punta a punta.
- **Multi-tenant:** `tenant_stores` (una tienda por integración Bsale, campo `bsale_integration_id`
  = `cpnId` del webhook) bajo una `licenses`.

> ⚠️ **"Hexagonal" es nominal.** La estructura de carpetas lo sugiere, pero el dominio no contiene
> las reglas de negocio (el mapeo real vive en PHP) y `sync-worker.ts` está acoplado a
> infraestructura concreta. No lo trates como hexagonal estricto.

---

## 5. Estructura del monorepo

```
kpcrop-latam-zollner-platform/
├── packages/
│   ├── bot-miki/              # Servicio central Node/TS (API + worker + scheduler en 1 proceso)
│   │   ├── src/{routes,workers,scheduler,adapters,infrastructure,domain}/
│   │   └── migrations/        # 001_initial_schema.sql, 002_seed_dev.sql (Postgres)
│   ├── cms-prestashop/
│   │   ├── synkrop/           # El módulo PrestaShop en sí
│   │   │   ├── classes/       # BsaleApiClient, LicenseClient, SynkropService
│   │   │   ├── controllers/admin/AdminSynkropController.php
│   │   │   ├── cli/sync.php
│   │   │   ├── sql/           # install / uninstall / migrate_* (MySQL)
│   │   │   └── webhook.php     # endpoint público de entrada
│   │   └── tests/             # PHPUnit
│   ├── cms-shopify/           # (vacío / planificado)
│   ├── cms-wordpress/         # (vacío / planificado)
│   └── shared/                # @kpcrop/shared — modelos canónicos TS
├── docs/                      # architecture, adr, api-contracts, business, deployment, licensing…
├── ssh/                       # Scripts de deploy (GITIGNORED — contiene secretos, ver §7)
├── .github/workflows/         # ci.yml, release.yml (filtrado por paths)
├── docker-compose.yml         # Postgres 16 + Redis 7 + bot-miki (local)
├── railway.toml               # Deploy prod bot-miki (Dockerfile)
├── turbo.json / pnpm-workspace.yaml
```

---

## 6. Comandos habituales

```bash
# Monorepo (raíz)
pnpm install
pnpm build                          # turbo run build (respeta dependencias)
pnpm --filter bot-miki dev          # bot-miki con watch (http://localhost:3000, /docs = Swagger)
pnpm --filter bot-miki test         # Vitest
pnpm --filter bot-miki lint         # tsc --noEmit

# Infra local
docker compose up postgres redis -d
# Migración Postgres (ruta REAL): packages/bot-miki/migrations/001_initial_schema.sql

# PrestaShop local (stack propio del plugin)
cd packages/cms-prestashop && docker compose up -d   # PrestaShop 1.7.8 en :8080, MySQL en :3307

# Tests PHP
cd packages/cms-prestashop && composer test          # phpunit --testdox
```

> ℹ️ El `README.md` apunta la migración a `src/infrastructure/sql/…`; **la ruta real es
> `packages/bot-miki/migrations/`**. Corregir el README si se toca.

---

## 7. Despliegue

Hay scripts de deploy en **`ssh/`** (carpeta **gitignored** a propósito):

| Script | Qué hace |
|---|---|
| `ssh/deploy_bot_miki.sh` | Push `develop` → crea/mergea PR a `master` → monitorea deploy en **Railway** → health check en `https://miki.keepcrop.com/health` |
| `ssh/strainmachine.sh` | Config centralizada del **servidor PrestaShop de producción** (`strainmachine.com`, cPanel `strainma@67.222.29.249`): `ssh`, `run`, `upload/download`, `deploy-synkrop`, `deploy-db` |
| `ssh/deploy_synkrop.sh` | Alias → `strainmachine.sh deploy-synkrop` (sube los .php del plugin por scp) |
| `ssh/deploy_synkrop_db.sh` | Migraciones de BD en el servidor de producción |

**Producción:**
- `bot-miki` → **Railway** (Dockerfile), dominio `miki.keepcrop.com`.
- `synkrop` (PrestaShop) → **cPanel** en `strainmachine.com` (primer cliente).
- DNS/edge → **Cloudflare**. Dominios planificados: `api.kpcrop.com`, landing `kpcrop.com`.

> 🔐 **Seguridad:** los scripts de `ssh/` contienen **secretos en texto plano** (PAT de GitHub,
> tokens de Railway, passphrase de la clave SSH privada `ssh/id_rsa`). Están gitignored, pero:
> **nunca los muevas fuera de `ssh/` ni los incluyas en un commit/PR.** Si el usuario pide operar
> con GitHub/Railway/servidor, extrae las credenciales de ahí, pero no las imprimas en respuestas
> ni las persistas en otro archivo. Conviene rotarlas.

---

## 8. Problemas conocidos (auditoría jul-2026)

25 hallazgos respaldados en GitHub Issues **#91–#115** (label `audit: synkrop-2026-07`). Los más
importantes a tener presentes al tocar código:

- **#91 / #92 (P0, seguridad):** `POST /v1/sync/report` sin autenticación; `ADMIN_KEY` con default
  público en `config.ts`.
- **#93 (P0):** `webhook.php` responde siempre `success:true` y el `finally` de `sync-worker.ts`
  marca siempre `last_sync_status:'success'` → **eventos de stock se pierden en silencio**.
- **#94 / #95 (P0):** falta índice en `tenant_stores.bsale_integration_id` (full scan por webhook);
  INSERT en `sync_events` sin `onConflict` → un reintento revienta el worker.
- **#98 (P1):** el fallback `variant.href` (fix aplicado en el resolver TS) **no** se replicó en el
  bulk PHP `SynkropService::syncStock`.
- **#99 (P1):** `tenant_id` tiene 3 definiciones incoherentes según el origen.
- **#100 (P1):** el fix de timezone depende de que el servidor MySQL esté en UTC (`CURRENT_TIMESTAMP`).

Consulta el issue correspondiente antes de "arreglar" algo en estas zonas: puede haber contexto.

---

## 9. Convenciones

- **Commits:** Conventional Commits con scope de paquete, en español.
  Ej.: `fix(cms-prestashop): corregir timezone en historial`,
  `feat(bot-miki): correlación end-to-end`. Formato `tipo(scope): descripción`.
- **Ramas:** `develop` (integración) → PR → `master` (producción/deploy). Ramas de fix por
  compatibilidad: `fix/php7x-compat`, `fix/ps8x-compat`, etc. No commitees directo en `master`.
- **GitHub Issues:** título `[Área] descripción`. Labels existentes:
  `priority: critical|high|medium|low`, `area: bot-miki|prestashop|security|infra|shared|shopify|wordpress`,
  `type: bug|chore|test|feature|docs`.
- **Idioma:** documentación, issues y mensajes de commit en **español**. Código e identificadores en
  inglés/español según el existente (respeta el estilo del archivo que edites).
- **Seguridad SQL:** en PHP, **siempre** `(int)` para enteros y `pSQL()` para strings (el patrón ya
  está aplicado; no lo rompas). En TS, Kysely parametriza.
- **Secretos:** los `.env` reales NO se commitean (solo `.env.example`). Ver §7 para `ssh/`.

---

## 10. Documentación de referencia

| Tema | Ruta |
|---|---|
| Arquitectura C4 / esquema BD / manejo de errores | `docs/architecture/` |
| Decisiones de arquitectura (ADR) | `docs/adr/` (001 modelo canónico, 002 stack, 003 idempotencia, 004 estrategia de sync) |
| Contrato OpenAPI de bot-miki | `docs/api-contracts/demonio-openapi.yaml` |
| Licenciamiento | `docs/licensing/` |
| Despliegue / secrets / sync manual / webhooks | `docs/deployment/` |
| Investigación de la API de Bsale | `docs/investigation/` |
| Negocio (pricing, GTM, agencias, finanzas) | `docs/business/` |
| Testing | `docs/testing/` |

---

_Última actualización: jul-2026. Si cambias stack, arquitectura o convenciones, actualiza este
archivo en el mismo PR._
