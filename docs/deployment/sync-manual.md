# Guia de Sincronizacion Manual — Synkrop para PrestaShop

Tiempo estimado: **15–30 minutos** (segun tamano del catalogo)

Este manual explica como realizar la sincronizacion manual de productos, stock y precios
desde Bsale hacia PrestaShop usando el modulo Synkrop. La sincronizacion manual es el
punto de partida de cualquier cliente nuevo y el mecanismo de respaldo cuando se necesita
forzar una actualizacion completa del catalogo.

---

## Que hace la sincronizacion manual

| Entidad | Que sincroniza |
|---------|----------------|
| **Productos** | Nombre, descripcion, precio base, estado activo/inactivo, SKU |
| **Stock** | Cantidad disponible por variante en todas las sucursales |
| **Precios** | Precio neto desde la lista de precios configurada en Bsale |

**Regla de negocio importante:** los productos **nuevos** (que no existen aun en
PrestaShop) se crean siempre en estado **inactivo**. Esto evita que aparezcan en
la tienda con precio $0 o datos incompletos antes de que el administrador los revise.
Los productos que ya existian en la tienda conservan el estado que tenian en Bsale.

---

## Prerrequisitos

Antes de sincronizar por primera vez, confirma que tienes:

- [x] Modulo Synkrop instalado y activo en PrestaShop
- [x] Token de acceso Bsale configurado y verificado (`✓ Conectado`)
- [x] API Key de licencia kpcrop configurada y verificada (`✓ Licencia activa`)
- [x] ID de lista de precios Bsale configurado en Configuracion avanzada
- [x] Todos los productos en Bsale tienen un codigo SKU asignado

Si alguno de estos puntos no esta listo, ve primero a la guia de instalacion:
[plugin-install.md](./plugin-install.md)

---

## Paso 1 — Abrir el panel de Synkrop

1. Entra al backoffice de PrestaShop
   (`https://tu-tienda.cl/admin` o la URL personalizada de tu hosting)

2. En el menu lateral izquierdo, haz clic en **Catalogo**

3. Aparece la opcion **Synkrop** — haz clic ahi

4. Veras el panel principal con tres botones de accion y el historial de sincronizaciones

---

## Paso 2 — Sincronizar productos

La sincronizacion de productos es siempre el primer paso, ya que crea la estructura
base sobre la que luego se aplican stock y precios.

1. Haz clic en el boton **Productos**

2. Aparece una barra de progreso con el contador de tiempo transcurrido

3. Espera a que termine — **no cierres el navegador durante el proceso**

   Tiempos estimados segun tamano del catalogo:
   | Productos en Bsale | Tiempo estimado |
   |--------------------|-----------------|
   | Hasta 500 | 1–3 minutos |
   | 500 – 2.000 | 5–10 minutos |
   | 2.000 – 5.000 | 10–20 minutos |
   | Mas de 5.000 | 20–40 minutos |

4. Al finalizar aparece el resultado:

   **Resultado exitoso:**
   ```
   ✓ 8.333 productos actualizados · 0 errores · 18 min 42 seg
   ```

   **Resultado con errores parciales:**
   ```
   ⚠ 8.310 productos actualizados · 23 errores · 19 min 05 seg
   [Ver detalle de errores]
   ```

---

## Paso 3 — Revisar errores de productos (si los hay)

Si hubo errores, haz clic en **Ver detalle de errores**. Veras una tabla con:

| SKU | Motivo del error |
|-----|-----------------|
| `BF-118` | Variante sin codigo SKU — asigna un SKU en Bsale |
| `WL 22` | Nombre contiene caracteres invalidos para PrestaShop |

**Errores comunes y su solucion:**

**"Variante sin codigo SKU"**
El producto en Bsale no tiene un codigo asignado en el campo SKU/Codigo.
→ En Bsale: Inventario → Variantes → edita la variante → asigna un codigo unico
→ Vuelve a ejecutar la sincronizacion de Productos

**"No se pudo guardar producto"**
El nombre del producto tiene caracteres especiales que PrestaShop rechaza (`< > ; = # { }`).
→ En Bsale: edita el nombre del producto eliminando esos caracteres
→ Vuelve a ejecutar la sincronizacion de Productos

**"Error al validar licencia"**
La API Key de kpcrop no es valida o la suscripcion vencio.
→ Verifica en **Configuracion → Synkrop** que la API Key empiece con `kp_`
→ Contacta a soporte@kpcrop.com si el error persiste

---

## Paso 4 — Sincronizar stock

Una vez que los productos esten sincronizados, actualiza el stock:

1. Haz clic en el boton **Stock**

2. La sincronizacion de stock es mas rapida que la de productos
   (tipicamente 30–90 segundos para catalogos de hasta 10.000 variantes)

3. Resultado esperado:
   ```
   ✓ 7.348 registros de stock actualizados · 0 errores · 1 min 09 seg
   ```

> **Nota:** el stock que se sincroniza es la suma de todas las sucursales Bsale
> a menos que hayas configurado un ID de sucursal especifico en **Configuracion avanzada**.

---

## Paso 5 — Sincronizar precios

1. Haz clic en el boton **Precios**

2. El sistema descarga la lista de precios configurada (ejemplo: Lista Base, ID 1)
   y actualiza el precio neto de cada producto en PrestaShop

3. Resultado esperado:
   ```
   ✓ 8.297 precios actualizados · 0 errores · 2 min 14 seg
   ```

> **PrestaShop trabaja con precio neto.** El modulo no aplica IVA — eso lo hace
> PrestaShop automaticamente segun la regla de impuesto asignada al producto.
> Si ves precios incorrectos en la tienda, revisa la configuracion de impuestos
> en **PrestaShop → Internacional → Impuestos**.

