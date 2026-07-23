<?php
/**
 * Controller del panel de sincronizacion en el backoffice de PrestaShop.
 * Maneja la pantalla de sync manual y las llamadas AJAX del front-end del backoffice.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'synkrop/synkrop.php';
require_once _PS_MODULE_DIR_ . 'synkrop/classes/BsaleApiClient.php';
require_once _PS_MODULE_DIR_ . 'synkrop/classes/LicenseClient.php';
require_once _PS_MODULE_DIR_ . 'synkrop/classes/SynkropService.php';
require_once _PS_MODULE_DIR_ . 'synkrop/classes/OrderDocumentService.php';

class AdminSynkropController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
        $this->meta_title = $this->l('Synkrop');
    }

    public function initContent()    {
        parent::initContent();

        $config = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'synkrop_config`
             WHERE id_shop = ' . (int)$this->context->shop->id
        );

        $logs = Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'synkrop_log`
             WHERE id_shop = ' . (int)$this->context->shop->id . '
             ORDER BY created_at DESC LIMIT 20'
        );

        $isConfigured = !empty($config['bsale_api_token']) && !empty($config['daemon_api_key']);

        // #127: banner persistente de estado de licencia — no bloquea el panel
        // (siempre visible, historial y config accesibles), solo informa. Si no
        // se puede determinar el estado (p.ej. bot-miki caido, ver #128), no se
        // muestra nada: no es lo mismo indisponibilidad del servicio que
        // licencia vencida, y no hay que alarmar por una caida transitoria.
        $licenseBanner = null;
        if ($isConfigured) {
            try {
                $license = new LicenseClient(SYNKROP_DAEMON_URL, $config['daemon_api_key']);
                $license->getToken();
            } catch (LicenseException $e) {
                if ($e->isExpired()) {
                    $licenseBanner = $e->getMessage();
                }
            }
        }

        $shopTz = new DateTimeZone(Configuration::get('PS_TIMEZONE') ?: 'UTC');
        $utcTz  = new DateTimeZone('UTC');
        foreach ($logs as &$log) {
            $dt = DateTime::createFromFormat('Y-m-d H:i:s', $log['created_at'], $utcTz);
            if ($dt) {
                $dt->setTimezone($shopTz);
                $log['created_at'] = $dt->format('d/m/Y H:i:s');
            }
        }
        unset($log);

        // ── Pestaña Ventas: cola de pedidos → documentos Bsale ────────────────
        $ordersEnabled = (int)($config['sync_orders'] ?? 0) === 1;
        $orderQueue    = [];
        $orderCounts   = [];

        if ($ordersEnabled) {
            $orderQueue = Db::getInstance()->executeS(
                'SELECT q.*, o.reference AS order_reference
                 FROM `' . _DB_PREFIX_ . 'synkrop_order_queue` q
                 LEFT JOIN `' . _DB_PREFIX_ . 'orders` o ON o.id_order = q.id_order
                 WHERE q.id_shop = ' . (int)$this->context->shop->id . '
                 ORDER BY q.id DESC LIMIT 100'
            ) ?: [];

            $adminOrdersLink = $this->context->link->getAdminLink('AdminOrders');
            foreach ($orderQueue as &$row) {
                $dt = DateTime::createFromFormat('Y-m-d H:i:s', $row['created_at'], $utcTz);
                if ($dt) {
                    $dt->setTimezone($shopTz);
                    $row['created_at'] = $dt->format('d/m/Y H:i');
                }
                $row['order_url'] = $adminOrdersLink . '&id_order=' . (int)$row['id_order'] . '&vieworder';
                $row['error_message'] = '';
                if (!empty($row['error_details'])) {
                    $decoded = json_decode($row['error_details'], true);
                    $row['error_message'] = is_array($decoded) ? (string)($decoded['message'] ?? '') : '';
                }
                $orderCounts[$row['status']] = ($orderCounts[$row['status']] ?? 0) + 1;
            }
            unset($row);
        }

        $this->context->smarty->assign([
            'is_configured'         => $isConfigured,
            'license_banner'        => $licenseBanner,
            'sync_logs'             => $logs,
            'ajax_url'              => $this->context->link->getAdminLink('AdminSynkrop') . '&ajax=1',
            'orders_enabled'        => $ordersEnabled,
            'order_queue'           => $orderQueue,
            'order_counts'          => $orderCounts,
            'config_url'            => $this->context->link->getAdminLink('AdminModules') . '&configure=synkrop',
            // #87: boton "Categorias" solo visible si la feature esta activada
            'category_sync_enabled' => (int)($config['sync_categories'] ?? 0) === 1,
        ]);

        $this->content = $this->context->smarty->fetch(
            _PS_MODULE_DIR_ . 'synkrop/views/templates/admin/sync-panel.tpl'
        );
        $this->context->smarty->assign(['content' => $this->content]);
    }

    // ─── AJAX: Sincronizacion manual ──────────────────────────────────────────

    public function ajaxProcessSyncNow()    {
        set_time_limit(0);
        ini_set('memory_limit', '256M');

        $entityType = Tools::getValue('entity', 'products');

        try {
            $service = $this->buildSyncService();
            // #127: syncManual (no sync) — con licencia vencida/suspendida permite
            // seguir operando en modo degradado (limitado a 1x/24h) en vez de
            // bloquear duro; cli/sync.php (cron) sigue usando sync() sin gracia.
            $result  = $service->syncManual($entityType);

            $this->logSync('manual', $entityType, $result);

            $this->ajaxDie(json_encode([
                'success' => true,
                'status'  => $result->status(),
                'updated' => $result->updated,
                'failed'  => $result->failed,
                'duration'=> $result->durationMs,
                'errors'  => array_slice($result->errors, 0, 20),
                'message' => sprintf(
                    $this->l('%d productos actualizados, %d errores.'),
                    $result->updated,
                    $result->failed
                ),
            ]));
        } catch (LicenseException $e) {
            $failed = new SyncResult();
            $failed->failed = 1;
            $failed->errors = [['code' => 'LICENSE_ERROR', 'message' => $e->getMessage()]];
            $this->logSync('manual', $entityType, $failed);
            $this->ajaxDie(json_encode([
                'success' => false,
                'status'  => 'failed',
                'code'    => 'LICENSE_ERROR',
                'message' => $e->getMessage(),
            ]));
        } catch (Exception $e) {
            $failed = new SyncResult();
            $failed->failed = 1;
            $failed->errors = [['code' => 'SYNC_ERROR', 'message' => $e->getMessage()]];
            $this->logSync('manual', $entityType, $failed);
            $this->ajaxDie(json_encode([
                'success' => false,
                'status'  => 'failed',
                'code'    => 'SYNC_ERROR',
                'message' => $e->getMessage(),
            ]));
        }
    }

    // ─── AJAX: Verificar token de Bsale ──────────────────────────────────────

    public function ajaxProcessVerifyBsale()    {
        if ((bool)Tools::getValue('use_saved')) {
            $config = $this->getConfig();
            $enc    = $config['bsale_api_token'] ?? '';
            if (empty($enc)) {
                $this->ajaxDie(json_encode(['success' => false, 'message' => 'Token no configurado']));
            }
            $bsaleToken = TokenCipher::decrypt($enc);
        } else {
            $bsaleToken = Tools::getValue('token');
            if (empty($bsaleToken)) {
                $this->ajaxDie(json_encode(['success' => false, 'message' => 'Token vacio']));
            }
        }

        try {
            $client   = new BsaleApiClient($bsaleToken);
            $response = $client->get('/v1/users.json', ['limit' => 1]);
            $this->ajaxDie(json_encode([
                'success'  => true,
                'business' => $response['items'][0]['email'] ?? 'Conectado',
            ]));
        } catch (BsaleApiException $e) {
            $this->ajaxDie(json_encode([
                'success' => false,
                'message' => 'Token invalido o sin permisos (HTTP ' . $e->getCode() . ')',
            ]));
        }
    }

    // ─── AJAX: Verificar licencia ─────────────────────────────────────────────

    public function ajaxProcessVerifyLicense()    {
        $config = $this->getConfig();

        if ((bool)Tools::getValue('use_saved')) {
            $apiKey = $config['daemon_api_key'] ?? '';
            if (empty($apiKey)) {
                $this->ajaxDie(json_encode(['success' => false, 'message' => 'API Key no configurada']));
            }
        } else {
            $apiKey = Tools::getValue('api_key');
            if (empty($apiKey)) {
                $this->ajaxDie(json_encode(['success' => false, 'message' => 'API Key vacia']));
            }
        }

        $client = new LicenseClient(SYNKROP_DAEMON_URL, $apiKey); // #99: sin tenantId muerto

        try {
            $jwt = $client->getToken();
            $this->ajaxDie(json_encode([
                'success' => true,
                'message' => 'Licencia activa',
                'token'   => substr($jwt, 0, 20) . '...',
            ]));
        } catch (LicenseException $e) {
            $this->ajaxDie(json_encode([
                'success' => false,
                'message' => $e->getMessage(),
            ]));
        }
    }

    // ─── AJAX: Guardar configuracion ──────────────────────────────────────────

    public function ajaxProcessSaveConfig()    {
        $bsaleToken = Tools::getValue('bsale_token');
        $apiKey     = Tools::getValue('api_key');

        if (empty($bsaleToken) || empty($apiKey)) {
            $this->ajaxDie(json_encode([
                'success' => false,
                'message' => 'El token de Bsale y la API Key son obligatorios.',
            ]));
        }

        $encryptedToken = TokenCipher::encrypt($bsaleToken);

        Db::getInstance()->update(
            'synkrop_config',
            [
                'bsale_api_token' => pSQL($encryptedToken),
                'daemon_api_key'  => pSQL($apiKey),
                'daemon_api_url'  => pSQL(SYNKROP_DAEMON_URL),
            ],
            'id_shop = ' . (int)$this->context->shop->id
        );

        $this->ajaxDie(json_encode([
            'success' => true,
            'message' => 'Configuracion guardada correctamente.',
        ]));
    }

    // ─── AJAX: Ventas — generar notas de venta (modo semi-manual) ─────────────

    public function ajaxProcessGenerateOrderDoc()    {
        set_time_limit(120);

        try {
            $service = $this->buildOrderDocumentService();
        } catch (Exception $e) {
            $this->ajaxDie(json_encode(['success' => false, 'message' => $e->getMessage()]));
        }

        $idOrder = (int)Tools::getValue('id_order');
        if ($idOrder > 0) {
            $targets = [$idOrder];
        } else {
            // Lote: todos los pendientes (y reintentos de error) de la tienda
            $rows = Db::getInstance()->executeS(
                'SELECT id_order FROM `' . _DB_PREFIX_ . 'synkrop_order_queue`
                 WHERE id_shop = ' . (int)$this->context->shop->id . "
                 AND status IN ('" . OrderDocumentService::STATUS_PENDING . "','" . OrderDocumentService::STATUS_ERROR . "')
                 ORDER BY id ASC LIMIT 50"
            ) ?: [];
            $targets = array_map(function ($r) { return (int)$r['id_order']; }, $rows);
        }

        if (empty($targets)) {
            $this->ajaxDie(json_encode(['success' => true, 'generated' => 0, 'failed' => 0,
                'message' => $this->l('No hay pedidos pendientes de generar.')]));
        }

        $generated = 0;
        $failed    = 0;
        $messages  = [];
        foreach ($targets as $target) {
            $result = $service->createSaleNote($target);
            if ($result['ok']) {
                $generated++;
            } else {
                $failed++;
            }
            $messages[] = $result['message'];
        }

        $this->ajaxDie(json_encode([
            'success'   => $failed === 0,
            'generated' => $generated,
            'failed'    => $failed,
            'messages'  => array_slice($messages, 0, 20),
            'message'   => sprintf(
                $this->l('%d notas de venta generadas, %d con error.'),
                $generated,
                $failed
            ),
        ]));
    }

    // ─── AJAX: Ventas — verificar emisiones en Bsale (cierre del ciclo) ───────

    public function ajaxProcessCheckEmissions()    {
        set_time_limit(120);

        try {
            $service = $this->buildOrderDocumentService();
            $summary = $service->checkEmissions();
            $this->ajaxDie(json_encode([
                'success' => true,
                'checked' => $summary['checked'],
                'emitted' => $summary['emitted'],
                'closed'  => $summary['closed'],
                'message' => sprintf(
                    $this->l('%d notas revisadas: %d emitidas en Bsale, %d pedidos pasados a "Documentado en Bsale".'),
                    $summary['checked'],
                    $summary['emitted'],
                    $summary['closed']
                ),
            ]));
        } catch (Exception $e) {
            $this->ajaxDie(json_encode(['success' => false, 'message' => $e->getMessage()]));
        }
    }

    // ─── Helpers privados ─────────────────────────────────────────────────────

    private function buildOrderDocumentService(): OrderDocumentService
    {
        $config = $this->getConfig();

        if (empty($config['bsale_api_token'])) {
            throw new RuntimeException($this->l('Configura el token de Bsale antes de generar documentos.'));
        }
        if (!(int)($config['sync_orders'] ?? 0)) {
            throw new RuntimeException($this->l('El flujo de ventas está desactivado — actívalo en la configuración del módulo.'));
        }

        $token = TokenCipher::decrypt($config['bsale_api_token']);
        return new OrderDocumentService(new BsaleApiClient($token), (int)$this->context->shop->id);
    }

    private function buildSyncService(): SynkropService
    {
        $config = $this->getConfig();

        if (empty($config['bsale_api_token']) || empty($config['daemon_api_key'])) {
            throw new RuntimeException($this->l('Configura el token de Bsale y la API Key antes de sincronizar.'));
        }

        $decryptedToken = TokenCipher::decrypt($config['bsale_api_token']);
        $bsale          = new BsaleApiClient($decryptedToken);
        $license        = new LicenseClient(SYNKROP_DAEMON_URL, $config['daemon_api_key']); // #99: sin tenantId muerto

        return new SynkropService($bsale, $license, (int)$this->context->shop->id);
    }

    private function getConfig(): array
    {
        return Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'synkrop_config`
             WHERE id_shop = ' . (int)$this->context->shop->id
        ) ?: [];
    }

    private function logSync(string $type, string $entity, SyncResult $result)    {
        Db::getInstance()->insert('synkrop_log', [
            'id_shop'      => (int)$this->context->shop->id,
            'sync_type'    => pSQL($type),
            'entity_type'  => pSQL($entity),
            'status'       => pSQL($result->status()),
            'records_ok'   => $result->updated,
            'records_fail' => $result->failed,
            'duration_ms'  => $result->durationMs,
            // La columna error_details es JSON (MariaDB: CHECK json_valid): NUNCA null/''.
            // Db::insert() convierte null de PHP en '' y MariaDB lo rechaza -> el INSERT
            // fallaba en silencio y el sync manual jamas quedaba registrado.
            'error_details'=> pSQL(json_encode($result->errors ?: [])),
            'created_at'   => gmdate('Y-m-d H:i:s'), // #100: UTC explicito (servidor en UTC-5)
        ]);
    }
}
