# Diseño de Plugins — CMS Restantes

Despues de PrestaShop (MVP), los otros 5 CMS se construyen siguiendo el mismo patron de adapters del ADR-001. Este documento define las particularidades de cada uno.

---

## Orden de Implementacion Post-MVP

| Prioridad | CMS | Lenguaje | Complejidad | Razon |
|---|---|---|---|---|
| 2 | **WordPress / WooCommerce** | PHP | Media | Mayor base instalada en LATAM |
| 3 | **Shopify** | Node.js | Baja | API REST moderna, bien documentada |
| 4 | **Jumpseller** | Node.js | Baja | Mercado chileno, API simple |
| 5 | **Magento 2** | PHP | Alta | Arquitectura compleja, menor volumen |
| 6 | **WooCommerce standalone** | PHP | Baja | Reutiliza 80% del plugin de WordPress |

---

## WordPress / WooCommerce

### Diferencias con PrestaShop

| Aspecto | PrestaShop | WordPress/WooCommerce |
|---|---|---|
| Framework de plugin | `Module extends Module` | `WC_Integration` o plugin standalone |
| Almacenamiento de config | Tabla `bsalesync_config` | `wp_options` (via `get_option`) |
| Jobs background | CLI o cron del servidor | `WP-Cron` o `Action Scheduler` |
| Admin UI | HelperForm + Smarty | WC Settings API o React (Gutenberg) |
| AJAX | Controller con `ajaxProcess*` | `wp_ajax_` hooks |

### Estructura de carpetas

```
packages/cms-wordpress/
├── bsalesync-woo/
│   ├── bsalesync-woo.php          ← Plugin header + bootstrap
│   ├── includes/
│   │   ├── class-bsalesync-woo.php        ← Clase principal
│   │   ├── class-bsale-api-client.php     ← Igual al de PrestaShop
│   │   ├── class-license-client.php       ← Igual al de PrestaShop
│   │   ├── class-woo-product-adapter.php  ← Specific WooCommerce
│   │   └── class-bsale-sync-service.php
│   ├── admin/
│   │   ├── class-bsalesync-admin.php
│   │   └── views/settings-page.php
│   └── languages/
│       └── bsalesync-woo-es_CL.po
├── tests/
└── composer.json
```

### Mapeo WooCommerce ← CanonicalProduct

```php
// packages/cms-wordpress/bsalesync-woo/includes/class-woo-product-adapter.php

class WooProductAdapter {
    public function upsert(CanonicalProduct $canonical): int {
        // Buscar producto por SKU (campo _sku en postmeta)
        $productId = wc_get_product_id_by_sku($canonical->code);

        if ($productId) {
            $product = wc_get_product($productId);
        } else {
            $product = new WC_Product_Simple();
        }

        $product->set_name($canonical->name);
        $product->set_sku($canonical->code);
        // WooCommerce trabaja con precio SIN IVA en el campo price
        // El IVA se agrega segun la configuracion de impuestos de WC
        $product->set_regular_price($canonical->priceNet);
        $product->set_stock_quantity($canonical->stock->quantity);
        $product->set_manage_stock(true);
        $product->set_status($canonical->status === 'active' ? 'publish' : 'draft');
        $product->set_description($canonical->description ?? '');

        return $product->save();
    }
}
```

### Storage de configuracion (wp_options)

```php
// En lugar de tabla propia, usar wp_options:
get_option('bsalesync_bsale_token');         // Token cifrado
get_option('bsalesync_api_key');             // API Key de licencia
get_option('bsalesync_price_list_id');
get_option('bsalesync_office_id');
get_option('bsalesync_license_jwt');
get_option('bsalesync_license_jwt_expires');
```

### Background jobs con Action Scheduler

WooCommerce incluye **Action Scheduler** — una cola de jobs persistente en BD que es mas confiable que WP-Cron para sync automatico.

```php
// Registrar sync automatico al activar el plugin
as_schedule_recurring_action(time(), HOUR_IN_SECONDS, 'bsalesync_auto_sync', ['entity' => 'products']);

// Handler del job
add_action('bsalesync_auto_sync', function(string $entity) {
    $service = BsaleSyncServiceFactory::build();
    $result  = $service->sync($entity);
    // Log result...
});
```

---

## Shopify

### Particularidades de Shopify

- El plugin es una **Shopify App** (OAuth 2.0), no un modulo instalado localmente
- La instalacion ocurre via el Shopify App Store o via URL de instalacion directa
- La API de Shopify es REST + GraphQL Admin API
- El almacenamiento de configuracion va en el backend del demonio (no en el CMS del cliente)

### Arquitectura del plugin Shopify

