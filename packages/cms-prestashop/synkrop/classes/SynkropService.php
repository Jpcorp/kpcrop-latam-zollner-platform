<?php
/**
 * Orquesta el flujo completo de sincronizacion Bsale → PrestaShop.
 * Soporta productos, precios y stock. Idempotente: usa SKU como clave.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SynkropService
{
    private $bsale;
    private $license;
    private $idShop;

    public function __construct(BsaleApiClient $bsale, LicenseClient $license, int $idShop)
    {
        $this->bsale   = $bsale;
        $this->license = $license;
        $this->idShop  = $idShop;
    }

    /**
     * #111: synkrop_log crece sin techo (un INSERT por webhook/sync/cli). No
     * requiere las dependencias de instancia (Bsale/licencia) — pensado para
     * correr desde cli/retention.php via cron del servidor.
     * @return void
     */
    public static function purgeOldLogs(int $days = 90, int $batchLimit = 5000)
    {
        Db::getInstance()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . 'synkrop_log`
             WHERE created_at < (UTC_TIMESTAMP() - INTERVAL ' . (int)$days . ' DAY)
             LIMIT ' . (int)$batchLimit
        );
    }

    /**
     * Sync quirúrgico: actualiza solo el recurso que cambió según el topic del webhook.
     * Llamado por webhook.php cuando bot-miki resuelve el recurso concreto de Bsale.
     */
    public function syncSingle(string $topic, array $bsaleData, ?int $sendTimestamp = null): SyncResult
    {
        $this->license->getToken();
        $start  = microtime(true);
        $result = new SyncResult();

        switch ($topic) {
            case 'stock':
                // #98: variant.id puede venir ausente pero con variant.href resoluble
                $variantId = $this->extractVariantId($bsaleData['variant'] ?? []);
                if (!$variantId) {
                    $stockId    = (int)($bsaleData['id'] ?? 0);
                    $officeName = $bsaleData['office']['name'] ?? null;
                    $context = $stockId
                        ? "Stock Bsale #$stockId" . ($officeName ? " (oficina: $officeName)" : '') .
                          ". Consulta /v1/stocks/$stockId.json en Bsale." .
                          " Causa más probable: ajuste de inventario manual sin variante asignada." .
                          " Si pertenece a un producto real, vincula su variante en Bsale y luego ejecuta Sync de Productos."
                        : "El payload no incluye id ni variant.id." .
                          " Revisa los logs de bot-miki para ver el recurso original que envió Bsale.";
                    $result->failed++;
                    $result->errors[] = [
                        'code'    => '0',
                        'message' => "Webhook de stock sin variant.id — payload inválido. $context",
                    ];
                    break;
                }

                // #101: sin fallback para la cantidad — si no es numerica, es un error,
                // no "0 unidades" (poner stock en 0 por error de forma sin distinguirlo).
                if (!array_key_exists('quantityAvailable', $bsaleData) || !is_numeric($bsaleData['quantityAvailable'])) {
                    $result->failed++;
                    $result->errors[] = [
                        'code'    => (string)$variantId,
                        'message' => "Webhook de stock para variante #{$variantId} sin quantityAvailable numérico — no se aplica para evitar poner stock en 0 por error.",
                    ];
                    break;
                }

                // #115: concurrency:5 en bot-miki + latencia variable de red puede
                // hacer que dos webhooks de stock de la MISMA variante se procesen
                // fuera de orden — el mas viejo llega despues y pisa al mas nuevo
                // con datos obsoletos. Se descarta (no es error) si ya se aplico
                // un evento mas reciente para esta variante.
                if ($sendTimestamp !== null && $this->isStockEventStale($variantId, $sendTimestamp)) {
                    break;
                }

                $idProduct = $this->findProductByBsaleVariantId($variantId);
                if (!$idProduct) {
                    // #114: self-healing — una variante nueva en Bsale no tiene mapa local
                    // todavia; en vez de perder el stock hasta el proximo Sync de Productos
                    // manual, resolvemos la variante y la mapeamos ahora mismo.
                    $idProduct = $this->healVariantMap($variantId);
                }
                if ($idProduct) {
                    $this->setStockDirect($idProduct, (int)$bsaleData['quantityAvailable']);
                    $result->updated = 1;
                    if ($sendTimestamp !== null) {
                        $this->recordStockEventTimestamp($variantId, $sendTimestamp);
                    }
                } else {
                    $result->failed++;
                    $result->errors[] = [
                        'code'    => (string)$variantId,
                        'message' => "Variante Bsale #{$variantId} no encontrada en el mapa local y no se pudo resolver automáticamente contra Bsale (ver /v1/variants/{$variantId}.json).",
                    ];
                }
                break;

            case 'variant':
                // expand=[product] viene inline — si no, usamos description como nombre
                $product = $bsaleData['product'] ?? [
                    'name'        => $bsaleData['description'] ?? $bsaleData['code'] ?? '',
                    'description' => '',
                    'state'       => $bsaleData['state'] ?? 1,
                ];
                $this->upsertVariant($product, $bsaleData);
                $result->updated = 1;
                break;

            case 'product':
                foreach ($bsaleData['variants']['items'] ?? [] as $variant) {
                    try {
                        $this->upsertVariant($bsaleData, $variant);
                        $result->updated++;
                    } catch (Exception $e) {
                        $result->failed++;
                        $result->errors[] = [
                            'code'    => $variant['code'] ?? '?',
                            'message' => $e->getMessage(),
                        ];
                    }
                }
                break;
        }

        $result->durationMs = (int)((microtime(true) - $start) * 1000);
        return $result;
    }

    /**
     * #109: action=delete de una variante en Bsale. bot-miki no llama a Bsale
     * (el recurso ya no existe, GET daria 404) — manda directo el resourceId.
     * Pone el stock en 0 en vez de dejar el catalogo vendiendo un SKU eliminado.
     * No desactiva el producto: puede tener otras variantes vigentes.
     */
    public function syncDelete(string $topic, string $resourceId): SyncResult
    {
        $start  = microtime(true);
        $result = new SyncResult();

        if ($topic !== 'variant') {
            $result->failed++;
            $result->errors[] = [
                'code'    => $topic,
                'message' => "syncDelete no soporta topic '{$topic}' — solo 'variant'",
            ];
            $result->durationMs = (int)((microtime(true) - $start) * 1000);
            return $result;
        }

        $variantId = (int)$resourceId;
        $idProduct = $this->findProductByBsaleVariantId($variantId);
        if ($idProduct) {
            $this->setStockDirect($idProduct, 0);
            $result->updated = 1;
        }
        // Si no estaba mapeada, no hay nada que limpiar — no es un error.

        $result->durationMs = (int)((microtime(true) - $start) * 1000);
        return $result;
    }

    /**
     * #127: horas entre syncs manuales permitidos en modo degradado (licencia
     * vencida/suspendida) — el negocio del cliente no se detiene, pero no es
     * un sustituto gratuito del auto-sync en tiempo real.
     */
    private const DEGRADED_MANUAL_SYNC_HOURS = 24;

    /**
     * Punto de entrada principal (bot-miki/webhook y cron via cli/sync.php).
     * Exige licencia activa sin excepcion — cron es sync automatico, y el
     * modo degradado de #127 solo aplica al boton manual del panel
     * (ver syncManual()), o dejaria de ser un incentivo real para renovar.
     */
    public function sync(string $entityType = 'products'): SyncResult
    {
        $this->license->getToken(); // Lanza LicenseException si no es valida
        return $this->dispatchSync($entityType);
    }

    /**
     * #127: variante de sync() para el boton "Sync ahora" del panel de admin.
     * Con licencia activa se comporta igual que sync(). Con licencia vencida
     * o suspendida (no con otros errores, p.ej. bot-miki caido — ver #128),
     * permite el sync de todos modos pero limitado a 1 vez cada
     * DEGRADED_MANUAL_SYNC_HOURS, para que el cliente pueda seguir operando
     * sin que el modo degradado reemplace gratis al auto-sync.
     */
    public function syncManual(string $entityType = 'products'): SyncResult
    {
        try {
            $this->license->getToken();
        } catch (LicenseException $e) {
            if (!$e->isExpired()) {
                throw $e; // conexion/formato invalido -> mismo comportamiento que antes
            }
            $this->assertDegradedManualSyncAllowed();
        }

        return $this->dispatchSync($entityType);
    }

    private function dispatchSync(string $entityType): SyncResult
    {
        switch ($entityType) {
            case 'products':   return $this->syncProducts();
            case 'stock':      return $this->syncStock();
            case 'prices':     return $this->syncPrices();
            case 'categories': return $this->syncCategories();
            default:           throw new InvalidArgumentException("Entidad no soportada: {$entityType}");
        }
    }

    /**
     * @throws LicenseException si ya se uso el sync manual degradado hace
     * menos de DEGRADED_MANUAL_SYNC_HOURS.
     */
    private function assertDegradedManualSyncAllowed(): void
    {
        $lastRaw = Db::getInstance()->getValue(
            'SELECT degraded_manual_sync_at FROM `' . _DB_PREFIX_ . 'synkrop_config`
             WHERE id_shop = ' . $this->idShop
        );

        if ($lastRaw) {
            $elapsedHours = (strtotime(gmdate('Y-m-d H:i:s')) - strtotime($lastRaw)) / 3600;
            if ($elapsedHours < self::DEGRADED_MANUAL_SYNC_HOURS) {
                $nextAvailable = gmdate('Y-m-d H:i', strtotime($lastRaw) + self::DEGRADED_MANUAL_SYNC_HOURS * 3600);
                throw new LicenseException(
                    'Licencia vencida o suspendida — el sync manual está limitado a 1 vez cada ' .
                    self::DEGRADED_MANUAL_SYNC_HOURS . "h mientras tanto. Próximo disponible: {$nextAvailable} UTC. " .
                    'Renueva en kpcrop.com/billing para restablecer el sync automático.',
                    402
                );
            }
        }

        Db::getInstance()->update(
            'synkrop_config',
            ['degraded_manual_sync_at' => gmdate('Y-m-d H:i:s')],
            'id_shop = ' . $this->idShop
        );
    }

    /**
     * #87: sync de categorias Bsale -> PS (modo automatico, MVP — sin vista
     * previa ni mapeo manual todavia). Crea (o reutiliza si ya existe una con
     * el mismo nombre bajo el mismo padre) una categoria PS por cada
     * product_type de Bsale, y guarda el mapeo en synkrop_category_map.
     */
    public function syncCategories(): SyncResult
    {
        $start  = microtime(true);
        $result = new SyncResult();

        $config = Db::getInstance()->getRow(
            'SELECT sync_categories, category_parent_id FROM `' . _DB_PREFIX_ . 'synkrop_config`
             WHERE id_shop = ' . $this->idShop
        );

        if (empty($config['sync_categories'])) {
            throw new RuntimeException('Sincronización de categorías desactivada. Actívala en Configuración > Synkrop.');
        }

        $parentId = (int)($config['category_parent_id'] ?? 0) ?: (int)Configuration::get('PS_HOME_CATEGORY');

        $types = $this->bsale->getAll('/v1/product_types.json');

        foreach ($types as $type) {
            $typeId = (int)($type['id'] ?? 0);
            try {
                $typeName = trim((string)($type['name'] ?? ''));
                // Mismos caracteres invalidos para PS que en upsertVariant (nombre de categoria)
                $typeName = trim(preg_replace('/[<>{};=#\\x00-\\x1F]/u', '', $typeName));
                if (!$typeId || $typeName === '') {
                    continue;
                }

                $idPsCategory = $this->resolveOrCreateCategory($typeName, $parentId);

                Db::getInstance()->execute(
                    'INSERT INTO `' . _DB_PREFIX_ . 'synkrop_category_map`
                     (id_shop, bsale_type_id, bsale_type_name, id_ps_category, active)
                     VALUES (' . $this->idShop . ', ' . $typeId . ', "' . pSQL($typeName) . '", ' . $idPsCategory . ', 1)
                     ON DUPLICATE KEY UPDATE bsale_type_name = VALUES(bsale_type_name),
                                             id_ps_category = VALUES(id_ps_category), active = 1'
                );
                $result->updated++;
            } catch (Exception $e) {
                $result->failed++;
                $result->errors[] = [
                    'code'    => (string)($typeId ?: ($type['id'] ?? '?')),
                    'message' => $e->getMessage(),
                ];
            }
        }

        $result->durationMs = (int)((microtime(true) - $start) * 1000);
        return $result;
    }

    /**
     * Reutiliza una categoria PS existente con el mismo nombre bajo el mismo
     * padre (criterio de aceptación de #87: "si ya existe, la reutiliza en
     * vez de duplicar"), o la crea si no existe.
     */
    private function resolveOrCreateCategory(string $name, int $parentId): int
    {
        $existing = Db::getInstance()->getValue(
            'SELECT cl.id_category FROM `' . _DB_PREFIX_ . 'category_lang` cl
             INNER JOIN `' . _DB_PREFIX_ . 'category` c ON c.id_category = cl.id_category
             WHERE cl.name = "' . pSQL($name) . '" AND c.id_parent = ' . $parentId
        );
        if ($existing) {
            return (int)$existing;
        }

        $defaultLangId = (int)Configuration::get('PS_LANG_DEFAULT');

        $category = new Category();
        $category->id_parent = $parentId;
        $category->active    = 1;
        $category->name[$defaultLangId]         = $name;
        $category->link_rewrite[$defaultLangId] = Tools::link_rewrite($name);

        if (!$category->add()) {
            throw new RuntimeException("No se pudo crear la categoría '{$name}' en PrestaShop");
        }

        return (int)$category->id;
    }

    /**
     * Resuelve la categoria PS de un producto segun su product_type de Bsale.
     * Sin mapeo (feature desactivada o categoria nunca sincronizada) cae al
     * comportamiento actual: PS_HOME_CATEGORY — comportamiento identico al de
     * antes de #87 (criterio de aceptación: "si sync de categorías está
     * desactivado, syncProducts() se comporta igual que hoy").
     */
    private function resolveCategoryId(?int $bsaleTypeId): int
    {
        if ($bsaleTypeId) {
            $mapped = Db::getInstance()->getValue(
                'SELECT id_ps_category FROM `' . _DB_PREFIX_ . 'synkrop_category_map`
                 WHERE id_shop = ' . $this->idShop . ' AND bsale_type_id = ' . $bsaleTypeId . ' AND active = 1'
            );
            if ($mapped) {
                return (int)$mapped;
            }
        }

        return (int)Configuration::get('PS_HOME_CATEGORY');
    }

    private function syncProducts(): SyncResult
    {
        $start  = microtime(true);
        $result = new SyncResult();

        // expand=variants trae variantes e imagenes en una sola llamada (reduce rate limit usage)
        $products = $this->bsale->getAll('/v1/products.json', [
            'expand' => '[variants,images]',
        ]);

        foreach ($products as $bsaleProduct) {
            $variants = $bsaleProduct['variants']['items'] ?? [];

            foreach ($variants as $variant) {
                try {
                    $this->upsertVariant($bsaleProduct, $variant);
                    $result->updated++;
                } catch (Exception $e) {
                    $result->failed++;
                    $result->errors[] = [
                        'code'    => $variant['code'] ?? 'unknown',
                        'message' => $e->getMessage(),
                    ];
                }
            }
        }

        $result->durationMs = (int)((microtime(true) - $start) * 1000);
        return $result;
    }

    private function upsertVariant(array $product, array $variant)    {
        $code = trim($variant['code'] ?? '');

        // Bsale/Excel puede exportar SKUs como ="BF118" (artefacto CSV para evitar que Excel
        // interprete el valor como fórmula). Extraemos el valor real.
        if (strlen($code) >= 3 && substr($code, 0, 2) === '="' && substr($code, -1) === '"') {
            $code = substr($code, 2, -1);
        }

        // PS rechaza <>={}  en product.reference — stripear cualquier caracter invalido
        $code = trim(preg_replace('/[<>={}"\'\\\\]/', '', $code));

        if (empty($code)) {
            throw new RuntimeException('Variante sin codigo SKU — no se puede sincronizar');
        }

        // #115: dos webhooks concurrentes para el mismo SKU nuevo (ej.
        // topic=product y topic=variant casi simultaneos) podian ver ambos
        // "no existe" en findProductByReference() y crear dos productos con
        // la misma referencia — PrestaShop no exige UNIQUE en ps_product.reference
        // (y no se toca esa tabla core para agregarlo: blast radius de toda la
        // tienda, no solo synkrop). Lock nombrado de MySQL, scopeado al SKU:
        // serializa el check-then-create entre procesos PHP concurrentes.
        $lockName = 'synkrop_upsert_' . md5($code . '_' . $this->idShop);
        $gotLock  = (bool)Db::getInstance()->getValue("SELECT GET_LOCK('" . pSQL($lockName) . "', 10)");
        if (!$gotLock) {
            throw new RuntimeException("No se pudo sincronizar SKU {$code}: timeout esperando a otro proceso");
        }

        try {
            // Buscar combinacion existente por SKU (campo reference en PrestaShop)
            $idProduct = $this->findProductByReference($code);

            $isNew = ($idProduct === null);
            if ($isNew) {
                $psProduct = new Product();
                $psProduct->reference = $code;
            } else {
                $psProduct = new Product($idProduct, false);
            }

            $defaultLangId = (int)Configuration::get('PS_LANG_DEFAULT');

            // PS valida nombres con Validate::isCatalogName() que rechaza <>{} y caracteres de control.
            $name = strip_tags(html_entity_decode($product['name'] ?? '', ENT_QUOTES, 'UTF-8'));
            // PS valida con /^[^<>;=#{}]*$/u — eliminar todos los caracteres que rechaza isCatalogName
            $name = trim(preg_replace('/[<>{};=#\\x00-\\x1F]/u', '', $name));
            $name = substr($name, 0, 128);
            if (empty($name)) {
                $name = $code; // fallback al SKU si el nombre viene vacio
            }

            $psProduct->name[$defaultLangId]        = $name;
            $psProduct->description[$defaultLangId] = strip_tags($product['description'] ?? '');
            // Bsale devuelve precio NETO — PrestaShop trabaja con precio neto y calcula IVA
            $psProduct->price  = (float)($variant['cost'] ?? $product['price'] ?? 0);
            // Productos nuevos siempre inactivos: el admin debe revisar precio y datos antes de publicar.
            // Productos existentes siguen el estado de Bsale (state=0 → activo, otros → inactivo).
            $psProduct->active = $isNew ? 0 : ((int)$product['state'] === 0 ? 1 : 0);

            if ($isNew) {
                // #87: si hay sync de categorias activo y mapeo para el product_type
                // de Bsale, usa esa categoria — si no, cae a PS_HOME_CATEGORY (igual
                // que antes de #87).
                $categoryId = $this->resolveCategoryId((int)($product['product_type']['id'] ?? 0) ?: null);

                // Campos obligatorios para Product::add() en PS 1.7
                $psProduct->id_category_default  = $categoryId;
                $psProduct->link_rewrite[$defaultLangId] = Tools::link_rewrite($name);
                $psProduct->id_tax_rules_group   = (int)Configuration::get('PS_TAX_DISPLAY_PREFERENCE') ?: 0;
                $psProduct->minimal_quantity     = 1;
                $psProduct->show_price           = 1;
                $psProduct->is_virtual           = 0;
                $psProduct->state                = 1;
                $psProduct->visibility           = 'both';
                $psProduct->available_for_order  = 1;
                $psProduct->condition            = 'new';

                $psProduct->add();

                // Asociar a su categoria (home si no hay mapeo — requerido para que aparezca en catálogo)
                $psProduct->addToCategories([$categoryId]);
            } else {
                $psProduct->update();
            }

            if (!$psProduct->id) {
                throw new RuntimeException('No se pudo guardar producto: ' . $code);
            }

            // Actualizar stock directamente en DB para evitar disparar hooks de ps_emailalerts
            $this->setStockDirect($psProduct->id, (int)($variant['quantity'] ?? 0));

            // Registrar mapeo Bsale ↔ PrestaShop para syncs futuros
            $this->saveProductMap($psProduct->id, $variant);
        } finally {
            Db::getInstance()->execute("SELECT RELEASE_LOCK('" . pSQL($lockName) . "')");
        }
    }

    private function setStockDirect(int $idProduct, int $quantity)    {
        $existing = (int)Db::getInstance()->getValue(
            'SELECT id_stock_available FROM `' . _DB_PREFIX_ . 'stock_available`
             WHERE id_product = ' . $idProduct . ' AND id_product_attribute = 0
             AND id_shop = ' . $this->idShop
        );

        if ($existing) {
            Db::getInstance()->update(
                'stock_available',
                ['quantity' => $quantity, 'physical_quantity' => $quantity],
                'id_stock_available = ' . $existing
            );
        } else {
            Db::getInstance()->insert('stock_available', [
                'id_product'           => $idProduct,
                'id_product_attribute' => 0,
                'id_shop'              => $this->idShop,
                'quantity'             => $quantity,
                'physical_quantity'    => $quantity,
                'reserved_quantity'    => 0,
                'depends_on_stock'     => 0,
                'out_of_stock'         => 2,
            ]);
        }
    }

    private function findProductByReference(string $reference)
    {
        $id = (int)Db::getInstance()->getValue(
            'SELECT id_product FROM `' . _DB_PREFIX_ . 'product`
             WHERE reference = "' . pSQL($reference) . '"
             AND id_shop_default = ' . $this->idShop
        );
        return $id ?: null;
    }

    private function saveProductMap(int $idProduct, array $variant)    {
        $exists = Db::getInstance()->getValue(
            'SELECT id FROM `' . _DB_PREFIX_ . 'synkrop_product_map`
             WHERE bsale_code = "' . pSQL($variant['code']) . '"
             AND id_shop = ' . $this->idShop
        );

        $data = [
            'id_product'       => $idProduct,
            'bsale_variant_id' => (int)$variant['id'],
            'bsale_code'       => pSQL($variant['code']),
            'bsale_barcode'    => pSQL($variant['barCode'] ?? ''),
            'last_synced_at'   => date('Y-m-d H:i:s'),
        ];

        if ($exists) {
            Db::getInstance()->update('synkrop_product_map', $data,
                'bsale_code = "' . pSQL($variant['code']) . '" AND id_shop = ' . $this->idShop);
        } else {
            $data['id_shop'] = $this->idShop;
            Db::getInstance()->insert('synkrop_product_map', $data);
        }
    }

    private function syncStock(): SyncResult
    {
        $start  = microtime(true);
        $result = new SyncResult();

        $stocks = $this->bsale->getAll('/v1/stocks.json');

        foreach ($stocks as $stock) {
            $variantId = null;
            try {
                // #98: mismo fallback variant.href que ya usaba el resolver de bot-miki —
                // antes esta ruta bulk salteaba en silencio (updated=0, failed=0, status
                // 'success') cualquier stock sin variant.id explicito.
                $variantId = $this->extractVariantId($stock['variant'] ?? []);
                if (!$variantId) {
                    $result->failed++;
                    $result->errors[] = ['code' => '0', 'message' => 'Stock sin variant.id ni variant.href resoluble — ver /v1/stocks.json'];
                    continue;
                }

                // #101: mismo criterio que syncSingle — cantidad no numerica es error,
                // no "0 unidades".
                if (!array_key_exists('quantityAvailable', $stock) || !is_numeric($stock['quantityAvailable'])) {
                    $result->failed++;
                    $result->errors[] = ['code' => (string)$variantId, 'message' => "quantityAvailable no numerico para variante #{$variantId}"];
                    continue;
                }

                $idProduct = $this->findProductByBsaleVariantId($variantId);
                if (!$idProduct) continue; // bulk: no self-healing aca (evitar N+1 llamadas a Bsale); usar Sync de Productos

                $this->setStockDirect($idProduct, (int)$stock['quantityAvailable']);
                $result->updated++;
            } catch (Exception $e) {
                $result->failed++;
                $result->errors[] = ['code' => (string)($variantId ?? '?'), 'message' => $e->getMessage()];
            }
        }

        $result->durationMs = (int)((microtime(true) - $start) * 1000);
        return $result;
    }

    private function findProductByBsaleVariantId(int $variantId)
    {
        $id = (int)Db::getInstance()->getValue(
            'SELECT id_product FROM `' . _DB_PREFIX_ . 'synkrop_product_map`
             WHERE bsale_variant_id = ' . $variantId . ' AND id_shop = ' . $this->idShop
        );
        return $id ?: null;
    }

    /**
     * #115: true si ya se aplico un evento de stock mas reciente para esta
     * variante — el evento actual (mas viejo, llegado tarde por
     * concurrencia/red) debe descartarse en vez de pisar el dato bueno.
     */
    private function isStockEventStale(int $variantId, int $sendTimestamp): bool
    {
        $lastApplied = Db::getInstance()->getValue(
            'SELECT last_stock_event_send FROM `' . _DB_PREFIX_ . 'synkrop_product_map`
             WHERE bsale_variant_id = ' . $variantId . ' AND id_shop = ' . $this->idShop
        );
        return $lastApplied !== null && $lastApplied !== false && (int)$lastApplied > $sendTimestamp;
    }

    /** #115: registra el timestamp del evento de stock recien aplicado para esta variante. */
    private function recordStockEventTimestamp(int $variantId, int $sendTimestamp): void
    {
        Db::getInstance()->update(
            'synkrop_product_map',
            ['last_stock_event_send' => $sendTimestamp],
            'bsale_variant_id = ' . $variantId . ' AND id_shop = ' . $this->idShop
        );
    }

    /**
     * #98: extrae el id de variante desde el objeto `variant` de un payload de stock.
     * Bsale a veces omite `variant.id` pero incluye `variant.href`
     * (".../variants/123.json") — el mismo fallback que ya aplicaba el resolver de
     * bot-miki (bsale-webhook-resolver.ts) para el path del webhook, ahora tambien
     * en el sync bulk/manual, que leia `variant.id` directo del mismo endpoint.
     */
    private function extractVariantId(array $variant): ?int
    {
        if (!empty($variant['id'])) {
            return (int)$variant['id'];
        }
        if (!empty($variant['href']) && preg_match('#/variants/(\d+)\.json#', (string)$variant['href'], $m)) {
            return (int)$m[1];
        }
        return null;
    }

    /**
     * #114: self-healing del mapa variante→producto. Si llega stock de una variante
     * que Bsale conoce pero que todavia no esta en `synkrop_product_map` (producto
     * nuevo, nunca corrio un Sync de Productos), la resolvemos ahora y la mapeamos,
     * en vez de perder el stock hasta que alguien corra el sync manual.
     * @return int|null id_product ya mapeado, o null si no se pudo resolver/crear
     */
    private function healVariantMap(int $variantId)
    {
        try {
            $variant = $this->bsale->get('/v1/variants/' . $variantId . '.json', ['expand' => '[product]']);
        } catch (Exception $e) {
            return null;
        }
        if (empty($variant['id'])) {
            return null;
        }

        $product = $variant['product'] ?? [
            'name'        => $variant['description'] ?? $variant['code'] ?? '',
            'description' => '',
            'state'       => $variant['state'] ?? 1,
        ];

        try {
            $this->upsertVariant($product, $variant);
        } catch (Exception $e) {
            return null;
        }

        return $this->findProductByBsaleVariantId($variantId);
    }

    private function syncPrices(): SyncResult
    {
        $start  = microtime(true);
        $result = new SyncResult();

        $config = Db::getInstance()->getRow(
            'SELECT bsale_price_list_id FROM `' . _DB_PREFIX_ . 'synkrop_config`
             WHERE id_shop = ' . $this->idShop
        );

        $priceListId = (int)($config['bsale_price_list_id'] ?? 0);
        if (!$priceListId) {
            throw new RuntimeException('No hay lista de precios configurada. Ve a Configuración > Synkrop.');
        }

        // Precio base: aplica a todos los clientes
        $this->applyPriceList($priceListId, 0, $result);

        // Precio por grupo de clientes (mayorista, minorista, etc.)
        $groupMaps = Db::getInstance()->executeS(
            'SELECT id_group, bsale_price_list_id
             FROM `' . _DB_PREFIX_ . 'synkrop_price_group_map`
             WHERE id_shop = ' . $this->idShop . ' AND active = 1'
        );

        foreach ($groupMaps as $map) {
            $groupResult = new SyncResult();
            $this->applyPriceList((int)$map['bsale_price_list_id'], (int)$map['id_group'], $groupResult);
            // Acumular errores de grupos en el resultado principal
            $result->errors = array_merge($result->errors, $groupResult->errors);
            $result->failed += $groupResult->failed;
        }

        $result->durationMs = (int)((microtime(true) - $start) * 1000);
        return $result;
    }

    /**
     * Descarga una lista de precios Bsale y la aplica en PrestaShop.
     * Si $idGroup = 0 actualiza el precio base del producto.
     * Si $idGroup > 0 crea un SpecificPrice para ese grupo de clientes.
     */
    private function applyPriceList(int $priceListId, int $idGroup, SyncResult $result)    {
        $details = $this->bsale->getAll("/v1/price_lists/{$priceListId}/details.json");

        foreach ($details as $detail) {
            try {
                $variantId = (int)($detail['variant']['id'] ?? 0);
                if (!$variantId) continue;

                $price = (float)($detail['variantValue'] ?? 0);
                if ($price <= 0) continue;

                $idProduct = $this->findProductByBsaleVariantId($variantId);
                if (!$idProduct) continue;

                if ($idGroup === 0) {
                    // Precio base: actualizar ps_product y ps_product_shop
                    Db::getInstance()->update(
                        'product',
                        ['price' => $price],
                        'id_product = ' . $idProduct
                    );
                    Db::getInstance()->update(
                        'product_shop',
                        ['price' => $price],
                        'id_product = ' . $idProduct . ' AND id_shop = ' . $this->idShop
                    );
                } else {
                    // Precio por grupo: upsert en ps_specific_price
                    $this->upsertSpecificPrice($idProduct, $idGroup, $price);
                }

                $result->updated++;
            } catch (Exception $e) {
                $result->failed++;
                $result->errors[] = [
                    'code'    => (string)($detail['variant']['id'] ?? '?'),
                    'message' => $e->getMessage(),
                ];
            }
        }
    }

    /**
     * Crea o actualiza un precio específico en ps_specific_price para un grupo de clientes.
     * Esto permite que mayoristas y minoristas vean precios distintos en la tienda.
     */
    private function upsertSpecificPrice(int $idProduct, int $idGroup, float $price)    {
        $existing = (int)Db::getInstance()->getValue(
            'SELECT id_specific_price FROM `' . _DB_PREFIX_ . 'specific_price`
             WHERE id_product = ' . $idProduct . '
               AND id_group = ' . $idGroup . '
               AND id_shop = ' . $this->idShop . '
               AND id_cart = 0
               AND id_product_attribute = 0
               AND id_customer = 0'
        );

        if ($existing) {
            Db::getInstance()->update(
                'specific_price',
                ['price' => $price],
                'id_specific_price = ' . $existing
            );
        } else {
            Db::getInstance()->insert('specific_price', [
                'id_product'           => $idProduct,
                'id_product_attribute' => 0,
                'id_shop'              => $this->idShop,
                'id_currency'          => 0,   // todas las monedas
                'id_country'           => 0,   // todos los países
                'id_group'             => $idGroup,
                'id_customer'          => 0,
                'id_cart'              => 0,
                'price'                => $price,
                'from_quantity'        => 1,
                'reduction'            => 0,
                'reduction_tax'        => 1,
                'reduction_type'       => 'amount',
                'from'                 => '0000-00-00 00:00:00',
                'to'                   => '0000-00-00 00:00:00',
            ]);
        }
    }
}

class SyncResult
{
    public $updated   = 0;
    public $failed    = 0;
    public $durationMs = 0;
    public $errors  = [];

    public function status(): string
    {
        if ($this->failed === 0) return 'success';
        if ($this->updated > 0) return 'partial';
        return 'failed';
    }
}
