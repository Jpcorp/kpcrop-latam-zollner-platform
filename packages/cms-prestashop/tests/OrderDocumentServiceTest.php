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
        Order::reset();
        Customer::reset();
        Mail::reset();
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

    // ─── notifyDocumentEmitted (#128: notificación al cliente final) ────────

    public function testNotifyDocumentEmittedEnviaMailConLosDatosCorrectos(): void
    {
        Order::$fixtures[10] = ['id_customer' => 55, 'id_lang' => 1];
        Customer::$fixtures[55] = ['email' => 'ana@test.cl', 'firstname' => 'Ana', 'lastname' => 'Soto'];

        $result = $this->service->notifyDocumentEmitted(10, 'B-1024', 'https://bsale.cl/doc/1024.pdf');

        $this->assertTrue($result);
        $this->assertCount(1, Mail::$calls);
        [$idLang, $template, $subject, $vars, $to, $toName] = Mail::$calls[0];
        $this->assertSame(1, $idLang);
        $this->assertSame('synkrop_document_emitted', $template);
        $this->assertSame('B-1024', $vars['{doc_number}']);
        $this->assertSame('https://bsale.cl/doc/1024.pdf', $vars['{doc_url}']);
        $this->assertSame('ana@test.cl', $to);
        $this->assertSame('Ana Soto', $toName);
    }

    public function testNotifyDocumentEmittedDevuelveFalseSiElPedidoNoExiste(): void
    {
        $result = $this->service->notifyDocumentEmitted(999, 'B-1', 'https://x');

        $this->assertFalse($result);
        $this->assertEmpty(Mail::$calls);
    }

    public function testNotifyDocumentEmittedDevuelveFalseSiElClienteNoTieneEmail(): void
    {
        Order::$fixtures[11] = ['id_customer' => 56];
        Customer::$fixtures[56] = ['email' => '', 'firstname' => 'Sin', 'lastname' => 'Email'];

        $result = $this->service->notifyDocumentEmitted(11, 'B-2', 'https://x');

        $this->assertFalse($result);
        $this->assertEmpty(Mail::$calls);
    }

    public function testCheckEmissionsNoBloqueaSiElEmailFalla(): void
    {
        // #128: un fallo de Mail::Send nunca debe interrumpir checkEmissions() —
        // el documento ya se emitio en Bsale, eso es lo que importa.
        Mail::$returnValue = false;

        Order::$fixtures[12] = ['id_customer' => 57];
        Customer::$fixtures[57] = ['email' => 'x@test.cl', 'firstname' => 'X', 'lastname' => 'Y'];

        $result = $this->service->notifyDocumentEmitted(12, 'B-3', 'https://x');

        // Send() devolvio false, pero el metodo no lanzo excepcion — eso es lo
        // que garantiza que checkEmissions() (que lo envuelve en try/catch) siga.
        $this->assertFalse($result);
    }
}
