# Hallazgos — Investigacion Bsale API

**Fecha:** 2026-05-22  
**Fuentes:** docs.bsale.dev, apichile.bsalelab.com, github.com/gmontero/Webhooks-Bsale-doc  
**Estado:** Completado (puntos sin respuesta identificados para consulta directa a Bsale)

---

## Resumen Ejecutivo

| Area | Estado | Impacto |
|---|---|---|
| Autenticacion | Documentada | Token por empresa, sin expiracion conocida, OAuth 2.0 disponible |
| Webhooks | **SI existen** — 9 topics | Arquitectura REACTIVA es viable |
| Payload de webhooks | Solo metadata (no datos completos) | Cada webhook requiere una segunda llamada a la API |
| Rate limits | **NO documentados** | Riesgo — hay que medir empiricamente |
| Filtro por fecha en productos | **NO existe** | Polling requiere descargar TODOS los productos cada vez |
| Variantes | Endpoint separado | Cada sync de producto requiere N+1 llamadas |
| Sandbox | **SI existe** — rapido de crear | Se puede desarrollar sin datos reales |
| Escritura en Bsale | Soportada (clientes, ventas) | Sync bidireccional es posible |
| Idempotencia de escritura | **NO documentada** | Riesgo de duplicados en reintentos |

---

## Bloque 1 — Autenticacion

### 1.1 Tipo de autenticacion
**Respuesta:** API Key (token simple en header HTTP)

```http
GET https://api.bsale.io/v1/products.json
access_token: tu_token_aqui
```

El token se pasa como header `access_token` en cada request. No es Bearer token ni OAuth por defecto — es un header custom.

**OAuth 2.0 tambien disponible:** Para integraciones multi-tenant (como bot-miki gestionando N clientes), Bsale ofrece OAuth 2.0 con flujo de 3 pasos:
1. Redirigir usuario a `https://oauth.bsale.io/login`
2. Recibir codigo de autorizacion en callback
3. POST a `https://oauth.bsale.io/gateway/oauth_response.json` para obtener `accessToken`

### 1.2 Expiracion de tokens
**Respuesta:** No documentada. La documentacion no menciona TTL ni refresh tokens. Comportamiento probable: tokens de larga duracion (semanas o meses) sin rotacion automatica.

**Accion requerida:** Confirmar con ayuda@bsale.app si los tokens expiran y cada cuanto.

### 1.3 Token por empresa o por usuario
**Respuesta:** Token por empresa (cpnId). Cada empresa tiene un `access_token` unico que da acceso a todos sus recursos. No es por usuario individual.

**Implicacion para bot-miki:** Cada tenant almacena su propio `access_token` de Bsale en la tabla de configuracion. Un token = una empresa en Bsale.

### 1.4 Revocacion de token
**Respuesta:** No documentada via API. Probablemente solo via panel de Bsale o contactando soporte.

### 1.5 Sandbox
**Respuesta: SI EXISTE.** Se crea en `https://account.bsale.dev/users/create` en menos de 1 minuto.

**Consideraciones criticas del sandbox:**
- El `access_token` del sandbox es DISTINTO al de produccion
- Los IDs de productos, clientes y documentos cambian entre sandbox y produccion
- El sandbox NO soporta funciones electronicas (factura electronica, boleta electronica)
- Los endpoints son los mismos — el token determina el ambiente

---

## Bloque 2 — Webhooks (Decision Arquitectonica)

### 2.1 ¿Bsale soporta webhooks?
**Respuesta: SI.** Bsale tiene un sistema de webhooks documentado y en produccion.

### 2.2 Eventos/Topics disponibles

