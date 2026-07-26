# Guía de Onboarding — Canal Agencias

Para el equipo kpcrop y para agencias ya activas en el canal (plan `agency`). Cubre lo que
existe hoy: agregar un cliente nuevo, gestionar los clientes existentes, y configurar el
white-label de la agencia.

> ⚠️ **Estado real (jul-2026):** agregar un cliente nuevo **no es self-service todavía** — lo
> hace el equipo kpcrop a pedido de la agencia (ver #61, "onboarding asistido" para la primera
> agencia). Un dashboard donde la agencia agregue clientes por su cuenta es el issue #53,
> pendiente. Esta guía documenta el proceso real de hoy, no uno especulativo.

---

## 1. Qué es una "agencia" en Synkrop

Técnicamente, una agencia es una **licencia** con plan `agency` (no hay una tabla "agencias"
separada). Cada cliente final que la agencia gestiona es una **tienda** (`tenant_stores`)
asociada a esa licencia. El plan `agency` permite hasta 50 tiendas por default (`max_stores`).

Todo el acceso de la agencia a la API es con **una sola API Key** (`X-API-Key`, formato
`kp_...`) — la misma para consultar todos sus clientes.

---

## 2. Agregar un cliente nuevo (asistido por kpcrop)

1. La agencia le pasa al equipo kpcrop los datos del cliente nuevo:
   - Nombre de la tienda
   - CMS que usa (`prestashop`, `wordpress`, `shopify`, `woocommerce`, `magento`, `jumpseller`)
   - URL de la tienda
   - Token de acceso Bsale del cliente (si ya lo tiene a mano)
2. El equipo kpcrop registra la tienda con:
   ```bash
   curl -X POST https://miki.keepcrop.com/v1/admin/tenants/{tenantId}/stores \
     -H "X-Admin-Key: ***" \
     -H "Content-Type: application/json" \
     -d '{
       "storeName": "Tienda Cliente X",
       "cmsType": "prestashop",
       "cmsUrl": "https://tiendaclientex.cl",
       "bsaleAccessToken": "token-de-bsale-del-cliente"
     }'
   ```
3. El cliente final instala el plugin normalmente (ver `docs/deployment/plugin-install.md`),
   usando la API Key de licencia de la **agencia** (no una propia) y su propio token de Bsale.

Si la licencia ya llegó al límite de tiendas del plan, este paso devuelve
`403 STORE_LIMIT_REACHED` — hay que ampliar el plan antes de seguir.

---

## 3. Gestionar los clientes existentes (self-service, con tu API Key)

Estos 3 sí los usa la agencia directamente, sin depender del equipo kpcrop:

**Listar tus clientes y su último estado de sync:**
```bash
curl -H "X-API-Key: kp_tu_api_key" https://miki.keepcrop.com/v1/agency/clients
```

**Disparar un sync manual para un cliente puntual:**
```bash
curl -X POST -H "X-API-Key: kp_tu_api_key" \
  https://miki.keepcrop.com/v1/agency/clients/{storeId}/sync \
  -d '{"entity":"products"}'
```
`entity` puede ser `products`, `stock` o `prices` (default: `products`).

**Ver el historial de syncs de un cliente:**
```bash
curl -H "X-API-Key: kp_tu_api_key" \
  https://miki.keepcrop.com/v1/agency/clients/{storeId}/logs
```

En los 3, `storeId` tiene que pertenecer a tu propia licencia — si pertenece a otra agencia,
la API responde `404` (no revela que existe, por seguridad).

---

## 4. Configurar el white-label (logo y nombre de tu agencia)

Desde jul-2026 (#56), tus clientes ven **tu marca** en el panel del plugin en vez de "Synkrop"
genérico.

**Consultar tu branding actual:**
```bash
curl -H "X-API-Key: kp_tu_api_key" https://miki.keepcrop.com/v1/agency/profile
```

**Configurarlo (o cambiarlo):**
```bash
curl -X PUT -H "X-API-Key: kp_tu_api_key" -H "Content-Type: application/json" \
  https://miki.keepcrop.com/v1/agency/profile \
  -d '{
    "agencyName": "Tu Agencia",
    "agencyLogoUrl": "https://tu-dominio.cl/logo.png",
    "agencyBrandColor": "#1e88e5"
  }'
```

Reglas:
- `agencyLogoUrl` debe empezar con `https://`.
- `agencyBrandColor` debe ser un color hex de 6 dígitos (ej. `#1e88e5`).
- Podés mandar solo el campo que querés cambiar — los que no mandás quedan como estaban.
- Hoy no hay forma de "borrar" un campo (volverlo vacío) — solo sobreescribirlo con otro valor.

**Cuánto tarda en verse reflejado:** el plugin de cada cliente cachea esto por ~4 minutos
junto con su token de licencia — no es instantáneo, pero tampoco hace falta reinstalar nada.

---

## 5. Códigos de error comunes

| Código HTTP | Significado | Qué hacer |
|---|---|---|
| `401 MISSING_API_KEY` | Falta el header `X-API-Key` | Agregalo a la request |
| `404 TENANT_NOT_FOUND` | La API Key no corresponde a ninguna licencia | Confirmá que copiaste la key completa |
| `402 LICENSE_EXPIRED` | Tu licencia de agencia está vencida o suspendida | Contactar a kpcrop para renovar |
| `404 STORE_NOT_FOUND` | El `storeId` no existe o pertenece a otra agencia | Revisá el id con `GET /v1/agency/clients` |
| `403 STORE_LIMIT_REACHED` | Llegaste al máximo de tiendas de tu plan | Contactar a kpcrop para ampliar el plan |

---

## Fuera de alcance por ahora

- **Agregar clientes por tu cuenta** (sin pasar por el equipo kpcrop) — requiere un dashboard
  propio, issue #53, todavía no construido.
- **Pricing page pública con planes de agencia** — issue #57 (este mismo), depende de la
  landing page (#46), no construida.
- **Email de bienvenida con tu marca** para tus clientes nuevos — depende del flujo de
  activación de licencia (#18), no implementado todavía.
