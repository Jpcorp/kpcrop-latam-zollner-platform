<?php
/**
 * Controller del panel de sincronizacion en el backoffice de PrestaShop.
 * Maneja la pantalla de sync manual y las llamadas AJAX del front-end del backoffice.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'bsalesync/bsalesync.php';
require_once _PS_MODULE_DIR_ . 'bsalesync/classes/BsaleApiClient.php';
require_once _PS_MODULE_DIR_ . 'bsalesync/classes/LicenseClient.php';
require_once _PS_MODULE_DIR_ . 'bsalesync/classes/BsaleSyncService.php';

class AdminBsaleSyncController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
        $this->meta_title = $this->l('Bsale Sync');
    }

    public function initContent(): void
    {
        parent::initContent();

        $config = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'bsalesync_config`
             WHERE id_shop = ' . (int)$this->context->shop->id
        );

        $logs = Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'bsalesync_log`
             WHERE id_shop = ' . (int)$this->context->shop->id . '
             ORDER BY created_at DESC LIMIT 20'
        );

        $isConfigured = !empty($config['bsale_api_token']) && !empty($config['daemon_api_key']);

        $this->context->smarty->assign([
            'is_configured' => $isConfigured,
            'sync_logs'     => $logs,
            'ajax_url'      => $this->context->link->getAdminLink('AdminBsaleSync') . '&ajax=1',
        ]);

        $this->content = $this->context->smarty->fetch(
            _PS_MODULE_DIR_ . 'bsalesync/views/templates/admin/sync-panel.tpl'
        );
        $this->context->smarty->assign(['content' => $this->content]);
    }

    // ─── AJAX: Sincronizacion manual ──────────────────────────────────────────

    public function ajaxProcessSyncNow(): void
    {
        $entityType = Tools::getValue('entity', 'products');

        try {
            $service = $this->buildSyncService();
            $result  = $service->sync($entityType);

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
            $this->ajaxDie(json_encode([
                'success' => false,
                'code'    => 'LICENSE_ERROR',
                'message' => $e->getMessage(),
            ]));
        } catch (Exception $e) {
            $this->ajaxDie(json_encode([
                'success' => false,
                'code'    => 'SYNC_ERROR',
                'message' => $e->getMessage(),
            ]));
        }
    }

    // ─── AJAX: Verificar token de Bsale ──────────────────────────────────────

    public function ajaxProcessVerifyBsale(): void
    {
        if ((bool)Tools::getValue('use_saved')) {
            $config = $this->getConfig();
            $enc    = $config['bsale_api_token'] ?? '';
            if (empty($enc)) {
                $this->ajaxDie(json_encode(['success' => false, 'message' => 'Token no configurado']));
            }
            $bsaleToken = BsaleSync::decryptToken($enc);
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

    public function ajaxProcessVerifyLicense(): void
    {
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

        $tenantId = md5($config['daemon_api_url'] . $apiKey);
        $client   = new LicenseClient($config['daemon_api_url'], $apiKey, $tenantId);

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

    public function ajaxProcessSaveConfig(): void
    {
        $bsaleToken = Tools::getValue('bsale_token');
        $apiKey     = Tools::getValue('api_key');

        if (empty($bsaleToken) || empty($apiKey)) {
            $this->ajaxDie(json_encode([
                'success' => false,
                'message' => 'El token de Bsale y la API Key son obligatorios.',
            ]));
        }

        $encryptedToken = BsaleSync::encryptToken($bsaleToken);

        Db::getInstance()->update(
            'bsalesync_config',
            [
                'bsale_api_token' => pSQL($encryptedToken),
                'daemon_api_key'  => pSQL($apiKey),
            ],
            'id_shop = ' . (int)$this->context->shop->id
        );

        $this->ajaxDie(json_encode([
            'success' => true,
            'message' => 'Configuracion guardada correctamente.',
        ]));
    }

    // ─── Helpers privados ─────────────────────────────────────────────────────

    private function buildSyncService(): BsaleSyncService
    {
        $config = $this->getConfig();

        if (empty($config['bsale_api_token']) || empty($config['daemon_api_key'])) {
            throw new RuntimeException($this->l('Configura el token de Bsale y la API Key antes de sincronizar.'));
        }

        $decryptedToken = BsaleSync::decryptToken($config['bsale_api_token']);
        $bsale          = new BsaleApiClient($decryptedToken);
        $license        = new LicenseClient(
            $config['daemon_api_url'],
            $config['daemon_api_key'],
            md5($config['daemon_api_url'] . $config['daemon_api_key'])
        );

        return new BsaleSyncService($bsale, $license, (int)$this->context->shop->id);
    }

    private function getConfig(): array
    {
        return Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'bsalesync_config`
             WHERE id_shop = ' . (int)$this->context->shop->id
        ) ?: [];
    }

    private function logSync(string $type, string $entity, SyncResult $result): void
    {
        Db::getInstance()->insert('bsalesync_log', [
            'id_shop'      => (int)$this->context->shop->id,
            'sync_type'    => pSQL($type),
            'entity_type'  => pSQL($entity),
            'status'       => pSQL($result->status()),
            'records_ok'   => $result->updated,
            'records_fail' => $result->failed,
            'duration_ms'  => $result->durationMs,
            'error_details'=> $result->errors ? json_encode($result->errors) : null,
        ]);
    }
}
