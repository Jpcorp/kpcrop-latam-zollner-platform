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
        Configuration::reset();
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

    // ─── notifyPendingIfAny (#130: aviso de pedidos pendientes en modo automático) ───

    public function testNotifyPendingIfAnySinPendientesNoEnviaMail(): void
    {
        Db::getInstance()->queryResults['synkrop_order_queue'] = 0;

        $result = $this->service->notifyPendingIfAny();

        $this->assertSame(0, $result);
        $this->assertEmpty(Mail::$calls);
    }

    public function testNotifyPendingIfAnySinEmailDeTiendaNoEnviaMail(): void
    {
        Db::getInstance()->queryResults['synkrop_order_queue'] = 3;
        Configuration::$values['PS_SHOP_EMAIL'] = '';

        $result = $this->service->notifyPendingIfAny();

        $this->assertSame(0, $result);
        $this->assertEmpty(Mail::$calls);
    }

    public function testNotifyPendingIfAnyConPendientesEnviaUnSoloMailResumen(): void
    {
        Db::getInstance()->queryResults['synkrop_order_queue'] = 5;
        Configuration::$values['PS_SHOP_EMAIL'] = 'tienda@test.cl';
        Configuration::$values['PS_SHOP_NAME']  = 'Mi Tienda';

        $result = $this->service->notifyPendingIfAny();

        $this->assertSame(5, $result);
        $this->assertCount(1, Mail::$calls, 'Debe enviar un solo email resumen, no uno por pedido');
        [$idLang, $template, $subject, $vars, $to, $toName] = Mail::$calls[0];
        $this->assertSame('synkrop_orders_pending', $template);
        $this->assertSame(5, $vars['{count}']);
        $this->assertSame('tienda@test.cl', $to);
        $this->assertSame('Mi Tienda', $toName);
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

    // ─── checkEmissions: lock contra ejecucion concurrente (#130 Fase 2) ─────

    public function testCheckEmissionsLanzaSiNoConsigueElLock(): void
    {
        // #130: con el webhook de Bsale (topic=document) disparando checkEmissions()
        // automaticamente, dos llamadas casi simultaneas para la misma tienda ya
        // son posibles (antes solo lo disparaba un clic humano) — mismo patron de
        // lock que ya protege upsertVariant() (#115) contra el mismo problema.
        Db::getInstance()->queryResults['GET_LOCK'] = 0; // otro proceso ya esta corriendo checkEmissions

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/timeout/');
        $this->service->checkEmissions();
    }

    public function testCheckEmissionsLiberaElLockAlTerminar(): void
    {
        Db::getInstance()->queryResults['synkrop_order_queue'] = [];

        $this->service->checkEmissions();

        $releaseCalls = array_filter(
            Db::getInstance()->getCalls('execute'),
            fn($c) => strpos($c['sql'], 'RELEASE_LOCK') !== false
        );
        $this->assertNotEmpty($releaseCalls, 'Debe liberar el lock aunque no haya filas que procesar');
    }

    // ─── createSaleNote: sin stock en Bsale (stk_002) sale del reintento ─────
    // 'error' vuelve a entrar al ciclo de reintentos automatico; sin stock eso no
    // funciona nunca (el stock no reaparece solo) -> 'backorder': fuera del reintento,
    // pero regenerable a mano cuando repongan. NO 'review', que son pedidos que ya
    // tienen documento en Bsale (regenerarlos duplicaria la nota de venta).

    private function encolarPedidoValido(int $idOrder): void
    {
        $db = Db::getInstance();
        $db->queryResults['synkrop_order_queue'] = ['id_order' => $idOrder, 'status' => 'pending'];
        $db->queryResults['synkrop_config'] = ['bsale_office_id' => 1, 'sale_doc_type_id' => 9];

        Order::$fixtures[$idOrder] = [
            'id_customer' => 5, 'id_cart' => 9, 'id_address_invoice' => 3,
            'products' => [[
                'product_name'        => 'Producto X',
                'product_reference'   => 'SKU-1',
                'product_quantity'    => 2,
                'unit_price_tax_excl' => 1000.0,
            ]],
        ];
    }

    /** Cliente Bsale que rechaza el POST con el body real de la API */
    private function bsaleQueRechaza(string $body): BsaleApiClient
    {
        return new class ('token-test', $body) extends BsaleApiClient {
            private $body;
            public function __construct(string $token, string $body)
            {
                parent::__construct($token);
                $this->body = $body;
            }
            public function post(string $path, array $data): array
            {
                throw new BsaleApiException(400, $this->body);
            }
        };
    }

    public function testCreateSaleNoteSinStockQuedaEnBackorderYNoEnError(): void
    {
        $this->encolarPedidoValido(77);
        $bsale = $this->bsaleQueRechaza(
            '{"error":"There is no stock for this products: SKU-1","errorCode":"stk_002"}'
        );

        $result = (new OrderDocumentService($bsale, 1))->createSaleNote(77);

        $this->assertFalse($result['ok']);
        $updates = Db::getInstance()->getCalls('update');
        $this->assertNotEmpty($updates);
        $this->assertSame(
            OrderDocumentService::STATUS_BACKORDER,
            $updates[0]['data']['status'],
            'Sin stock no se reintenta solo: debe quedar en backorder, no en error'
        );

        // error_details siempre JSON valido (MariaDB CHECK json_valid) y en español
        $decoded = json_decode(stripslashes($updates[0]['data']['error_details']), true);
        $this->assertIsArray($decoded);
        $this->assertStringContainsString('stock', strtolower($decoded['message']));
    }

    public function testCreateSaleNoteOtroErrorDeBsaleSigueEnErrorParaReintentar(): void
    {
        $this->encolarPedidoValido(78);
        $bsale = $this->bsaleQueRechaza('{"error":"Client is required","errorCode":"cli_001"}');

        $result = (new OrderDocumentService($bsale, 1))->createSaleNote(78);

        $this->assertFalse($result['ok']);
        $updates = Db::getInstance()->getCalls('update');
        $this->assertSame(
            OrderDocumentService::STATUS_ERROR,
            $updates[0]['data']['status'],
            'Un error reintentable debe seguir en error'
        );
    }

    public function testBsaleApiExceptionExponeElErrorCode(): void
    {
        // El body se trunca a 500 chars para el log: el errorCode se extrae antes.
        $relleno = str_repeat('x', 600);
        $e = new BsaleApiException(400, '{"error":"' . $relleno . '","errorCode":"stk_002"}');

        $this->assertSame('stk_002', $e->getErrorCode());
        $this->assertSame('', (new BsaleApiException(500, 'Internal Server Error'))->getErrorCode());
    }

    public function testCreateSaleNoteRegeneraUnBackorderCuandoReponenStock(): void
    {
        // El pedido quedo en backorder; el operador repone stock en Bsale y aprieta
        // "Generar". Antes esto era imposible: createSaleNote() salia temprano.
        $this->encolarPedidoValido(79);
        Db::getInstance()->queryResults['synkrop_order_queue'] = ['id_order' => 79, 'status' => 'backorder'];

        $bsale = new class ('token-test') extends BsaleApiClient {
            public function post(string $path, array $data): array
            {
                return ['id' => 5001, 'number' => 123, 'totalAmount' => 2380];
            }
            public function get(string $path, array $params = []): array
            {
                return ['items' => [['variant' => ['code' => 'SKU-1']]]];
            }
        };

        $result = (new OrderDocumentService($bsale, 1))->createSaleNote(79);

        $this->assertTrue($result['ok'], $result['message']);
        $updates = Db::getInstance()->getCalls('update');
        $this->assertNotEmpty($updates, 'Debe generar la nota, no salir temprano');
        $this->assertSame(OrderDocumentService::STATUS_GENERATED, $updates[0]['data']['status']);
        $this->assertSame(5001, $updates[0]['data']['bsale_doc_id']);
    }

    public function testCreateSaleNoteNoRegeneraUnReviewParaNoDuplicarDocumento(): void
    {
        // 'review' = ya hay documento en Bsale (anulacion fallida / cancelado con boleta
        // emitida). Regenerarlo crearia una nota de venta duplicada.
        $this->encolarPedidoValido(80);
        Db::getInstance()->queryResults['synkrop_order_queue'] = ['id_order' => 80, 'status' => 'review'];

        $bsale = new class ('token-test') extends BsaleApiClient {
            public function post(string $path, array $data): array
            {
                throw new LogicException('No debe llamarse a Bsale para un pedido en review');
            }
        };

        $result = (new OrderDocumentService($bsale, 1))->createSaleNote(80);

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('ya procesado', $result['message']);
        $this->assertEmpty(Db::getInstance()->getCalls('update'), 'No debe tocar la fila');
    }
}