| Topic | Evento | Descripcion |
|---|---|---|
| `product` | POST, PUT | Producto creado o modificado (incluyendo desactivacion) |
| `variant` | POST, PUT | Variante creada o modificada |
| `stock` | PUT | Cambio de stock en una sucursal |
| `price` | PUT | Cambio de precio en una lista de precios |
| `document` | POST, PUT | Documento creado (venta, boleta, factura) |
| `document_paid` | PUT | Documento marcado como pagado |
| `checkout` | POST | Checkout de tienda online creado |
| `purchase_document` | POST, PUT | Documento de compra (recepcion de mercaderia) |
| `rcof` | POST | Reporte de consumo de folios |

**Para el sync de un CMS:** Los topics relevantes son `product`, `variant`, `stock`, y `price`.

### 2.3 ¿El payload incluye datos completos o solo el ID?

**Respuesta: Solo metadata — NO incluye datos del objeto.**

```json
{
  "cpnId": 2,
  "resource": "/v2/products/952.json",
  "resourceId": "952",
  "topic": "product",
  "action": "put",
  "send": 1503500856
}
```

**Implicacion critica:** Cada webhook recibido requiere una segunda llamada a `https://api.bsale.io{resource}` para obtener el producto completo. El flow es:

```
Bsale webhook → bot-miki recibe {resourceId: 952} → GET /v2/products/952.json → sync al CMS
```

Esto consume 1 request de rate limit adicional por cada evento webhook.

### 2.4 ¿Bsale reintenta el webhook si el receptor no responde?
**Respuesta: NO documentado.** La documentacion no especifica politica de reintentos.

**Implicacion:** El receptor (bot-miki) debe responder `200 OK` inmediatamente y procesar el job en background (via cola BullMQ). Si el demonio esta caido cuando llega un webhook, el evento se pierde — a menos que se complemente con polling periodico como fallback.

**Recomendacion de arquitectura:** 
```
webhook recibido → responder 200 OK en < 1s → encolar job en BullMQ → procesar asincrono
```

### 2.5 ¿Los webhooks se configuran por empresa?
**Respuesta: SI.** Cada empresa puede tener su URL de webhook configurada independientemente, y puede elegir que topics recibir.

**Como configurar:** Enviando email a `ayuda@bsale.app` con:
- La URL del endpoint receptor (ej: `https://api.kpcrop.com/v1/webhooks/bsale`)
- El `cpnId` o RUT de la empresa
- Los topics que se quieren recibir

**No hay API para registrar webhooks programaticamente** — es un proceso manual via soporte de Bsale. Esto es un friction point importante para onboarding de nuevos clientes.

### 2.6 ¿Hay firma/verificacion del webhook?
**Respuesta: NO documentada.** La documentacion no menciona HMAC, secret, ni ningun mecanismo de verificacion de autenticidad del payload.

**Riesgo:** Cualquiera podria hacer POST a la URL de webhooks del demonio con datos falsos. 

**Mitigacion recomendada:** El demonio debe validar que el `resource` del payload apunta a `api.bsale.io` y hacer la llamada de verificacion al resource antes de procesar — si la llamada falla o los datos no coinciden, descartar el evento.

---

## Decision Arquitectonica: Webhooks + Polling como Fallback

Dado que:
- Los webhooks existen pero NO tienen politica de reintentos documentada
- La configuracion de webhooks es manual (via email a soporte Bsale)
- El payload no incluye datos completos (requiere segunda llamada)

**La arquitectura correcta es HIBRIDA:**

```
PRIMARIO: Webhooks (tiempo real, sync lag < 30s)
FALLBACK: Polling cada 60 min (recupera eventos perdidos si el demonio estuvo caido)
```

El polling de fallback no necesita ser frecuente — su funcion es corregir inconsistencias, no reemplazar los webhooks. Ver impacto en Bloque 3.

---

## Bloque 3 — Rate Limits

### 3.1-3.5 Rate limits
**Respuesta: NO documentados publicamente.**

La documentacion oficial no menciona rate limits en ningun endpoint. No hay headers `X-RateLimit-*` documentados.

**Estrategia ante falta de documentacion:**

