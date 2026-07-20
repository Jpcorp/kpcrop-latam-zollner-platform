# Tutorial: probar todos los casos del flujo PS → Bsale (Fase 1)

Guía práctica para validar a mano, en el entorno local, cada escenario del flujo de
ventas PrestaShop → Bsale. Escrita para ejecutarse de corrido (~45 min) o por casos
sueltos. Última actualización: 19-jul-2026.

## El entorno

| Qué | Dónde |
|---|---|
| Tienda | http://localhost:8080 |
| Admin PS | http://localhost:8080/admin-dev — `admin@kpcrop.local` / `Admin1234!` |
| Pestaña Ventas de synkrop | Admin → Catálogo → Synkrop |
| Panel sandbox Bsale | https://account.bsale.dev (tu cuenta) |
| Levantar el stack | `cd packages/cms-prestashop && docker compose up -d` |

**Productos preparados** (SKU de PrestaShop = SKU real del sandbox Bsale):

| Producto PS | SKU | Existe en Bsale |
|---|---|---|
| Mug "The best is yet to come" | `12453` | ✅ |
| Mug "The adventure begins" | `12458` | ✅ |
| Mug "Today is a good day" | `PL-4523` | ✅ |
| Poleras / polerones / cuadros | `demo_*` | ❌ (a propósito — caso de error) |

**Datos de prueba**: RUT válido `76.543.210-3` · RUT inválido `76.543.210-K` ·
RUT genérico que usa el sistema para boleta: `66.666.666-6`.

**Regla de oro del flujo**: nada llega a Bsale sin pasar por [Generar], y ningún
documento tributario se emite sin un humano en Bsale. Si algo falla, la fila queda
en `error` o `review` con el detalle — nunca se pierde un pedido.

### Estados de la cola (pestaña Ventas)

`pending` → encolado, sin documento aún · `generated` → nota de venta creada en
Bsale · `emitted` → emisión detectada · `closed` → pedido pasado a "Documentado en
Bsale" · `error` → falló la generación, reintentable · `review` → requiere decisión
humana · `cancelled` → anulado.

---

## Caso 1 — Compra con boleta (camino feliz)

1. En la tienda, compra un **tazón** (SKU real). Termina con "transferencia bancaria".
2. Admin → Pedidos → tu pedido → cambia el estado a **Pago aceptado**.
3. Pestaña Ventas: la fila debe aparecer en `pending` (el hook encoló solo).
4. Botón **[Generar]** → la fila pasa a `generated` con número de nota y link al PDF.
5. En el panel Bsale: Documentos → verás la **NOTA VENTA** con el total exacto del
   checkout y RUT `66.666.666-6` (boleta = sin datos del comprador, por diseño).
6. En Bsale, **emite la boleta** desde la nota (los datos se heredan).
7. Botón **[Verificar emisiones]**: ⚠️ en el sandbox NO cerrará el pedido — la
   boleta del sandbox es "manual, no válida al SII" y el matching exige documento
   tributario (con código SII). En producción con boleta electrónica este paso pasa
   la fila a `closed` y el pedido a "Documentado en Bsale" automáticamente.

**Qué validaste**: hook → cola → generación → emisión humana → (cierre en prod).

## Caso 2 — Compra con factura (formulario del checkout)

1. Compra otro tazón. En el paso de pago aparece **"Documento de compra"**.
2. Prueba primero el RUT inválido `76.543.210-K` → debe rechazarlo en vivo.
3. Elige **Factura**, llena RUT `76.543.210-3`, razón social y giro → **Guardar
   datos de factura** → mensaje verde.
4. (Opcional) vuelve a **Boleta** → "Se emitirá boleta" (borra la solicitud) → y
   re-elige Factura para dejarla guardada.
5. Completa la compra → Pago aceptado → [Generar].
6. En Bsale, la nota debe traer **tu RUT, razón social (campo empresa) y giro** —
   todo lo que el usuario Bsale necesita para decidir emitir factura sin digitar nada.

**Qué validaste**: formulario, validación de RUT doble (JS + servidor), datos
tributarios viajando a la nota.

## Caso 3 — SKU inexistente en Bsale (manejo de errores)

1. Compra una **polera o polerón** (SKU `demo_*`). Pago aceptado → [Generar].
2. La fila queda en `error` con el detalle: `Bsale API 400: invalid variant
   (doc_006)`. Nada se creó en Bsale, nada se reservó. El botón procesa el lote:
   si había otros pedidos válidos pendientes, esos sí se generan.
