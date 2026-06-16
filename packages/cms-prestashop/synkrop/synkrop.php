<?php
/**
 * Synkrop — Plugin PrestaShop para sincronizar con Bsale ERP/POS
 * Conecta productos, precios y stock desde Bsale a tu tienda PrestaShop.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

define('SYNKROP_DAEMON_URL', 'https://kpcrop-latam-zollner-platform-production.up.railway.app');

// Autoload de clases del modulo
require_once __DIR__ . '/classes/BsaleApiClient.php';
require_once __DIR__ . '/classes/LicenseClient.php';
require_once __DIR__ . '/classes/SynkropService.php';

class Synkrop extends Module
{
    public function __construct()
    {
        $this->name          = 'synkrop';
        $this->tab           = 'administration';
        $this->version       = '1.0.0';
        $this->author        = 'kpcrop-latam';
        $this->need_instance = 0;
        $this->bootstrap     = true;
        $this->ps_versions_compliancy = ['min' => '1.7.0.0', 'max' => _PS_VERSION_];

        parent::__construct();

        $this->displayName = $this->l('Synkrop');
        $this->description = $this->l('Sincroniza productos, precios y stock desde Bsale ERP a tu tienda PrestaShop.');
        $this->confirmUninstall = $this->l('¿Seguro que quieres desinstalar Synkrop? Se eliminaran todos los datos de configuracion.');
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
        $tab->class_name = 'AdminSynkrop';
        $tab->icon       = 'sync';
        $tab->module     = $this->name;
        $tab->id_parent  = (int)Tab::getIdFromClassName('AdminCatalog');

        foreach (Language::getLanguages(true) as $lang) {
            $tab->name[$lang['id_lang']] = 'Synkrop';
        }

        // Tab::add() deprecated in PS 8; Tab::save() is the unified method
        if (version_compare(_PS_VERSION_, '8.0.0', '>=')) {
            return (bool)$tab->save();
        }
        return (bool)$tab->add();
    }

    private function uninstallTab(): bool
    {
        $idTab = (int)Tab::getIdFromClassName('AdminSynkrop');
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

        if (Tools::isSubmit('submit_synkrop_config')) {
            $output .= $this->saveConfig();
        }

        if (Tools::isSubmit('submit_price_groups')) {
            $output .= $this->savePriceGroups();
        }

        return $output . $this->renderConfigForm() . $this->renderPriceGroupForm();
    }

    private function saveConfig(): string
    {
        $token     = Tools::getValue('SYNKROP_BSALE_TOKEN');
        $apiKey    = Tools::getValue('SYNKROP_API_KEY');
        $priceList = (int)Tools::getValue('SYNKROP_PRICE_LIST_ID');
        $officeId  = (int)Tools::getValue('SYNKROP_OFFICE_ID');

        if (empty($token) || empty($apiKey)) {
            return $this->displayError($this->l('El token de Bsale y la API Key de licencia son obligatorios.'));
        }

        $encryptedToken = self::encryptToken($token);

        Db::getInstance()->update(
            'synkrop_config',
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
                'legend' => ['title' => $this->l('Configuracion de Synkrop'), 'icon' => 'icon-cogs'],
                'input' => [
                    [
                        'type'     => 'text',
                        'label'    => $this->l('Token de acceso Bsale'),
                        'name'     => 'SYNKROP_BSALE_TOKEN',
                        'required' => true,
                        'desc'     => $this->l('Obtenlo en: Bsale > Mi cuenta > Integraciones'),
                        'class'    => 'fixed-width-xxl',
                    ],
                    [
                        'type'     => 'text',
                        'label'    => $this->l('API Key de licencia kpcrop'),
                        'name'     => 'SYNKROP_API_KEY',
                        'required' => true,
                        'desc'     => $this->l('Tu clave de licencia kpcrop (formato: kp_...)'),
                        'class'    => 'fixed-width-xxl',
                    ],
                    [
                        'type'  => 'text',
                        'label' => $this->l('ID de lista de precios Bsale'),
                        'name'  => 'SYNKROP_PRICE_LIST_ID',
                        'desc'  => $this->l('Ingresa el ID de la lista de precios a sincronizar (ej: 1)'),
                    ],
                    [
                        'type'  => 'text',
                        'label' => $this->l('ID de sucursal (stock)'),
                        'name'  => 'SYNKROP_OFFICE_ID',
                        'desc'  => $this->l('Deja en blanco para sumar stock de todas las sucursales'),
                    ],
                ],
                'submit' => ['title' => $this->l('Guardar'), 'class' => 'btn btn-default pull-right'],
            ],
        ];

        $config = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'synkrop_config` WHERE id_shop = ' . (int)$this->context->shop->id
        );

        $helper = new HelperForm();
        $helper->show_toolbar      = false;
        $helper->table             = $this->table;
        $helper->module            = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->identifier        = $this->identifier;
        $helper->submit_action     = 'submit_synkrop_config';
        $helper->currentIndex      = $this->context->link->getAdminLink('AdminModules') . '&configure=' . $this->name;
        $helper->token             = Tools::getAdminTokenLite('AdminModules');
        $helper->fields_value      = [
            'SYNKROP_BSALE_TOKEN'    => '',  // Nunca pre-llenar el token
            'SYNKROP_API_KEY'        => $config['daemon_api_key'] ?? '',
            'SYNKROP_PRICE_LIST_ID'  => $config['bsale_price_list_id'] ?? '',
            'SYNKROP_OFFICE_ID'      => $config['bsale_office_id'] ?? '',
        ];

        return $helper->generateForm([$fields]);
    }

    // ─── Formulario de mapeo grupo → lista de precios ────────────────────────

    private function renderPriceGroupForm(): string
    {
        $groups = Group::getGroups((int)$this->context->language->id);

        $maps = Db::getInstance()->executeS(
            'SELECT id_group, bsale_price_list_id, active
             FROM `' . _DB_PREFIX_ . 'synkrop_price_group_map`
             WHERE id_shop = ' . (int)$this->context->shop->id
        );

        $mapped = [];
        foreach ($maps as $map) {
            $mapped[$map['id_group']] = $map;
        }

        $adminLink = $this->context->link->getAdminLink('AdminModules')
            . '&configure=' . $this->name;

        $html  = '<div class="panel">';
        $html .= '<h3><i class="icon-tags"></i> ' . $this->l('Precios por grupo de clientes') . '</h3>';
        $html .= '<p class="help-block">'
            . $this->l('Asocia una lista de precios Bsale a cada grupo de clientes de PrestaShop.')
            . ' ' . $this->l('Deja el ID en 0 para que el grupo use el precio base.')
            . '</p>';
        $html .= '<form method="post" action="' . htmlspecialchars($adminLink) . '">';
        $html .= '<input type="hidden" name="submit_price_groups" value="1">';
        $html .= '<table class="table">';
        $html .= '<thead><tr>';
        $html .= '<th>' . $this->l('Grupo PS') . '</th>';
        $html .= '<th>' . $this->l('ID Lista de precios Bsale') . '</th>';
        $html .= '<th>' . $this->l('Activo') . '</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($groups as $group) {
            $gid    = (int)$group['id_group'];
            $map    = $mapped[$gid] ?? null;
            $listId = (int)($map['bsale_price_list_id'] ?? 0);
            $active = (int)($map['active'] ?? 0);

            $html .= '<tr>';
            $html .= '<td><strong>' . htmlspecialchars($group['name']) . '</strong> <small>(#' . $gid . ')</small></td>';
            $html .= '<td><input type="number" class="form-control" style="width:100px"'
                . ' name="group_price_list[' . $gid . ']"'
                . ' value="' . $listId . '" min="0"></td>';
            $html .= '<td><input type="checkbox" name="group_active[' . $gid . ']" value="1"'
                . ($active ? ' checked' : '') . '></td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        $html .= '<button type="submit" class="btn btn-default pull-right">'
            . '<i class="process-icon-save"></i> ' . $this->l('Guardar mapeo de precios')
            . '</button>';
        $html .= '</form></div>';

        return $html;
    }

    private function savePriceGroups(): string
    {
        $groupPriceLists = Tools::getValue('group_price_list', []);
        $groupActive     = Tools::getValue('group_active', []);
        $idShop          = (int)$this->context->shop->id;

        foreach ($groupPriceLists as $idGroup => $priceListId) {
            $idGroup     = (int)$idGroup;
            $priceListId = (int)$priceListId;
            $active      = isset($groupActive[$idGroup]) ? 1 : 0;

            if ($priceListId <= 0) {
                Db::getInstance()->delete(
                    'synkrop_price_group_map',
                    'id_group = ' . $idGroup . ' AND id_shop = ' . $idShop
                );
                continue;
            }

            $exists = Db::getInstance()->getValue(
                'SELECT id FROM `' . _DB_PREFIX_ . 'synkrop_price_group_map`
                 WHERE id_group = ' . $idGroup . ' AND id_shop = ' . $idShop
            );

            $data = ['bsale_price_list_id' => $priceListId, 'active' => $active];

            if ($exists) {
                Db::getInstance()->update(
                    'synkrop_price_group_map',
                    $data,
                    'id_group = ' . $idGroup . ' AND id_shop = ' . $idShop
                );
            } else {
                $data['id_group'] = $idGroup;
                $data['id_shop']  = $idShop;
                Db::getInstance()->insert('synkrop_price_group_map', $data);
            }
        }

        return $this->displayConfirmation($this->l('Mapeo de precios guardado correctamente.'));
    }

    // ─── Cifrado de token de Bsale ────────────────────────────────────────────

    public static function encryptToken(string $token): string
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
        $parts = explode('::', $data, 2);
        $iv = $parts[0];
        $ciphertext = $parts[1];
        return openssl_decrypt($ciphertext, 'AES-256-CBC', $key, 0, $iv) ?: '';
    }
}
