# Flujo: Sync Manual

El comercio dispara la sincronizacion desde el panel de administracion de su CMS. No requiere que bot-miki este activo — el plugin CMS se comunica directamente con Bsale API.

```mermaid
sequenceDiagram
    autonumber
    actor U as Comercio (Admin CMS)
    participant P as Plugin CMS
    participant BM as bot-miki (Licencias)
    participant BS as Bsale API
    participant DB as Base de Datos CMS

    U->>P: Clic "Sincronizar productos"
    P->>BM: GET /v1/license/validate {token, tenantId}
    BM-->>P: 200 OK {valid: true, features: [...]}

    alt Licencia invalida o expirada
        BM-->>P: 402 Payment Required
        P-->>U: Error: "Licencia inactiva. Renueva tu plan."
    end

    P->>BS: GET /v1/products?page=1&limit=100
    BS-->>P: 200 [{id, code, name, price, stock, ...}]

    loop Por cada pagina de productos
        P->>BS: GET /v1/products?page=N
        BS-->>P: Productos pagina N
        P->>P: Mapear a Canonical Product Model
        P->>DB: UPSERT productos en CMS
        DB-->>P: OK
    end

    P->>BM: POST /v1/sync/report {tenantId, type: "manual", status: "success", count: N}
    BM-->>P: 204 No Content
    P-->>U: "Sincronizacion completada: N productos actualizados"
```

---

## Consideraciones de Implementacion

**Paginacion obligatoria:** Bsale pagina sus respuestas. El plugin debe manejar `total_pages` y nunca asumir que una sola llamada trae todos los productos.

**UPSERT por codigo de producto:** La clave de sincronizacion es `product.code` (SKU en Bsale), no el ID interno del CMS. Esto garantiza que un producto eliminado y recreado en Bsale se actualiza en lugar de duplicarse.

**Validacion de licencia con cache:** El plugin debe cachear el resultado de `/v1/license/validate` por 5 minutos para no bloquear cada operacion en la red al demonio. El cache es un JWT firmado con TTL.

**Reporte de sync:** El `POST /v1/sync/report` es fire-and-forget. Si falla, el plugin no debe interrumpir la operacion — el event log es informacional, no critico para el sync.

**Tiempo de ejecucion PHP:** Los plugins WordPress/PrestaShop/Magento corren bajo Apache con `max_execution_time` tipicamente de 30-300s. Para catalogos grandes (>5000 productos), el sync manual debe ejecutarse via `wp-cron` o un proceso de background PHP, no en el request HTTP del admin.
