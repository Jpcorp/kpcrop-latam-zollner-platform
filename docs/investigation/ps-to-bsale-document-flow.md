# Investigación — Flujo PrestaShop → Bsale (emisión de documentos de venta)

**Fecha:** 2026-07-17
**Contexto:** allgrano.com (PrestaShop 1.7.6.9, hosting DirectAdmin solo-FTP) tiene instalado el
módulo legado `syncBsale` (autor: SidecKick, contacto@sideckick.cl, ~2020). Se analizó su código
completo (copia en `sources_of_information/allgrano.com/.../modules/syncBsale/`, gitignored) para
entender cómo resuelve la venta web → documento Bsale → descuento de stock, y diseñar el flujo
**semi-manual** que pidió el negocio: *el sistema crea el documento automáticamente, pero un
usuario de Bsale revisa y emite; la emisión cierra el ciclo en PrestaShop para despachar*.

---

## 1. Cómo lo hace el módulo legado `syncBsale` (flujo actual, 100% automático)

### 1.1 Disparo

- Hook **`actionOrderStatusUpdate`** (`syncBsale.php:3111`): en cada cambio de estado de pedido.
- Condiciones para emitir (todas configurables en BD, tabla `ps_syncbsale_configuracion`):
  - `syncDocumentOrder = 1` (switch general),
  - `tokenBsale` presente (token API guardado en texto plano en la BD),
  - el nuevo estado ∈ `ps_syncbsale_documento_estado_orden` (lista de estados que gatillan
    emisión, típicamente **2 = Pago aceptado**).
- Guard anti-duplicado débil: consulta el último registro en `ps_syncbsale_documento` para la
  orden; si existe uno con `tipo_documento` NULL (= ya se emitió venta) aborta con 401.
- Estado **6 (Cancelado)** dispara el flujo inverso: nota de crédito (ver §1.4).

### 1.2 Construcción del documento (`models/DocumentBsale.php::syncDocumentOrder`)

1. **Sucursal** (`officeId`): config `idSucursal`, con override por transportista
   (config `sync_carriers`: mapa carrier → sucursal).
2. **Detalle**: itera `Order::getProducts()`:
   - Ítems normales: `code` = referencia (SKU), `netUnitValue` = precio (÷1.19 si config
     `syncDocumentIvaOrder=1`, i.e. los precios PS incluyen IVA), `quantity`, `taxId "[1]"`.
   - Si config `syncDocumentNoStock=1`: manda `comment` (nombre) en vez de `code` → el documento
     NO descuenta stock en Bsale (línea de servicio).
   - Precio 0 se omite (Bsale rechaza ≤ 0; se considera "regalo").
   - **Packs**: explota el pack en sus componentes prorrateando el precio del pack entre ítems
     (lógica frágil, ~150 líneas).
   - **Descuentos del carro**: prorratea `total_discounts` multiplicando cada línea por un factor.
   - **Envío**: línea extra `comment: "Costo de despacho"` con el neto del shipping.
3. **Tipo de documento**: config `idTypeDocument` (default **22 = boleta electrónica**). Si el
   cliente pidió factura (ver §1.3): cambia a `idTypeDocumentF` y agrega bloque `client`
   (RUT formateado, razón social, giro, dirección, ciudad, comuna).
4. **Flags**: `dispatch: 1`, `declareSii` = config `syncTimbraje` (0 = no declara al SII),
   `emissionDate`/`expirationDate` = now.
5. **Llamada**: `POST https://api.bsale.cl/v1/documents.json` con header `access_token`
   (`models/ApiBsale.php`; espera HTTP 201; errores → tabla `ps_syncbsale_logs_error` y `null`).
6. **Post-emisión**:
   - INSERT en `ps_syncbsale_documento` (id_orden_ps, id_documento_bsale, `number`,
     estado de orden, `urlPdfOriginal`, totalAmount) — el nexo orden↔documento.
   - Re-lee el stock de cada producto desde Bsale y lo actualiza en PS (cierra el loop de stock
     inmediatamente, sin esperar webhook).
   - Notifica a `hooks2.sidekick.cl/api/v1/regDocumento` (telemetría/licencia del proveedor).
   - Email al cliente con el link del PDF de la boleta (plantilla `newsletter`).