3. Corrige: Admin → Catálogo → el producto → campo **Referencia** → pon un SKU real
   (p. ej. `PL-2536`). Ojo: los pedidos ya creados guardan el SKU antiguo — para el
   pedido existente edita la referencia en la línea del pedido, o simplemente
   repite la compra tras corregir el producto.
4. **[Generar]** de nuevo → el reintento pasa a `generated`.

**Qué validaste**: todo-o-nada de Bsale, error visible y reintentable, snapshot del
SKU al momento de la compra.

## Caso 4 — Anulación antes de emitir (automática)

1. Toma un pedido en `generated` (nota creada, boleta NO emitida).
2. Admin → Pedidos → cambia el estado a **Cancelado**.
3. La fila pasa a `cancelled` y en el panel Bsale la nota aparece **anulada** (la
   anulación viajó por API, con el officeId de la config).
4. Bonus: en Bsale → Inventario, el stock **reservado** por la nota se libera.

## Caso 5 — Cancelar un pedido ya emitido (revisión humana)

1. Toma un pedido `emitted` o `closed` (con boleta emitida) y cancélalo en PS.
2. La fila pasa a `review` con el mensaje "evaluar nota de crédito en Bsale".
   **Nunca** se anula solo: un documento tributario emitido exige decisión humana.

## Caso 6 — Cancelar antes de generar

1. Pedido en `pending` (encolado, sin [Generar]) → cancélalo en PS.
2. La fila pasa a `cancelled` sin tocar Bsale (no había nada que anular).

## Caso 7 — Efectos en el stock de Bsale

Míralo en el panel Bsale (Inventario → Stock, sucursal Casa Matriz) en cada fase:

| Evento | Efecto |
|---|---|
| Nota de venta generada | **Reserva**: disponible baja, stock físico intacto |
| Boleta emitida | **Descuenta** el físico |
| Nota anulada | **Libera** la reserva |

## Caso 8 — SKU de despacho configurable

1. Admin → Módulos → Synkrop → Configurar → campo **"SKU de despacho en Bsale"**.
2. Con un SKU de servicio del sandbox (p. ej. `1363272658`): compra con envío
   pagado ("My carrier", $5.000) → genera → la línea de despacho de la nota usa ese
   SKU y **no** aparece ninguna variante nueva en el catálogo Bsale.
3. Déjalo en blanco y repite: Bsale **auto-crea** una variante con código
   timestamp por cada documento (por eso en producción conviene configurarlo —
   o definir el SKU con que se cobra el despacho externalizado).

## Caso 9 — Descuentos de carro

1. Admin → Catálogo → Descuentos → crea un cupón (p. ej. 10%).
2. Compra un tazón aplicando el cupón → genera.
3. En la nota, el descuento va **prorrateado en el precio neto de cada línea**
   (no como línea aparte) y el total sigue cuadrando con el checkout.

## Caso 10 — Pack de productos (rechazo por diseño)

1. Admin → Catálogo → nuevo producto tipo **Pack** con 2 tazones → cómpralo.
2. [Generar] → error claro: "El pedido contiene un pack — genera el documento
   manualmente en Bsale". La v1 no prorratea packs (lección del módulo legado).

---

## Consultas útiles (con `!` desde Claude Code, o en tu terminal)

Estado de la cola completa:

```bash
docker exec kpcrop-ps178-mysql mysql -uprestashop -pprestashop_dev prestashop \
  -e "SELECT id_order, status, bsale_doc_number, emitted_doc_number, total_amount, error_details FROM ps_synkrop_order_queue ORDER BY id_order;"
```

Solicitudes de factura guardadas por el formulario:

```bash
docker exec kpcrop-ps178-mysql mysql -uprestashop -pprestashop_dev prestashop \
  -e "SELECT * FROM ps_synkrop_invoice_request;"
```

Documentos en el sandbox vía API (token en `ssh/bsale_sandbox.sh`):

```bash
bash ssh/bsale_sandbox.sh raw "documents.json?limit=20"
```

## Limitaciones conocidas del sandbox

1. **Sin documentos tributarios**: todos los tipos son "manuales, no válidos al
   SII" → [Verificar emisiones] nunca matchea en sandbox. El cierre automático solo
   es observable en producción (o cuando exista el flag "modo prueba", pendiente).
2. **La vista "detalle" del panel** a veces dice "no posee detalle disponible" para
   notas creadas por API — es cosmético: el PDF y la API muestran las líneas bien.
3. El sandbox se puede regenerar en https://account.bsale.dev/users/create; si
   cambia el token, actualízalo en `ssh/bsale_sandbox.sh` (línea 22) y en la config
   del módulo (Admin → Módulos → Synkrop → token de acceso Bsale).
