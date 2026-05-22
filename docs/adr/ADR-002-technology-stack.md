# ADR-002 — Stack Tecnologico

**Estado:** Propuesto  
**Fecha:** 2026-05-22  
**Autores:** Equipo kpcrop-latam  

---

## Contexto

El proyecto esta en fase de scaffolding. Se necesita decidir el stack tecnologico para las capas que la plataforma controla directamente: el demonio `bot-miki`, la base de datos, la cola de tareas, el monorepo tooling, la infraestructura, y el billing. Los plugins CMS heredan el lenguaje de su plataforma host (PHP para WordPress/PrestaShop/Magento, Node para Shopify/Jumpseller).

El criterio de decision es: **menor costo de operacion para un equipo pequeno en LATAM, con capacidad de escalar a 500+ tenants sin reescritura**.

---

## Decisiones

### Lenguaje del Demonio: Node.js + TypeScript

**Elegido sobre:** Python (Celery+FastAPI), Go, PHP

**Razon:** El package `shared` (Canonical Product Model, adapters, validaciones) necesita ser consumido tanto por `bot-miki` como por los plugins Node (Shopify, Jumpseller). Un solo lenguaje en el monorepo elimina la duplicacion del modelo de dominio. TypeScript agrega seguridad de tipos en los contratos entre capas.

**Framework:** Fastify (sobre Express). Fastify tiene serialization 2x mas rapida y un sistema de plugins correcto. Diferencia material cuando el demonio maneja miles de jobs/hora.

---

### Cola de Tareas: BullMQ + Redis

**Elegido sobre:** Temporal.io, Celery, Sidekiq, Amazon SQS

**Razon:** BullMQ es la unica cola de jobs para Node con reintentos con backoff exponencial, jobs programados (cron por tenant), concurrencia configurable por cola, y UI de administracion (Bull Board) lista para usar. Cubre los tres requisitos del demonio: retry ante fallos de Bsale, scheduling por tenant, visibilidad del estado.

**Sobre Temporal.io:** Es mas robusto para workflows complejos de larga duracion pero tiene curva de operacion significativamente mayor. Candidato para v2 si los workflows de sync se vuelven multi-paso.

**Patron critico:** Cada job es **idempotente**. Clave: `{tenantId}:{syncType}:{entityId}:{date}`. Ver ADR-003.

---

### Base de Datos: PostgreSQL + Redis

**Elegido sobre:** MongoDB, MySQL, DynamoDB

**Razon:** El modelo de datos de licencias y sync es altamente relacional (tenant → stores → sync_events → licencias). MongoDB suena flexible pero elimina la capacidad de queries de auditoria eficientes. PostgreSQL con JSONB cubre el caso de campos semi-estructurados (features de licencia, configuracion por tenant) sin sacrificar queries relacionales.

**Redis:** Cola BullMQ, tokens JWT con TTL, cache de respuestas de Bsale API (5 minutos — los precios no cambian en segundos). No se usa Redis como base de datos primaria.

---

### API del Demonio: REST + OpenAPI 3.1

**Elegido sobre:** GraphQL, gRPC, tRPC

**Razon:** Los consumidores de la API son los plugins CMS en PHP y Node. PHP no tiene clientes GraphQL maduros en el ecosistema de plugins. gRPC requiere generacion de stubs por lenguaje. REST con OpenAPI 3.1 permite:
- Generar clientes tipados para TypeScript y PHP desde el mismo contrato
- Documentacion interactiva con Scalar/Redoc para que agencias integren
- Lint del contrato en CI con Spectral antes de que ningun plugin actualice

**Regla:** El contrato OpenAPI se actualiza antes de la implementacion (contract-first), no despues.

---

### Monorepo Tooling: pnpm + Turborepo

**Elegido sobre:** Nx, Yarn Workspaces, npm Workspaces, Lerna

