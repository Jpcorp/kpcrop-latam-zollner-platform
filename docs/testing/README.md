# Estrategia de Testing

---

## Principios

1. **Tests de integracion > tests unitarios** para los adapters CMS — el adapter correcto es el que produce el resultado correcto en PrestaShop real, no el que pasa un mock
2. **Sandbox de Bsale como fuente de verdad** — los fixtures de test reflejan respuestas reales del sandbox
3. **Idempotencia es testeable** — cada test de sync debe ejecutarse dos veces y producir el mismo resultado

---

## Capas de Testing

### Capa 1 — Unitarios (sin dependencias externas)

**Que testear:**
- `CanonicalProduct` — validaciones del modelo
- `BsaleProductAdapter` — mapeo de respuesta Bsale al modelo canonico
- `PrestashopProductAdapter` — mapeo de CanonicalProduct al formato de PrestaShop
- `LicenseClient` — logica de cache del JWT
- Logica de idempotencia (generacion de claves, deduplicacion)

**Ubicacion:**
```
packages/shared/src/__tests__/canonical-product.test.ts
packages/cms-prestashop/tests/unit/BsaleProductAdapterTest.php
packages/cms-prestashop/tests/unit/PrestashopProductAdapterTest.php
packages/bot-miki/src/__tests__/license.test.ts
```

**Ejemplo — test del adapter (PHP):**
```php
class BsaleProductAdapterTest extends PHPUnit\Framework\TestCase
{
    public function testMapsBsaleResponseToCanonicalProduct(): void
    {
        $bsaleResponse = json_decode(
            file_get_contents(__DIR__ . '/fixtures/bsale-product-with-variants.json'),
            true
        );

        $adapter  = new BsaleProductAdapter();
        $products = $adapter->fromBsaleResponse($bsaleResponse);

        $this->assertNotEmpty($products);
        $this->assertEquals('SKU-001', $products[0]->code);
        $this->assertEquals(10000, $products[0]->priceNet);     // Precio neto
        $this->assertEquals(11900, $products[0]->priceGross);   // Con IVA 19%
        $this->assertEquals('active', $products[0]->status);
    }

    public function testUpsertIsIdempotent(): void
    {
        // Ejecutar dos veces con el mismo producto — debe existir un solo registro
        $canonical = $this->buildCanonicalProduct('SKU-TEST-001');
        $adapter   = new PrestashopProductAdapter(1, 1);
        $adapter->upsert($canonical);
        $adapter->upsert($canonical);

        $count = Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product` WHERE reference = "SKU-TEST-001"'
        );
        $this->assertEquals(1, $count);
    }
}
```

---

### Capa 2 — Integracion con Bsale Sandbox

**Que testear:**
- `BsaleApiClient` — que los endpoints devuelven la estructura esperada
- Paginacion — que `getAll()` recorre todas las paginas correctamente
- Rate limiting — que el throttle funciona y no genera 429
- Manejo de errores — que un 401 lanza `BsaleApiException` correctamente

**Requisito:** Token del sandbox Bsale en `.env.test`

```bash
# packages/cms-prestashop/.env.test
BSALE_SANDBOX_TOKEN=tu_token_del_sandbox
```

**Ejemplo:**
```php
class BsaleApiClientIntegrationTest extends PHPUnit\Framework\TestCase
{
    private BsaleApiClient $client;

    protected function setUp(): void
    {
        $token = getenv('BSALE_SANDBOX_TOKEN');
        if (!$token) $this->markTestSkipped('Sin token de sandbox Bsale');
        $this->client = new BsaleApiClient($token);
    }

    public function testGetProductsReturnsPaginatedResults(): void
    {
        $products = $this->client->getAll('/v1/products.json', ['expand' => '[variants]']);
        $this->assertIsArray($products);
        // El sandbox deberia tener al menos 1 producto
        $this->assertNotEmpty($products);
        $this->assertArrayHasKey('id', $products[0]);
        $this->assertArrayHasKey('name', $products[0]);
    }

    public function testInvalidTokenThrows401(): void
    {
        $client = new BsaleApiClient('token_invalido');
        $this->expectException(BsaleApiException::class);
        $client->get('/v1/products.json');
    }
}
```

---

### Capa 3 — End-to-End del flujo de licencias

**Que testear:**
- `GET /v1/license/token` con API Key valida devuelve JWT
- `GET /v1/license/token` con licencia suspendida devuelve 402
- El JWT expira correctamente despues de 5 minutos
- El plugin puede validar el JWT sin llamar al demonio (cache local)

**Ejemplo (Node.js con vitest):**
```typescript
// packages/bot-miki/src/__tests__/license-route.test.ts
import { describe, it, expect } from 'vitest';
import { Queue } from 'bullmq';
import IORedis from 'ioredis';
import { buildApp } from '../app.js';

describe('GET /v1/license/token', () => {
  const redis = new IORedis(process.env.REDIS_URL!, { maxRetriesPerRequest: null });
  const queue = new Queue('sync', { connection: redis });
  const app   = buildApp(queue);

  it('devuelve JWT para licencia activa', async () => {
    const res = await app.inject({
      method: 'GET',
      url: '/v1/license/token?tenantId=dev-tenant-001',
      headers: { 'x-api-key': 'kp_dev_api_key_para_desarrollo_local_no_usar_en_prod' },
    });
    expect(res.statusCode).toBe(200);
    const body = res.json();
    expect(body.token).toBeDefined();
    expect(body.plan).toBe('agency');
  });

  it('devuelve 402 para licencia suspendida', async () => {
    // Insertar licencia suspendida en BD de test primero
    const res = await app.inject({
      method: 'GET',
      url: '/v1/license/token?tenantId=suspended-tenant',
      headers: { 'x-api-key': 'kp_suspended_key' },
    });
    expect(res.statusCode).toBe(402);
  });
});
```

---

## Fixtures de Bsale

Guardar respuestas reales del sandbox como fixtures para tests unitarios:

```
packages/cms-prestashop/tests/fixtures/
├── bsale-product-simple.json         ← Producto sin variantes
├── bsale-product-with-variants.json  ← Producto con 3 variantes (talla/color)
├── bsale-product-inactive.json       ← Producto desactivado (state: 1)
├── bsale-stock-response.json         ← Respuesta de /v1/stocks.json
└── bsale-price-list-response.json    ← Respuesta de /v1/price_lists/1/details.json
```

Para generar los fixtures con datos reales del sandbox:
```bash
curl -H "access_token: $BSALE_SANDBOX_TOKEN" \
     "https://api.bsale.io/v1/products.json?expand=[variants,images]&limit=3" \
     | jq . > tests/fixtures/bsale-product-with-variants.json
```

---

## Cobertura Minima para Hacer Merge

| Componente | Minimo |
|---|---|
| `packages/shared` — modelos y adapters | 80% |
| `packages/bot-miki` — rutas criticas (licencia, webhook) | 70% |
| `packages/cms-prestashop` — adapters Bsale y PrestaShop | 70% |
| `packages/cms-prestashop` — BsaleSyncService | 60% |

---

## Comandos de Test

```bash
# Todos los tests (TypeScript + PHP)
pnpm test

# Solo shared
pnpm --filter @kpcrop/shared test

# Solo bot-miki
pnpm --filter bot-miki test

# Solo PrestaShop (requiere PHP 8.1+)
cd packages/cms-prestashop && composer test

# Tests de integracion con Bsale sandbox (requiere token)
BSALE_SANDBOX_TOKEN=xxx cd packages/cms-prestashop && composer test -- --group integration
```
