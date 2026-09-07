# Tutorial — dar de alta 3 e-commerce PrestaShop contra un solo Bsale

Procedimiento operativo, de principio a fin. Para *probar* que la implementación funciona
antes de tocar al cliente, mirá `manual-multi-tienda.md`; este documento asume que ya
decidiste avanzar.

**Regla de oro: una tienda primero.** Hacé los pasos 1 a 6 con UNA sola tienda, dejala
sincronizando un par de días con ventas reales, y recién ahí repetí para las otras dos.
Todo el procedimiento es idempotente: correrlo de nuevo no rompe lo ya hecho.

---

## Antes de empezar — lo que tenés que juntar

| Dato | De dónde sale |
|---|---|
| Token de la integración Bsale | Panel Bsale → Configuración → Integraciones/API |
| Acceso SSH a los 3 hostings | Del cliente o de su proveedor |
| Ruta de PrestaShop en cada hosting | Suele ser `/home/<usuario>/public_html` |
| `X-Admin-Key` de bot-miki | La que ya usás para `miki.keepcrop.com` |

**Un solo token de Bsale para las tres tiendas.** No pidas tres: es la misma cuenta, y que
compartan la integración es justamente lo que hace que las tres se enteren de las ventas
de las otras.

---

## Paso 1 — Confirmar el plan de licencia

Las tres tiendas van bajo **una licencia**. El plan define cuántas admite:

| Plan | Tiendas |
|---|---|
| `starter` | 1 |
| **`growth`** | **3** ← el que corresponde acá |
| `agency` | 50 |

Si el plan es `starter`, el alta de la segunda tienda va a fallar con
`STORE_LIMIT_REACHED` y el mensaje "El plan permite máximo 1 tiendas".

---

## Paso 2 — Instalar el módulo en cada tienda

Esto es manual y va **antes** que el script: las tablas base las crea el instalador de
PrestaShop, no las migraciones.

1. Subí la carpeta `packages/cms-prestashop/synkrop/` a `modules/` de la tienda.
2. Back-office → Módulos → buscá "Synkrop" → **Instalar**.
3. Verificá que aparezca el menú de Synkrop en el panel.

Si te salteás esto, el paso 4 va a avisar: `[!] <tabla> no existe — instala el modulo primero`.

---

## Paso 3 — Configurar el script

Abrí `scripts/onboard-cliente.sh` y editá **solo el bloque `STORES`**:

```bash
STORES=(
  "tienda-uno|usuario@host1|/home/usuario/public_html|https://tienda-uno.cl||"
  "tienda-dos|usuario@host2|/home/usuario/public_html|https://tienda-dos.cl||"
  "tienda-tres|usuario@host3|/home/usuario/public_html|https://tienda-tres.cl||"
)
```

El formato de cada línea:

```
nombre | usuario@host | raiz de PrestaShop | url publica | price_list | office
```

Los dos últimos (lista de precios y sucursal de Bsale) podés dejarlos vacíos y cargarlos
después desde el panel. El **prefijo de tablas no se declara**: el runner lo detecta solo,
funcione la tienda con `ps_`, `pr_` o el que sea.

> Para la primera pasada, dejá **una sola línea** y comentá las otras dos.

---

## Paso 4 — Correr el alta

```bash
export BSALE_TOKEN=...          # token de la integración Bsale
export ADMIN_KEY=...            # X-Admin-Key de bot-miki
export TENANT_ID=cliente-real   # identificador del cliente
export SSH_KEY=ssh/id_rsa

bash scripts/onboard-cliente.sh https://api.bsale.io/v1
```

El script valida el token contra Bsale **antes de tocar ninguna tienda**, y después, por
cada una: la registra en bot-miki, sube el plugin, corre las migraciones y escribe la
configuración.

Si algo falla, lo dice y para:

| Mensaje | Qué pasó |
|---|---|
| `Bsale respondio 401` | token inválido o URL de API equivocada |
| `Falta BSALE_TOKEN` / `Falta ADMIN_KEY` | te olvidaste un `export` |
| `No pude detectar el cpnId` | pasalo a mano: `export BSALE_INTEGRATION_ID=...` |
| `el plan no permite mas tiendas` | ver Paso 1 |
| `[!] <tabla> no existe` | falta instalar el módulo (Paso 2) |

---

## Paso 5 — El webhook de Bsale: uno solo para las tres

En Bsale → Configuración → Integraciones → Webhooks, apuntá a:

```
https://miki.keepcrop.com/v1/webhooks/bsale
```

**No configures uno por tienda.** Es uno solo: bot-miki lo reparte a las tres porque
comparten el mismo `bsale_integration_id` (el `cpnId` que manda Bsale). Esa es la pieza
que hace que una venta en la tienda 1 actualice el stock de las tiendas 2 y 3.

Activá al menos los topics de **stock** y **precio**.

---

## Paso 6 — Configurar cada tienda en su panel

En el back-office de cada tienda, menú Synkrop:

1. **Lista de precios** (`bsale_price_list_id`) — puede ser distinta por tienda.
2. **Sucursal** (`bsale_office_id`) — desde qué sucursal de Bsale vende esa tienda.
3. **Qué sincronizar**: productos, precios, stock, categorías.
4. **Ventas** (`sync_orders`): si esa tienda genera notas de venta en Bsale, y en qué
   estado del pedido se disparan (`order_trigger_states`).
5. Botón **Sync de Productos** para la carga inicial del catálogo.

---

## Paso 7 — Verificar que las tres se hablan

Esta es la prueba que **solo falla con más de una tienda**, así que no te la saltees:

1. Cambiá el stock de un producto en Bsale.
2. Mirá los logs del worker:

```bash
cmd.exe /c "railway logs --service bot-miki-worker"
```

Buscá `webhook abanicado a varias tiendas` con `stores: 3`.

- Si dice **`stores: 3`** ✅ las tres comparten la integración correctamente.
- Si dice **`stores: 1`** ❌ las tiendas tienen `bsale_integration_id` distintos. Revisá que
  las tres se hayan dado de alta con el mismo `cpnId`.

3. Confirmá en cada tienda que el stock llegó (menú Synkrop → historial, tabla `synkrop_log`).

---

## Lo que tenés que explicarle al cliente

**Las tres tiendas comparten el mismo stock.** No son inventarios separados: si en Bsale
hay 10 unidades, cada tienda muestra 10, y esas 10 son las mismas.

**Qué pasa con la última unidad.** Si dos clientes de tiendas distintas compran la última
casi al mismo tiempo, los dos completan el checkout — las tiendas no se coordinan en ese
instante. Después:

- La **primera** nota de venta que llega a Bsale reserva la unidad y sale normal.
- La **segunda** la rechaza Bsale, y el pedido queda en estado **`backorder`** ("sin stock")
  en el panel de Synkrop, sin reintentarse solo.
- Alguien tiene que decidir: reponer stock, ofrecer otro producto, o reembolsar. Cuando se
  resuelva, el botón **Generar** de ese pedido emite el documento.

**Cómo hacer que eso casi no pase:** stock de seguridad en Bsale. Que las tiendas no
ofrezcan la última unidad o las últimas dos. Es la medida más efectiva y no depende de
ningún desarrollo.

Desde el fix de septiembre 2026, además, las tres tiendas se enteran de las reservas de las
otras: cuando una vende, el disponible baja para todas, así que la ventana en la que puede
ocurrir la carrera es de minutos, no de horas.

---

## Referencia rápida

| Qué | Dónde |
|---|---|
| Script de alta | `scripts/onboard-cliente.sh` |
| Runner de migraciones | `packages/cms-prestashop/synkrop/sql/migrate.php` |
| Cómo probar todo esto antes | `docs/testing/manual-multi-tienda.md` |
| Fan-out por `cpnId` | `packages/bot-miki/src/routes/webhooks.ts` |
| Estados de pedido | `packages/cms-prestashop/synkrop/classes/OrderDocumentService.php` |