### 1.3 Captura de datos de factura en el checkout

- Hook `displayPaymentTop` inyecta `payment.tpl` + `jquery.Rut.js` + `common.js` si configs
  `syncBoleta`/`syncFactura` activas: el comprador elige boleta o factura y llena RUT, razón
  social, giro, dirección, teléfono, ciudad, comuna.
- Se guarda en `ps_syncbsale_factura` **con `id` = id_cart** (así `syncDocumentOrder` la
  encuentra vía `Cart::getCartIdByOrderId`).
- `controllers/front/checkRut.php`: autocompleta los datos si el RUT ya compró antes (buen UX).

### 1.4 Cancelación

Estado 6 → `syncCanceledDocumentOrder`: por cada documento de la orden, lee su detalle en Bsale
y hace `POST returns.json` (nota de crédito, `referenceDocumentId` al documento original),
registra en `ps_syncbsale_documento` con `tipo_documento=1` y avisa al cliente por email.

### 1.5 Emisión manual

`controllers/front/psJson.php?method=generateDoc&id={orden}`: genera el documento bajo demanda
(botón en el panel admin del pedido, hook `displayAdminOrderContentOrder`).

---

## 2. Problemas del módulo legado (lecciones para el diseño nuevo)

| # | Problema | Detalle |
|---|---|---|
| 1 | **Emisión automática al pagar** | Nadie revisa antes de emitir la boleta: exactamente lo que el negocio quiere evitar. |
| 2 | 🔴 **`psJson.php` público y sin auth** | Cualquiera puede llamar `?method=generateDoc&id=N` (emitir documentos reales), `updateStock`, `updatePrice`. Vulnerabilidad viva en allgrano.com hoy. |
| 3 | **Idempotencia débil** | Guard solo en el hook; `generateDoc` no tiene ninguno; sin `UNIQUE` en BD → doble clic o doble hook = doble boleta. |
| 4 | **Sin reintentos** | Si Bsale no responde (código 0/≠201) solo loguea en una tabla y sigue; la orden queda sin documento en silencio. |
| 5 | SQL sin sanitizar | Concatenación directa de valores en decenas de queries. |
| 6 | Token Bsale en texto plano en BD; RUT de fallback hardcodeado (`12.345.678-5`); IVA hardcodeado 1.19 | |
| 7 | Dependencia del proveedor | Valida licencia contra `hooks.sidekick.cl` (¿sigue vivo?). |

---

## 3. Diseño propuesto — flujo SEMI-MANUAL (Synkrop)

**Principio acordado (17-jul-2026):** el sistema no emite documentos tributarios. Crea
automáticamente un documento **no tributario** con los datos exactos del pedido (cero
digitación → cero error humano) y **un usuario de Bsale decide y emite** la boleta/factura.
La emisión cierra el ciclo: PrestaShop pasa el pedido a estado despachable.

```
[1] Cliente compra en PS ──(pago aceptado)──▶ plugin synkrop (hook actionOrderStatusUpdate)
[2] plugin ──POST /v1/orders (X-API-Key)──▶ bot-miki ──BullMQ──▶ POST documents.json (Bsale)
        · documentTypeId = NOTA DE VENTA (no tributaria)          idempotente por nº de orden
        · guarda mapeo orden PS ↔ documento Bsale
[3] Usuario Bsale revisa la nota de venta en Bsale y la FACTURA/BOLETEA (flujo nativo Bsale)
        · la responsabilidad tributaria (SII) recae en esa persona
[4] Bsale ──webhook `document` (POST)──▶ bot-miki (endpoint /v1/webhooks/bsale existente)
        · GET del documento → si referencia la nota de venta de [2], correlaciona con la orden
[5] bot-miki ──▶ webhook.php del plugin ──▶ orden PS pasa a "Preparación/Listo para enviar"
        · adjunta link PDF del documento, email al cliente, y se habilita el despacho
[6] Stock: la emisión baja stock en Bsale → webhook `stock` → sync quirúrgico ya existente
```

