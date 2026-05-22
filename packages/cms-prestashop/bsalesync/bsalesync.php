<?php
/**
 * BsaleSync — Plugin PrestaShop para sincronizar con Bsale ERP/POS
 * Conecta productos, precios y stock desde Bsale a tu tienda PrestaShop.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Autoload de clases del modulo
require_once __DIR__ . '/classes/BsaleApiClient.php';
require_once __DIR__ . '/classes/LicenseClient.php';
require_once __DIR__ . '/classes/BsaleSyncService.php';

class BsaleSync extends Module
{
    public function __construct()
    {
        $this->name          = 'bsalesync';
        $this->tab           = 'administration';
        $this->version       = '1.0.0';
        $this->author        = 'kpcrop-latam';
        $this->need_instance = 0;
        $this->bootstrap     = true;
        $this->ps_versions_compliancy = ['min' => '1.7.0.0', 'max' => _PS_VERSION_];

        parent::__construct();

        $this->displayName = $this->l('Bsale Sync');
        $this->description = $this->l('Sincroniza productos, precios y stock desde Bsale ERP a tu tienda PrestaShop.');
        $this->confirmUninstall = $this->l('¿Seguro que quieres desinstalar BsaleSync? Se eliminaran todos los datos de configuracion.');
    }

    // ─── Instalacion ──────────────────────────────────────────────────────────

    public function install(): bool
    {
        return parent::install()
            && $this->installTab()
            && $this->installDb();
    }

    public function uninstall(): bool
    {
        return parent::uninstall()
            && $this->uninstallTab()
            && $this->uninstallDb();
    }

    private function installTab(): bool
    {
        $tab = new Tab();
        $tab->active     = 1;
        $tab->class_name = 'AdminBsaleSync';
        $tab->icon       = 'sync';
        $tab->module     = $this->name;
        $tab->id_parent  = (int)Tab::getIdFromClassName('AdminCatalog');

        foreach (Language::getLanguages(true) as $lang) {
            $tab->name[$lang['id_lang']] = 'Bsale Sync';
        }

        return $tab->add();
    }

    private function uninstallTab(): bool
    {
        $idTab = (int)Tab::getIdFromClassName('AdminBsaleSync');
        if (!$idTab) return true;
        $tab = new Tab($idTab);
        return $tab->delete();
    }

    private function installDb(): bool
    {
        $sql = file_get_contents(__DIR__ . '/sql/install.sql');
        $sql = str_replace('PREFIX_', _DB_PREFIX_, $sql);
        foreach (explode(';', $sql) as $query) {
            $query = trim($query);
            if ($query && !Db::getInstance()->execute($query)) {
                return false;
            }
        }
        return true;
    }

    private function uninstallDb(): bool
    {
        $sql = file_get_contents(__DIR__ . '/sql/uninstall.sql');
        $sql = str_replace('PREFIX_', _DB_PREFIX_, $sql);
        foreach (explode(';', $sql) as $query) {
            $query = trim($query);
            if ($query) Db::getInstance()->execute($query);
        }
        return true;
    }

    // ─── Pagina de configuracion ──────────────────────────────────────────────

    public function getContent(): string
    {
        $output = '';

        if (Tools::isSubmit('submit_bsalesync_config')) {
            $output .= $this->saveConfig();
        }

        return $output . $this->renderConfigForm();
    }

    private function saveConfig(): string
    {
        $token     = Tools::getValue('BSALESYNC_BSALE_TOKEN');
        $apiKey    = Tools::getValue('BSALESYNC_API_KEY');
        $priceList = (int)Tools::getValue('BSALESYNC_PRICE_LIST_ID');
        $officeId  = (int)Tools::getValue('BSALESYNC_OFFICE_ID');

        if (empty($token) || empty($apiKey)) {
            return $this->displayError($this->l('El token de Bsale y la API Key de licencia son obligatorios.'));
        }

        // Cifrar el token de Bsale antes de guardar
        $encryptedToken = $this->encryptToken($token);

        Db::getInstance()->update(
            _DB_PREFIX_ . 'bsalesync_config',
            [
                'bsale_api_token'     => pSQL($encryptedToken),
                'bsale_price_list_id' => $priceList,
                'bsale_office_id'     => $officeId ?: 'NULL',
                'daemon_api_key'      => pSQL($apiKey),
            ],
            'id_shop = ' . (int)$this->context->shop->id
        );

        return $this->displayConfirmation($this->l('Configuracion guardada correctamente.'));
    }

    private function renderConfigForm(): string
    {
        $fields = [
            'form' => [
                'legend' => ['title' => $this->l('Configuracion de Bsale Sync'), 'icon' => 'icon-cogs'],
                'input' => [
                    [
                        'type'     => 'text',
                        'label'    => $this->l('Token de acceso Bsale'),
                        'name'     => 'BSALESYNC_BSALE_TOKEN',
                        'required' => true,
                        'desc'     => $this->l('Obtenlo en: Bsale > Mi cuenta > Integraciones'),
                        'class'    => 'fixed-width-xxl',
                    ],
                    [
                        'type'     => 'text',
                        'label'    => $this->l('API Key de licencia kpcrop'),
                        'name'     => 'BSALESYNC_API_KEY',
                        'required' => true,
                        'desc'     => $this->l('Tu clave de licencia kpcrop (formato: kp_...)'),
                        'class'    => 'fixed-width-xxl',
                    ],
                    [
                        'type'  => 'text',
                        'label' => $this->l('ID de lista de precios Bsale'),
                        'name'  => 'BSALESYNC_PRICE_LIST_ID',
                        'desc'  => $this->l('Ingresa el ID de la lista de precios a sincronizar (ej: 1)'),
                    ],
                    [
                        'type'  => 'text',
                        'label' => $this->l('ID de sucursal (stock)'),
                        'name'  => 'BSALESYNC_OFFICE_ID',
                        'desc'  => $this->l('Deja en blanco para sumar stock de todas las sucursales'),
                    ],
                ],
                'submit' => ['title' => $this->l('Guardar'), 'class' => 'btn btn-default pull-right'],
            ],
        ];

        $config = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'bsalesync_config` WHERE id_shop = ' . (int)$this->context->shop->id
        );

        $helper = new HelperForm();
        $helper->show_toolbar      = false;
        $helper->table             = $this->table;
        $helper->module            = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->identifier        = $this->identifier;
        $helper->submit_action     = 'submit_bsalesync_config';
        $helper->currentIndex      = $this->context->link->getAdminLink('AdminModules') . '&configure=' . $this->name;
        $helper->token             = Tools::getAdminTokenLite('AdminModules');
        $helper->fields_value      = [
            'BSALESYNC_BSALE_TOKEN'    => '',  // Nunca pre-llenar el token
            'BSALESYNC_API_KEY'        => $config['daemon_api_key'] ?? '',
            'BSALESYNC_PRICE_LIST_ID'  => $config['bsale_price_list_id'] ?? '',
            'BSALESYNC_OFFICE_ID'      => $config['bsale_office_id'] ?? '',
        ];

        return $helper->generateForm([$fields]);
    }

    // ─── Cifrado de token de Bsale ────────────────────────────────────────────

    private function encryptToken(string $token): string
    {
        $key = substr(_COOKIE_KEY_, 0, 32);
        $iv  = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($token, 'AES-256-CBC', $key, 0, $iv);
        return base64_encode($iv . '::' . $encrypted);
    }

    public static function decryptToken(string $encrypted): string
    {
        $key  = substr(_COOKIE_KEY_, 0, 32);
        $data = base64_decode($encrypted);
        [$iv, $ciphertext] = explode('::', $data, 2);
        return openssl_decrypt($ciphertext, 'AES-256-CBC', $key, 0, $iv) ?: '';
    }
}