---

## Paso 6 — Verificar en la tienda

Abre tu tienda en modo incognito (para ver como la ve un cliente) y verifica:

1. **Busca un producto que sabes que existe en Bsale** — deberia aparecer
2. **Revisa el precio** — debe coincidir con el precio neto de la lista de Bsale
3. **Revisa el stock** — si el producto tiene stock en Bsale, debe mostrarse disponible

Si un producto no aparece en la tienda pero si esta en PrestaShop (backoffice):
- El producto puede estar **inactivo** (fue creado nuevo y queda inactivo por seguridad)
- La categoria del producto puede estar **oculta**

Para activar los productos nuevos:
1. Ve a **Catalogo → Productos** en el backoffice
2. Filtra por estado "Inactivo"
3. Revisa nombre, precio e imagenes
4. Activa los que esten listos para publicar

---

## Paso 7 — Revisar el historial de sincronizaciones

El panel de Synkrop muestra un historial de todas las sincronizaciones realizadas.
Puedes usarlo para diagnosticar problemas o confirmar que el sistema esta funcionando.

**Columnas del historial:**

| Columna | Descripcion |
|---------|-------------|
| Fecha | Cuando se inicio la sincronizacion |
| Tipo | `manual` (boton del panel) o `webhook` (automatica desde Bsale) |
| Entidad | `products`, `stock` o `prices` |
| Estado | `success`, `partial` (hubo errores) o `failed` |
| Actualizados | Cuantos registros se procesaron correctamente |
| Errores | Cuantos registros fallaron |
| Duracion | Tiempo total de la sincronizacion |

---

## Guia de comprobacion rapida (checklist)

Despues de una sincronizacion completa, verifica estos puntos:

```
[ ] Panel muestra "N productos actualizados · 0 errores" para Productos
[ ] Panel muestra "N registros actualizados · 0 errores" para Stock
[ ] Panel muestra "N precios actualizados · 0 errores" para Precios
[ ] En la tienda (modo incognito): producto de Bsale aparece con precio correcto
[ ] En la tienda (modo incognito): producto con stock > 0 se puede agregar al carro
[ ] Historial muestra 3 entradas recientes con estado "success"
[ ] No hay errores de licencia (si hay, contactar soporte@kpcrop.com)
```

---

## Cuanto sincronizar y cuando

La sincronizacion manual es ideal para:

| Situacion | Que sincronizar |
|-----------|----------------|
| Primera vez que configuras el modulo | Productos → Stock → Precios (en ese orden) |
| Agregaste productos nuevos en Bsale | Productos |
| Hiciste un ajuste masivo de stock | Stock |
| Cambiaste la lista de precios en Bsale | Precios |
| Antes de una campana de ventas importante | Stock + Precios |
| Despues de importar productos desde Excel en Bsale | Productos → Stock → Precios |

> Para mantener el catalogo actualizado en tiempo real sin sincronizacion manual,
> activa la sincronizacion automatica via webhooks:
> [webhook-auto-sync.md](./webhook-auto-sync.md)

---

## Solucion de problemas

### La barra de progreso se queda pegada

El sync sigue corriendo en el servidor aunque el navegador parezca congelado.
- Espera 5 minutos adicionales antes de preocuparte
- Revisa el historial de Synkrop — si aparece una entrada nueva, el sync termino
- Si despues de 10 minutos no hay nada en el historial, recarga la pagina e intenta de nuevo

### El sync termina rapido con "0 actualizados"

Puede significar que:
- Todos los productos ya estaban sincronizados (es normal si no hubo cambios en Bsale)
- El token de Bsale no tiene permisos para leer el inventario

Verifica el token en **Configuracion → Synkrop → Verificar**.

### El precio en la tienda no coincide con Bsale

1. Confirma que el **ID de lista de precios** en Configuracion avanzada es correcto
2. Vuelve a ejecutar la sincronizacion de **Precios**
3. Limpia la cache de PrestaShop: **Parametros avanzados → Rendimiento → Vaciar cache**

### Los productos aparecen con precio $0

Ocurre cuando el precio en la lista de Bsale es 0 o cuando se sincronizaron
productos antes de sincronizar precios. Solucion:
1. Corrige el precio en la lista de precios de Bsale
2. Ejecuta nuevamente la sincronizacion de **Precios**

### Error "Could not connect to Bsale API"

El servidor de PrestaShop no puede alcanzar `api.bsale.io`. Causas posibles:
- El servidor tiene restringido el trafico saliente (consulta a tu hosting)
- El token de Bsale expiro o fue revocado (regeneralo en Bsale → Mi cuenta → Integraciones)

---

## Referencia de tiempos de sincronizacion

Los tiempos son aproximados y dependen de la velocidad del servidor de hosting.

| Catalogo | Productos | Stock | Precios |
|----------|-----------|-------|---------|
| 500 variantes | ~2 min | ~15 seg | ~30 seg |
| 2.000 variantes | ~8 min | ~40 seg | ~1 min |
| 5.000 variantes | ~18 min | ~1 min | ~2 min |
| 10.000 variantes | ~35 min | ~2 min | ~4 min |

---

## Soporte

- **Email**: soporte@kpcrop.com
- **Tiempo de respuesta**: 24–48 horas habiles

Para reportar un error en la sincronizacion, incluye:
1. Una captura del historial de Synkrop (panel → historial)
2. El mensaje de error exacto si aparecio uno
3. Tu version de PrestaShop (aparece abajo del backoffice)
4. La cantidad aproximada de productos en tu cuenta Bsale
