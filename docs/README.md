# Documentacion — kpcrop-latam-zollner-platform

Indice de toda la documentacion tecnica del proyecto.

---

## Arquitectura

| Documento | Descripcion |
|---|---|
| [Arquitectura Global](./architecture/README.md) | Vision general, tipo de arquitectura, bounded contexts, riesgos |
| [C4 — Contexto y Contenedores](./architecture/C4-context.md) | Diagramas C4 nivel contexto y nivel contenedor |

### Flujos

| Flujo | Descripcion |
|---|---|
| [Sync Manual](./architecture/flows/sync-manual.md) | El comercio dispara sync desde el admin del CMS |
| [Sync Automatico](./architecture/flows/sync-auto.md) | bot-miki ejecuta syncs programadas con cola y reintentos |
| [Dropshipping](./architecture/flows/dropshipping.md) | Catalogo compartido entre dos CMS via servi/client-dropi |
| [Validacion de Licencias](./architecture/flows/license-validation.md) | JWT en edge, activacion, suspension, modelo de datos |

---

## Decisiones Tecnicas (ADR)

| ADR | Titulo | Estado |
|---|---|---|
| [ADR-001](./adr/ADR-001-canonical-product-model.md) | Canonical Product Model — esquema unico en `shared` | Propuesto |
| [ADR-002](./adr/ADR-002-technology-stack.md) | Stack Tecnologico completo (Node, BullMQ, PG, Railway...) | Propuesto |
| [ADR-003](./adr/ADR-003-queue-idempotency.md) | Cola de Tareas e Idempotencia | Propuesto |
| [ADR-004](./adr/ADR-004-sync-strategy.md) | Estrategia de Sync: Webhooks + Polling Hibrido | Propuesto |

---

## Contratos de API

| Documento | Descripcion |
|---|---|
| [demonio-openapi.yaml](./api-contracts/demonio-openapi.yaml) | OpenAPI 3.1 completo de la API REST de bot-miki |

---

## Licenciamiento

| Documento | Descripcion |
|---|---|
| [Licensing Overview](./licensing/README.md) | Planes, modelo de datos, flujos de activacion/suspension, metricas |

---

## MVP

| Documento | Descripcion |
|---|---|
| [Definicion del MVP](./mvp/README.md) | Scope, definition of done, orden de construccion, metricas de exito |

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

---

## Estructura de Archivos

```
docs/
├── README.md                               ← este archivo
├── architecture/
│   ├── README.md                           ← vision general + diagrama principal
│   ├── C4-context.md                       ← diagramas C4 nivel contexto y contenedor
│   ├── plugins/
│   │   └── prestashop.md                  ← diseño completo del modulo PrestaShop
│   └── flows/
│       ├── sync-manual.md                 ← secuencia de sync manual
│       ├── sync-auto.md                   ← secuencia de sync automatico + reintentos
│       ├── dropshipping.md               ← flujo dropshipping servidor/cliente
│       └── license-validation.md        ← validacion JWT + activacion + suspension
├── adr/
│   ├── ADR-001-canonical-product-model.md
│   ├── ADR-002-technology-stack.md
│   └── ADR-003-queue-idempotency.md
├── api-contracts/
│   └── demonio-openapi.yaml               ← contrato OpenAPI 3.1 de bot-miki
├── investigation/
│   └── bsale-api-checklist.md            ← checklist de investigacion de Bsale API
├── licensing/
│   └── README.md                          ← planes, billing, modelo de agencias
└── mvp/
    └── README.md                          ← scope, DoD, roadmap, metricas
```