```
packages/cms-shopify/
├── src/
│   ├── index.ts                   ← Express/Fastify app
│   ├── auth/
│   │   └── shopify-oauth.ts       ← Flujo OAuth con Shopify
│   ├── adapters/
│   │   ├── bsale-product-adapter.ts
│   │   └── shopify-product-adapter.ts
│   ├── routes/
│   │   ├── install.ts             ← GET /install?shop=...
│   │   ├── callback.ts            ← GET /callback (OAuth)
│   │   └── sync.ts                ← POST /sync (desde bot-miki)
│   └── webhooks/
│       └── shopify-webhooks.ts    ← Recibe webhooks de Shopify
├── package.json
└── tsconfig.json
```

### Mapeo Shopify ← CanonicalProduct

```typescript
// packages/cms-shopify/src/adapters/shopify-product-adapter.ts

export function toShopifyProduct(canonical: CanonicalProduct) {
  return {
    product: {
      title:       canonical.name,
      body_html:   canonical.description ?? '',
      status:      canonical.status === 'active' ? 'active' : 'archived',
      variants: [{
        sku:             canonical.code,
        price:           (canonical.price.gross / 100).toFixed(2), // Shopify en decimales
        inventory_quantity: canonical.stock.quantity,
        inventory_management: 'shopify',
        barcode:         canonical.variants?.[0]?.barcode ?? '',
      }],
    },
  };
}

export async function upsertToShopify(shopifyClient: ShopifyAdminApi, canonical: CanonicalProduct) {
  // Buscar por SKU
  const existing = await shopifyClient.get(`/products.json?fields=id,variants&limit=1`, {
    params: { 'variants[sku]': canonical.code },
  });

  if (existing.products.length > 0) {
    return shopifyClient.put(`/products/${existing.products[0].id}.json`, toShopifyProduct(canonical));
  } else {
    return shopifyClient.post('/products.json', toShopifyProduct(canonical));
  }
}
```

---

## Jumpseller

### Particularidades de Jumpseller

- API REST con autenticacion OAuth 2.0
- Producto de Jumpseller tiene estructura similar a Shopify (mas simple)
- Plugin se implementa como app de Jumpseller registrada en su marketplace
- Base de clientes principalmente chilena y latinoamericana

### Mapeo Jumpseller ← CanonicalProduct

```typescript
export function toJumpsellerProduct(canonical: CanonicalProduct) {
  return {
    product: {
      name:        canonical.name,
      description: canonical.description ?? '',
      price:       canonical.price.gross,   // Jumpseller trabaja con precio bruto
      stock:       canonical.stock.quantity,
      sku:         canonical.code,
      status:      canonical.status === 'active' ? 'available' : 'not-available',
    },
  };
}
```

---

## Magento 2

### Particularidades de Magento 2

- El modulo se instala via Composer (`composer require kpcrop/bsalesync-magento`)
- La arquitectura de Magento usa Dependency Injection — el adapter debe seguir este patron
- Los productos en Magento son EAV (Entity-Attribute-Value) — mas complejo que PrestaShop
- El sync de imagenes requiere usar el `MediaGalleryProcessor` de Magento

### Estructura del modulo Magento 2

```
packages/cms-magento/
├── Kpcrop/
│   └── BsaleSync/
│       ├── registration.php
│       ├── etc/
│       │   ├── module.xml
│       │   └── di.xml                    ← Inyeccion de dependencias
│       ├── Model/
│       │   ├── BsaleApiClient.php
│       │   ├── LicenseClient.php
│       │   ├── ProductAdapter.php        ← Usa ProductRepository de Magento
│       │   └── SyncService.php
│       ├── Controller/Adminhtml/Sync/
│       │   └── Index.php                 ← Pantalla de sync en backoffice Magento
│       └── view/adminhtml/
│           ├── layout/
│           └── templates/
└── composer.json
```

---

## Tabla Comparativa de Implementacion

| Aspecto | PrestaShop | WooCommerce | Shopify | Jumpseller | Magento 2 |
|---|---|---|---|---|---|
| Lenguaje | PHP 7.4+ | PHP 7.4+ | Node.js/TS | Node.js/TS | PHP 7.4+ |
| Auth con CMS | N/A (modulo local) | N/A (plugin local) | OAuth 2.0 | OAuth 2.0 | N/A (modulo local) |
| Storage config | Tabla SQL propia | wp_options | bot-miki DB | bot-miki DB | Config Magento |
| Background jobs | CLI / cron servidor | Action Scheduler | bot-miki scheduler | bot-miki scheduler | Magento Cron |
| Precio | Neto (PS calcula IVA) | Neto (WC calcula IVA) | Bruto (en pesos) | Bruto (en pesos) | Neto (Magento calcula) |
| Complejidad implementacion | Media | Media | Baja | Baja | Alta |
| Reutilizacion de codigo | BsaleApiClient, LicenseClient | Mismo que PrestaShop | shared TS adapters | Reutiliza Shopify | BsaleApiClient, LicenseClient |
