# Checklist de Investigacion — Bsale API

**Completado:** 2026-05-22 (investigacion publica — sin sandbox aun)  
**Hallazgos detallados:** [bsale-api-findings.md](./bsale-api-findings.md)  
**Leyenda:** ✅ Confirmado | ⚠️ Parcial / con limitacion | ❌ No existe | ❓ Sin documentar

---

## Bloque 1 — Autenticacion

| # | Pregunta | Respuesta | Impacto |
|---|---|---|---|
| 1.1 | ¿Tipo de autenticacion? | ✅ API Key en header `access_token` + OAuth 2.0 disponible | OAuth 2.0 es el camino para multi-tenant (bot-miki gestiona N clientes) |
| 1.2 | ¿Los tokens expiran? | ❓ No documentado — probable vida larga sin rotacion | Confirmar con ayuda@bsale.app antes de decidir estrategia de refresh |
| 1.3 | ¿Token por empresa o por usuario? | ✅ Por empresa (cpnId) — un token = una empresa Bsale | Cada tenant almacena su propio token en la tabla de configuracion del demonio |
| 1.4 | ¿Se puede revocar via API? | ❓ No documentado — probablemente solo via panel Bsale | Riesgo bajo para MVP; documentar como proceso manual |
| 1.5 | ¿Existe sandbox? | ✅ SI — `account.bsale.dev/users/create`, listo en < 1 min | Desarrollar 100% en sandbox antes de tocar datos reales de un cliente |

---

## Bloque 2 — Webhooks

> **Conclusion: SI existen webhooks.** La arquitectura reactiva es viable.

| # | Pregunta | Respuesta | Impacto |
|---|---|---|---|
| 2.1 | ¿Bsale soporta webhooks? | ✅ SI — sistema documentado y en produccion | Arquitectura REACTIVA viable como canal primario |
| 2.2 | ¿Que eventos disparan? | ✅ `product`, `variant`, `stock`, `price`, `document`, `document_paid`, `checkout`, `purchase_document`, `rcof` | Los topics relevantes para sync CMS: product, variant, stock, price |
| 2.3 | ¿El payload incluye datos completos o solo el ID? | ⚠️ **Solo metadata** — `{resourceId, resource, topic, action}` | Cada webhook = 1 llamada adicional a la API para obtener el objeto. Dobla el consumo de rate limits |
| 2.4 | ¿Bsale reintenta el webhook si falla? | ❓ No documentado | Asumir que NO reintenta — implementar polling horario como fallback obligatorio |
| 2.5 | ¿Los webhooks se configuran por empresa? | ✅ SI, por `cpnId` — cada empresa elige URL y topics | Cada nuevo tenant requiere configuracion en Bsale |
| 2.6 | ¿Hay firma/verificacion del payload? | ❌ No documentada | Riesgo de spoofing — mitigar validando el `resource` contra api.bsale.io antes de procesar |

### ⚠️ Friction Point Critico: Registro de Webhooks es Manual

**No hay API para registrar webhooks.** El proceso es enviar email a `ayuda@bsale.app` con la URL y el `cpnId` del cliente. Esto significa que **cada nuevo cliente requiere una accion manual** para activar el sync automatico.

**Estrategia recomendada:**
```
Tier Starter (MVP):    Sin webhooks → polling manual por el cliente (cron en su servidor)
Tier Growth:           Con webhooks → kpcrop gestiona el alta via email a Bsale por el cliente
Tier Agency (futuro):  Negociar acuerdo con Bsale para registro programatico de webhooks via API
```

---

## Bloque 3 — Rate Limits

| # | Pregunta | Respuesta | Impacto |
|---|---|---|---|
| 3.1 | ¿Bsale tiene rate limiting? | ❓ No documentado — probablemente existe | Implementar rate limiter propio de 10 req/s como medida conservadora |
| 3.2 | ¿Cuantas requests por minuto/hora? | ❓ Sin documentar — medir en sandbox | Pendiente de medicion empirica en sandbox |
| 3.3 | ¿Rate limit por API Key o por IP? | ❓ Sin documentar | Si es por IP, el demonio comparte el pool entre todos los tenants — alto riesgo |
| 3.4 | ¿Codigo HTTP al superar el limite? ¿Header `Retry-After`? | ❓ Presumiblemente 429 — sin confirmar | Implementar manejo de 429 con backoff exponencial desde el inicio |
| 3.5 | ¿Rate limits distintos por plan Bsale del cliente? | ❓ Sin documentar | Considerar implementar colas separadas por plan en BullMQ |

**Accion inmediata:** Medir rate limits empiricamente en sandbox con script de carga. Tambien preguntar a ayuda@bsale.app.

---

## Bloque 4 — Endpoints Disponibles

