<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests unitarios para SynkropService.
 * Verifica: syncPrices (precio base + por grupo), idempotencia del upsert,
 * y manejo de fallos parciales.
 */
class SynkropServiceTest extends TestCase
{
    private BsaleApiClient $bsaleMock;
    private LicenseClient $licenseMock;
    private int $idShop = 1;

    protected function setUp(): void
    {
        Db::reset();
        StockAvailable::reset();
        Product::reset();

        $this->licenseMock = $this->createMock(LicenseClient::class);
        $this->licenseMock->method('getToken')->willReturn('mock.jwt.token');
    }

    // ─── syncPrices: precio base ──────────────────────────────────────────────

    public function test_syncPrices_updates_base_price_in_ps_product(): void
    {
        $db = Db::getInstance();
        $db->queryResults['synkrop_config'] = ['bsale_price_list_id' => 1];
        // No group maps
        $db->queryResults['synkrop_price_group_map'] = [];
        // Variant 1001 maps to product 5
        $db->queryResults['synkrop_product_map'] = 5;

        $this->bsaleMock = $this->createMock(BsaleApiClient::class);
        $this->bsaleMock->method('getAll')
            ->willReturn([
                ['variantValue' => 19990.0, 'variant' => ['id' => 1001]],
            ]);

        $service = new SynkropService($this->bsaleMock, $this->licenseMock, $this->idShop);
        $result  = $service->sync('prices');

        $this->assertEquals('success', $result->status());
        $this->assertEquals(1, $result->updated);
        $this->assertEquals(0, $result->failed);

        // Debe haber actualizado ps_product y ps_product_shop
        $updateCalls = $db->getCalls('update');
        $tables      = array_column($updateCalls, 'table');
        $this->assertContains('product', $tables, 'Debe actualizar ps_product');
        $this->assertContains('product_shop', $tables, 'Debe actualizar ps_product_shop');
    }

    public function test_syncPrices_price_value_is_correct(): void
    {
        $db = Db::getInstance();
        $db->queryResults['synkrop_config']          = ['bsale_price_list_id' => 1];
        $db->queryResults['synkrop_price_group_map'] = [];
        $db->queryResults['synkrop_product_map']     = 10;

        $this->bsaleMock = $this->createMock(BsaleApiClient::class);
        $this->bsaleMock->method('getAll')
            ->willReturn([
                ['variantValue' => 35500.0, 'variant' => ['id' => 9999]],
            ]);

        $service = new SynkropService($this->bsaleMock, $this->licenseMock, $this->idShop);
        $service->sync('prices');

        $productUpdate = array_values(array_filter(
            Db::getInstance()->getCalls('update'),
            fn($c) => $c['table'] === 'product'
        ))[0] ?? null;

        $this->assertNotNull($productUpdate);
        $this->assertEquals(35500.0, $productUpdate['data']['price']);
    }

    // ─── syncPrices: precios por grupo ────────────────────────────────────────

    public function test_syncPrices_creates_specific_price_for_group(): void
    {
        $db = Db::getInstance();
        $db->queryResults['synkrop_config']          = ['bsale_price_list_id' => 1];
        $db->queryResults['synkrop_price_group_map'] = [
            ['id_group' => 2, 'bsale_price_list_id' => 2],
        ];
        $db->queryResults['synkrop_product_map'] = 7;
        // Simula que no existe specific_price previo → insert
        $db->queryResults['specific_price'] = null;

        $this->bsaleMock = $this->createMock(BsaleApiClient::class);
        $this->bsaleMock->method('getAll')
            ->willReturn([
                ['variantValue' => 15000.0, 'variant' => ['id' => 1001]],
            ]);

        $service = new SynkropService($this->bsaleMock, $this->licenseMock, $this->idShop);
        $service->sync('prices');

        $insertCalls = array_values(array_filter(
            $db->getCalls('insert'),
            fn($c) => $c['table'] === 'specific_price'
        ));

        $this->assertNotEmpty($insertCalls, 'Debe insertar en ps_specific_price para el grupo');
        $this->assertEquals(15000.0, $insertCalls[0]['data']['price']);
        $this->assertEquals(2, $insertCalls[0]['data']['id_group']);
    }

