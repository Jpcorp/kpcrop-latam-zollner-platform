# Flujo: Validacion de Licencias

El sistema de licencias es el boundary critico de toda la plataforma. Todos los plugins CMS validan su licencia antes de ejecutar cualquier sync. La validacion debe ser rapida, disponible, y resistente a la latencia de red.

## Patron: JWT con Cache en Edge

Para evitar que cada operacion del plugin bloquee en la red al demonio, la licencia se representa como un **JWT firmado por bot-miki con TTL de 5 minutos**, cacheado en Cloudflare Workers (edge cache cercano al CMS del cliente en Chile/LATAM).

```mermaid
sequenceDiagram
    autonumber
    participant P as Plugin CMS
    participant CF as Cloudflare Edge
    participant BM as bot-miki API
    participant LIC as Motor Licencias
    participant DB as PostgreSQL
    participant ST as Stripe

    P->>P: Leer JWT del storage local del CMS
    P->>P: Verificar firma y TTL del JWT

    alt JWT valido y no expirado (cache hit local)
        P->>P: Continuar con la operacion
    end

    alt JWT expirado o ausente
        P->>CF: GET /v1/license/token {tenantId, apiKey}
        CF->>CF: Buscar en cache de edge (TTL 4 min)

        alt Cache hit en edge
            CF-->>P: JWT fresco (desde cache)
        end

        alt Cache miss en edge
            CF->>BM: GET /v1/license/token
            BM->>LIC: Verificar licencia de tenantId
            LIC->>DB: SELECT license WHERE tenantId = ?
            DB-->>LIC: License record

            alt Licencia activa
                LIC->>ST: GET /v1/subscriptions/{subscriptionId}
                ST-->>LIC: Subscription {status: "active", features: [...]}
                LIC-->>BM: Licencia valida, features habilitados
                BM->>BM: Firmar JWT {tenantId, features, exp: +5min}
                BM-->>CF: 200 {token: "eyJ..."}
                CF->>CF: Guardar en cache (TTL 4 min)
                CF-->>P: 200 {token: "eyJ..."}
                P->>P: Guardar JWT en storage local del CMS
            end

            alt Licencia expirada o suspendida
                LIC-->>BM: Licencia invalida
                BM-->>CF: 402 Payment Required
                CF-->>P: 402 Payment Required
                P-->>P: Mostrar aviso de renovacion al admin
            end
        end
    end
```

---

## Flujo de Activacion de Licencia (nuevo cliente)

```mermaid
sequenceDiagram
    autonumber
    actor CL as Cliente Nuevo
    participant WEB as Landing / Checkout
    participant ST as Stripe
    participant BM as bot-miki
    participant DB as PostgreSQL
    participant EM as Email

    CL->>WEB: Seleccionar plan y pagar
    WEB->>ST: Crear suscripcion {customerId, planId}
    ST-->>WEB: Suscripcion creada {subscriptionId}
    ST->>BM: Webhook: customer.subscription.created
    BM->>DB: INSERT license {tenantId, subscriptionId, plan, features, status: "active"}
    BM->>BM: Generar apiKey unico para el tenant
    DB-->>BM: OK
    BM->>EM: Enviar email con apiKey + instrucciones de instalacion
    EM-->>CL: "Tu licencia esta activa. apiKey: kp_..."
```

---

## Flujo de Revocacion de Licencia

```mermaid
sequenceDiagram
    autonumber
    participant ST as Stripe
    participant BM as bot-miki
    participant CF as Cloudflare Edge
    participant DB as PostgreSQL
    participant Q as Cola BullMQ

    ST->>BM: Webhook: customer.subscription.deleted o invoice.payment_failed
    BM->>DB: UPDATE license SET status = "suspended" WHERE subscriptionId = ?
    BM->>CF: DELETE /cache/license/{tenantId}
    Note over CF: Invalida el cache del JWT en el edge inmediatamente
    BM->>Q: Cancelar todos los jobs pendientes del tenant
    Q-->>BM: Jobs cancelados
    BM->>BM: Registrar en event log: license_suspended
```

---

## Modelo de Datos: Licencias

```sql
-- Tabla principal de licencias
CREATE TABLE licenses (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id       VARCHAR(100) UNIQUE NOT NULL,
    subscription_id VARCHAR(100) NOT NULL,          -- Stripe subscription ID
    plan            VARCHAR(50) NOT NULL,            -- starter | growth | agency
    status          VARCHAR(20) NOT NULL DEFAULT 'active', -- active | suspended | cancelled
    features        JSONB NOT NULL DEFAULT '[]',     -- ["sync_auto","dropshipping",...]
    max_stores      INTEGER NOT NULL DEFAULT 1,
    api_key         VARCHAR(64) UNIQUE NOT NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    expires_at      TIMESTAMPTZ,
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Sub-cuentas para agencias (una agencia → N tiendas)
CREATE TABLE tenant_stores (
    id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    license_id  UUID NOT NULL REFERENCES licenses(id),
    store_name  VARCHAR(200) NOT NULL,
    cms_type    VARCHAR(50) NOT NULL,              -- wordpress | shopify | prestashop | ...
    cms_url     VARCHAR(500) NOT NULL,
    bsale_integration_id INTEGER,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Event log de sincronizaciones
CREATE TABLE sync_events (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id       VARCHAR(100) NOT NULL,
    store_id        UUID REFERENCES tenant_stores(id),
    sync_type       VARCHAR(50) NOT NULL,           -- manual | auto | dropshipping
    entity_type     VARCHAR(50) NOT NULL,           -- products | prices | stock | clients
    status          VARCHAR(20) NOT NULL,           -- success | partial | failed | skipped
    records_updated INTEGER DEFAULT 0,
    duration_ms     INTEGER,
    error_message   TEXT,
    idempotency_key VARCHAR(200) UNIQUE,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);
```
