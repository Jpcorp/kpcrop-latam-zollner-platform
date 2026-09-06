# CLAUDE.md

Guía para agentes de IA que trabajan en este repositorio. Aquí va solo lo que **no se deduce
leyendo el código**: si borrar una línea no provoca un error, esa línea sobra.

---

## 1. Qué es

**Synkrop** — producto SaaS por licencia (planes Starter / Growth / Agency, canal de agencias
white-label en Chile, expansión a Perú) que integra **Bsale** (ERP/POS chileno) con tiendas
e-commerce. El repo (`kpcrop-latam-zollner-platform`) es un monorepo hub-and-spoke:
Bsale es la fuente de verdad, `bot-miki` el hub, cada plugin CMS un spoke.

Son **dos flujos, en direcciones opuestas** — no asumas que solo existe el primero:

1. **Bsale → CMS (catálogo):** productos, precios, stock y categorías. Modo manual (plugin + CLI)
   o automático (webhooks Bsale → `bot-miki` → plugin).
2. **CMS → Bsale (ventas):** los pedidos de PrestaShop generan documentos de venta en Bsale
   (`OrderDocumentService`, cola `synkrop_order_queue`, nota de venta → boleta/factura, estados
   `pending→generated→emitted→closed`, hook `actionOrderStatusPostUpdate`).

> ⚠️ **Nomenclatura:** el producto se llama **Synkrop**. Antes fue *BsaleSync* / *bsalesync*
> (quedan referencias históricas en `sql/migrate-from-bsalesync.sql`). Usa "Synkrop" siempre.

**Estado:** en producción con un cliente piloto real, la tienda PrestaShop `strainmachine.com`
(cPanel propio, no Railway), plugin v1.3.0. `cms-shopify` y `cms-wordpress` están vacíos.
El negocio se documenta en `docs/business/`.

> 🚫 **No migrar `bot-miki` a otro stack (p.ej. Spring Boot) salvo petición explícita del usuario.**
> Node/TS/Fastify es una decisión tomada (`docs/adr/ADR-002-technology-stack.md`).

---

## 2. Conceptos de dominio

- **Surgical vs bulk:** un webhook de `stock` resuelve **solo esa variante** y actualiza solo ese
  producto. Los topics de precio/manual disparan sync bulk (itera listas completas).
- **Mapeo variante→producto:** `bot-miki` no conoce el catálogo del CMS; manda el `variantId` de
  Bsale y el plugin lo resuelve contra `synkrop_product_map`. El mapa se puebla en el sync de
  productos y, si llega stock de una variante desconocida, `SynkropService::healVariantMap()` la
  resuelve contra Bsale al vuelo (sí es self-healing).
- **Idempotencia:** `jobId = webhook_{store}_{topic}_{resourceId}_{send}` (BullMQ deduplica los
  reintentos de Bsale) + `sync_events.idempotency_key UNIQUE`. BullMQ **rechaza `:` en el jobId**.
- **Licenciamiento:** `bot-miki` emite un JWT por `X-API-Key`; el plugin lo cachea y, si bot-miki
  cae, sigue con el JWT stale hasta 24 h. Ver `docs/licensing/`.