**Razon:** pnpm resuelve hoisting de dependencias correctamente en monorepos mixtos. Turborepo agrega cache incremental de tasks: si `shared` no cambio, no se re-compila ni re-testean los consumers que no lo tocan. En un repo con 10+ packages esto reduce CI de minutos a segundos en el caso tipico.

**Sobre Nx:** Mas potente pero configuracion significativamente mas compleja. El equipo pagaria costo de aprendizaje alto sin beneficio proporcional en esta escala.

**Packages PHP:** Composer por package, con Makefile en raiz que orquestra los tests PHP en paralelo desde CI.

---

### Infraestructura: Railway → ECS Fargate

**Ahora (0-500 tenants):** Railway con region us-east-1 o Sao Paulo.
- Zero-ops: PostgreSQL y Redis managed incluidos
- Pricing predecible sin sorpresas de egress
- Deployment desde GitHub en minutos

**Despues (500+ tenants activos):** ECS Fargate en AWS us-east-1 con RDS PostgreSQL y ElastiCache Redis. Migracion directa porque los mismos contenedores Docker corren sin cambios.

**Cloudflare obligatorio desde el inicio:**
- CDN y DDoS protection
- Workers para cache de tokens JWT en el edge (POPs en Santiago, Sao Paulo, Bogota)
- Reduce latencia de validacion de licencia desde el plugin CMS al demonio

**Lo que no se usa ahora:** Kubernetes. Opera como impuesto de complejidad sobre un equipo que debe construir producto.

---

### CI/CD: GitHub Actions con Path Filters

**Razon:** La estructura `.github/workflows/` ya existe. Path filters garantizan que un cambio en `cms-wordpress` solo corre el pipeline de ese package mas `shared`. La matrix strategy valida que `shared` no rompa ningun consumer.

**Release por package:** Cada `cms-*` tiene semver independiente (los usuarios del CMS instalan versiones del plugin). `bot-miki` tiene su propio semver de API. El monorepo no tiene una version global.

---

### Observabilidad: OpenTelemetry + Axiom + Grafana Cloud

**OpenTelemetry como capa de instrumentacion:** Vendor-neutral. Se instrumenta una vez, se cambia el backend sin reescribir.

**Axiom:** Logs estructurados en JSON. Schema estandar: `{tenantId, jobId, cmsType, duration, status, bsaleEndpoint}`. Tier gratuito generoso, queries SQL-like.

**Grafana Cloud:** Metricas de negocio con alertas a Slack. La metrica clave: **sync lag** (tiempo desde cambio en Bsale hasta reflejo en CMS).

**Sentry:** Errores no capturados en `bot-miki` y plugins Node.

---

### Billing: Stripe + MercadoPago

**Stripe:** Capa principal de billing. Suscripciones, metered billing (por volumen de syncs/mes), webhooks confiables para sincronizar estado de licencia.

**MercadoPago:** Metodo de pago adicional para tarjetas locales que Stripe a veces rechaza en Chile y Argentina. No reemplaza a Stripe — se agrega como opcion en el checkout.

**Modelo de agencias:** Sub-cuentas desde el dia 1. Una cuenta de agencia con plan "seats" otorga N tiendas conectadas, cada una con token de licencia hijo.

---

## Resumen

| Capa | Tecnologia |
|---|---|
| Demonio | Node.js 22 LTS + TypeScript 5 + Fastify 5 |
| Cola | BullMQ 5 + Redis 7 |
| Base de datos | PostgreSQL 16 + Redis 7 |
| API | REST + OpenAPI 3.1 |
| Monorepo | pnpm 9 + Turborepo 2 |
| CI/CD | GitHub Actions |
| Hosting (ahora) | Railway + Cloudflare |
| Hosting (escala) | AWS ECS Fargate + RDS + ElastiCache + Cloudflare |
| Observabilidad | OpenTelemetry + Axiom + Grafana Cloud + Sentry |
| Billing | Stripe Billing + MercadoPago |
