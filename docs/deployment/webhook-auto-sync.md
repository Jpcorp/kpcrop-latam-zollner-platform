# Guia de Configuracion — Sincronizacion Automatica con Webhooks de Bsale

Tiempo estimado: **20–30 minutos**

Este manual explica como activar la sincronizacion automatica entre Bsale y PrestaShop
usando la arquitectura de webhooks de Synkrop. Cuando este activo, cualquier cambio de
stock, precio o variante en Bsale se refleja en tu tienda PrestaShop en segundos, sin
necesitar sincronizaciones manuales.

---

## Arquitectura del flujo

```
Bsale (cambio de stock/precio/variante)
    │
    │  POST webhook
    ▼
bot-miki (Railway) — valida, encola en BullMQ
    │
    │  POST /modules/synkrop/webhook.php
    ▼
PrestaShop (tu tienda) — sincroniza y registra resultado
```

---

## Que necesitas antes de empezar

| Dato | Donde conseguirlo |
|------|------------------|
| URL publica de tu tienda PrestaShop | ej: `https://www.misitienda.cl` |
| Access Token de Bsale | Bsale → tu nombre → Mi cuenta → Integraciones |
| ID de empresa Bsale (cpnId) | mismo lugar, campo "ID Empresa" |
| API Key de licencia kpcrop (`kp_...`) | correo de activacion de kpcrop |
| Admin Key de bot-miki (`X-Admin-Key`) | entregada por kpcrop al activar tu cuenta |
| URL de bot-miki | entregada por kpcrop: `https://....railway.app` |

---

## Paso 1 — Instalar el modulo Synkrop en PrestaShop

Si no lo tienes instalado aun, sigue primero la guia de instalacion:
[plugin-install.md](./plugin-install.md)

Confirma que el archivo `webhook.php` esta en la ruta:
```
/public_html/modules/synkrop/webhook.php
```
Este archivo viene incluido en el ZIP del modulo a partir de la version **1.1.0**.
Si tienes una version anterior, actualiza el modulo o sube el archivo manualmente.

---

## Paso 2 — Registrar tu tienda en bot-miki

bot-miki necesita saber la URL de tu tienda y el secret que usara para autenticarse
contra el `webhook.php`. Esto se configura una sola vez con el endpoint de administracion.

Abre una terminal y ejecuta el siguiente comando, reemplazando los valores entre `< >`:

```bash
curl -X PATCH \
  -H "Content-Type: application/json" \
  -H "X-Admin-Key: <TU_ADMIN_KEY>" \
  -d '{
    "cmsUrl":            "https://www.tu-tienda.cl",
    "cmsWebhookSecret":  "<TU_API_KEY_KPCROP>",
    "bsalePriceListId":  1
  }' \
  "https://<URL_BOT_MIKI>/v1/admin/tenants/<TU_TENANT_ID>/stores/<TU_STORE_ID>"
```

**Parametros:**
- `cmsUrl` — URL raiz de tu tienda (sin barra al final)
- `cmsWebhookSecret` — usa tu API Key kpcrop (`kp_...`); es el secret compartido
  entre bot-miki y el `webhook.php` de tu tienda
- `bsalePriceListId` — ID de la lista de precios de Bsale que quieres sincronizar
  (en Bsale: Inventario → Listas de precios, anota el numero de la columna ID)

**Respuesta esperada:**
```json
{
  "updated": true,
  "store": {
    "id": "...",
    "cms_url": "https://www.tu-tienda.cl",
    "cms_webhook_secret": "kp_..."
  }
}
```

Si ves `"updated": true`, el registro fue exitoso.

---

## Paso 3 — Configurar el secret en PrestaShop

El `webhook.php` valida que los requests vengan de bot-miki comparando el header
`X-Synkrop-Secret` contra el campo `daemon_api_key` de la configuracion del modulo.

1. Entra al backoffice de PrestaShop
2. Ve a **Catalogo → Synkrop → Configuracion avanzada**
3. En el campo **"Daemon API Key / Webhook Secret"**, ingresa tu API Key kpcrop (`kp_...`)
   — el mismo valor que usaste como `cmsWebhookSecret` en el Paso 2
4. Haz clic en **Guardar**

> **Por que son el mismo valor?** Es un secret compartido: bot-miki lo envia en el
> header HTTP y PrestaShop lo verifica. Si no coinciden, el webhook devuelve 401.

---

## Paso 4 — Verificar que el endpoint responde

Antes de registrar en Bsale, confirma que tu tienda puede recibir el webhook.
Ejecuta este comando desde tu terminal:

```bash
curl -X POST \
  -H "Content-Type: application/json" \
  -H "X-Synkrop-Secret: <TU_API_KEY_KPCROP>" \
  -d '{"entity":"stock"}' \
  -w "\nHTTP: %{http_code} | Tiempo: %{time_total}s" \
  "https://www.tu-tienda.cl/modules/synkrop/webhook.php"
```

**Resultado esperado:**
```
{"success":true,"status":"accepted","message":"Sync iniciado en background"}
HTTP: 200 | Tiempo: 0.9s
```

El tiempo de respuesta debe ser menor a 2 segundos. Si tarda mas, revisa el Paso 3.

**Resultados posibles y su significado:**

| HTTP | Respuesta | Causa |
|------|-----------|-------|
| `200` | `accepted` | Todo correcto |
| `401` | `Unauthorized` | El secret no coincide — revisa Paso 2 y 3 |
| `400` | `entity invalido` | El payload es incorrecto |
| `500` | error de PHP | Revisa los logs de PHP en tu hosting |
| Sin respuesta / timeout | — | El archivo `webhook.php` no esta en la ruta correcta |

---

## Paso 5 — Registrar los webhooks en Bsale

Bsale no permite registrar webhooks por API (devuelve error). Debes hacerlo desde
el panel web de Bsale.

1. Entra a tu cuenta en **bsale.io**
2. Ve a **Configuracion → Aplicaciones** (o **Integraciones → Webhooks** segun tu version)
3. Agrega los siguientes webhooks:

| URL | Topic | Accion |
|-----|-------|--------|
| `https://<URL_BOT_MIKI>/v1/webhooks/bsale` | `stock` | `put` |
| `https://<URL_BOT_MIKI>/v1/webhooks/bsale` | `variant` | `put` |
| `https://<URL_BOT_MIKI>/v1/webhooks/bsale` | `price` | `put` |

Reemplaza `<URL_BOT_MIKI>` con la URL de Railway que te entrego kpcrop.

> Si Bsale te pide un campo "Enviar ahora" o "Verificar URL", puedes ignorarlo o
> seleccionar "No" — el webhook se validara con la primera operacion real.

---

## Paso 6 — Simular un webhook de prueba

Para verificar el flujo completo sin hacer cambios reales en Bsale, puedes enviar
un webhook simulado directamente a bot-miki:

```bash
curl -X POST \
  -H "Content-Type: application/json" \
  -d '{
    "cpnId":      <TU_CPN_ID_BSALE>,
    "resource":   "/v2/stocks/1.json",
    "resourceId": "1",
    "topic":      "stock",
    "action":     "put",
    "send":       1750000000
  }' \
  "https://<URL_BOT_MIKI>/v1/webhooks/bsale"
```

**Parametros clave:**
- `cpnId` — el ID de empresa de Bsale (mismo numero de la columna "ID Empresa")
- `topic` — puede ser `stock`, `variant` o `price`
- `send` — timestamp Unix cualquiera (es solo para idempotencia, no afecta el sync)

**Respuesta esperada:** sin cuerpo, HTTP 200.

Si el `cpnId` no coincide con ninguna tienda registrada en bot-miki, la respuesta
sigue siendo 200 (Bsale exige 200 siempre para no reintentar). Revisa el Paso 2.

---

## Paso 7 — Monitorear el resultado

Espera 10–15 segundos y consulta el endpoint de observabilidad:

```bash
curl -s \
  -H "X-Admin-Key: <TU_ADMIN_KEY>" \
  "https://<URL_BOT_MIKI>/v1/admin/observability?tenantId=<TU_TENANT_ID>"
```

**Respuesta exitosa:**
```json
{
  "queue": {
    "waiting": 0,
    "active":  0,
    "completed": 1,
    "failed":  0,
    "delayed": 0,
    "health":  "ok"
  },
  "events": [
    {
      "sync_type":    "webhook",
      "entity_type":  "stock",
      "status":       "success",
      "records_updated": 0,
      "created_at":   "2026-06-20T09:23:50.000Z"
    }
  ]
}
```

> **Por que `records_updated: 0`?** El sync corre en background en PrestaShop. bot-miki
> registra que el webhook fue aceptado, no el conteo final. El conteo real queda en
> el historial del modulo Synkrop dentro de tu backoffice de PrestaShop.

**Interpretacion de la cola:**

| Campo | Significado |
|-------|-------------|
| `waiting` | Jobs en cola, aun no procesados |
| `active` | Jobs ejecutandose ahora |
| `completed` | Jobs terminados exitosamente |
| `failed` | Jobs que fallaron todas las reintentos |
| `delayed` | Jobs en espera de reintento (backoff) |
| `health: "ok"` | Cola sana; `"busy"` o `"degraded"` indica problemas |

