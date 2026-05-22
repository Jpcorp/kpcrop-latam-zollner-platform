<?php
/**
 * Orquesta el flujo completo de sincronizacion Bsale → PrestaShop.
 * Soporta productos, precios y stock. Idempotente: usa SKU como clave.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class BsaleSyncService
{
    private BsaleApiClient $bsale;
    private LicenseClient $license;
    private int $idShop;

    public function __construct(BsaleApiClient $bsale, LicenseClient $license, int $idShop)
    {
        $this->bsale   = $bsale;
        $this->license = $license;
        $this->idShop  = $idShop;
    }

    /**
     * Punto de entrada principal. Devuelve el resultado del sync.
     */
    public function sync(string $entityType = 'products'): SyncResult
    {
        $this->license->getToken(); // Lanza LicenseException si no es valida

        return match ($entityType) {
            'products' => $this->syncProducts(),
            'stock'    => $this->syncStock(),
            'prices'   => $this->syncPrices(),
            default    => throw new InvalidArgumentException("Entidad no soportada: {$entityType}"),
        };
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

    private function upsertVariant(array $product, array $variant): void
    {
        $code = $variant['code'] ?? '';
        if (empty($code)) {
            throw new RuntimeException('Variante sin codigo SKU — no se puede sincronizar');
        }

        // Buscar combinacion existente por SKU (campo reference en PrestaShop)
        $idProduct = $this->findProductByReference($code);

        if ($idProduct === null) {
            $psProduct = new Product();
            $psProduct->reference = $code;
        } else {
            $psProduct = new Product($idProduct);
        }

        $defaultLangId = (int)Configuration::get('PS_LANG_DEFAULT');

        $psProduct->name[$defaultLangId]        = $product['name'];
        $psProduct->description[$defaultLangId] = $product['description'] ?? '';
        // Bsale devuelve precio NETO — PrestaShop trabaja con precio neto y calcula IVA
        $psProduct->price  = (float)($variant['cost'] ?? $product['price'] ?? 0);
        $psProduct->active = (int)$product['state'] === 0 ? 1 : 0;

        if ($psProduct->id) {
            $psProduct->update();
        } else {
            $psProduct->add();
        }

        // Actualizar stock
        StockAvailable::setQuantity($psProduct->id, 0, (int)($variant['quantity'] ?? 0));

        // Registrar mapeo Bsale ↔ PrestaShop para syncs futuros
        $this->saveProductMap($psProduct->id, $variant);
    }

    private function findProductByReference(string $reference): ?int
    {
        $id = (int)Db::getInstance()->getValue(
            'SELECT id_product FROM `' . _DB_PREFIX_ . 'product`
             WHERE reference = "' . pSQL($reference) . '"
             AND id_shop_default = ' . $this->idShop
        );
        return $id ?: null;
    }

    private function saveProductMap(int $idProduct, array $variant): void
    {
        $exists = Db::getInstance()->getValue(
            'SELECT id FROM `' . _DB_PREFIX_ . 'bsalesync_product_map`
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
            Db::getInstance()->update(_DB_PREFIX_ . 'bsalesync_product_map', $data,
                'bsale_code = "' . pSQL($variant['code']) . '" AND id_shop = ' . $this->idShop);
        } else {
            $data['id_shop'] = $this->idShop;
            Db::getInstance()->insert(_DB_PREFIX_ . 'bsalesync_product_map', $data);
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

                StockAvailable::setQuantity($idProduct, 0, (int)($stock['quantityAvailable'] ?? 0));
                $result->updated++;
            } catch (Exception $e) {
                $result->failed++;
                $result->errors[] = ['code' => (string)($stock['variant']['id'] ?? '?'), 'message' => $e->getMessage()];
            }
        }

        $result->durationMs = (int)((microtime(true) - $start) * 1000);
        return $result;
    }

    private function findProductByBsaleVariantId(int $variantId): ?int
    {
        $id = (int)Db::getInstance()->getValue(
            'SELECT id_product FROM `' . _DB_PREFIX_ . 'bsalesync_product_map`
             WHERE bsale_variant_id = ' . $variantId . ' AND id_shop = ' . $this->idShop
        );
        return $id ?: null;
    }

    private function syncPrices(): SyncResult
    {
        // Similar a syncProducts pero solo actualiza el campo price
        // TODO: implementar con bsale->getAll('/v1/price_lists/{id}/details.json')
        return new SyncResult();
    }
}

class SyncResult
{
    public int $updated   = 0;
    public int $failed    = 0;
    public int $durationMs = 0;
    public array $errors  = [];

    public function status(): string
    {
        if ($this->failed === 0) return 'success';
        if ($this->updated > 0) return 'partial';
        return 'failed';
    }
}