### Decisiones de diseño

1. **Vía bot-miki, no directo a Bsale** (a diferencia del legado): reutiliza cola/reintentos
   BullMQ, idempotencia (`sync_events`), trazabilidad `job_id`, y el token Bsale queda en el
   hub, no en la BD del CMS. Consistente con hub-and-spoke (ADR-004).
2. **Nota de venta, no boleta con `declareSii:0`**: el legado emitía boleta "sin declarar",
   que sigue siendo un documento tributario mal usado. La nota de venta es el documento
   diseñado para "venta pendiente de facturar" y el panel de Bsale ofrece nativamente
   "facturar/boletear una nota de venta" — no hay que construir UI de aprobación propia.
3. **Idempotencia dura**: `UNIQUE` sobre la referencia de orden + consulta previa
   `GET documents.json?number={orden}` (la API de Bsale no documenta external_id;
   ver bsale-api-findings §5.3).
4. **Elección boleta/factura del comprador**: conservar el patrón del legado (formulario en
   checkout + autocompletar por RUT), pero como *dato informativo* en la nota de venta
   (el usuario Bsale lo ve al emitir), guardado con la orden, no como emisión directa.
5. **Cancelaciones**: pedido cancelado antes de emisión → anular la nota de venta; después de
   emisión → NO automatizar la nota de crédito (misma lógica: decisión humana en Bsale).

### ✅ Validación en sandbox (17-jul-2026)

Sandbox validado con el token del cliente prototipo strainmachine (¡es el **cpnId 95601**, la
misma integración que ya manda webhooks a bot-miki!). Kit: `ssh/bsale_sandbox.sh` (gitignored).
OJO: el sandbox genera ventas falsas constantemente (simulador POS) — hay ruido de fondo.

| Pregunta | Resultado |
|---|---|
| **Q1** — tipo "nota de venta" | `NOTA VENTA` existe (id=3 en esta cuenta; sin codeSii, no electrónica). Bonus: `PEDIDO WEB` (32) y `PEDIDO CON RESERVA DE STOCK` (48). **Los IDs varían por cuenta → descubrir por nombre/atributos, no hardcodear.** |
| **Q2** — efecto en stock | Los 3 tipos **RESERVAN** (`quantityReserved`↑, `quantityAvailable`↓, `quantity` intacta). Al emitir la boleta desde la nota, la reserva se consume y `quantity` baja. **Sin ventana de sobreventa.** ✔ Ideal para el flujo. |
| **Q3** — correlación al emitir | ❌ El doc emitido **NO referencia** la nota (`references`=0, `salesId`=null, sin attributes ni comments heredados). ✔ PERO conserva **cliente + total + detalle exacto** → correlación determinística por `(clientId, totalAmount, set de SKUs, id posterior)` + filtro por tipo tributario. Validada contra emisión real (boleta n°41303 ← nota n°3). |
| Hallazgo extra | La nota de venta **exige cliente** (`cli_001`) — siempre disponible desde el pedido PS. La boleta creada vía UI conserva ese cliente. |
| Hallazgo extra | El precio se manda **neto** (`netUnitValue`); Bsale agrega IVA 19% solo (1000 → total 1190). |

**Implicación de diseño (Q3):** el cierre del ciclo usa el webhook `document` como *gatillo* y la
coincidencia cliente+total+SKUs como *correlación* contra las órdenes en estado `GENERADO` de
`synkrop_order_map`; empates se resuelven por antigüedad y se marca `REVISAR` si la ambigüedad
persiste. La conciliación periódica (polling `documents.json?clientid=`) cubre webhooks perdidos.

### Preguntas abiertas (confirmar con Bsale / cuenta del cliente)

1. `documentTypeId` de "Nota de venta" en la cuenta Bsale de Allgrano
   (`GET /v1/document_types.json`) y si la API permite crearla con `POST documents.json`.
