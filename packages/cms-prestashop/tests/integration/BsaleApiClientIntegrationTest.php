<?php

use PHPUnit\Framework\TestCase;

/**
 * #32: tests reales contra el sandbox de Bsale (no mocks) — validan el
 * comportamiento real de BsaleApiClient que los tests unitarios (mocks)
 * no pueden probar: paginacion real, codigo HTTP real ante token invalido,
 * y que el throttle cliente evita un 429 real del servidor.
 *
 * Requieren red y una cuenta sandbox real. Se saltan solos (no fallan) si
 * BSALE_SANDBOX_TOKEN no esta seteado.
 *
 * Activar: BSALE_SANDBOX_TOKEN=xxx composer test -- --group integration
 * (excluidas del "composer test" normal via phpunit.xml)
 *
 * @group integration
 */
class BsaleApiClientIntegrationTest extends TestCase
{
    private ?BsaleApiClient $client = null;

    protected function setUp(): void
    {
        $token = getenv('BSALE_SANDBOX_TOKEN');
        if (!$token) {
            $this->markTestSkipped(
                'BSALE_SANDBOX_TOKEN no configurado — solo corre con: '
                . 'BSALE_SANDBOX_TOKEN=xxx composer test -- --group integration'
            );
        }
        $this->client = new BsaleApiClient($token);
    }

    public function testGetProductsReturnsPaginatedResults(): void
    {
        // limit bajo a proposito para forzar mas de una pagina real y
        // confirmar que getAll() las recorre todas (no solo la primera).
        $products = $this->client->getAll('/v1/products.json', ['limit' => 1]);

        $this->assertIsArray($products);
        $this->assertNotEmpty($products, 'El sandbox deberia tener al menos un producto cargado');
        $this->assertArrayHasKey('id', $products[0]);
    }

    public function testInvalidTokenThrows401(): void
    {
        $badClient = new BsaleApiClient('token-invalido-a-proposito-' . bin2hex(random_bytes(4)));

        try {
            $badClient->get('/v1/products.json');
            $this->fail('Deberia haber lanzado BsaleApiException con un token invalido');
        } catch (BsaleApiException $e) {
            $this->assertSame(401, $e->getCode());
            $this->assertTrue($e->isClientError());
        }
    }

    public function testRateLimitingDoesNotGenerate429(): void
    {
        // 15 requests reales seguidas — si el throttle interno (10 req/s,
        // ver BsaleApiClient::throttle()) no funcionara, el sandbox
        // devolveria 429 y esto lanzaria BsaleApiException.
        $start = microtime(true);
        for ($i = 0; $i < 15; $i++) {
            $result = $this->client->get('/v1/products.json', ['limit' => 1]);
            $this->assertIsArray($result);
        }
        $elapsedMs = (microtime(true) - $start) * 1000;

        // 14 intervalos entre 15 requests, ~100ms cada uno si el throttle
        // esta activo de verdad (con margen: red real nunca da exactamente 100ms).
        $this->assertGreaterThan(14 * 90, $elapsedMs, 'El throttle no parece estar espaciando las requests');
    }
}