    public function test_syncPrices_updates_existing_specific_price_idempotent(): void
    {
        $db = Db::getInstance();
        $db->queryResults['synkrop_config']          = ['bsale_price_list_id' => 1];
        $db->queryResults['synkrop_price_group_map'] = [
            ['id_group' => 2, 'bsale_price_list_id' => 2],
        ];
        $db->queryResults['synkrop_product_map'] = 7;
        // Simula que YA existe specific_price con id 99
        $db->queryResults['specific_price'] = 99;

        $this->bsaleMock = $this->createMock(BsaleApiClient::class);
        $this->bsaleMock->method('getAll')
            ->willReturn([
                ['variantValue' => 12000.0, 'variant' => ['id' => 1001]],
            ]);

        $service = new SynkropService($this->bsaleMock, $this->licenseMock, $this->idShop);
        $service->sync('prices');

        // Idempotencia: update, NO insert
        $db = Db::getInstance();
        $inserts = array_filter($db->getCalls('insert'), fn($c) => $c['table'] === 'specific_price');
        $updates = array_filter($db->getCalls('update'), fn($c) => $c['table'] === 'specific_price');

        $this->assertEmpty($inserts, 'No debe insertar si ya existe specific_price');
        $this->assertNotEmpty($updates, 'Debe actualizar el precio existente');
    }

    // ─── syncPrices: config faltante ─────────────────────────────────────────

    public function test_syncPrices_throws_when_price_list_not_configured(): void
    {
        $db = Db::getInstance();
        $db->queryResults['synkrop_config'] = ['bsale_price_list_id' => null];

        $this->bsaleMock = $this->createMock(BsaleApiClient::class);

        $service = new SynkropService($this->bsaleMock, $this->licenseMock, $this->idShop);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/lista de precios/i');

        $service->sync('prices');
    }

    // ─── syncPrices: fallo parcial ────────────────────────────────────────────

    public function test_syncPrices_counts_skipped_variants_without_product_map(): void
    {
        $db = Db::getInstance();
        $db->queryResults['synkrop_config']          = ['bsale_price_list_id' => 1];
        $db->queryResults['synkrop_price_group_map'] = [];
        // Variant no mapeada → findProductByBsaleVariantId devuelve null
        $db->queryResults['synkrop_product_map']     = null;

        $this->bsaleMock = $this->createMock(BsaleApiClient::class);
        $this->bsaleMock->method('getAll')
            ->willReturn([
                ['variantValue' => 9999.0, 'variant' => ['id' => 5555]],
                ['variantValue' => 8888.0, 'variant' => ['id' => 6666]],
            ]);

        $service = new SynkropService($this->bsaleMock, $this->licenseMock, $this->idShop);
        $result  = $service->sync('prices');

        // updated=0 porque no hay productos mapeados, pero tampoco falla
        $this->assertEquals(0, $result->updated);
        $this->assertEquals(0, $result->failed);
    }

    // ─── sync entidad inválida ────────────────────────────────────────────────

    public function test_sync_throws_on_unknown_entity(): void
    {
        $this->bsaleMock = $this->createMock(BsaleApiClient::class);
        $service = new SynkropService($this->bsaleMock, $this->licenseMock, $this->idShop);

        $this->expectException(InvalidArgumentException::class);
        $service->sync('orders'); // no soportado
    }

    // ─── LicenseException propaga hacia fuera ─────────────────────────────────

    public function test_sync_propagates_LicenseException_from_getToken(): void
    {
        $this->licenseMock = $this->createMock(LicenseClient::class);
        $this->licenseMock->method('getToken')
            ->willThrowException(new LicenseException('Licencia suspendida', 402));

        $this->bsaleMock = $this->createMock(BsaleApiClient::class);
        $service = new SynkropService($this->bsaleMock, $this->licenseMock, $this->idShop);

        $this->expectException(LicenseException::class);
        $service->sync('prices');
    }

    // ─── SyncResult ──────────────────────────────────────────────────────────

    public function test_SyncResult_status_success_when_no_failures(): void
    {
        $result = new SyncResult();
        $result->updated = 5;
        $this->assertEquals('success', $result->status());
    }

    public function test_SyncResult_status_partial_when_some_updated_some_failed(): void
    {
        $result = new SyncResult();
        $result->updated = 3;
        $result->failed  = 2;
        $this->assertEquals('partial', $result->status());
    }

    public function test_SyncResult_status_failed_when_all_failed(): void
    {
        $result = new SyncResult();
        $result->updated = 0;
        $result->failed  = 4;
        $this->assertEquals('failed', $result->status());
    }
}
