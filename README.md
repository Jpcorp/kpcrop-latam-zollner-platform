# kpcrop-latam-zollner-platform

Plataforma hub-and-spoke que conecta tiendas en cualquier CMS (PrestaShop, WooCommerce, Shopify) con Bsale ERP/POS. Sincroniza productos, stock y precios de forma manual (plugin por CMS) y automática (servicio central con cola y reintentos).

## Estructura del monorepo

```
kpcrop-latam-zollner-platform/
├── packages/
│   ├── bot-miki/          # Servicio central: API de licencias, webhook Bsale, scheduler, cola BullMQ
│   ├── cms-prestashop/    # Módulo PrestaShop (synkrop) — sync manual + CLI
│   ├── cms-shopify/       # App Shopify (en desarrollo)
│   ├── cms-wordpress/     # Plugin WordPress/WooCommerce (en desarrollo)
│   └── shared/            # Modelos canónicos y contratos TypeScript (@kpcrop/shared)
├── docs/                  # Arquitectura, ADRs, contratos OpenAPI, flujos, testing
├── .github/workflows/     # CI/CD por paquete (paths-filter)
└── docker-compose.yml     # Entorno local: PostgreSQL 16 + Redis 7 + bot-miki
```

## Requisitos

| Herramienta | Versión mínima |
|---|---|
| Node.js | 22.x |
| pnpm | 9.x |
| Docker + Docker Compose | cualquier versión reciente |
| PHP (para PrestaShop local) | 7.4+ |

## Instalación y desarrollo local

```bash
# 1. Instalar dependencias del monorepo
pnpm install

# 2. Levantar infraestructura (PostgreSQL + Redis)
docker compose up postgres redis -d

# 3. Ejecutar migraciones de bot-miki
docker exec -i $(docker compose ps -q postgres) psql -U botmiki botmiki \
  < packages/bot-miki/src/infrastructure/sql/001_initial_schema.sql

# 4. Arrancar bot-miki en modo desarrollo (hot-reload)
pnpm --filter bot-miki dev
```

El API de bot-miki queda disponible en `http://localhost:3000`.
Swagger UI: `http://localhost:3000/docs`

## Comandos del monorepo

```bash
pnpm build                          # Compilar todos los paquetes (turbo, respeta dependencias)
pnpm --filter @kpcrop/shared build  # Compilar sólo el paquete shared
pnpm --filter bot-miki dev          # Arrancar bot-miki con watch
pnpm --filter bot-miki lint         # TypeScript --noEmit en bot-miki
pnpm clean                          # Limpiar todos los dist/ y node_modules
```

## Entorno PrestaShop local

El plugin `synkrop` incluye su propio stack Docker:

```bash
cd packages/cms-prestashop
docker compose up -d          # PrestaShop 1.7.8 en http://localhost:8080
                               # Backoffice: http://localhost:8080/admin-dev (admin / adminpass123)
                               # MySQL en localhost:3307 (prestashop / prestashop_dev)
```

El módulo se monta como volumen (`./synkrop → /var/www/html/modules/synkrop`) para edición en vivo.

## Variables de entorno (bot-miki)

Copiar `.env.example` → `.env` en `packages/bot-miki/`:

```env
DATABASE_URL=postgres://botmiki:botmiki_dev@localhost:5432/botmiki
REDIS_URL=redis://localhost:6379
JWT_SECRET=cambia_esto_en_produccion
PORT=3000
```

## Documentación

| Sección | Ruta |
|---|---|
| Arquitectura general (C4) | `docs/architecture/C4-context.md` |
| Esquema de base de datos | `docs/architecture/database-schema.md` |
| Flujos de sincronización | `docs/architecture/flows/` |
| Contrato OpenAPI bot-miki | `docs/api-contracts/demonio-openapi.yaml` |
| Sistema de licencias | `docs/licensing/README.md` |
| Estrategia de testing | `docs/testing/README.md` |
| ADRs | `docs/adr/` |

## Stack tecnológico

| Componente | Stack |
|---|---|
| bot-miki | Node.js 24, TypeScript 5, Fastify 5, BullMQ, Kysely, PostgreSQL 16, Redis 7 |
| cms-prestashop | PHP 7.4+, PrestaShop 1.7+ |
| shared | TypeScript 5, ESM (target ES2022, module NodeNext) |
| Infraestructura | Docker, Railway (prod), Cloudflare (DNS + cache) |

## Licencia

Ver [LICENSE](LICENSE).