- **Modo degradado (#127):** `OrderDocumentService` **no recibe `LicenseClient` a propósito** — la
  emisión de documentos no valida licencia. Añadir ahí un chequeo rompe el diseño.
- **Trazabilidad:** `job_id` correlaciona el evento de punta a punta
  (`X-Synkrop-Job-Id` → `synkrop_log.job_id` / `sync_events.idempotency_key`).
- **Multi-tenant:** `tenant_stores` (una tienda por integración Bsale; `bsale_integration_id` =
  `cpnId` del webhook) bajo una `licenses`.

> ⚠️ **"Hexagonal" es nominal.** Las carpetas lo sugieren, pero el dominio no tiene las reglas de
> negocio (el mapeo real vive en PHP) y `sync-worker.ts` está acoplado a infraestructura concreta.

---

## 3. Ejecución y despliegue

**`PROCESS_ROLE`** (`packages/bot-miki/src/index.ts`) decide qué levanta el proceso:
`all` (default, monolito) | `api` | `worker` | `scheduler`. En producción son **3 servicios
Railway** con la misma imagen. Dos reglas que no se ven en el código:

- **Las migraciones Postgres solo corren en `all` y `api`** — no muevas `applyMigrations()` fuera
  de ese `if` o cada réplica del worker las aplicaría en paralelo.
- **`worker` y `scheduler` igual exponen `/health`** (servidor `node:http` mínimo, sin Fastify)
  porque `railway.toml` aplica `healthcheckPath` a todo servicio. No lo quites.

Env vars **obligatorias** (`config.ts` hace `process.exit(1)` si faltan): `ADMIN_KEY`,
`TOKEN_ENCRYPTION_KEY` y `JWT_SECRET`, las tres ≥32 caracteres. El `.env.example` de la **raíz
está obsoleto** (no las trae): copia `packages/bot-miki/.env.example`.

**Producción:** `bot-miki` → Railway (`miki.keepcrop.com`); `synkrop` → cPanel en
`strainmachine.com`; DNS/edge → Cloudflare. El deploy real sale de `ssh/deploy_bot_miki.sh`
(merge `develop`→`master`), no de `release.yml` (que solo construye por tag).

> 🔐 **`ssh/` está gitignored y contiene secretos en texto plano** (PAT de GitHub, tokens de
> Railway, passphrase de `ssh/id_rsa`), junto a los scripts de deploy y utilitarios
> (`strainmachine.sh`, `deploy_synkrop*.sh`, `bsale_sandbox.sh`, `synkrop_log_view.sh`…).
> **Nunca los muevas fuera de `ssh/` ni los incluyas en un commit/PR**, no imprimas credenciales
> en respuestas ni las copies a otro archivo. Conviene rotarlas.

---

## 4. Comandos no obvios

```bash
pnpm build && pnpm --filter bot-miki dev   # dev NO compila (node --watch dist/): build primero
docker compose -f docker-compose.roles.yml up --build   # laboratorio del split de roles (#113)
./scripts/roles-lab.sh                                  # ídem, con menú; puertos propios (3001/5434/6381)

cd packages/cms-prestashop && composer test             # phpunit (unitarios)
BSALE_SANDBOX_TOKEN=xxx composer test -- --group integration   # sandbox real; sin token se SALTAN
cd packages/cms-prestashop && docker compose up -d      # PS 1.7.8 :8080, MySQL :3307, Mailhog
```

- `docker compose up bot-miki` exige un `packages/bot-miki/.env` real (`env_file`), no variables sueltas.
- **CI verde ≠ tests PHP pasando:** phpcs y phpunit llevan `|| true` en `ci.yml`, y los jobs se
  filtran por paths. Además el job `shared` invoca `pnpm --filter @kpcrop/shared lint`, script
  que **no existe**, y el job `bot-miki` corre sin `ADMIN_KEY`/`TOKEN_ENCRYPTION_KEY`.
- `cms-prestashop` **no tiene `package.json`** → `pnpm test` / `pnpm lint` en la raíz **nunca
  tocan PHP**. Para el plugin es siempre `composer test` dentro del paquete.
- **No hay formatter ni linter de estilo en el repo** (ni ESLint, ni Prettier, ni Biome, ni
  `.editorconfig`): `lint` en TS es `tsc --noEmit`; en PHP, `phpcs --standard=PSR12` a mano.

---

## 5. Base de datos

Dos motores distintos: **Postgres** (bot-miki) y **MySQL/MariaDB** (plugin PrestaShop).

**Postgres — `packages/bot-miki/migrations/`:** basta con agregar un `.sql`;
`infrastructure/migrations-runner.ts` los descubre y aplica al boot en **orden alfabético**
(registro en `schema_migrations`).

- Respeta el prefijo `NNN_`, y **nunca renombres ni edites una migración ya aplicada** (no se
  re-ejecuta).
- `002_seed_dev.sql` está **excluido a propósito** (filtro por nombre literal): es data de dev.
- Cada archivo se manda como un solo `pool.query()` multi-statement → transacción implícita →
  **`CREATE INDEX CONCURRENTLY` falla ahí**. El patrón del repo es `IF NOT EXISTS` en índices y
  columnas, y `DROP CONSTRAINT IF EXISTS` antes de recrear un CHECK.

**MySQL — `packages/cms-prestashop/synkrop/sql/`:** 11 migraciones `migrate_*.sql` aplicadas en
producción con `ssh/deploy_synkrop_db.sh`.

- Deben ser **idempotentes** con el patrón `information_schema` + stored procedure (plantilla:
  `migrate_add_test_mode.sql`); un `ALTER TABLE` plano revienta en la segunda pasada.
- Ese patrón usa `DELIMITER`, directiva del cliente `mysql` → **solo se aplican por CLI**, nunca
  vía PDO ni `Db::getInstance()`.
- Dos convenciones de prefijo fáciles de confundir: `install.sql` usa el literal `PREFIX_` que
  `synkrop.php` sustituye por `_DB_PREFIX_`; los `migrate_*.sql` traen `SET @db_prefix = 'ps_'`
  **hardcodeado** (otra tienda exige editar la migración a mano).
- El MySQL de producción **no está en UTC**: fechas nuevas sin `DEFAULT CURRENT_TIMESTAMP`,
  escritas desde PHP con `gmdate()`.
- `error_details` es columna **JSON** con `json_valid` en MariaDB → escribe `json_encode([])`,
  nunca `''`. El único `null` legítimo es limpiar un error previo, y exige el 5º argumento
  `$null_values=true` de `Db::update()` (`OrderDocumentService.php:138,634`) — sin él PrestaShop
  descarta el campo del UPDATE en silencio.

---

## 6. Convenciones

- **Commits:** Conventional Commits en español, con scope de paquete.
  Ej.: `fix(cms-prestashop): corregir timezone en historial`.
- **Ramas:** `develop` (integración) → PR → `master` (producción). No commitees directo en `master`.
- **Issues:** título `[Área] descripción`. Labels: `priority: critical|high|medium|low`,
  `area: bot-miki|prestashop|security|infra|shared|shopify|wordpress`,
  `type: bug|chore|test|feature|docs`.
- **Idioma:** docs, issues y commits en **español**. En código respeta el estilo del archivo.
- **Seguridad — reglas vigentes, no las rompas:**
  - PHP: `(int)` para enteros y `pSQL()` para strings, siempre. En TS, Kysely parametriza.
  - El `bsale_access_token` va cifrado AES-256-GCM: escribe con `encryptToken()` y lee con
    `decryptToken()` (`infrastructure/token-crypto.ts`), nunca texto plano.
  - `/admin` y `/docs` van tras `adminKeyMatches(x-admin-key)` (timing-safe) + rate-limit global.
  - **El tenant nunca se toma del body**: se deriva server-side de la `X-API-Key`.
- **Secretos:** los `.env` reales no se commitean (solo `.env.example`). Ver `ssh/` arriba.
- **Tests:** tras tocar un paquete, corre su suite (`vitest` / `phpunit`) antes de dar algo por hecho.

---

## 7. Auditoría e historial

La auditoría multi-agente de jul-2026 (issues #91–#115, label `audit: synkrop-2026-07`) está
**cerrada y desplegada**: no re-arregles esos hallazgos. Si tocas una de esas zonas y quieres el
contexto de por qué está como está, lee el issue correspondiente en GitHub.

Documentación por tema en `docs/` (haz `ls docs/`; la enumeración se desactualiza).

_Última actualización: 05-sep-2026. Si cambias stack, arquitectura o convenciones, actualiza este
archivo en el mismo PR._
