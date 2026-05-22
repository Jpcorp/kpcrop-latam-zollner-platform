# Flujo: Dropshipping entre CMS

Un comercio mayorista (Servidor) expone su catalogo via `app-servi-dropi`. Un distribuidor (Cliente) consume ese catalogo via `app-client-dropi` y lo importa a su propio CMS. Ambos usan Bsale como fuente de verdad del servidor.

```mermaid
sequenceDiagram
    autonumber
    actor MA as Mayorista (Admin Servidor)
    actor DI as Distribuidor (Admin Cliente)
    participant SD as app-servi-dropi
    participant CD as app-client-dropi
    participant BS as Bsale API (Mayorista)
    participant DBCL as CMS Distribuidor

    MA->>SD: Seleccionar productos a consignar
    SD->>BS: GET /v1/products?ids=[...]
    BS-->>SD: Productos seleccionados
    SD->>SD: Publicar catalogo en endpoint autenticado

    DI->>CD: Configurar URL del servidor + credenciales
    CD->>SD: GET /catalog/products {apiKey}
    SD-->>CD: Catalogo disponible [{id, name, price, stock, images}]

    CD->>CD: Mapear a Canonical Product Model
    CD->>DBCL: UPSERT productos en CMS del distribuidor
    DBCL-->>CD: OK
    CD-->>DI: "N productos importados desde [Mayorista]"

    loop Sync programada (configurable por distribuidor)
        CD->>SD: GET /catalog/products?updated_since={lastSync}
        SD->>BS: GET /v1/products?updated_since={lastSync}
        BS-->>SD: Cambios de precio/stock
        SD-->>CD: Delta de productos actualizados
        CD->>DBCL: UPSERT cambios
    end
```

---

## Flujo de Orden Dropshipping (v2 — roadmap)

Cuando un cliente final compra en el CMS del distribuidor, la orden debe propagarse al mayorista para que este la procese en Bsale.

```mermaid
sequenceDiagram
    autonumber
    actor CF as Cliente Final
    participant CMSCL as CMS Distribuidor
    participant CD as app-client-dropi
    participant SD as app-servi-dropi
    participant BS as Bsale API (Mayorista)

    CF->>CMSCL: Checkout y pago
    CMSCL->>CD: Webhook: nueva orden {items, shippingAddress, ...}
    CD->>SD: POST /orders {items, shipping, distributorId}
    SD->>SD: Validar stock disponible
    SD->>BS: POST /v1/orders {items, client, address}
    BS-->>SD: Orden creada {orderId, status: "pendiente"}
    SD-->>CD: 201 Created {orderId, estimatedDelivery}
    CD-->>CMSCL: Actualizar estado orden
    CMSCL-->>CF: "Orden confirmada — despacho por [Mayorista]"
```

---

## Consideraciones de Seguridad

**Autenticacion entre servidor y cliente:** El servidor genera un `apiKey` unico por distribuidor. Las requests del cliente incluyen `Authorization: Bearer {apiKey}`. El servidor valida el key y registra el uso para facturacion.

**Isolation de catalogo:** El mayorista puede definir que productos son visibles para cada distribuidor — no todos los distribuidores ven el catalogo completo. La segmentacion es por `distributorId`.

**Precios diferenciados:** El servidor puede exponer precios de lista o precios especiales por distribuidor. La logica de precio diferenciado vive en `app-servi-dropi`, no en Bsale.

**Rate limiting:** El servidor debe limitar las requests del cliente para evitar que un distribuidor con sync muy frecuente sature la conexion a Bsale del mayorista.