2. ¿La nota de venta creada por API **reserva** o **descuenta** stock, o no lo toca hasta la
   emisión? (define si el sitio debe descontar stock local mientras tanto).
3. ¿El webhook `document` se dispara al emitir boleta/factura *desde* una nota de venta, y el
   documento emitido trae la referencia a la nota de venta para correlacionar?
4. Configuración del webhook `document` para el cpnId de Allgrano (vía ayuda@bsale.app).
5. ¿Qué pasa si el usuario Bsale emite una boleta manualmente SIN partir de la nota de venta?
   (documento huérfano: definir heurística de correlación por monto/fecha o ignorar).

### Modos de operación: automático y semi-manual (decisión 17-jul-2026)

Sigue la dualidad que Synkrop ya tiene para stock/precios (Manual = plugin bajo demanda;
Automática = bot-miki): **una sola cola, dos motores**. Todo pedido pagado entra SIEMPRE a
`synkrop_order_queue` (tabla del plugin, `UNIQUE(id_order)`); el modo define quién la empuja:

- **Automático (licencia vigente — Growth/Agency):** bot-miki procesa la cola solo (BullMQ,
  reintentos, `job_id`); cada venta genera su nota de venta sin intervención. El cierre del
  ciclo llega por webhook `document`.
- **Semi-manual (plan base / licencia vencida):** panel "Documentos pendientes" en el admin PS
  con botón [Generar] (unitario o lote) y [Verificar emisiones] (consulta a Bsale qué notas de
  venta ya se facturaron y actualiza pedidos). Misma función interna
  (`SynkropService::createSaleNote()`), disparo humano.

**Degradación con gracia:** si vence la licencia no se rompe nada — la cola sigue llenándose y
el botón sigue operativo; solo desaparece la automatización (incentivo natural de renovación,
sin rehenes). La emisión tributaria es SIEMPRE humana en Bsale, en ambos modos.

**Estados de la cola:** `PENDIENTE → GENERADO (id/link nota de venta) → EMITIDO → CERRADO
(pedido PS despachable)`, más `ERROR` con motivo visible en el panel (el legado fallaba en
silencio). Palanca de upsell alineada con pricing: semi-manual en plan base, automático en
Growth/Agency (misma lógica que "sync automático cada 15 min" de la tabla de planes).

### Alcance para Synkrop (paquetes afectados)

- `cms-prestashop/synkrop`: hook `actionOrderStatusUpdate` + captura boleta/factura en checkout
  + tabla `synkrop_order_map` (orden ↔ documento Bsale) + manejo del webhook de vuelta
  (nuevo `action` en `webhook.php`).
- `bot-miki`: endpoint `POST /v1/orders` (auth `X-API-Key` como `/v1/sync/report`), worker que
  crea la nota de venta con reintentos, y extensión del adapter de webhooks para el topic
  `document` (hoy se ignora) con resolución + correlación.
- `shared`: modelo canónico `CanonicalOrder`/`CanonicalDocument`.

---

## 4. Plan de implementación (17-jul-2026)

**Orden estratégico: Fase 1 primero y completa.** El modo semi-manual funciona sin tocar
bot-miki, entrega valor vendible de inmediato (Allgrano), y la Fase 2 solo agrega el motor
automático sobre la misma lógica.

### Fase 1 — Plugin PrestaShop: cola + modo semi-manual