---

## Paso 8 — Verificar en PrestaShop

Dentro del backoffice de PrestaShop, el modulo Synkrop registra cada sincronizacion
en su historial:

1. Ve a **Catalogo → Synkrop**
2. Busca la seccion **Historial de sincronizaciones**
3. Deberias ver una entrada reciente con tipo `webhook`, entidad `stock` y estado `success`

El historial muestra el conteo real de registros actualizados y, si hubo errores,
el detalle de que SKU fallo y por que.

---

## Flujo automatico en produccion

Una vez configurado, el flujo ocurre sin intervencion:

1. Alguien cambia stock en Bsale (por una venta, ajuste manual o importacion)
2. Bsale envia un POST automatico a bot-miki
3. bot-miki valida el `cpnId`, encola el job en BullMQ
4. El worker llama a `webhook.php` de tu tienda (responde en <1 segundo)
5. `webhook.php` sincroniza stock/precios/variantes en background (~60–120 segundos)
6. El resultado queda registrado en el historial del modulo

El tiempo total desde el cambio en Bsale hasta que se refleja en PrestaShop es
tipicamente **2–5 minutos** dependiendo del tamano de tu catalogo.

---

## Solucion de problemas

### El webhook llega a bot-miki pero no a PrestaShop

Revisa que:
- `cms_url` en bot-miki apunta a la URL correcta de tu tienda
- El archivo `webhook.php` existe en `/modules/synkrop/webhook.php`
- Tu servidor no bloquea requests entrantes (firewall, mod_security)

Prueba el endpoint directamente (Paso 4).

### El job aparece en `delayed` en vez de `completed`

Ocurre cuando bot-miki no puede conectarse a tu `webhook.php`. Causas comunes:
- La tienda tiene el acceso bloqueado por IP o firewall
- El servidor devuelve un error 5xx (revisa los logs de PHP)
- El `X-Synkrop-Secret` no coincide (el endpoint devuelve 401)

Los jobs en `delayed` se reintentaran hasta 5 veces con backoff exponencial (30s, 60s, 120s...).
Si fallan todas las reintentos, pasan a `dead_letter` y dejan de reintentarse.

### Jobs en `failed` o `dead_letter`

Indica un error permanente. Consulta el campo `error_message` en la respuesta
de observabilidad:
```bash
curl -s \
  -H "X-Admin-Key: <TU_ADMIN_KEY>" \
  "https://<URL_BOT_MIKI>/v1/admin/observability?tenantId=<TU_TENANT_ID>&status=dead_letter"
```

### El sync en PrestaShop falla con error de licencia

El modulo valida la licencia kpcrop antes de sincronizar. Si la licencia vencio
o fue suspendida, el sync falla con `LICENSE_ERROR`. Contacta a soporte@kpcrop.com.

### No llegan webhooks desde Bsale

Verifica en el panel de Bsale que los webhooks esten activos. Bsale puede desactivar
un webhook automaticamente si recibe errores HTTP repetidos desde la URL de destino.
Si bot-miki estuvo caido un tiempo, reactiva los webhooks manualmente en el panel
de Bsale y re-registra con el mismo procedimiento del Paso 5.

---

## Referencia rapida de comandos

```bash
# Verificar endpoint de tu tienda
curl -X POST -H "X-Synkrop-Secret: <SECRET>" \
  -d '{"entity":"stock"}' https://tu-tienda.cl/modules/synkrop/webhook.php

# Enviar webhook de prueba a bot-miki
curl -X POST -H "Content-Type: application/json" \
  -d '{"cpnId":<CPN_ID>,"resource":"/v2/stocks/1.json","resourceId":"1","topic":"stock","action":"put","send":1750000000}' \
  https://<BOT_MIKI>/v1/webhooks/bsale

# Monitorear estado de la cola y eventos
curl -H "X-Admin-Key: <ADMIN_KEY>" \
  "https://<BOT_MIKI>/v1/admin/observability?tenantId=<TENANT_ID>"

# Ver solo eventos fallidos
curl -H "X-Admin-Key: <ADMIN_KEY>" \
  "https://<BOT_MIKI>/v1/admin/observability?tenantId=<TENANT_ID>&status=failed"
```

---

## Soporte

- **Email**: soporte@kpcrop.com
- **Tiempo de respuesta**: 24–48 horas habiles

Para reportar un problema con los webhooks, incluye:
1. El output del comando del Paso 4 (verificacion del endpoint)
2. El output del endpoint de observabilidad (Paso 7)
3. Tu `tenantId` y la URL de tu tienda
