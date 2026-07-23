<?php
/**
 * Synkrop — Plugin PrestaShop para sincronizar con Bsale ERP/POS
 * Conecta productos, precios y stock desde Bsale a tu tienda PrestaShop.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

define('SYNKROP_DAEMON_URL', 'https://miki.keepcrop.com');

// Autoload de clases del modulo
require_once __DIR__ . '/classes/BsaleApiClient.php';
require_once __DIR__ . '/classes/LicenseClient.php';
require_once __DIR__ . '/classes/SynkropService.php';
require_once __DIR__ . '/classes/OrderDocumentService.php';
require_once __DIR__ . '/classes/TokenCipher.php';

class Synkrop extends Module
{
    public function __construct()
    {
        $this->name          = 'synkrop';
        $this->tab           = 'administration';
        $this->version       = '1.2.0';
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
            && $this->installDb()
            && $this->registerHook('actionOrderStatusPostUpdate')
            && $this->registerHook('displayPaymentTop')
            && $this->registerHook('actionFrontControllerSetMedia')
            && $this->installOrderState();
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

    /**
     * Estado de pedido custom "Documentado en Bsale": destino del ciclo de ventas
     * (se asigna cuando el usuario Bsale emite la boleta/factura → gatilla despacho).
     */
    private function installOrderState(): bool
    {
        $idState = (int)Configuration::get('SYNKROP_OS_DOCUMENTED');
        if ($idState && Validate::isLoadedObject(new OrderState($idState))) {
            return true; // reinstalación: reutiliza el estado existente
        }

        $state = new OrderState();
        foreach (Language::getLanguages(true) as $lang) {
            $state->name[$lang['id_lang']] = 'Documentado en Bsale';
        }
        $state->color       = '#3498DB';
        $state->module_name = $this->name;
        $state->logable     = true;
        $state->send_email  = false;
        $state->hidden      = false;
        $state->paid        = false;
        $state->invoice     = false;
        $state->delivery    = false;
        $state->shipped     = false;

        if (!$state->add()) {
            return false;
        }
        return Configuration::updateValue('SYNKROP_OS_DOCUMENTED', (int)$state->id);
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

    // ─── Hook: flujo de ventas PS → Bsale ─────────────────────────────────────

    /**
     * Encola el pedido cuando llega a un estado gatillo (default: Pago aceptado)
     * y gestiona la cancelación. NO llama a Bsale en el request del cliente:
     * solo escribe en la cola local (a diferencia del módulo legado syncBsale).
     */
    public function hookActionOrderStatusPostUpdate($params): void
    {
        $idOrder  = (int)($params['id_order'] ?? 0);
        $newState = $params['newOrderStatus'] ?? null;
        if (!$idOrder || !($newState instanceof OrderState)) {
            return;
        }

        $config = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'synkrop_config` WHERE id_shop = ' . (int)$this->context->shop->id
        );
        if (!$config || !(int)($config['sync_orders'] ?? 0)) {
            return;
        }

        // Cancelación: doble método (API primero, revisión humana como respaldo)
        if ((int)$newState->id === (int)Configuration::get('PS_OS_CANCELED')) {
            try {
                $this->buildOrderDocumentService($config)->cancelSaleNote($idOrder);
            } catch (Exception $e) {
                // La cancelación nunca debe romper el cambio de estado del pedido
            }
            return;
        }

        $triggers = array_map('intval', explode(',', (string)$config['order_trigger_states']));
        if (in_array((int)$newState->id, $triggers, true)) {
            $order = new Order($idOrder);
            if (Validate::isLoadedObject($order)) {
                $this->buildOrderDocumentService($config)->enqueue($idOrder, (int)$order->id_cart);
                // Fase 2: aquí el modo automático notificará a bot-miki (licencia vigente)
            }
        }
    }

    // ─── Formulario boleta/factura en el checkout ─────────────────────────────

    /**
     * Muestra el selector boleta/factura sobre las opciones de pago. Si el
     * comprador pide factura, sus datos tributarios viajan en la nota de venta
     * y el usuario Bsale decide el documento a emitir (nunca el sistema).
     */
    public function hookDisplayPaymentTop($params): string
    {
        $config = Db::getInstance()->getRow(
            'SELECT sync_orders FROM `' . _DB_PREFIX_ . 'synkrop_config`
             WHERE id_shop = ' . (int)$this->context->shop->id
        );
        if (!$config || !(int)$config['sync_orders']) {
            return '';
        }

        $idCart = (int)($this->context->cart->id ?? 0);
        if (!$idCart) {
            return '';
        }

        $existing = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'synkrop_invoice_request`
             WHERE id_cart = ' . $idCart
        );

        $this->context->smarty->assign([
            'synkrop_invoice_request' => $existing ?: null,
            'synkrop_ajax_url'        => $this->context->link->getModuleLink($this->name, 'invoicerequest'),
            'synkrop_token'           => Tools::getToken(false),
        ]);

        return $this->display(__FILE__, 'views/templates/hook/invoice_request.tpl');
    }

    public function hookActionFrontControllerSetMedia($params): void
    {
        if (($this->context->controller->php_self ?? '') !== 'order') {
            return;
        }
        $this->context->controller->registerJavascript(
            'synkrop-invoice-request',
            'modules/' . $this->name . '/views/js/invoice_request.js',
            ['position' => 'bottom', 'priority' => 150]
        );
    }

    private function buildOrderDocumentService(array $config): OrderDocumentService
    {
        $token = TokenCipher::decrypt((string)$config['bsale_api_token']);
        return new OrderDocumentService(new BsaleApiClient($token), (int)$this->context->shop->id);
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

        // El campo del token nunca se pre-llena (no se muestra en pantalla por
        // seguridad) — dejarlo en blanco significa "no cambiar", no "borrar".
        // Solo es obligatorio si todavia no hay un token guardado (alta inicial).
        $existing = Db::getInstance()->getRow(
            'SELECT bsale_api_token FROM `' . _DB_PREFIX_ . 'synkrop_config`
             WHERE id_shop = ' . (int)$this->context->shop->id
        );
        $hasStoredToken = !empty($existing['bsale_api_token']);

        if (empty($apiKey) || (empty($token) && !$hasStoredToken)) {
            return $this->displayError($this->l('El token de Bsale y la API Key de licencia son obligatorios.'));
        }

        $syncOrders    = (int)Tools::getValue('SYNKROP_SYNC_ORDERS', 0);
        $triggerStates = implode(',', array_filter(array_map(
            'intval',
            explode(',', (string)Tools::getValue('SYNKROP_ORDER_TRIGGER_STATES', '2'))
        ))) ?: '2';
        $vatRate = (float)Tools::getValue('SYNKROP_ORDER_VAT_RATE', 19.0);

        $data = [
            'bsale_price_list_id'  => $priceList,
            'bsale_office_id'      => $officeId ?: null,
            'daemon_api_key'       => pSQL($apiKey),
            'sync_orders'          => $syncOrders,
            'order_trigger_states' => pSQL($triggerStates),
            'order_vat_rate'       => $vatRate,
            'shipping_sku'         => pSQL(trim((string)Tools::getValue('SYNKROP_SHIPPING_SKU', ''))),
            'test_mode'            => (int)Tools::getValue('SYNKROP_TEST_MODE', 0),
        ];

        // Solo re-cifra y sobreescribe el token si el usuario pego uno nuevo
        if (!empty($token)) {
            $data['bsale_api_token'] = pSQL(TokenCipher::encrypt($token));
        }

        Db::getInstance()->update(
            'synkrop_config',
            $data,
            'id_shop = ' . (int)$this->context->shop->id,
            0,
            true
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
                    [
                        'type'    => 'switch',
                        'label'   => $this->l('Flujo de ventas (PS → Bsale)'),
                        'name'    => 'SYNKROP_SYNC_ORDERS',
                        'desc'    => $this->l('Encola cada pedido pagado para generar su nota de venta en Bsale. La boleta/factura la emite siempre un usuario en Bsale.'),
                        'values'  => [
                            ['id' => 'sync_orders_on',  'value' => 1, 'label' => $this->l('Sí')],
                            ['id' => 'sync_orders_off', 'value' => 0, 'label' => $this->l('No')],
                        ],
                    ],
                    [
                        'type'  => 'text',
                        'label' => $this->l('Estados gatillo (ids separados por coma)'),
                        'name'  => 'SYNKROP_ORDER_TRIGGER_STATES',
                        'desc'  => $this->l('Estados de pedido que encolan el documento. Default: 2 (Pago aceptado)'),
                    ],
                    [
                        'type'  => 'text',
                        'label' => $this->l('Tasa de IVA (%)'),
                        'name'  => 'SYNKROP_ORDER_VAT_RATE',
                        'desc'  => $this->l('Usada para prorratear descuentos. Default: 19'),
                    ],
                    [
                        'type'  => 'text',
                        'label' => $this->l('SKU de despacho en Bsale'),
                        'name'  => 'SYNKROP_SHIPPING_SKU',
                        'desc'  => $this->l('SKU del servicio de despacho en tu catalogo Bsale (crealo una vez, sin control de stock). En blanco: Bsale creara una variante nueva por cada documento con envio. Util si el despacho es propio; si lo externalizas, define aqui el SKU que uses para cobrarlo.'),
                    ],
                    [
                        'type'    => 'switch',
                        'label'   => $this->l('Modo prueba (verificacion de emisiones)'),
                        'name'    => 'SYNKROP_TEST_MODE',
                        'desc'    => $this->l('SOLO para sandbox o staging. "Verificar emisiones" acepta cualquier documento manual (boleta/factura no tributaria) como emision valida, sin exigir codigo SII. NUNCA actives esto en produccion: se saltaria la validacion de documento tributario real.'),
                        'values'  => [
                            ['id' => 'test_mode_on',  'value' => 1, 'label' => $this->l('Sí (solo pruebas)')],
                            ['id' => 'test_mode_off', 'value' => 0, 'label' => $this->l('No')],
                        ],
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
            'SYNKROP_BSALE_TOKEN'          => '',  // Nunca pre-llenar el token
            'SYNKROP_API_KEY'              => $config['daemon_api_key'] ?? '',
            'SYNKROP_PRICE_LIST_ID'        => $config['bsale_price_list_id'] ?? '',
            'SYNKROP_OFFICE_ID'            => $config['bsale_office_id'] ?? '',
            'SYNKROP_SYNC_ORDERS'          => (int)($config['sync_orders'] ?? 0),
            'SYNKROP_ORDER_TRIGGER_STATES' => $config['order_trigger_states'] ?? '2',
            'SYNKROP_ORDER_VAT_RATE'       => $config['order_vat_rate'] ?? '19.00',
            'SYNKROP_SHIPPING_SKU'         => $config['shipping_sku'] ?? '',
            'SYNKROP_TEST_MODE'            => (int)($config['test_mode'] ?? 0),
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
}