1. **Medir empiricamente en sandbox:** Ejecutar 100-500 requests rapidos y observar cuando Bsale devuelve `429 Too Many Requests`
2. **Conservar con limite propio:** Implementar rate limiter en el cliente HTTP del demonio desde el inicio — 10 req/s es un limite razonable hasta confirmar el real
3. **Manejar 429 obligatoriamente:** Aunque no este documentado, implementar `Retry-After` desde el inicio

```typescript
// packages/bot-miki/src/infrastructure/bsale-http-client.ts
// Limitar a 10 requests/segundo como medida de seguridad hasta confirmar limite real
const rateLimiter = new RateLimiter({ tokensPerInterval: 10, interval: 'second' });
```

**Accion requerida:** Contactar ayuda@bsale.app preguntando explicitamente por rate limits por empresa.

---

## Bloque 4 — Endpoints Disponibles

### Base URL
```
https://api.bsale.io/v1/
```

### Endpoints criticos para la plataforma

| Entidad | Endpoint GET | Filtro por fecha | Paginacion |
|---|---|---|---|
| Productos | `GET /v1/products.json` | **NO** | limit 50, offset |
| Variantes | `GET /v1/products/{id}/variants.json` | **NO** | limit 50, offset |
| Stock | `GET /v1/stocks.json` | **NO** | limit 50, offset |
| Listas de precio | `GET /v1/price_lists.json` | **NO** | limit 50, offset |
| Detalle de lista | `GET /v1/price_lists/{id}/details.json` | — | limit 50, offset |
| Clientes | `GET /v1/clients.json` | — | limit 50, offset |
| Ventas/Documentos | `GET /v1/documents.json` | SI (emissiondaterange) | limit 50, offset |

### Hallazgo critico: Sin filtro `updated_since` en productos

**NO existe un filtro `updated_since` o `lastmodified` para productos, variantes, ni stock.**

**Implicacion para polling:** El demonio NO puede pedir "dame solo los productos modificados desde las 14:00". Debe descargar TODOS los productos, comparar con el estado guardado, y detectar diferencias.

Para un catálogo de 1000 productos con `limit=50`: **20 llamadas a la API por sync de polling**, cada una trayendo 50 productos. Para 100 tenants en polling cada hora: 2000 llamadas/hora solo para productos.

**Esta es la principal razon para priorizar webhooks sobre polling.**

### Variantes e Imagenes: Llamadas separadas

| Dato | Forma de obtenerlo |
|---|---|
| Datos base del producto | `GET /v1/products/{id}.json` |
| Variantes (SKU, precio, stock) | `GET /v1/products/{id}/variants.json` o `expand=variants` |
| Stock por sucursal | Via variante con `expand=stock` |
| Imagenes | Via producto con `expand=images` o parametro `images` |
| Precio en lista | `GET /v1/price_lists/{id}/details.json?variantid={id}` |

El parametro `expand` permite reducir llamadas:
```
GET /v1/products.json?expand=[variants,images]&limit=50
```
Esto devuelve productos con sus variantes e imagenes en una sola llamada — **usar esto siempre** para reducir presion sobre rate limits.

### Codigo del Producto (SKU) — Clave de Idempotencia

En Bsale el concepto de SKU vive en la **variante**, no en el producto. El producto es un contenedor logico; la variante tiene:
- `code`: codigo alfanumerico (SKU)
- `barCode`: codigo de barras (EAN)
- `description`: descripcion de la variante (ej: "Talla M / Color Rojo")

**Un producto puede tener muchas variantes.** Para PrestaShop: un producto Bsale con variantes = una combinacion en PrestaShop.

---

## Bloque 5 — Escritura en Bsale

### 5.1 Crear/actualizar clientes
**Respuesta: SI.** `POST /v1/clients.json` y `PUT /v1/clients/{id}.json` estan disponibles.

### 5.2 Crear ordenes/ventas
**Respuesta: SI.** `POST /v1/documents.json` permite crear documentos de venta (boletas, facturas) desde el CMS. Este es el mecanismo para registrar ventas del ecommerce en Bsale.

