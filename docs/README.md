# Documentacion — kpcrop-latam-zollner-platform

Indice de toda la documentacion tecnica del proyecto.

---

## Negocio

| Documento | Descripcion |
|---|---|
| [Resumen Ejecutivo](./business/executive-summary.md) | v2.0 — pivot a canal agencias, propuesta de valor, proyecciones |
| [Business Model Canvas](./business/canvas.md) | v2.0 — segmentos, canales, fuentes de ingreso, costos |
| [Estrategia de Pricing](./business/pricing-strategy.md) | Agency Standard/Pro + Starter/Growth — justificacion y proyecciones |
| [Go-to-Market](./business/go-to-market.md) | v2.0 — 3 agencias en 90 dias como objetivo primario |
| [Plan de Accion 90 dias](./business/action-plan.md) | Matriz de Eisenhower, semana a semana, formalizacion legal |
| [Analisis Competitivo](./business/competitive-analysis.md) | 9 competidores identificados (Sidekick, Pixofia, Codificando...) |
| [Estudio de Mercado](./business/market-study.md) | TAM/SAM/SOM, perfil del cliente, tamano del dolor |
| [FODA](./business/foda.md) | Fortalezas, oportunidades, debilidades, amenazas |
| [Buyer Personas](./business/buyer-personas.md) | Sebastian (agencia), Rodrigo (comerciante), Valeria (PYME) |
| [Plan Financiero](./business/financial-plan.md) | Proyecciones a 24 meses, escenarios conservador/base/optimista |
| [KPIs](./business/kpis.md) | Metricas clave de producto y negocio |

---

## Arquitectura

| Documento | Descripcion |
|---|---|
| [Arquitectura Global](./architecture/README.md) | Vision general, tipo de arquitectura, bounded contexts, riesgos |
| [C4 — Contexto y Contenedores](./architecture/C4-context.md) | Diagramas C4 nivel contexto y nivel contenedor |
| [Esquema de Base de Datos](./architecture/database-schema.md) | PostgreSQL (bot-miki) + MySQL (plugin) — ERD + SQL de creacion |
| [Manejo de Errores](./architecture/error-handling.md) | Estrategia de errores por capa |

### Flujos

| Flujo | Descripcion |
|---|---|
| [Sync Manual](./architecture/flows/sync-manual.md) | El comercio dispara sync desde el admin del CMS |
| [Sync Automatico](./architecture/flows/sync-auto.md) | bot-miki ejecuta syncs programadas con cola y reintentos |
| [Dropshipping](./architecture/flows/dropshipping.md) | Catalogo compartido entre dos CMS via servi/client-dropi |
| [Validacion de Licencias](./architecture/flows/license-validation.md) | JWT en edge, activacion, suspension, modelo de datos |
| [Flujos PrestaShop](./architecture/flows/prestashop-sync-flows.md) | Flujos especificos del plugin PrestaShop |

---

## Decisiones Tecnicas (ADR)

| ADR | Titulo | Estado |
|---|---|---|
| [ADR-001](./adr/ADR-001-canonical-product-model.md) | Canonical Product Model con Zod en `shared` | **Aceptado** |
| [ADR-002](./adr/ADR-002-technology-stack.md) | Stack Tecnologico (Node, BullMQ, PG, Railway...) | **Aceptado** |
| [ADR-003](./adr/ADR-003-queue-idempotency.md) | Cola de Tareas e Idempotencia | **Aceptado** |
| [ADR-004](./adr/ADR-004-sync-strategy.md) | Estrategia de Sync: Webhooks + Polling Hibrido | **Aceptado** |

---

## Contratos de API

| Documento | Descripcion |
|---|---|
| [demonio-openapi.yaml](./api-contracts/demonio-openapi.yaml) | OpenAPI 3.1 de bot-miki (generado desde Fastify/Swagger) |

---

## Licenciamiento

| Documento | Descripcion |
|---|---|
| [Licensing Overview](./licensing/README.md) | Planes v2.0, modelo de datos, flujos de activacion/suspension, metricas |

---

## MVP

| Documento | Descripcion |
|---|---|
| [Definicion del MVP](./mvp/README.md) | Scope, definition of done, progreso actual, metricas de exito |

---

## Investigacion Bsale API

| Documento | Descripcion |
|---|---|
| [Checklist Bsale API](./investigation/bsale-api-checklist.md) | Preguntas respondidas sobre autenticacion, webhooks, rate limits y modelo de datos |
| [Hallazgos Bsale API](./investigation/bsale-api-findings.md) | Analisis detallado con evidencia, implicaciones y preguntas pendientes para Bsale |

---

## Plugins por CMS

| Documento | Descripcion |
|---|---|
| [Plugin PrestaShop](./architecture/plugins/prestashop.md) | Estructura de modulo PHP, adapters, SQL, flujo de configuracion |
| [Otros CMS](./architecture/plugins/other-cms.md) | Notas de diseno para WordPress, Shopify, Jumpseller |

---

## Diseno y Testing

| Documento | Descripcion |
|---|---|
| [Wireframes PrestaShop](./design/wireframes-prestashop.md) | Wireframes del backoffice del plugin |
| [Testing](./testing/README.md) | Estrategia de testing, PHPUnit (PHP), Vitest (TS) |
| [Deployment](./deployment/README.md) | Guia de despliegue en Railway |
| [Instalacion del Plugin](./deployment/plugin-install.md) | Guia para el cliente final — instalar y configurar bsalesync en PS |
| [Config de Produccion](./deployment/production-config.md) | IDs de Railway, URLs, tenants activos (no-secrets) |

---

## Estructura de Archivos

```
docs/
├── README.md
├── adr/
│   ├── ADR-001-canonical-product-model.md  ← Aceptado
│   ├── ADR-002-technology-stack.md         ← Aceptado
│   ├── ADR-003-queue-idempotency.md        ← Aceptado
│   └── ADR-004-sync-strategy.md            ← Aceptado
├── api-contracts/
│   └── demonio-openapi.yaml
├── architecture/
│   ├── README.md
│   ├── C4-context.md
│   ├── database-schema.md
│   ├── error-handling.md
│   ├── flows/
│   │   ├── sync-manual.md
│   │   ├── sync-auto.md
│   │   ├── dropshipping.md
│   │   ├── license-validation.md
│   │   └── prestashop-sync-flows.md
│   └── plugins/
│       ├── prestashop.md
│       └── other-cms.md
├── business/
│   ├── README.md
│   ├── action-plan.md
│   ├── buyer-personas.md
│   ├── canvas.md                           ← v2.0
│   ├── competitive-analysis.md
│   ├── executive-summary.md               ← v2.0
│   ├── financial-plan.md
│   ├── foda.md
│   ├── go-to-market.md                    ← v2.0
│   ├── kpis.md
│   ├── market-study.md
│   └── pricing-strategy.md               ← v2.0
├── deployment/
│   └── README.md
├── design/
│   └── wireframes-prestashop.md
├── investigation/
│   ├── bsale-api-checklist.md
│   └── bsale-api-findings.md
├── licensing/
│   └── README.md
├── mvp/
│   └── README.md
└── testing/
    └── README.md
```