| # | Entidad | Endpoint GET | Filtro por fecha | Paginacion |
|---|---|---|---|---|
| 4.1 | Productos | ✅ `GET /v1/products.json` | ❌ **NO existe `updated_since`** | limit 50, offset |
| 4.2 | Variantes | ✅ `GET /v1/products/{id}/variants.json` o `expand=variants` | ❌ NO | limit 50, offset |
| 4.3 | Stock | ✅ `GET /v1/stocks.json` (filtro por `officeid`, `variantid`) | ❌ NO | limit 50, offset |
| 4.4 | Listas de precio | ✅ `GET /v1/price_lists.json` y `/v1/price_lists/{id}/details.json` | ❌ NO | limit 50, offset |
| 4.5 | Clientes | ✅ `GET /v1/clients.json` | ❓ No confirmado | limit 50, offset |
| 4.6 | Ventas/Documentos | ✅ `GET /v1/documents.json` | ✅ SI (`emissiondaterange`) | limit 50, offset |
| 4.7 | Imagenes | ✅ Via `expand=images` en producto | — | Incluidas en la respuesta |
| 4.8 | Variantes con stock y precio | ✅ `expand=variants` + `expand=stock` + `expand=prices` | ❌ NO | Incluidas en la respuesta |

### ⚠️ Hallazgo Critico: Sin Filtro de Fecha en Productos

El endpoint de productos **no soporta** `updated_since`, `lastmodified`, ni ningun filtro por fecha de modificacion.

**Consecuencia para polling:** El demonio debe descargar el catalogo COMPLETO en cada ciclo de polling y comparar localmente para detectar cambios. Para 1000 productos con `limit=50`: **20 llamadas por tenant por ciclo de polling**.

**Por esto los webhooks son prioritarios sobre el polling.**

**Optimizacion disponible:** Usar `expand=variants` para obtener variantes e imagenes en una sola llamada en lugar de N llamadas separadas:
```
GET /v1/products.json?expand=[variants,images]&limit=50&offset=0
```

---

## Bloque 5 — Escritura en Bsale

| # | Pregunta | Respuesta |
|---|---|---|
| 5.1 | ¿Crear/actualizar clientes via API? | ✅ SI — `POST /v1/clients.json`, `PUT /v1/clients/{id}.json` |
| 5.2 | ¿Crear ordenes/ventas via API? | ✅ SI — `POST /v1/documents.json` (boletas, facturas) |
| 5.3 | ¿La escritura es idempotente? | ❓ No documentado — asumir que NO. Implementar deduplicacion propia |
| 5.4 | ¿Existe campo `external_id`? | ❌ No documentado — usar campo `number` del documento como referencia externa |

**Estrategia de deduplicacion para documentos:**
```
Antes de POST /v1/documents.json:
  → GET /v1/documents.json?number={id_orden_cms}
  → Si existe → no crear, registrar referencia
  → Si no existe → crear documento
```

---

## Bloque 6 — Modelo de Datos Bsale

| # | Pregunta | Respuesta |
|---|---|---|
| 6.1 | ¿El `code` del producto es unico por empresa? | ✅ El `code` vive en la **variante** (no en el producto). Es el SKU — unico por empresa |
| 6.2 | ¿Como representa Bsale las variantes? | ✅ Producto es contenedor logico; variante tiene stock, precio, SKU y atributos |
| 6.3 | ¿Multiples sucursales con stock independiente? | ✅ SI — filtro por `officeid`. El cliente debe elegir que sucursal sincronizar |
| 6.4 | ¿Precios netos o brutos? | ✅ **NETOS (sin IVA).** IVA calculado aparte (tipicamente 19% Chile) |
| 6.5 | ¿Multiples listas de precios? | ✅ SI — N listas por empresa. El plugin debe permitir elegir la lista a sincronizar |
| 6.6 | ¿Imagenes hosteadas en Bsale? | ✅ SI — CDN de Bsale (`resources.bsale.cl`). URLs persistentes pero pueden cambiar si el cliente reemplaza la imagen |

---

## Decision Arquitectonica Resultante

```
ARQUITECTURA HIBRIDA: Webhooks (primario) + Polling (fallback)

Primario  → Webhooks Bsale → bot-miki → encolar job → sync al CMS     (lag < 30s)
Fallback  → Polling cada 60 min → detectar inconsistencias             (lag < 60 min)
Onboarding→ Manual via email a Bsale por cada nuevo cliente en tier Growth+
```

Ver [ADR-004](../adr/ADR-004-sync-strategy.md) para la decision completa.

---

## Preguntas Pendientes para ayuda@bsale.app

Enviar email con estas preguntas antes de comenzar desarrollo del sync automatico:

1. ¿Los `access_token` tienen fecha de expiracion? Si expiran, ¿como se renuevan?
2. ¿Cual es el rate limit de requests por empresa? ¿Es por API Key o por IP?
3. ¿Bsale reintenta los webhooks si el receptor devuelve error o no responde?
4. ¿Es posible registrar y gestionar URLs de webhook via API REST (sin email)?
5. ¿Existe un campo `external_id` o equivalente al crear documentos para evitar duplicados?
6. ¿El parametro `expand=variants` en `GET /v1/products.json` devuelve las variantes de cada producto en la misma respuesta paginada?