### 5.3 Idempotencia de escritura
**Respuesta: NO documentada.** No hay campo `external_id` documentado para prevenir duplicados.

**Riesgo:** Si un reintento de creacion de documento llega dos veces a Bsale, podria crear dos documentos de venta. 

**Mitigacion recomendada:** Antes de crear un documento en Bsale, consultar si ya existe uno con el mismo numero de orden del CMS usando `GET /v1/documents.json?number={orden_cms}`. Si existe, no crear.

### 5.4 Campo de referencia externa
**Respuesta:** Los documentos tienen campo `number` que puede usarse como referencia externa. No es un `external_id` explicito pero sirve para deduplicacion.

---

## Bloque 6 — Modelo de Datos Bsale

### 6.1 Unicidad del codigo de producto (SKU)
El `code` vive en la **variante**, no en el producto. Confirmado como campo unico por empresa. Es la clave de idempotencia correcta.

### 6.2 Modelo de variantes
**Producto → tiene muchas Variantes.** Las variantes representan las combinaciones de atributos (talla, color). El stock y el precio base estan en la variante, no en el producto.

```
Producto: "Poleron Marca X"
  Variante 1: code="POL-S-RJ", desc="Talla S / Rojo", stock=10, price=15000
  Variante 2: code="POL-M-AZ", desc="Talla M / Azul", stock=5, price=15000
```

### 6.3 Multiples sucursales
**SI.** Bsale maneja stock por sucursal (`officeId`). El campo `stock` de la variante devuelve stock agregado de todas las sucursales, o filtrado por `officeid`.

**Decision que debe tomar cada cliente:** Que sucursal sincronizar al CMS. Si tiene bodega central y tienda fisica, probablemente quiere sincronizar solo la bodega central.

### 6.4 Precios netos o brutos
**Precios en Bsale son NETOS (sin IVA).** El IVA se calcula aparte segun el tipo de impuesto configurado por la empresa (tipicamente 19% en Chile).

**Implicacion para el adapter:** El `CanonicalProduct.priceGross = priceNet * 1.19` (o segun la tasa del producto).

### 6.5 Multiples listas de precios
**SI.** Bsale soporta N listas de precio por empresa (ej: "Precio publico", "Precio mayorista", "Precio distribuidor"). El precio base de la variante NO es necesariamente el precio de venta al publico — depende de que lista de precios use la empresa.

**El plugin debe permitir al comercio elegir que lista de precios sincronizar al CMS.**

### 6.6 Imagenes
Las imagenes de productos estan **hosteadas en los servidores de Bsale** (`resources.bsale.cl` o CDN). Las URLs son persistentes pero pueden cambiar si el cliente remplaza la imagen en Bsale.

**Estrategia:** El adapter de PrestaShop debe guardar la URL de origen (`source_url`) y solo re-descargar si la URL cambio — evita re-descargar imagenes en cada sync.

---

## ADR Pendiente a Crear

Basado en estos hallazgos, el proximo ADR debe documentar:

> **ADR-004 — Estrategia de Sync: Webhooks + Polling Hibrido**
> - Webhooks como canal primario (sync lag < 30s)  
> - Polling horario como fallback para eventos perdidos  
> - Onboarding manual de webhooks via email a Bsale como friction point documentado  
> - Rate limiter conservador de 10 req/s hasta confirmar limite real

---

## Preguntas Pendientes para ayuda@bsale.app

1. ¿Los `access_token` expiran? ¿Cada cuanto tiempo?
2. ¿Cual es el rate limit de requests por empresa por minuto/hora?
3. ¿Bsale reintenta los webhooks si el receptor responde con error o no responde?
4. ¿Es posible registrar URLs de webhook via API en lugar de email?
5. ¿Hay un campo `external_id` o equivalente para evitar duplicados al crear documentos?
6. ¿El parametro `expand=variants` en `GET /v1/products.json` trae variantes de todos los productos en una sola respuesta paginada?
