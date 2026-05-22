# Checklist de Investigacion — Bsale API

Antes de disenar el sync automatico, estas preguntas deben tener respuesta documentada.
El costo de no saberlas es disenar la arquitectura incorrecta y reescribir despues.

---

## Como obtener respuestas

1. **Documentacion oficial:** [https://developers.bsale.cl](https://developers.bsale.cl)
2. **Sandbox:** Solicitar credenciales de prueba al equipo de Bsale (soporte@bsale.cl)
3. **Pruebas directas:** Usar Postman o `curl` con credenciales de sandbox
4. **Comunidad:** Foro de desarrolladores Bsale / Slack si existe

---

## Bloque 1 — Autenticacion

| # | Pregunta | Respuesta | Impacto |
|---|---|---|---|
| 1.1 | ¿Que tipo de autenticacion usa la API? (OAuth2, API Key, Basic Auth) | | Determina como el plugin guarda y rota credenciales |
| 1.2 | ¿Los tokens tienen expiracion? ¿Cuanto tiempo duran? | | Si expiran, el plugin necesita logica de refresh automatico |
| 1.3 | ¿Hay un token por empresa o por usuario? | | Afecta el modelo de datos de configuracion por tenant |
| 1.4 | ¿Se puede revocar un token programaticamente? | | Necesario para cuando un cliente cancela la integracion |
| 1.5 | ¿Existe un ambiente de sandbox/pruebas separado de produccion? | | Sin sandbox, todo desarrollo requiere datos reales — alto riesgo |

---

## Bloque 2 — Webhooks (decision arquitectonica critica)

> Esta es la pregunta mas importante. La respuesta cambia el costo de construir sync automatico en un factor de 5.

| # | Pregunta | Respuesta | Impacto |
|---|---|---|---|
| 2.1 | ¿Bsale soporta webhooks? ¿Para que eventos? | | Con webhooks: sync reactivo. Sin webhooks: polling obligatorio |
| 2.2 | Si hay webhooks, ¿que eventos disparan? (producto creado, precio actualizado, stock modificado, orden confirmada...) | | Determina que entidades se pueden sincronizar en tiempo real |
| 2.3 | ¿Los webhooks incluyen el payload completo del objeto o solo el ID? | | Si solo incluyen ID, hay que hacer una segunda llamada para obtener el dato |
| 2.4 | ¿Bsale reintenta el webhook si el receptor no responde? ¿Cuantas veces? ¿Con que backoff? | | El demonio debe ser idempotente si Bsale reintenta |
| 2.5 | ¿Los webhooks se configuran por empresa o son globales? | | Cada tenant necesita su propia URL de webhook registrada |
| 2.6 | ¿Hay algun mecanismo de firma/verificacion del webhook (HMAC, secret)? | | Necesario para validar que el webhook viene realmente de Bsale |

### Decision segun resultado

```
Si Bsale tiene webhooks completos (2.1 = SI, 2.2 cubre productos/stock/precios):
  → Arquitectura REACTIVA: Bsale notifica → demonio sincroniza inmediatamente
  → Sync automatico = receptor de webhooks + cola de procesamiento
  → Sync lag < 30 segundos

Si Bsale NO tiene webhooks o son parciales:
  → Arquitectura POLLING: demonio consulta Bsale cada N minutos por tenant
  → Requiere endpoint GET /products?updated_since={timestamp} en Bsale
  → Sync lag = frecuencia de polling (ej: 15 minutos)
  → Mayor presion sobre rate limits de Bsale
```

---

## Bloque 3 — Rate Limits

| # | Pregunta | Respuesta | Impacto |
|---|---|---|---|
| 3.1 | ¿Bsale tiene rate limiting en su API? | | Si no hay limite documentado, hay un limite implicito — hay que medirlo |
| 3.2 | ¿Cuantas requests por minuto/hora permite por empresa? | | Determina la frecuencia maxima de polling y la concurrencia del demonio |
| 3.3 | ¿El rate limit es por API Key o por IP? | | Si es por IP, el demonio compartido tiene un solo pool de requests para todos los tenants |
| 3.4 | ¿Que codigo HTTP devuelve al superar el limite? ¿Incluye header `Retry-After`? | | Necesario para implementar backoff correcto en la cola de reintentos |
| 3.5 | ¿Hay rate limits distintos segun el plan de Bsale del cliente? | | Un tenant con plan basico puede tener menos requests que uno enterprise |

### Calculo de presion sobre rate limits (completar con respuesta de 3.2)

```
Tenants activos: N
Frecuencia de sync: cada 15 min = 4 veces/hora
Endpoints por sync: ~3 (products, prices, stock)
Requests por hora por tenant: 4 × 3 = 12

Requests totales por hora: N × 12

Si rate limit es 1000 req/hora → maximo sostenible: 83 tenants activos
Si rate limit es 10000 req/hora → maximo sostenible: 833 tenants activos
```

---

## Bloque 4 — Endpoints Disponibles

| # | Entidad | Endpoint GET (listar) | Filtro por fecha? | Paginacion? |
|---|---|---|---|---|
| 4.1 | Productos | `/v1/products` | ¿`updated_since`? | ¿`offset`/`limit`? |
| 4.2 | Precios / Listas de precio | `/v1/price_lists` | ¿? | ¿? |
| 4.3 | Stock por sucursal | `/v1/stocks` | ¿? | ¿? |
| 4.4 | Clientes | `/v1/clients` | ¿? | ¿? |
| 4.5 | Ordenes / Ventas | `/v1/sales` | ¿? | ¿? |
| 4.6 | Guias de despacho | `/v1/shippings` | ¿? | ¿? |
| 4.7 | Imagenes de productos | ¿Incluidas en producto o endpoint separado? | — | — |
| 4.8 | Variantes de productos | ¿Incluidas en producto o endpoint separado? | — | — |

> **Critico:** Si no hay filtro `updated_since`, el demonio debe descargar TODOS los productos en cada sync y comparar localmente — orden de magnitud mas lento y costoso en rate limits.

---

## Bloque 5 — Escritura (el demonio tambien escribe en Bsale?)

> La plataforma actualmente diseña sync Bsale → CMS. Pero algunos casos requieren escritura inversa.

| # | Pregunta | Respuesta |
|---|---|---|
| 5.1 | ¿Hay endpoint para crear/actualizar clientes en Bsale desde el CMS? | |
| 5.2 | ¿Hay endpoint para crear ordenes/ventas en Bsale cuando se vende en el CMS? | |
| 5.3 | ¿La API de escritura es idempotente? (si se llama dos veces con los mismos datos, ¿crea duplicados?) | |
| 5.4 | ¿Existe un campo de referencia externa (external_id) para evitar duplicados al crear entidades? | |

---

## Bloque 6 — Modelo de Datos Bsale

| # | Pregunta | Respuesta |
|---|---|---|
| 6.1 | ¿El campo `code` de producto es unico por empresa? ¿O puede repetirse? | Clave de idempotencia en todos los CMS |
| 6.2 | ¿Como representa Bsale las variantes? ¿Productos separados o atributos del mismo producto? | Afecta el Canonical Product Model |
| 6.3 | ¿Bsale maneja multiples sucursales con stock independiente? | Si el cliente tiene varias sucursales, ¿que stock sincronizamos al CMS? |
| 6.4 | ¿Los precios en Bsale son netos o brutos? ¿Incluyen IVA? | El CMS del cliente puede operar de forma distinta |
| 6.5 | ¿Hay multiples listas de precios por empresa? | El plugin debe permitir elegir que lista de precios sincronizar |
| 6.6 | ¿Las imagenes de productos estan hosteadas en Bsale o son URLs externas? | Si son de Bsale, pueden expirar o cambiar de URL |

---

## Resultado Esperado

Al completar este checklist, documentar las respuestas aqui mismo y crear:

- `docs/investigation/bsale-api-findings.md` con los hallazgos
- Actualizar `docs/adr/ADR-004-sync-strategy.md` con la decision: **reactivo (webhooks) vs polling**
- Actualizar el diagrama de flujo `docs/architecture/flows/sync-auto.md` segun la estrategia elegida

---

## Tiempo Estimado de Investigacion

| Actividad | Tiempo |
|---|---|
| Leer documentacion oficial Bsale | 2-4 horas |
| Solicitar y configurar sandbox | 1-2 dias (depende de Bsale) |
| Pruebas con Postman / curl | 4-8 horas |
| Documentar hallazgos | 2 horas |
| **Total** | **2-4 dias habiles** |
