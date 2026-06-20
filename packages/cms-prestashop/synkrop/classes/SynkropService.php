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
     * Sync quirúrgico: actualiza solo el recurso que cambió según el topic del webhook.
     * Llamado por webhook.php cuando bot-miki resuelve el recurso concreto de Bsale.
     */
    public function syncSingle(string $topic, array $bsaleData): SyncResult
    {
        $this->license->getToken();
        $start  = microtime(true);
        $result = new SyncResult();

        switch ($topic) {
            case 'stock':
                $variantId = (int)($bsaleData['variant']['id'] ?? 0);
                if ($variantId) {
                    $idProduct = $this->findProductByBsaleVariantId($variantId);
                    if ($idProduct) {
                        $this->setStockDirect($idProduct, (int)($bsaleData['quantityAvailable'] ?? 0));
                        $result->updated = 1;
                    }
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
     * Punto de entrada principal. Devuelve el resultado del sync.
     */
    public function sync(string $entityType = 'products'): SyncResult
    {
        $this->license->getToken(); // Lanza LicenseException si no es valida

        switch ($entityType) {
            case 'products': return $this->syncProducts();
            case 'stock':    return $this->syncStock();
            case 'prices':   return $this->syncPrices();
            default:         throw new InvalidArgumentException("Entidad no soportada: {$entityType}");
        }
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
            // Campos obligatorios para Product::add() en PS 1.7
            $psProduct->id_category_default  = (int)Configuration::get('PS_HOME_CATEGORY');
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

            // Asociar a la categoria home (requerido para que aparezca en catálogo)
            $psProduct->addToCategories([(int)Configuration::get('PS_HOME_CATEGORY')]);
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
            try {
                $variantId = $stock['variant']['id'] ?? null;
                if (!$variantId) continue;

                $idProduct = $this->findProductByBsaleVariantId((int)$variantId);
                if (!$idProduct) continue;

                $this->setStockDirect($idProduct, (int)($stock['quantityAvailable'] ?? 0));
                $result->updated++;
            } catch (Exception $e) {
                $result->failed++;
                $result->errors[] = ['code' => (string)($stock['variant']['id'] ?? '?'), 'message' => $e->getMessage()];
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
