<?php
/**
 * Flujo de ventas PS → Bsale: cola de pedidos y notas de venta.
 *
 * Una sola cola (synkrop_order_queue), dos motores:
 *  - Semi-manual: botones [Generar] / [Verificar emisiones] en el panel admin.
 *  - Automático (Fase 2): bot-miki empuja la misma cola vía webhook.php.
 *
 * La emisión tributaria (boleta/factura) SIEMPRE la decide un usuario en Bsale.
 * La nota de venta RESERVA stock en Bsale (validado en sandbox 17-jul-2026);
 * el descuento real ocurre cuando el usuario Bsale emite el documento.
 *
 * Ciclo: pending → generated → emitted → closed  (+ error / review / cancelled)
 *
 * #127: a proposito, esta clase NO recibe LicenseClient y no valida licencia en
 * ningun metodo. Es una decision de diseno, no un descuido: la emision de
 * boleta/factura es parte de la operacion legal/comercial real del cliente, no
 * un "extra" — si se corta junto con el sync de catalogo cuando la licencia
 * vence, el negocio del cliente queda sin poder emitir sus documentos
 * tributarios, que es exactamente lo que el modo degradado busca evitar. Ver
 * la politica completa en el issue #127. No agregar un chequeo de licencia
 * aca sin revisar esa decision primero.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class OrderDocumentService
{
    const STATUS_PENDING   = 'pending';
    const STATUS_GENERATED = 'generated';
    const STATUS_EMITTED   = 'emitted';
    const STATUS_CLOSED    = 'closed';
    const STATUS_ERROR     = 'error';
    const STATUS_REVIEW    = 'review';
    const STATUS_CANCELLED = 'cancelled';

    /** RUT genérico de consumidor final (SII Chile) para compradores sin RUT válido */
    const GENERIC_RUT = '66.666.666-6';

    private $bsale;
    private $idShop;
    private $config;

    public function __construct(BsaleApiClient $bsale, int $idShop)
    {
        $this->bsale  = $bsale;
        $this->idShop = $idShop;
    }

    // ─── Cola ─────────────────────────────────────────────────────────────────

    /**
     * Encola un pedido para generar su nota de venta. Idempotente (UNIQUE id_shop+id_order).
     * No llama a Bsale: es seguro invocarlo desde el hook de cambio de estado.
     */
    public function enqueue(int $idOrder, int $idCart): bool
    {
        $now = gmdate('Y-m-d H:i:s');
        return Db::getInstance()->execute(
            'INSERT IGNORE INTO `' . _DB_PREFIX_ . 'synkrop_order_queue`
             (`id_shop`, `id_order`, `id_cart`, `status`, `created_at`, `updated_at`)
             VALUES (' . (int)$this->idShop . ', ' . (int)$idOrder . ', ' . (int)$idCart . ",
             '" . self::STATUS_PENDING . "', '" . pSQL($now) . "', '" . pSQL($now) . "')"
        );
    }

    public function getQueueRow(int $idOrder)
    {
        return Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'synkrop_order_queue`
             WHERE id_shop = ' . (int)$this->idShop . ' AND id_order = ' . (int)$idOrder
        );
    }

    // ─── Generación de la nota de venta ──────────────────────────────────────

    /**
     * Crea la nota de venta en Bsale para un pedido encolado.
     * Compartida por el modo semi-manual (botón admin) y el automático (bot-miki).
     *
     * @return array ['ok' => bool, 'message' => string]
     */
    public function createSaleNote(int $idOrder): array
    {
        $row = $this->getQueueRow($idOrder);
        if (!$row) {
            $order = new Order($idOrder);
            if (!Validate::isLoadedObject($order)) {
                return ['ok' => false, 'message' => 'Pedido ' . $idOrder . ' no existe'];
            }
            $this->enqueue($idOrder, (int)$order->id_cart);
            $row = $this->getQueueRow($idOrder);
        }

        // Idempotencia: solo se genera desde pending o reintentando un error
        if (!in_array($row['status'], [self::STATUS_PENDING, self::STATUS_ERROR], true)) {
            return ['ok' => true, 'message' => 'Pedido ' . $idOrder . ' ya procesado (status: ' . $row['status'] . ')'];
        }

        try {
            $order   = new Order($idOrder);
            $payload = $this->buildPayload($order);
            $resp    = $this->bsale->post('/v1/documents.json', $payload);

            if (empty($resp['id'])) {
                throw new RuntimeException('Respuesta de Bsale sin id de documento: ' . json_encode($resp));
            }

            // Hash desde los detalles del documento YA creado en Bsale — la misma
            // fuente que lee checkEmissions(). Con los codes del payload el hash
            // divergía: Bsale auto-crea variante (con code propio) para la línea
            // de envío enviada sin code, y el doc emitido la hereda.
            $skus = [];
            try {
                $created = $this->bsale->get('/v1/documents/' . (int)$resp['id'] . '/details.json', ['limit' => 50]);
                foreach ($created['items'] ?? [] as $item) {
                    $skus[] = (string)($item['variant']['code'] ?? '');
                }
                $skus = array_filter($skus);
            } catch (Exception $e) {
                foreach ($payload['details'] as $detail) {
                    if (isset($detail['code'])) {
                        $skus[] = $detail['code'];
                    }
                }
            }

            $this->updateRow($idOrder, [
                'status'           => self::STATUS_GENERATED,
                'bsale_doc_id'     => (int)$resp['id'],
                'bsale_doc_number' => pSQL((string)($resp['number'] ?? '')),
                'bsale_doc_url'    => pSQL((string)($resp['urlPdf'] ?? $resp['urlPdfOriginal'] ?? '')),
                'client_code'      => pSQL($payload['client']['code']),
                'total_amount'     => (float)($resp['totalAmount'] ?? 0),
                'skus_hash'        => pSQL($this->skusHash($skus)),
                'error_details'    => null,
            ]);

            return ['ok' => true, 'message' => 'Nota de venta N°' . ($resp['number'] ?? '?') . ' creada para pedido ' . $idOrder];
        } catch (Exception $e) {
            $this->updateRow($idOrder, [
                'status'        => self::STATUS_ERROR,
                // JSON válido siempre — MariaDB con CHECK json_valid rechaza '' (lección prod)
                'error_details' => pSQL(json_encode(['message' => $e->getMessage()])),
            ]);
            return ['ok' => false, 'message' => 'Error en pedido ' . $idOrder . ': ' . $e->getMessage()];
        }
    }

    /**
     * Arma el payload de la nota de venta desde el pedido PS.
     * Precios NETOS: Bsale agrega el IVA solo (validado en sandbox).
     */
    private function buildPayload(Order $order): array
    {
        $config  = $this->loadConfig();
        $officeId = (int)($config['bsale_office_id'] ?? 0);
        if (!$officeId) {
            throw new RuntimeException('Configura la sucursal Bsale (ID de sucursal) antes de generar documentos');
        }

        $vatRate  = (float)($config['order_vat_rate'] ?? 19.00);
        $products = $order->getProducts();
        if (empty($products)) {
            throw new RuntimeException('El pedido no tiene productos');
        }

        $details       = [];
        $totalTaxIncl  = 0.0;
        foreach ($products as $product) {
            if (!empty($product['cache_is_pack'])) {
                // v1 no soporta packs: mejor un error claro que un prorrateo frágil (lección del legado)
                throw new RuntimeException('El pedido contiene un pack ("' . $product['product_name'] . '") — genera el documento manualmente en Bsale');
            }
            $reference = trim((string)($product['product_reference'] ?? ''));
            if ($reference === '') {
                throw new RuntimeException('El producto "' . $product['product_name'] . '" no tiene referencia (SKU) — Bsale no puede identificar la variante');
            }

            $netUnit = isset($product['unit_price_tax_excl'])
                ? (float)$product['unit_price_tax_excl']
                : (float)$product['product_price'];
            if ($netUnit <= 0) {
                continue; // Bsale rechaza precios <= 0 (regalos/promos se omiten)
            }

            $qty = (int)$product['product_quantity'];
            $details[] = [
                'code'         => $reference,
                'quantity'     => $qty,
                'netUnitValue' => round($netUnit, 4),
            ];
            $totalTaxIncl += $netUnit * (1 + $vatRate / 100) * $qty;
        }

        if (empty($details)) {
            throw new RuntimeException('Ningún producto del pedido es facturable (todos con precio 0 o sin SKU)');
        }

        // Descuentos del carro: prorrateo proporcional sobre cada línea
        $discounts = (float)$order->total_discounts_tax_incl;
        if ($discounts > 0 && $totalTaxIncl > 0) {
            $factor = max(0, 1 - $discounts / $totalTaxIncl);
            foreach ($details as &$detail) {
                $detail['netUnitValue'] = round($detail['netUnitValue'] * $factor, 4);
            }
            unset($detail);
        }

        // Envío: con SKU configurado reutiliza esa variante de servicio en Bsale
        // (despacho propio o externalizado — lo decide el cliente en la config);
        // sin SKU, Bsale auto-crea una variante nueva por cada documento
        $shippingNet = (float)$order->total_shipping_tax_excl;
        if ($shippingNet > 0) {
            $line = [
                'quantity'     => 1,
                'netUnitValue' => round($shippingNet, 4),
            ];
            $shippingSku = trim((string)($config['shipping_sku'] ?? ''));
            if ($shippingSku !== '') {
                $line['code'] = $shippingSku;
            } else {
                $line['comment'] = 'Costo de despacho';
            }
            $details[] = $line;
        }

        $now = time();
        return [
            'documentTypeId' => $this->resolveSaleDocTypeId(),
            'officeId'       => $officeId,
            'emissionDate'   => $now,
            'expirationDate' => $now,
            'client'         => $this->buildClient($order),
            'details'        => $details,
        ];
    }

    /**
     * Cliente para la nota de venta (obligatorio en Bsale — error cli_001 si falta).
     * Mezcla la dirección de facturación PS con la solicitud de factura del checkout si existe.
     */
    private function buildClient(Order $order): array
    {
        $customer = new Customer((int)$order->id_customer);
        $address  = new Address((int)$order->id_address_invoice);

        $invoiceReq = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'synkrop_invoice_request`
             WHERE id_cart = ' . (int)$order->id_cart
        );

        $rut = $this->formatRut($invoiceReq['rut'] ?? ($address->dni ?? ''));
        if ($rut === null) {
            $rut = self::GENERIC_RUT;
        }

        $client = [
            'code'         => $rut,
            'firstName'    => (string)$customer->firstname,
            'lastName'     => (string)$customer->lastname,
            'email'        => (string)$customer->email,
            'address'      => trim($address->address1 . ' ' . $address->address2),
            'city'         => (string)$address->city,
            'municipality' => (string)($address->city ?: 'N/A'),
            'activity'     => 'Particular',
        ];

        // Si pidió factura en el checkout, se anexan los datos tributarios (el usuario
        // Bsale los verá al decidir si emite boleta o factura)
        if ($invoiceReq) {
            $client['company']      = (string)$invoiceReq['razon_social'];
            $client['activity']     = (string)($invoiceReq['giro'] ?: 'Particular');
            $client['municipality'] = (string)($invoiceReq['comuna'] ?: $client['municipality']);
            if (!empty($invoiceReq['ciudad'])) {
                $client['city'] = (string)$invoiceReq['ciudad'];
            }
            if (!empty($invoiceReq['direccion'])) {
                $client['address'] = (string)$invoiceReq['direccion'];
            }
        }

        return $client;
    }

    /**
     * Descubre el documentTypeId de la "Nota de venta" por nombre y lo cachea en config.
     * Los IDs varían por cuenta Bsale — nunca hardcodear (lección del legado).
     */
    public function resolveSaleDocTypeId(): int
    {
        $config = $this->loadConfig();
        if (!empty($config['sale_doc_type_id'])) {
            return (int)$config['sale_doc_type_id'];
        }

        $types = $this->bsale->getAll('/v1/document_types.json');
        foreach ($types as $type) {
            $name = mb_strtolower((string)($type['name'] ?? ''));
            $isElectronic = (int)($type['isElectronicDocument'] ?? 0) === 1;
            $codeSii = trim((string)($type['codeSii'] ?? ''));
            if (!$isElectronic && $codeSii === '' && strpos($name, 'nota') !== false && strpos($name, 'venta') !== false) {
                Db::getInstance()->update(
                    'synkrop_config',
                    ['sale_doc_type_id' => (int)$type['id']],
                    'id_shop = ' . (int)$this->idShop
                );
                $this->config = null; // invalida cache local
                return (int)$type['id'];
            }
        }

        throw new RuntimeException('No se encontró el tipo de documento "Nota de venta" en la cuenta Bsale — créalo o configura sale_doc_type_id manualmente');
    }

    // ─── Verificación de emisiones (cierre del ciclo) ─────────────────────────

    /**
     * Busca, para cada nota de venta generada, el documento tributario emitido en Bsale.
     * Correlación por cliente + total + SKUs (la API no expone referencias entre la nota
     * y el documento emitido — validado en sandbox 17-jul-2026).
     *
     * @return array Resumen ['checked' => n, 'emitted' => n, 'closed' => n]
     */
    public function checkEmissions(): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'synkrop_order_queue`
             WHERE id_shop = ' . (int)$this->idShop . "
             AND status = '" . self::STATUS_GENERATED . "'"
        ) ?: [];

        $summary = ['checked' => 0, 'emitted' => 0, 'closed' => 0];

        foreach ($rows as $row) {
            $summary['checked']++;
            $emitted = $this->findEmittedDocument($row);
            if ($emitted === null) {
                continue;
            }

            $this->updateRow((int)$row['id_order'], [
                'status'             => self::STATUS_EMITTED,
                'emitted_doc_id'     => (int)$emitted['id'],
                'emitted_doc_number' => pSQL((string)($emitted['number'] ?? '')),
                'emitted_doc_url'    => pSQL((string)($emitted['urlPdf'] ?? '')),
                'emitted_doc_type'   => pSQL((string)($emitted['type_name'] ?? '')),
            ]);
            $summary['emitted']++;

            if ($this->closeOrder((int)$row['id_order'])) {
                $this->updateRow((int)$row['id_order'], ['status' => self::STATUS_CLOSED]);
                $summary['closed']++;
            }
        }

        return $summary;
    }

    /**
     * Busca el documento tributario que corresponde a la nota de venta de la fila.
     * @return array|null ['id','number','urlPdf','type_name'] o null si aún no se emite
     */
    private function findEmittedDocument(array $row)
    {
        $noteId = (int)$row['bsale_doc_id'];
        if (!$noteId) {
            return null;
        }

        // El cliente del doc emitido es el mismo de la nota (validado en sandbox)
        $note = $this->bsale->get('/v1/documents/' . $noteId . '.json', ['expand' => '[client]']);
        $clientId = (int)($note['client']['id'] ?? 0);
        if (!$clientId) {
            return null;
        }

        $docs = $this->bsale->get('/v1/documents.json', [
            'clientid' => $clientId,
            'expand'   => '[document_type,details]',
            'limit'    => 50,
        ]);

        foreach ($docs['items'] ?? [] as $doc) {
            if ((int)$doc['id'] <= $noteId) {
                continue; // solo documentos posteriores a la nota
            }
            $type    = $doc['document_type'] ?? [];
            $codeSii = trim((string)($type['codeSii'] ?? ''));
            $isSaleNote = stripos((string)($type['name'] ?? ''), 'nota venta') !== false
                || stripos((string)($type['name'] ?? ''), 'nota de venta') !== false;
            if ($codeSii === '') {
                // Sin modo prueba: solo cuenta como emision un documento tributario
                // (boleta/factura electronica). Con modo prueba (sandbox/staging sin
                // SII): se acepta cualquier documento manual que NO sea otra nota de
                // venta, para poder validar el cierre sin boleta electronica real.
                $testMode = (int)($this->loadConfig()['test_mode'] ?? 0);
                if (!$testMode || $isSaleNote) {
                    continue;
                }
            }
            if (round((float)$doc['totalAmount'], 2) !== round((float)$row['total_amount'], 2)) {
                continue;
            }
            $skus = [];
            foreach (($doc['details']['items'] ?? []) as $item) {
                $skus[] = (string)($item['variant']['code'] ?? '');
            }
            if ($this->skusHash(array_filter($skus)) !== $row['skus_hash']) {
                continue;
            }
            return [
                'id'        => (int)$doc['id'],
                'number'    => $doc['number'] ?? '',
                'urlPdf'    => $doc['urlPdf'] ?? ($doc['urlPdfOriginal'] ?? ''),
                'type_name' => $type['name'] ?? '',
            ];
        }

        return null;
    }

    /**
     * Pasa el pedido PS al estado "Documentado en Bsale" (gatilla el flujo de despacho).
     */
    private function closeOrder(int $idOrder): bool
    {
        $idState = (int)Configuration::get('SYNKROP_OS_DOCUMENTED');
        if (!$idState) {
            return false;
        }
        $order = new Order($idOrder);
        if (!Validate::isLoadedObject($order) || (int)$order->getCurrentState() === $idState) {
            return false;
        }
        $order->setCurrentState($idState);
        return true;
    }

    // ─── Cancelación ──────────────────────────────────────────────────────────

    /**
     * Pedido cancelado en PS. Doble método (decisión 17-jul-2026):
     * 1º intenta anular la nota vía API; si Bsale no lo permite → status 'review'
     * para decisión humana en el panel (continuidad operacional siempre).
     */
    public function cancelSaleNote(int $idOrder): string
    {
        $row = $this->getQueueRow($idOrder);
        if (!$row) {
            return 'none';
        }

        switch ($row['status']) {
            case self::STATUS_PENDING:
            case self::STATUS_ERROR:
                // Aún no existe documento en Bsale: basta marcar la fila
                $this->updateRow($idOrder, ['status' => self::STATUS_CANCELLED]);
                return self::STATUS_CANCELLED;

            case self::STATUS_GENERATED:
                try {
                    // Bsale exige officeId (camelCase) en el DELETE — sin él responde 400
                    $officeId = (int)($this->loadConfig()['bsale_office_id'] ?? 0);
                    $this->bsale->delete('/v1/documents/' . (int)$row['bsale_doc_id'] . '.json?officeId=' . $officeId);
                    $this->updateRow($idOrder, ['status' => self::STATUS_CANCELLED]);
                    return self::STATUS_CANCELLED;
                } catch (Exception $e) {
                    $this->updateRow($idOrder, [
                        'status'        => self::STATUS_REVIEW,
                        'error_details' => pSQL(json_encode([
                            'message' => 'Pedido cancelado en PS pero la nota de venta no pudo anularse vía API — anular manualmente en Bsale',
                            'api'     => $e->getMessage(),
                        ])),
                    ]);
                    return self::STATUS_REVIEW;
                }

            case self::STATUS_EMITTED:
            case self::STATUS_CLOSED:
                // Ya hay documento tributario: la nota de crédito es SIEMPRE decisión humana
                $this->updateRow($idOrder, [
                    'status'        => self::STATUS_REVIEW,
                    'error_details' => pSQL(json_encode([
                        'message' => 'Pedido cancelado en PS con documento tributario ya emitido — evaluar nota de crédito en Bsale',
                    ])),
                ]);
                return self::STATUS_REVIEW;
        }

        return $row['status'];
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function loadConfig(): array
    {
        if ($this->config === null) {
            $this->config = Db::getInstance()->getRow(
                'SELECT * FROM `' . _DB_PREFIX_ . 'synkrop_config` WHERE id_shop = ' . (int)$this->idShop
            ) ?: [];
        }
        return $this->config;
    }

    private function updateRow(int $idOrder, array $data): void
    {
        $data['updated_at'] = pSQL(gmdate('Y-m-d H:i:s'));
        Db::getInstance()->update(
            'synkrop_order_queue',
            $data,
            'id_shop = ' . (int)$this->idShop . ' AND id_order = ' . (int)$idOrder,
            0,
            true // permite NULL (error_details)
        );
    }

    /** Hash estable del conjunto de SKUs (correlación nota ↔ documento emitido) */
    private function skusHash(array $skus): string
    {
        sort($skus, SORT_STRING);
        return sha1(implode('|', $skus));
    }

    /**
     * Valida (módulo 11) y formatea un RUT chileno como XX.XXX.XXX-D.
     * @return string|null null si el RUT es inválido
     */
    public static function formatRut(string $rut)
    {
        $clean = preg_replace('/[^0-9kK]/', '', $rut);
        if (strlen($clean) < 8 || strlen($clean) > 9) {
            return null;
        }

        $dv   = strtoupper(substr($clean, -1));
        $num  = substr($clean, 0, -1);
        if (!ctype_digit($num) || (int)$num <= 0) {
            return null;
        }

        $sum = 0;
        $mul = 2;
        for ($i = strlen($num) - 1; $i >= 0; $i--) {
            $sum += (int)$num[$i] * $mul;
            $mul = $mul === 7 ? 2 : $mul + 1;
        }
        $expected = 11 - ($sum % 11);
        $expected = $expected === 11 ? '0' : ($expected === 10 ? 'K' : (string)$expected);

        if ($dv !== $expected) {
            return null;
        }

        return number_format((float)$num, 0, '', '.') . '-' . $dv;
    }
}
