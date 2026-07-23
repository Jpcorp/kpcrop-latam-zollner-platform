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

    // ─── syncStock: fallback variant.href (#98) ───────────────────────────────

    public function test_syncStock_bulk_falls_back_to_variant_href_when_id_missing(): void
    {
        $db = Db::getInstance();
        $db->queryResults['synkrop_product_map'] = 42; // variante 1001 ya mapeada al producto 42

        $this->bsaleMock = $this->createMock(BsaleApiClient::class);
        $this->bsaleMock->method('getAll')
            ->willReturn([
                [
                    'quantityAvailable' => 15,
                    'variant' => ['href' => 'https://api.bsale.io/v1/variants/1001.json'], // sin id
                ],
            ]);

        $service = new SynkropService($this->bsaleMock, $this->licenseMock, $this->idShop);
        $result  = $service->sync('stock');

        $this->assertEquals(1, $result->updated);
        $this->assertEquals(0, $result->failed);

        $stockUpdate = array_values(array_filter(
            $db->getCalls('update'),
            fn($c) => $c['table'] === 'stock_available'
        ))[0] ?? ($db->getCalls('insert')[0] ?? null);
        $this->assertNotNull($stockUpdate);
    }

    public function test_syncStock_bulk_marks_failed_when_variant_unresolvable(): void
    {
        $this->bsaleMock = $this->createMock(BsaleApiClient::class);
        $this->bsaleMock->method('getAll')
            ->willReturn([
                ['quantityAvailable' => 5, 'variant' => []], // ni id ni href — antes esto se salteaba en silencio
            ]);

        $service = new SynkropService($this->bsaleMock, $this->licenseMock, $this->idShop);
        $result  = $service->sync('stock');

        $this->assertEquals(0, $result->updated);
        $this->assertEquals(1, $result->failed, 'Antes del fix #98 esto era updated=0/failed=0 (status success) sin haber actualizado nada');
    }

    // ─── syncStock/syncSingle: quantityAvailable sin validar (#101) ───────────

    public function test_syncStock_bulk_marks_failed_when_quantityAvailable_missing(): void
    {
        $db = Db::getInstance();
        $db->queryResults['synkrop_product_map'] = 42;

        $this->bsaleMock = $this->createMock(BsaleApiClient::class);
        $this->bsaleMock->method('getAll')
            ->willReturn([
                ['variant' => ['id' => 1001]], // sin quantityAvailable
            ]);

        $service = new SynkropService($this->bsaleMock, $this->licenseMock, $this->idShop);
        $result  = $service->sync('stock');

        $this->assertEquals(0, $result->updated);
        $this->assertEquals(1, $result->failed);
        $this->assertEmpty($db->getCalls('update'), 'No debe tocar stock_available con una cantidad invalida');
        $this->assertEmpty($db->getCalls('insert'));
    }

    public function test_syncSingle_stock_marks_failed_when_quantityAvailable_not_numeric(): void
    {
        $db = Db::getInstance();
        $db->queryResults['synkrop_product_map'] = 42;

        $this->bsaleMock = $this->createMock(BsaleApiClient::class);

        $service = new SynkropService($this->bsaleMock, $this->licenseMock, $this->idShop);
        $result  = $service->syncSingle('stock', [
            'variant' => ['id' => 1001],
            'quantityAvailable' => null, // shape distinta de la esperada -> antes caia a 0 en silencio
        ]);

        $this->assertEquals(0, $result->updated);
        $this->assertEquals(1, $result->failed);
        $this->assertEmpty($db->getCalls('update'), 'No debe poner stock en 0 por una cantidad invalida');
    }

    public function test_syncSingle_stock_applies_valid_zero_quantity(): void
    {
        // Cero es un valor legitimo (sin stock) — no debe confundirse con "invalido".
        $db = Db::getInstance();
        $db->queryResults['synkrop_product_map'] = 42;

        $this->bsaleMock = $this->createMock(BsaleApiClient::class);

        $service = new SynkropService($this->bsaleMock, $this->licenseMock, $this->idShop);
        $result  = $service->syncSingle('stock', [
            'variant' => ['id' => 1001],
            'quantityAvailable' => 0,
        ]);

        $this->assertEquals(1, $result->updated);
        $this->assertEquals(0, $result->failed);
    }

    // ─── syncSingle stock: self-healing del mapa (#114) ───────────────────────

    public function test_syncSingle_stock_self_heals_unmapped_variant(): void
    {
        $db = Db::getInstance();
        $variantMapCalls = 0;
        $db->queryResults['synkrop_product_map'] = function (string $sql) use (&$variantMapCalls) {
            if (strpos($sql, 'bsale_variant_id') !== false) {
                $variantMapCalls++;
                // 1ra consulta (antes de sanar): no mapeada. Desde la 2da (tras el
                // upsert dentro de healVariantMap): ya mapeada al producto 55.
                return $variantMapCalls === 1 ? null : 55;
            }
            // Consulta de existencia por bsale_code dentro de saveProductMap: no existe -> INSERT.
            return null;
        };
        $db->queryResults['`ps_product`'] = null; // findProductByReference: producto nuevo

        $this->bsaleMock = $this->createMock(BsaleApiClient::class);
        $this->bsaleMock->method('get')
            ->with('/v1/variants/1001.json', ['expand' => '[product]'])
            ->willReturn([
                'id'       => 1001,
                'code'     => 'SKU-NUEVO-1001',
                'quantity' => 10,
                'product'  => ['name' => 'Producto Nuevo', 'description' => '', 'state' => 0],
            ]);

        $service = new SynkropService($this->bsaleMock, $this->licenseMock, $this->idShop);
        $result  = $service->syncSingle('stock', [
            'variant'           => ['id' => 1001],
            'quantityAvailable' => 7,
        ]);

        $this->assertEquals(1, $result->updated);
        $this->assertEquals(0, $result->failed);
        $this->assertNotEmpty(Product::$added, 'Debe crear el producto nuevo al sanar el mapa');

        $mapInsert = array_values(array_filter(
            $db->getCalls('insert'),
            fn($c) => $c['table'] === 'synkrop_product_map'
        ));
        $this->assertNotEmpty($mapInsert, 'Debe registrar el mapeo variante->producto recien sanado');
        $this->assertEquals(1001, $mapInsert[0]['data']['bsale_variant_id']);
    }

    public function test_syncSingle_stock_reports_error_when_heal_fails(): void
    {
        $db = Db::getInstance();
        $db->queryResults['synkrop_product_map'] = null; // nunca mapeada

        $this->bsaleMock = $this->createMock(BsaleApiClient::class);
        $this->bsaleMock->method('get')
            ->willThrowException(new BsaleApiException(404, 'variant not found'));

        $service = new SynkropService($this->bsaleMock, $this->licenseMock, $this->idShop);
        $result  = $service->syncSingle('stock', [
            'variant'           => ['id' => 9999],
            'quantityAvailable' => 3,
        ]);

        $this->assertEquals(0, $result->updated);
        $this->assertEquals(1, $result->failed);
        $this->assertStringContainsString('no se pudo resolver', $result->errors[0]['message']);
        $this->assertEmpty(Product::$added, 'No debe crear productos cuando la variante no existe en Bsale');
    }

    // ─── syncDelete: action=delete de variante (#109) ─────────────────────────

    public function test_syncDelete_sets_stock_zero_for_mapped_variant(): void
    {
        $db = Db::getInstance();
        $db->queryResults['synkrop_product_map'] = 42; // variante 9506 mapeada al producto 42
        $db->queryResults['stock_available'] = 7; // ya existe fila de stock -> toma la rama UPDATE

        $this->bsaleMock = $this->createMock(BsaleApiClient::class);
        $service = new SynkropService($this->bsaleMock, $this->licenseMock, $this->idShop);

        $result = $service->syncDelete('variant', '9506');

        $this->assertEquals(1, $result->updated);
        $this->assertEquals(0, $result->failed);

        $stockCall = array_values(array_filter(
            $db->getCalls('update'),
            fn($c) => $c['table'] === 'stock_available'
        ))[0] ?? null;
        $this->assertNotNull($stockCall, 'Debe actualizar stock_available');
        $this->assertEquals(0, $stockCall['data']['quantity']);
    }

    public function test_syncDelete_noop_success_for_unmapped_variant(): void
    {
        $db = Db::getInstance();
        $db->queryResults['synkrop_product_map'] = null; // nunca estuvo mapeada

        $this->bsaleMock = $this->createMock(BsaleApiClient::class);
        $service = new SynkropService($this->bsaleMock, $this->licenseMock, $this->idShop);

        $result = $service->syncDelete('variant', '9999');

        $this->assertEquals(0, $result->updated);
        $this->assertEquals(0, $result->failed, 'No mapeada no es un error — no habia nada que limpiar');
        $this->assertEmpty($db->getCalls('update'));
    }

    public function test_syncDelete_fails_for_unsupported_topic(): void
    {
        $this->bsaleMock = $this->createMock(BsaleApiClient::class);
        $service = new SynkropService($this->bsaleMock, $this->licenseMock, $this->idShop);

        $result = $service->syncDelete('product', '123');

        $this->assertEquals(0, $result->updated);
        $this->assertEquals(1, $result->failed);
    }

    // ─── purgeOldLogs: retencion de synkrop_log (#111) ────────────────────────

    public function test_purgeOldLogs_deletes_with_default_90_days(): void
    {
        $db = Db::getInstance();

        SynkropService::purgeOldLogs();

        $calls = $db->getCalls('execute');
        $this->assertCount(1, $calls);
        $this->assertStringContainsString('DELETE FROM', $calls[0]['sql']);
        $this->assertStringContainsString('synkrop_log', $calls[0]['sql']);
        $this->assertStringContainsString('INTERVAL 90 DAY', $calls[0]['sql']);
        $this->assertStringContainsString('LIMIT 5000', $calls[0]['sql']);
    }

    public function test_purgeOldLogs_respects_custom_days_and_batch_limit(): void
    {
        $db = Db::getInstance();

        SynkropService::purgeOldLogs(30, 1000);

        $calls = $db->getCalls('execute');
        $this->assertStringContainsString('INTERVAL 30 DAY', $calls[0]['sql']);
        $this->assertStringContainsString('LIMIT 1000', $calls[0]['sql']);
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

    // ─── upsertVariant: lock de sincronizacion por SKU (#115) ─────────────────

    public function test_syncSingle_variant_libera_el_lock_al_terminar(): void
    {
        $db = Db::getInstance();
        $db->queryResults['`ps_product`'] = null; // producto nuevo

        $this->bsaleMock = $this->createMock(BsaleApiClient::class);
        $service = new SynkropService($this->bsaleMock, $this->licenseMock, $this->idShop);

        $result = $service->syncSingle('variant', [
            'id' => 1001, 'code' => 'SKU-LOCK-1', 'quantity' => 5, 'state' => 0,
        ]);

        $this->assertEquals(1, $result->updated);

        $releaseCalls = array_values(array_filter(
            $db->getCalls('execute'),
            fn($c) => strpos($c['sql'], 'RELEASE_LOCK') !== false
        ));
        $this->assertNotEmpty($releaseCalls, 'Debe liberar el lock al terminar');
    }

    public function test_syncSingle_variant_falla_si_no_consigue_el_lock(): void
    {
        $db = Db::getInstance();
        $db->queryResults['GET_LOCK'] = 0; // otro proceso ya tiene el lock de este SKU

        $this->bsaleMock = $this->createMock(BsaleApiClient::class);
        $service = new SynkropService($this->bsaleMock, $this->licenseMock, $this->idShop);

        try {
            $service->syncSingle('variant', [
                'id' => 1001, 'code' => 'SKU-LOCK-2', 'quantity' => 5, 'state' => 0,
            ]);
            $this->fail('Deberia haber lanzado RuntimeException por no conseguir el lock');
        } catch (RuntimeException $e) {
            $this->assertMatchesRegularExpression('/no se pudo sincronizar/i', $e->getMessage());
        }

        $this->assertEmpty(Product::$added, 'No debe crear el producto si no consiguio el lock');
    }

    // ─── syncSingle stock: orden de eventos por variante (#115) ───────────────
    // Dos webhooks de stock para la MISMA variante pueden llegar y procesarse
    // fuera de orden (concurrency:5 en bot-miki + latencia de red variable).
    // Sin esto, el evento mas viejo puede pisar al mas nuevo con datos obsoletos.

    private function mockProductMapWithLastSend($db, int $idProduct, $lastSend)
    {
        $db->queryResults['synkrop_product_map'] = function (string $sql) use ($idProduct, $lastSend) {
            if (strpos($sql, 'last_stock_event_send') !== false) {
                return $lastSend;
            }
            if (strpos($sql, 'bsale_variant_id') !== false) {
                return $idProduct;
            }
            return null; // exists-check de saveProductMap por bsale_code, no aplica aca
        };
    }

    public function test_syncSingle_stock_sin_sendTimestamp_aplica_como_antes(): void
    {
        // Retrocompatibilidad: si no viene el timestamp (otros llamadores que
        // todavia no lo pasan), el comportamiento es identico al de siempre.
        $db = Db::getInstance();
        $this->mockProductMapWithLastSend($db, 42, null);

        $this->bsaleMock = $this->createMock(BsaleApiClient::class);
        $service = new SynkropService($this->bsaleMock, $this->licenseMock, $this->idShop);

        $result = $service->syncSingle('stock', [
            'variant' => ['id' => 1001], 'quantityAvailable' => 7,
        ]);

        $this->assertEquals(1, $result->updated);
    }

    public function test_syncSingle_stock_aplica_evento_mas_nuevo_y_registra_el_timestamp(): void
    {
        $db = Db::getInstance();
        $this->mockProductMapWithLastSend($db, 42, null); // nunca se aplico nada todavia

        $this->bsaleMock = $this->createMock(BsaleApiClient::class);
        $service = new SynkropService($this->bsaleMock, $this->licenseMock, $this->idShop);

        $result = $service->syncSingle('stock', [
            'variant' => ['id' => 1001], 'quantityAvailable' => 7,
        ], 1_700_000_000);

        $this->assertEquals(1, $result->updated);

        $updateCall = array_values(array_filter(
            $db->getCalls('update'),
            fn($c) => $c['table'] === 'synkrop_product_map' && array_key_exists('last_stock_event_send', $c['data'])
        ));
        $this->assertNotEmpty($updateCall, 'Debe registrar el timestamp del evento aplicado');
        $this->assertEquals(1_700_000_000, $updateCall[0]['data']['last_stock_event_send']);
    }

    public function test_syncSingle_stock_descarta_evento_mas_viejo_que_el_ya_aplicado(): void
    {
        $db = Db::getInstance();
        // Ya se aplico un evento con send=1_700_000_500 (mas nuevo)
        $this->mockProductMapWithLastSend($db, 42, 1_700_000_500);

        $this->bsaleMock = $this->createMock(BsaleApiClient::class);
        $service = new SynkropService($this->bsaleMock, $this->licenseMock, $this->idShop);

        // Este evento llega DESPUES (por red/concurrencia) pero es de un
        // momento ANTERIOR (send mas chico) -> debe descartarse.
        $result = $service->syncSingle('stock', [
            'variant' => ['id' => 1001], 'quantityAvailable' => 999,
        ], 1_700_000_000);

        $this->assertEquals(0, $result->updated);
        $this->assertEquals(0, $result->failed, 'Descartar un evento obsoleto no es un error');

        $stockUpdate = array_values(array_filter(
            $db->getCalls('update'),
            fn($c) => $c['table'] === 'stock_available'
        ));
        $this->assertEmpty($stockUpdate, 'No debe tocar el stock con un evento mas viejo que el ya aplicado');
    }

    public function test_syncSingle_stock_aplica_evento_mas_nuevo_que_el_ya_aplicado(): void
    {
        $db = Db::getInstance();
        $db->queryResults['stock_available'] = 5; // ya existe fila de stock -> UPDATE
        $this->mockProductMapWithLastSend($db, 42, 1_700_000_000);

        $this->bsaleMock = $this->createMock(BsaleApiClient::class);
        $service = new SynkropService($this->bsaleMock, $this->licenseMock, $this->idShop);

        $result = $service->syncSingle('stock', [
            'variant' => ['id' => 1001], 'quantityAvailable' => 3,
        ], 1_700_000_500); // mas nuevo que el ya aplicado

        $this->assertEquals(1, $result->updated);
    }
}
