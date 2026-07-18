<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests del flujo de ventas PS → Bsale (cola + nota de venta).
 */
class OrderDocumentServiceTest extends TestCase
{
    private OrderDocumentService $service;

    protected function setUp(): void
    {
        Db::reset();
        $this->service = new OrderDocumentService(new BsaleApiClient('token-test'), 1);
    }

    // ─── formatRut (módulo 11) ────────────────────────────────────────────────

    public function testFormatRutValido(): void
    {
        $this->assertSame('12.345.678-5', $this->service->formatRut('12345678-5'));
    }

    public function testFormatRutYaFormateado(): void
    {
        $this->assertSame('12.345.678-5', $this->service->formatRut('12.345.678-5'));
    }

    public function testFormatRutDigitoVerificadorIncorrecto(): void
    {
        $this->assertNull($this->service->formatRut('12345678-4'));
    }

    public function testFormatRutMuyCorto(): void
    {
        $this->assertNull($this->service->formatRut('1-9'));
    }

    public function testFormatRutBasura(): void
    {
        $this->assertNull($this->service->formatRut('no-es-un-rut'));
        $this->assertNull($this->service->formatRut(''));
    }

    // ─── Cola ─────────────────────────────────────────────────────────────────

    public function testEnqueueInsertaConStatusPendingYFechaUtc(): void
    {
        $this->service->enqueue(42, 99);

        $calls = Db::getInstance()->getCalls('execute');
        $this->assertCount(1, $calls);
        $sql = $calls[0]['sql'];

        $this->assertStringContainsString('INSERT IGNORE', $sql);
        $this->assertStringContainsString('synkrop_order_queue', $sql);
        $this->assertStringContainsString("'pending'", $sql);
        // gmdate: fecha UTC en formato MySQL (no CURRENT_TIMESTAMP del servidor)
        $this->assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $sql);
    }

    public function testCancelSaleNoteSinFilaDevuelveNone(): void
    {
        // Sin resultados configurados en el stub → getQueueRow devuelve false
        $this->assertSame('none', $this->service->cancelSaleNote(123));
    }

    public function testCancelSaleNotePendingMarcaCancelledSinLlamarBsale(): void
    {
        Db::getInstance()->queryResults['synkrop_order_queue'] = [
            'id_order' => 55, 'status' => 'pending', 'bsale_doc_id' => null,
        ];

        $result = $this->service->cancelSaleNote(55);

        $this->assertSame('cancelled', $result);
        $updates = Db::getInstance()->getCalls('update');
        $this->assertCount(1, $updates);
        $this->assertSame('cancelled', $updates[0]['data']['status']);
    }

    public function testCancelSaleNoteEmitidaPasaAReview(): void
    {
        Db::getInstance()->queryResults['synkrop_order_queue'] = [
            'id_order' => 56, 'status' => 'emitted', 'bsale_doc_id' => 47143,
        ];

        $result = $this->service->cancelSaleNote(56);

        $this->assertSame('review', $result);
        $updates = Db::getInstance()->getCalls('update');
        $this->assertSame('review', $updates[0]['data']['status']);
        // error_details siempre es JSON válido (MariaDB CHECK json_valid)
        $decoded = json_decode(stripslashes($updates[0]['data']['error_details']), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('message', $decoded);
    }

    // ─── skusHash (correlación nota ↔ documento emitido) ─────────────────────

    public function testSkusHashEsEstableAnteElOrden(): void
    {
        $method = new ReflectionMethod(OrderDocumentService::class, 'skusHash');
        $method->setAccessible(true);

        $a = $method->invoke($this->service, ['SKU-B', 'SKU-A', 'SKU-C']);
        $b = $method->invoke($this->service, ['SKU-C', 'SKU-A', 'SKU-B']);
        $c = $method->invoke($this->service, ['SKU-A', 'SKU-X']);

        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c);
    }
}