1. **Migración SQL** (`sql/migrate_add_order_queue.sql` + `install.sql`):
   - `synkrop_order_queue`: `id`, `id_order UNIQUE`, `id_cart`, `status`
     (`pending|generated|emitted|closed|error`), `bsale_doc_id`, `bsale_doc_number`,
     `bsale_doc_url`, `emitted_doc_id/number/url/type`, `error_details` (JSON válido,
     nunca `''` — lección MariaDB), `created_at`/`updated_at` en **UTC con `gmdate()`**
     (lección #100).
   - `synkrop_invoice_request` (`id_cart` PK): rut, razón social, giro, dirección, teléfono,
     ciudad, comuna (datos factura del checkout).
2. **Hook `actionOrderStatusUpdate`** en `synkrop.php`: si el nuevo estado ∈ estados
   configurados (default: Pago aceptado) → `INSERT IGNORE` en la cola. Nada más — el hook no
   llama a Bsale (a diferencia del legado): rápido y sin riesgo en el checkout.
3. **`SynkropService::createSaleNote(int $idOrder)`** (lógica compartida por ambos modos):
   - Dedupe: status de la cola + `GET documents.json?number=` como cinturón.
   - Payload: detalle desde `Order::getProducts()` (SKU=reference, neto=precio÷(1+tasa IVA
     **configurable**, no 1.19 hardcode), descuentos prorrateados, línea de despacho),
     `client` obligatorio desde la dirección de facturación (RUT validado módulo 11),
     tipo de documento **descubierto por nombre** ("NOTA VENTA") y cacheado en config.
     Packs: v1 los rechaza con error claro en la cola (no replicar los ~150 líneas frágiles
     del legado; se aborda en fase 3).
   - POST vía `BsaleApiClient` → guarda `bsale_doc_*` + status `generated` (o `error` con
     detalle legible).
4. **Panel admin** (`AdminSynkropController`, pestaña "Ventas"): tabla de la cola con estados
   y links (nota de venta y doc emitido hipervinculados a `urlPdf`), botones **[Generar]**
   (fila y lote) y **[Verificar emisiones]**. Sin endpoints públicos nuevos (lección psJson).
5. **`SynkropService::checkEmissions()`**: para cada fila `generated`, busca en Bsale docs
   posteriores del mismo cliente con mismo total+SKUs y tipo tributario (boleta/factura) →
   `emitted` → cambia el estado del pedido PS al configurado ("Preparación en curso") →
   `closed`. Ambigüedad → status `review` visible en panel.
6. **Checkout boleta/factura**: formulario en `displayPaymentTop`/equivalente 1.7 con RUT +
   autocompletado (patrón del legado, pero vía controller autenticado por token de módulo).
7. **Tests PHPUnit**: builder del payload (prorrateo, IVA, RUT), transiciones de estado,
   dedupe. **Deploy**: allgrano.com por FTP (`ssh/allgrano.sh`, agregar tarea deploy-synkrop).

### Fase 2 — bot-miki: motor automático

1. **Migración 004**: tabla `order_documents` (store_id, ps_order_id, bsale_doc_id,
   client_code, total_amount, skus_hash, status, `UNIQUE(store_id, ps_order_id)`).
2. **`POST /v1/orders`** (auth `X-API-Key` contra `licenses.api_key`, patrón sync-report/#91):
   el plugin envía la orden canónica al encolar (solo si licencia válida — si no, opera
   semi-manual). Encola job `create-sale-note` (idempotencia BullMQ + UNIQUE).
3. **Worker**: crea la nota vía API Bsale con reintentos (`PermanentSyncError` para 4xx,
   patrón #93), guarda huella de correlación, notifica al plugin (`webhook.php` action nueva
   `order_document`) → la cola local pasa a `generated`.
4. **Topic `document`**: sacarlo de la lista de ignorados en `routes/webhooks.ts:47` →
   worker resuelve el doc → match contra `order_documents` (client+total+skus_hash) →
   notifica al plugin → `emitted`/`closed` sin intervención.
5. **Scheduler**: conciliación horaria (cubre webhooks perdidos) reutilizando el polling.
6. **`shared`**: `CanonicalOrder` + `CanonicalSaleDocument` con Zod.

### Fase 3 — Extras

Email al comprador con link del documento (config), packs, notas de crédito (solo aviso,
emisión humana), multi-moneda/multi-tienda, dashboard agencia.

### Decisiones abiertas al implementar

- Confirmación topic `document` (monitor en curso / correo a ayuda@bsale.app).
- Estado PS de destino al cerrar (¿"Preparación en curso" o estado custom "Documentado"?).
- Qué hacer con pedidos cancelados con nota `generated` (¿anular nota vía API? verificar
  endpoint DELETE/anulación en sandbox).
