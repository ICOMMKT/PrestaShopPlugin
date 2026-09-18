<?php
/**
 * NOTICE OF LICENSE
 *
 * This file is licenced under the Software License Agreement.
 * With the purchase or the installation of the software in your application
 * you accept the licence agreement.
 *
 * You must not modify, adapt or create derivative works of this source code
 *
 * @author    Icommkt
 * @copyright Icommkt
 * @license   GPLv3
 *
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class Icommktconnector extends Module
{
    protected $config_form = false;

    /* Tienda resuelta a partir de la AppKey en authorizeRequest() */
    public $context_id_shop = null;
    public $context_id_shop_group = null;

    /* Diagnóstico del endpoint de catálogo (parámetro debug=1) */
    protected $catalog_debug = false;
    protected $catalog_debug_sql = null;
    protected $catalog_debug_row = null;

    const CATALOG_PER_PAGE_DEFAULT = 50;
    const CATALOG_PER_PAGE_MAX = 200;

    public function __construct()
    {
        /* $this->name es el identificador técnico (carpeta, controladores, BD): no debe cambiar */
        $this->name = 'icommktconnector';
        /* $this->tab debe ser uno de los identificadores que reconoce PrestaShop */
        $this->tab = 'advertising_marketing';
        $this->version = '1.4.4';
        $this->author = 'icomm';
        $this->need_instance = 0;

        /**
         * Set $this->bootstrap to true if your module is compliant with bootstrap (PrestaShop 1.6)
         */
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('icomm AI Marketing Cloud');
        $this->description = $this->l('Enabled API service for icomm native integration');
    }

    public function install()
    {
        /* Las funcionalidades retiradas (carritos abandonados en 1.3.0, envío de suscriptores de la
           newsletter en 1.4.0) solo siguen disponibles en las tiendas que ya las usaban. Se detectan
           en lugar de fijarlas a 0 para no perderlas al reinstalar sobre una tienda que las usaba. */
        $newsletter_legacy = $this->detectNewsletterLegacy();

        Configuration::updateValue('ICOMMKT_ABANDON_LEGACY', ($this->detectAbandonLegacy() ? 1 : 0));
        Configuration::updateValue('ICOMMKT_NEWSLETTER_LEGACY', ($newsletter_legacy ? 1 : 0));

        if (!parent::install() || !$this->registerHook('moduleRoutes')) {
            return false;
        }

        /* Las instalaciones nuevas no modifican la tabla de suscriptores de PrestaShop */
        if ($newsletter_legacy) {
            $this->addNewColumn();
        }

        return true;
    }

    public function uninstall()
    {

        return parent::uninstall() && $this->uninstallDb() && $this->uninstallColumns();
    }

    /**
     * La gestión de carritos abandonados está retirada desde la versión 1.3.0, pero se mantiene operativa
     * en las tiendas que ya la tenían en uso. El resultado se guarda en configuración para que la
     * detección se haga una sola vez por tienda.
     */
    public function isAbandonLegacy()
    {
        $flag = Configuration::get('ICOMMKT_ABANDON_LEGACY');

        if ($flag === false || $flag === '') {
            $flag = ($this->detectAbandonLegacy() ? 1 : 0);
            Configuration::updateValue('ICOMMKT_ABANDON_LEGACY', $flag);
        }

        return (bool)$flag;
    }

    /**
     * Una tienda se considera usuaria de carritos abandonados si tiene el perfil configurado
     * o si ya ha enviado carritos a ICOMMKT.
     */
    protected function detectAbandonLegacy()
    {
        $profile_key = Configuration::get('ICOMMKT_PROFILEKEY_ABANDON');
        if (!empty($profile_key)) {
            return true;
        }

        $table = _DB_PREFIX_ . 'commktconnector_abandomentcarts';
        $exists = Db::getInstance()->executeS('SHOW TABLES LIKE \'' . pSQL($table) . '\'');
        if (!$exists) {
            return false;
        }

        return (bool)Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . bqSQL($table) . '`');
    }

    /**
     * El envío de suscriptores de la newsletter está retirado desde la versión 1.4.0, con el mismo
     * criterio que los carritos abandonados: sigue operativo donde ya se usaba.
     */
    public function isNewsletterLegacy()
    {
        $flag = Configuration::get('ICOMMKT_NEWSLETTER_LEGACY');

        if ($flag === false || $flag === '') {
            $flag = ($this->detectNewsletterLegacy() ? 1 : 0);
            Configuration::updateValue('ICOMMKT_NEWSLETTER_LEGACY', $flag);
        }

        return (bool)$flag;
    }

    /**
     * Una tienda se considera usuaria del envío de suscriptores si tiene el perfil configurado o si
     * el módulo ya añadió sus columnas a la tabla de suscriptores de PrestaShop.
     */
    protected function detectNewsletterLegacy()
    {
        $profile_key = Configuration::get('ICOMMKT_PROFILEKEY');
        if (!empty($profile_key)) {
            return true;
        }

        /* Si la tabla no existe, executeS devuelve false y la tienda no se considera legacy */
        $columns = Db::getInstance()->executeS(
            'SHOW COLUMNS FROM `' . bqSQL($this->getNewsletterTable()) . '` LIKE \'is_send_icommkt\''
        );

        return (bool)$columns;
    }

    /**
     * PrestaShop renombró la tabla de suscriptores en la 1.7.
     */
    public function getNewsletterTable()
    {
        if (Tools::version_compare(_PS_VERSION_, '1.7.0.0', '>=') == true) {
            return _DB_PREFIX_ . 'emailsubscription';
        }

        return _DB_PREFIX_ . 'newsletter';
    }

    public function addNewColumn()
    {
        $table = $this->getNewsletterTable();

        Db::getInstance()->execute('ALTER TABLE `' . bqSQL($table) . '` ADD COLUMN is_send_icommkt BOOLEAN');
        Db::getInstance()->execute('ALTER TABLE `' . bqSQL($table) . '` ADD COLUMN date_send_icommkt DATETIME');

        return true;
    }

    public function uninstallColumns()
    {
        /* Desde 1.4.0 las instalaciones nuevas no añaden estas columnas, así que no hay nada que quitar */
        if (!$this->isNewsletterLegacy()) {
            return true;
        }

        $table = $this->getNewsletterTable();

        Db::getInstance()->execute('ALTER TABLE `' . bqSQL($table) . '` DROP COLUMN is_send_icommkt');
        Db::getInstance()->execute('ALTER TABLE `' . bqSQL($table) . '` DROP COLUMN date_send_icommkt');

        return true;
    }

    public function uninstallDb()
    {
        /* IF EXISTS: desde 1.3.0 las instalaciones nuevas ya no crean estas tablas */
        Db::getInstance()->Execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'commktconnector_abandomentcarts`');
        Db::getInstance()->Execute(
            'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'commktconnector_abandomentcarts_error`'
        );

        return true;
    }

    /**
     * Load the configuration form
     */
    public function getContent()
    {
        /**
         * If values have been submitted in the form, process.
         */
        if (((bool)Tools::isSubmit('submitIcommktconnectorModule')) == true) {
            $this->postProcess();
        }

        /* La pantalla de configuración es solo la cabecera con el logo más el formulario de ajustes */
        $this->context->smarty->assign(array(
            'module_dir' => $this->_path,
            'module_version' => $this->version,
        ));

        $template = $this->local_path . 'views/templates/admin/settings.tpl';

        /* Tras actualizar el módulo, PrestaShop puede seguir sirviendo la versión compilada anterior
           de la plantilla. Recompilarla aquí es barato, porque esta pantalla se visita muy poco, y
           evita tener que vaciar la caché de la tienda en cada actualización. */
        if (method_exists($this->context->smarty, 'clearCompiledTemplate')) {
            $this->context->smarty->clearCompiledTemplate($template);
        }

        $output = $this->context->smarty->fetch($template);

        return $output . $this->renderForm();
    }

    /**
     * Create the form that will be displayed in the configuration of your module.
     */
    protected function renderForm()
    {
        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitIcommktconnectorModule';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFormValues(), /* Add values for your inputs */
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        return $helper->generateForm(array($this->getConfigForm()));
    }

    /**
     * Create the structure of your form.
     */
    protected function getConfigForm()
    {
        $form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Settings'),
                    'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'col' => 3,
                        'type' => 'text',
                        'prefix' => '<i class="icon icon-gear"></i>',
                        'desc' => $this->l('You can custom this value as you wish'),
                        'name' => 'ICOMMKT_APPKEY',
                        'label' => $this->l('App KEY'),
                    ),
                    array(
                        'col' => 3,
                        'type' => 'text',
                        'prefix' => '<i class="icon icon-gear"></i>',
                        'desc' => $this->l('You can custom this value as you wish'),
                        'name' => 'ICOMMKT_APPTOKEN',
                        'label' => $this->l('App TOKEN'),
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );

        /* API Key y Secure TOKEN solo los usan los crons retirados: sin ninguno de ellos activo
           no hay nada que autenticar con esos valores */
        if ($this->isAbandonLegacy() || $this->isNewsletterLegacy()) {
            $form['form']['input'][] = array(
                'col' => 3,
                'type' => 'text',
                'prefix' => '<i class="icon icon-gear"></i>',
                'desc' => $this->l('API KEY code from the account icomm'),
                'name' => 'ICOMMKT_APIKEY',
                'label' => $this->l('API Key'),
            );
            $form['form']['input'][] = array(
                'col' => 3,
                'type' => 'text',
                'prefix' => '<i class="icon icon-gear"></i>',
                'desc' => $this->l('Required parameter to send the data.Parameter Customizable'),
                'name' => 'ICOMMKT_SECURE_TOKEN',
                'label' => $this->l('Secure TOKEN'),
            );
        }

        /* Perfil de la newsletter: solo en las tiendas que ya usaban el envío de suscriptores */
        if ($this->isNewsletterLegacy()) {
            $form['form']['input'][] = array(
                'col' => 3,
                'type' => 'text',
                'class' => 'newsletter',
                'prefix' => '<i class="icon icon-gear"></i>',
                'desc' => $this->l('Code obtained from the account profile'),
                'name' => 'ICOMMKT_PROFILEKEY',
                'label' => $this->l('Profile Key'),
            );
        }

        /* Campos de carritos abandonados: solo en las tiendas que ya usaban la funcionalidad */
        if ($this->isAbandonLegacy()) {
            $legacy_inputs = array(
                array(
                    'col' => 3,
                    'type' => 'text',
                    'prefix' => '<i class="icon icon-gear"></i>',
                    'desc' => $this->l('Profile key code where to send Cart Abandon'),
                    'name' => 'ICOMMKT_PROFILEKEY_ABANDON',
                    'label' => $this->l('Profile Key Cart Abandon'),
                ),
                array(
                    'col' => 3,
                    'type' => 'text',
                    'prefix' => '<i class="icon icon-gear"></i>',
                    'desc' => $this->l('Time that goes by to consider an abandoned cart'),
                    'name' => 'ICOMMKT_DAYS_TO_ABANDON',
                    'label' => $this->l('Days to abandon'),
                ),
                array(
                    'type' => 'radio',
                    'label' => 'Friendly URL',
                    'name' => 'ICOMMKT_FRIENDLY_URL',
                    'values' => array(
                        array(
                            'id' => 'active_on',
                            'value' => 1,
                            'label' => 'Enabled'
                        ),
                        array(
                            'id' => 'active_off',
                            'value' => 0,
                            'label' => 'Disabled'
                        )
                    )
                )
            );

            $form['form']['input'] = array_merge($form['form']['input'], $legacy_inputs);
        }

        return $form;
    }

    /**
     * Set values for the inputs.
     */
    protected function getConfigFormValues()
    {
        $values = array(
            'ICOMMKT_APPKEY' => Configuration::get('ICOMMKT_APPKEY', null),
            'ICOMMKT_APPTOKEN' => Configuration::get('ICOMMKT_APPTOKEN', null),
        );

        /* postProcess() recorre este array, así que las claves de las funcionalidades retiradas solo
           se leen y se guardan en las tiendas que ya las usaban */
        if ($this->isAbandonLegacy() || $this->isNewsletterLegacy()) {
            $values['ICOMMKT_APIKEY'] = Configuration::get('ICOMMKT_APIKEY', null);
            $values['ICOMMKT_SECURE_TOKEN'] = Configuration::get('ICOMMKT_SECURE_TOKEN', null);
        }

        if ($this->isNewsletterLegacy()) {
            $values['ICOMMKT_PROFILEKEY'] = Configuration::get('ICOMMKT_PROFILEKEY', null);
        }

        if ($this->isAbandonLegacy()) {
            $icommkt_days_to_abandon = Configuration::get('ICOMMKT_DAYS_TO_ABANDON', null);

            $values['ICOMMKT_PROFILEKEY_ABANDON'] = Configuration::get('ICOMMKT_PROFILEKEY_ABANDON', null);
            $values['ICOMMKT_DAYS_TO_ABANDON'] = !empty($icommkt_days_to_abandon) ? $icommkt_days_to_abandon : '1';
            $values['ICOMMKT_FRIENDLY_URL'] = Configuration::get('ICOMMKT_FRIENDLY_URL', null);
        }

        return $values;
    }

    /**
     * Save form data.
     */
    protected function postProcess()
    {
        $form_values = $this->getConfigFormValues();

        foreach (array_keys($form_values) as $key) {
            Configuration::updateValue($key, Tools::getValue($key));
        }
    }

    public function getApiBodyRequest()
    {
        $input_xml = null;
        $putresource = fopen("php://input", "r");
        while ($putData = fread($putresource, 1024)) {
            $input_xml .= $putData;
        }
        fclose($putresource);
        return $input_xml;
    }

    private function getallheaders()
    {
        $headers = array();
        foreach ($_SERVER as $name => $value) {
            if (Tools::substr($name, 0, 5) == 'HTTP_') {
                $name = Tools::strtolower(str_replace('_', ' ', Tools::substr($name, 5)));
                $headers[str_replace(' ', '-', ucwords($name))] = $value;
            }
        }
        return $headers;
    }

    public function authorizeRequest()
    {
        $headers = $this->getallheaders();
        $headerKey = '';
        $headerToken = '';
        foreach ($headers as $key => $value) {
            if (Tools::strtoupper($key) == 'X-VTEX-API-APPKEY') {
                $headerKey = $value;
            }
            if (Tools::strtoupper($key) == 'X-VTEX-API-APPTOKEN') {
                $headerToken = $value;
            }
        }
        if (!empty($headerKey) && !empty($headerToken)) {
            $shopCondition = 'id_shop IS NULL';
            if (Configuration::get('PS_MULTISHOP_FEATURE_ACTIVE') && Shop::getTotalShops() > 1) {
                $shopCondition = 'id_shop IS NOT NULL';
            }

            $query = sprintf("SELECT * FROM " . _DB_PREFIX_ . "configuration WHERE name='ICOMMKT_APPKEY' "
                . "AND value='%s' AND " . $shopCondition, pSQL($headerKey));

            $result = Db::getInstance()->getRow($query);
            if ($result) {
                $this->context_id_shop = $result['id_shop'];
                $this->context_id_shop_group = $result['id_shop_group'];
            } else {
                $this->setError(
                    'Cannot find store for ICOMMKT_APPKEY - ' . $headerKey,
                    400,
                    json_encode($headers),
                    false
                );
                header("HTTP/1.1 400 Forbidden");
                die();
            }
        }
        $apiKey = Configuration::get('ICOMMKT_APPKEY', null, $this->context_id_shop_group, $this->context_id_shop);
        $apiToken = Configuration::get('ICOMMKT_APPTOKEN', null, $this->context_id_shop_group, $this->context_id_shop);

        if (empty($headerKey) || empty($headerToken) || $headerKey != $apiKey || $headerToken != $apiToken) {
            $this->setError('Bad credentials', 403, json_encode($headers), false);
            header("HTTP/1.1 403 Forbidden");
            die();
        }
    }

    public function controllerSetRespondeHeaders()
    {
        if (ob_get_level() && ob_get_length() > 0) {
            ob_end_clean();
        }
        header('Content-type: application/json');
        header('Cache-Control: no-store, no-cache');
    }

    public function setError($message, $level, $additional_data = false, $stop = true)
    {
        PrestaShopLogger::addLog('ICOMMKTCONNECTOR - ERROR: ' . $message . ' - ' . $additional_data, 4, $level);
        $this->controllerSetRespondeHeaders();
        //http_response_code($level);
        if ($stop) {
            exit(json_encode(array('error' => $message)));
        }
    }

    public function hookModuleRoutes($params)
    {
        $result = array(
            'oms_list_status' => array(
                //List Orders
                'controller' => 'oms',
                'keywords' => array(),
                'rule' => 'icommkt/oms/pvt/status_list',
                'params' => array(
                    'fc' => 'module',
                    'module' => $this->name
                ),
            ),
            'oms_list_orders' => array(
                //List Orders
                'controller' => 'oms',
                'keywords' => array(
                    'id_order' => array('regexp' => '[0-9]+', 'param' => 'id_order'),
                ),
                'rule' => 'icommkt/oms/pvt/orders{/:id_order}',
                'params' => array(
                    'fc' => 'module',
                    'module' => $this->name
                ),
            ),
            'master_data_search' => array(
                //List Orders
                'controller' => 'masterdata',
                'keywords' => array(
                    'entity_code' => array('regexp' => '[_a-zA-Z0-9\pL\pS-]*', 'param' => 'entity_code'),
                ),
                'rule' => 'icommkt/dataentities/{entity_code}/search',
                'params' => array(
                    'fc' => 'module',
                    'module' => $this->name
                ),
            ),
            'catalog_list_products' => array(
                //List Products (one row per combination / SKU)
                'controller' => 'catalog',
                'keywords' => array(
                    'id_product' => array('regexp' => '[0-9]+', 'param' => 'id_product'),
                ),
                'rule' => 'icommkt/catalog/pvt/products{/:id_product}',
                'params' => array(
                    'fc' => 'module',
                    'module' => $this->name
                ),
            ),
        );

        if ($this->isAbandonLegacy() && Configuration::get('ICOMMKT_FRIENDLY_URL', null) == 1) {
            $result[$this->name.'-abandomentcart'] = array(
                //List Orders
                'controller' => 'abandomentcart',
                'keywords' => array(
                    'action' => array('regexp' => '[_a-zA-Z0-9\pL\pS-]*', 'param' => 'action'),
                    'secure_token' => array('regexp' => '[_a-zA-Z0-9\pL\pS-]*', 'param' => 'secure_token'),
                    'id_cart' => array('regexp' => '[0-9\pL\pS-]*', 'param' => 'id_cart'),
                ),
                'rule' => 'abandomentcart/{action}/{secure_token}{/:id_cart}',
                'params' => array(
                    'fc' => 'module',
                    'module' => $this->name
                ),
            );
        }

        if ($this->isNewsletterLegacy()) {
            $result[$this->name.'-send_to_icommkt'] = array(
                'controller' => 'sendtoicommkt',
                'keywords' => array(
                    'action' => array('regexp' => '[_a-zA-Z0-9\pL\pS-]*', 'param' => 'action'),
                    'secure_token' => array('regexp' => '[_a-zA-Z0-9\pL\pS-]*', 'param' => 'secure_token'),
                ),
                'rule' => 'sendtoicommkt/{action}/{secure_token}',
                'params' => array(
                    'fc' => 'module',
                    'module' => $this->name
                ),
            );
        }

        return $result;
    }

    public function getSingleOrder($id_order)
    {
        $order = $this->getOrderInformation($id_order);
        $cart_rules = $this->getCartRules($id_order);
        if (!$cart_rules) {
            $cart_rules = array(
                array(
                    "code" => '',
                    "name" => ''
                )
            );
        }

        if (!$order) {
            exit('order not found');
        }

        $currency = Currency::getCurrency(Configuration::get('PS_CURRENCY_DEFAULT'));
        $data = array(
            'orderId' => $order['id_order'],
            'sequence' => $order['id_order'],
            'marketPlaceOrderId' => $order['id_order'],
            'marketplaceServicesEndpoint' => null,
            'sellerOrderId' => $order['id_order'],
            'origin' => null,
            'affiliateId' => null,
            'salesChannel' => 1,
            'merchantName' => null,
            'status' => $order['state_name'],
            'statusId' => $order['current_state'],
            'statusDescription' => $order['state_name'],
            'value' => $order['total_paid'],
            'creationDate' => gmdate("c", strtotime($order['date_add'])),
            'lastChange' => gmdate("c", strtotime($order['date_upd'])),
            'orderGroup' => null,
            'totals' => array(
                array(
                    'id' => 'Items',
                    'name' => 'Total de los items',
                    'values' => $order['total_products_wt'],
                ),
                array(
                    'id' => 'Discounts',
                    'name' => 'Total de descuentos',
                    'values' => $order['total_discounts_tax_incl'],
                ),
                array(
                    'id' => 'Shipping',
                    'name' => 'Costo total del envío',
                    'values' => $order['total_shipping_tax_incl'],
                ),
            ),
            'items' => $this->formatProductList($order['id_order'], true),
            'marketplaceItems' => array(),
            'clientProfileData' => array(
                'id' => $order['id_customer'],
                'email' => $order['email'],
                'firstName' => $order['firstname'],
                'lastName' => $order['lastname'],
                'documentType' => 'nif',
                'document' => $order['dni'],
                'phone' => ($order['phone'] != '' ? $order['phone'] : $order['phone_mobile']),
                'corporateName' => $order['company'],
                'tradeName' => null,
                'corporateDocument' => null,
                'stateInscription' => null,
                'corporatePhone' => null,
                'isCorporate' => false,
                'userProfileId' => $order['id_customer'],
                'customerClass' => null,

            ),
            'giftRegistryData' => null,
            'marketingData' => array(
                'id' => 'marketingData',
                'utmSource' => $cart_rules[0]['name'],
                'utmPartner' => null,
                'utmMedium' => '',
                'utmCampaign' => '',
                'coupon' => $cart_rules[0]['code'],
                'utmiCampaign' => '',
                'utmipage' => '',
                'utmiPart' => '',
                'marketingTags' => array(),
            ),
            'ratesAndBenefitsData' => array(
                'id' => 'ratesAndBenefitsData',
                'rateAndBenefitsIdentifiers' => array(),
            ),
            'shippingData' => array(
                'id' => 'shippingData',
                'address' => array(
                    'addressType' => "residential",
                    'receiverName' => $order['firstname'] . ' ' . $order['lastname'],
                    'addressId' => $order['id_address'],
                    'postalCode' => $order['postcode'],
                    'city' => $order['city'],
                    'state' => State::getNameById($order['id_state']),
                    'country' => Country::getNameById($order['id_lang'], $order['id_state']),
                    'street' => $order['address1'],
                    'number' => null,
                    'neighborhood' => null,
                    'complement' => $order['address2'],
                    'reference' => null,
                    'geoCoordinates' => array(),
                ),
                'logisticsInfo' => $this->getLogisticInfo($id_order),
                'trackingHints' => null,
                'selectedAddresses' => array(
                    array(
                        'addressId' => $order['id_address_delivery'],
                        'addressType' => "residential",
                        'receiverName' => $order['firstname'] . ' ' . $order['lastname'],
                        'street' => $order['address1'],
                        'number' => null,
                        'complement' => $order['address2'],
                        'neighborhood' => null,
                        'postalCode' => $order['postcode'],
                        'city' => $order['city'],
                        'state' => State::getNameById($order['id_state']),
                        'country' => Country::getNameById($order['id_lang'], $order['id_state']),
                        'reference' => null,
                        'geoCoordinates' => array(),
                    ),
                ),
            ),
            'paymentData' => array(
                'transactions' => array(
                    array(
                        'isActive' => true,
                        'transactionId' => "",
                        'merchantName' => Configuration::get('PS_SHOP_NAME'),
                        'payments' => $this->getDataPayment($order['reference']),
                    ),
                ),
            ),
            'packageAttachment' => array(
                'packages' => array(),
            ),
            'sellers' => array(
                array(
                    'id' => "",
                    'name' => "",
                    'logo' => "",
                ),
            ),
            'callCenterOperatorData' => null,
            'followUpEmail' => "",
            'lastMessage' => null,
            'hostname' => Configuration::get('PS_SHOP_NAME'),
            'changesAttachment' => null,
            'openTextField' => null,
            'roundingError' => 0,
            'orderFormId' => "",
            'commercialConditionData' => null,
            'isCompleted' => true,
            'customData' => null,
            'storePreferencesData' => array(
                'countryCode' => Country::getIsoById(Configuration::get('PS_SHOP_COUNTRY_ID')),
                'currencyCode' => (isset($currency['iso_code']) ? $currency['iso_code'] : 'undefined'),
                'currencyFormatInfo' => array(
                    'CurrencyDecimalDigits' => 2,
                    'CurrencyDecimalSeparator' => ",",
                    'CurrencyGroupSeparator' => ".",
                    'CurrencyGroupSize' => 3,
                    'StartsWithCurrencySymbol' => true,
                ),
                'currencyLocale' => null,
                'currencySymbol' => (isset($currency['sign']) ? $currency['sign'] : 'undefined'),
                'timeZone' => Configuration::get('PS_TIMEZONE'),
            ),
            'allowCancellation' => true,
            'allowEdition' => false,
            'isCheckedIn' => false,
            'marketplace' => array(
                'baseURL' => Configuration::get('PS_SHOP_DOMAIN'),
                'isCertified' => null,
                'name' => Configuration::get('PS_SHOP_NAME'),
            ),
        );

        exit(json_encode($data));
    }

    public function getOrders()
    {
        $orderField = null;
        $orderType = null;
        $limit = 1;
        $page = 1;
        $date_range = array();
        $date_updated = array();
        $order_states = array();

        if (Tools::getValue('page') && is_numeric(Tools::getValue('page'))) {
            $page = (int)Tools::getValue('page');
        }

        if (Tools::getValue('per_page') && is_numeric(Tools::getValue('per_page'))) {
            $limit = (int)Tools::getValue('per_page');
        }

        if (Tools::getValue('current_state') && is_array(Tools::getValue('current_state'))) {
            $order_states = Tools::getValue('current_state');
        }

        if ($orderBy = Tools::getValue('orderBy')) {
            $orderParams = explode(',', $orderBy);
            $field = $orderParams[0];

            switch ($field) {
                case 'orderId':
                    $orderField = 'id_order';
                    break;
                case 'totalValue':
                    $orderField = 'total_paid';
                    break;
                case 'creationDate':
                    $orderField = 'date_add';
                    break;
                default:
                    $orderField = null;
                    break;
            }

            $type = $orderParams[1];
            if ($type == 'asc' || $type == 'desc') {
                $orderType = $type;
            }
        }

        if ($f_creationDate = Tools::getValue('f_creationDate')) {
            preg_match('/\[(.*?)\]/s', $f_creationDate, $creationDate);
            $creationDate = explode('TO', $creationDate[1]);
            if ((bool)strtotime(trim($creationDate[0])) && (bool)strtotime(trim($creationDate[1]))) {
                $date_range['from'] = date('"Y-m-d H:i:s"', strtotime(trim($creationDate[0])));
                $date_range['to'] = date('"Y-m-d H:i:s"', strtotime(trim($creationDate[1])));
            }
        }

        if ($f_updateDate = Tools::getValue('f_updateDate')) {
            preg_match('/\[(.*?)\]/s', $f_updateDate, $updatedDate);
            $updatedDate = explode('TO', $updatedDate[1]);
            if ((bool)strtotime(trim($updatedDate[0])) && (bool)strtotime(trim($updatedDate[1]))) {
                $date_updated['from'] = date('"Y-m-d H:i:s"', strtotime(trim($updatedDate[0])));
                $date_updated['to'] = date('"Y-m-d H:i:s"', strtotime(trim($updatedDate[1])));
            }
        }

        $data_orders = $this->getOrdersWithInformations(
            $limit,
            $page,
            $orderField,
            $orderType,
            $date_range,
            $date_updated,
            $order_states
        );

        $ordersFormatVtex = array();
        $ordersFormatVtex['list'] = array();
        foreach ($data_orders['orders'] as &$order) {
            $ordersFormatVtex['list'][] = $this->formatListOrder($order);
        }

        //if (count($ordersFormatVtex['list'])) {
        $ordersFormatVtex['facets'] = array();
        $ordersFormatVtex['paging'] = array(
            'total' => (int)$data_orders['count'],
            'pages' => ceil($data_orders['count'] / $limit),
            'currentPage' => $page,
            'perPage' => $limit,
        );
        $ordersFormatVtex['stats'] = array(
            'stats' => array(
                'totalValue' => array(
                    'Count' => (int)$data_orders['count'],
                    'Max' => 0,
                    'Mean' => 0,
                    'Min' => 0,
                    'Missing' => 0,
                    'StdDev' => 0,
                    'Sum' => 0,
                    'SumOfSquares' => 0,
                    'Facets' => array(),
                ),
                'totalItems' => array(
                    'Count' => (int)$data_orders['count'],
                    'Max' => 0,
                    'Mean' => 0,
                    'Min' => 0,
                    'Missing' => 0,
                    'StdDev' => 0,
                    'Sum' => 0,
                    'SumOfSquares' => 0,
                    'Facets' => array(),
                ),
            ),
        );
        //}

        exit(json_encode($ordersFormatVtex));
    }


    public function formatListOrder($order)
    {
        $data = array(
            'orderId' => $order['id_order'],
            'creationDate' => gmdate("c", strtotime($order['date_add'])),
            'clientName' => $order['firstname'] . ' ' . $order['lastname'],
            'totalValue' => round($order['total_paid'], 2),
            'paymentNames' => $order['payment'],
            'status' => $order['state_name'],
            'statusId' => $order['current_state'],
            'statusDescription' => $order['state_name'],
            'marketPlaceOrderId' => $order['id_order'],
            'sequence' => $order['id_order'],
            'salesChannel' => 1,
            'affiliateId' => null,
            'origin' => null,
            'workflowInErrorState' => null,
            'workflowInRetry' => null,
            'lastMessageUnread' => null,
            'ShippingEstimatedDate' => 'undefined',
            'orderIsComplete' => ($order['valid'] ? true : false),
            'listId' => null,
            'listType' => null,
            'authorizedDate' => gmdate("c", strtotime($order['date_add'])),
            'callCenterOperatorName' => 'undefined',
            'items' => $this->formatProductList($order['id_order']),
        );

        return $data;
    }

    public function getOrdersWithInformations(
        $limit = null,
        $page = null,
        $orderField = null,
        $orderType = null,
        $date_range = array(),
        $date_updated = array(),
        $order_states = array(),
        Context $context = null
    ) {
        $result = array();

        if (!$context) {
            $context = Context::getContext();
        }

        if (!$page) {
            $n = 0;
        } else {
            $n = ((int)$page - 1) * (int)$limit;
        }
        $where = '';

        if (count($date_range)) {
            $where .= ' AND o.date_add BETWEEN ' . $date_range['from'] . ' AND ' . $date_range['to'];
        }

        if (count($date_updated)) {
            $where .= ' AND o.date_upd BETWEEN ' . $date_updated['from'] . ' AND ' . $date_updated['to'];
        }

        if (count($order_states)) {
            $where .= ' AND o.current_state IN (' . implode(',', $order_states) . ') ';
        }


        $sql = 'SELECT *, (
					SELECT osl.`name`
					FROM `' . _DB_PREFIX_ . 'order_state_lang` osl
					WHERE osl.`id_order_state` = o.`current_state`
					AND osl.`id_lang` = ' . (int)$context->language->id . '
					LIMIT 1
				) AS `state_name`,
                                (
					SELECT c.`name`
					FROM `' . _DB_PREFIX_ . 'carrier` c
					WHERE c.`id_carrier` = o.`id_carrier`
					LIMIT 1
				) AS `carrier_name`,
                                o.`date_add` AS `date_add`, o.`date_upd` AS `date_upd`
				FROM `' . _DB_PREFIX_ . 'orders` o
				LEFT JOIN `' . _DB_PREFIX_ . 'customer` c ON (c.`id_customer` = o.`id_customer`)
                                LEFT JOIN `' . _DB_PREFIX_ . 'address` ad ON (ad.`id_address` = o.`id_address_delivery`)
				WHERE 1
					' . Shop::addSqlRestriction(false, 'o') . '
                    ' . $where . '
                ORDER BY o.' . ($orderField ? $orderField : 'id_order') . ' ' . ($orderType ? $orderType : 'DESC') . '
				' . ((int)$limit ? 'LIMIT ' . (int)$n . ', ' . (int)$limit : '');

        $result['orders'] = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql);


        $sql = 'SELECT count(id_order) 
                FROM `' . _DB_PREFIX_ . 'orders` o
                WHERE 1
                    ' . Shop::addSqlRestriction(false, 'o') . '
                    ' . $where . '
                ORDER BY o.' . ($orderField ? $orderField : 'id_order') . ' ' . ($orderType ? $orderType : 'DESC');

        $result['count'] = Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue($sql);

        return $result;
    }

    public function getOrderInformation($id_order, Context $context = null)
    {
        if (!$context) {
            $context = Context::getContext();
        }

        $limit = false;
        $sql = 'SELECT *, (
                    SELECT osl.`name`
                    FROM `' . _DB_PREFIX_ . 'order_state_lang` osl
                    WHERE osl.`id_order_state` = o.`current_state`
                    AND osl.`id_lang` = ' . (int)$context->language->id . '
                    LIMIT 1
                ) AS `state_name`,
                                (
                    SELECT c.`name`
                    FROM `' . _DB_PREFIX_ . 'carrier` c
                    WHERE c.`id_carrier` = o.`id_carrier`
                    LIMIT 1
                ) AS `carrier_name`,
                                o.`date_add` AS `date_add`, o.`date_upd` AS `date_upd`
                FROM `' . _DB_PREFIX_ . 'orders` o
                LEFT JOIN `' . _DB_PREFIX_ . 'customer` c ON (c.`id_customer` = o.`id_customer`)
                                LEFT JOIN `' . _DB_PREFIX_ . 'address` ad ON (ad.`id_address` = o.`id_address_delivery`)
                WHERE 1
                    ' . Shop::addSqlRestriction(false, 'o') . ' AND id_order = ' . $id_order . '
                ORDER BY o.`date_add` DESC
                ' . ((int)$limit ? 'LIMIT 0, ' . (int)$limit : '');
        return Db::getInstance(_PS_USE_SQL_SLAVE_)->getRow($sql);
    }

    public function formatProductList($id_order, $extend = false, Context $context = null)
    {
        if (!$context) {
            $context = Context::getContext();
        }

        $products = array();

        foreach (OrderDetail::getList($id_order) as $item) {
            $data = array(
                'seller' => null,
                'quantity' => $item['product_quantity'],
                'description' => $item['product_name'],
                'ean' => $item['product_ean13'],
                'refId' => $item['product_reference'],
                'id' => (empty($item['product_attribute_id']) ? $item['product_id'] : $item['product_attribute_id']),
                'productId' => $item['product_id'],
                'sellingPrice' => round($item['unit_price_tax_incl'], 2),
                'price' => round($item['total_price_tax_incl'], 2),
            );

            if ($extend) {
                $product = new Product($item['product_id'], false, (int)$context->language->id);

                $image = Image::getCover($item['product_id']);
                $image_result = (isset($image['id_image']))
                    ?
                    $context->link->getImageLink($product->link_rewrite, $image['id_image'])
                    :
                    'not_cover_image';
                $data_extend = array(
                    'uniqueId' => $item['product_id'],
                    'description' => $item['product_name'],
                    'listPrice' => round($item['original_product_price'], 2),
                    'manualPrice' => null,
                    'priceTags' => array(),
                    'imageUrl' => $image_result,
                    'detailUrl' => $context->link->getProductLink($item['product_id']),
                    'categories' => array_column(Product::getProductCategoriesFull($item['product_id']), 'name'),
                    'components' => array(),
                    'bundleItems' => array(),
                    'params' => array(),
                    'offerings' => array(),
                    'sellerSku' => $item['product_attribute_id'],
                    'priceValidUntil' => null,
                    'commission' => 0,
                    'tax' => $item['tax_rate'],
                    'preSaleDate' => null,
                    'additionalInfo' => array(
                        'brandName' => $product->manufacturer_name,
                        'brandId' => $product->id_manufacturer,
                        'categoriesIds' => implode(',', $product->getCategories()),
                        'productClusterId' => '',
                        'commercialConditionId' => "1",
                        'dimension' => array(
                            'cubicweight' => 1,
                            'height' => 1,
                            'length' => 1,
                            'weight' => 1,
                            'width' => 1
                        ),
                        'offeringInfo' => null,
                        'offeringType' => null,
                        'offeringTypeId' => null,
                    ),
                    'measurementUnit' => "un",
                    'unitMultiplier' => 1,
                    'isGift' => false,
                    'shippingPrice' => round($item['total_shipping_price_tax_incl'], 2),
                    'rewardValue' => 0,
                    'freightCommission' => 0,
                );

                $data = array_merge($data, $data_extend);
            }

            $products[] = $data;
        }

        return $products;
    }

    public function getLogisticInfo($id_order)
    {
        $data = array();

        foreach (OrderDetail::getList($id_order) as $key => $item) {
            $data[] = array(
                'itemIndex' => $key,
                'selectedSla' => "Normal",
                'lockTTL' => "12d",
                'price' => round($item['unit_price_tax_incl'], 2),
                'listPrice' => round($item['original_product_price'], 2),
                'sellingPrice' => round($item['unit_price_tax_incl'], 2),
                'deliveryWindow' => null,
                'deliveryCompany' => "",
                'shippingEstimate' => "",
                'shippingEstimateDate' => "",
                'slas' => array(
                    array(
                        'id' => "Normal",
                        'name' => "Normal",
                        'shippingEstimate' => "",
                        'deliveryWindow' => null,
                        'price' => round($item['unit_price_tax_incl'], 2),
                        'deliveryChannel' => "delivery",
                        'pickupStoreInfo' => array(
                            'additionalInfo' => null,
                            'address' => null,
                            'dockId' => null,
                            'friendlyName' => null,
                            'isPickupStore' => false,
                        ),
                    ),
                ),
                'deliveryIds' => array(
                    array(
                        'courierId' => "1",
                        'courierName' => "",
                        'dockId' => "1",
                        'quantity' => 1,
                        'warehouseId' => "1_1",
                    ),
                ),
                'deliveryChannel' => 'delivery',
                'pickupStoreInfo' => array(
                    'additionalInfo' => null,
                    'address' => null,
                    'dockId' => null,
                    'friendlyName' => null,
                    'isPickupStore' => false,
                ),
                'addressId' => ""
            );
        }
    }

    public function getCartRules($id_order)
    {
        return Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS('
        SELECT *
        FROM `' . _DB_PREFIX_ . 'order_cart_rule` ocr
        LEFT JOIN `' . _DB_PREFIX_ . 'cart_rule` cr ON ocr.id_cart_rule = cr.id_cart_rule
        WHERE ocr.`id_order` = ' . (int)$id_order);
    }

    public static function getDataPayment($order_reference)
    {
        $payments = Db::getInstance()->executeS("
            SELECT *
            FROM `" . _DB_PREFIX_ . "order_payment`
            WHERE `order_reference` = '" . $order_reference . "'");

        $data = array();

        foreach ($payments as $value) {
            $data[] = array(
                'id' => $value['transaction_id'],
                'paymentSystem' => "",
                'paymentSystemName' => $value['card_brand'],
                'value' => $value['amount'],
                'installments' => 3,
                'referenceValue' => $value['transaction_id'],
                'cardHolder' => $value['card_holder'],
                'cardNumber' => $value['card_number'],
                'firstDigits' => "",
                'lastDigits' => "",
                'cvv2' => null,
                'expireMonth' => ($value['card_expiration'] ? Tools::substr($value['card_expiration'], 2) : null),
                'expireYear' => ($value['card_expiration'] ? Tools::substr($value['card_expiration'], -2) : null),
                'url' => null,
                'giftCardId' => null,
                'giftCardName' => null,
                'giftCardCaption' => null,
                'redemptionCode' => null,
                'group' => "",
                'tid' => "",
                'dueDate' => null,
                'connectorResponses' => array(),
            );
        }

        return $data;
    }

    public function getClients()
    {
        $customers = $this->getCustomers();
        $prepared_data = array();
        $langs = Language::getLanguages();

        foreach ($customers as $customer) {
            $customer['address_data_object'] = $this->getAddressCustomer($customer['id_customer']);
            $customer['address_company_object'] = $this->getAddressCompanyCustomer($customer['id_customer']);

            if(($customer['id_lang'] && isset($langs))) {
                $localeDefaultKey = array_search($customer['id_lang'], array_column($langs, 'id_lang'));
                $localeDefault = $langs[$localeDefaultKey]['iso_code'];
            } else {
                $localeDefault = null;
            }
            $customer['localeDefault'] = $localeDefault;
            $prepared_data[] = $this->formatCustomerDataToVTEX($customer);
        }
        die(json_encode($prepared_data));
    }

    public function getCustomers($only_active = false)
    {
        $where_params = Tools::getValue('_where');
        if (!$where_params || strpos($where_params, 'lastInteractionIn') === false
            || strpos($where_params, 'createdIn') === false
        ) {
            die('no "where" parameter missing lastInteractionIn/createdIn on where clausule');
        }
        $where_params = $this->sanitizeWhereParams($where_params);

        $page = 1;
        $limit = 1;
        if (Tools::getValue('page') && is_numeric(Tools::getValue('page'))) {
            $page = (int)Tools::getValue('page');
        }
        if (Tools::getValue('per_page') && is_numeric(Tools::getValue('per_page'))) {
            $limit = (int)Tools::getValue('per_page');
        }
        if (!$page) {
            $n = 0;
        } else {
            $n = ((int)$page - 1) * (int)$limit;
        }

        $where_params = str_replace('lastInteractionIn between ', 'date_upd between \'', $where_params);
        $where_params = str_replace('createdIn between ', 'date_add between \'', $where_params);
        $where_params = str_replace(' AND ', '\' AND \'', $where_params);
        $where_params = str_replace(')', '\')', $where_params);
        $sql = 'SELECT *
                FROM `' . _DB_PREFIX_ . 'customer`
                WHERE 1 ' . Shop::addSqlRestriction(Shop::SHARE_CUSTOMER) .
            ($only_active ? ' AND `active` = 1' : '') . '
                ' . ($where_params ? ' AND (' . $where_params . ')' : '') . '
                ORDER BY `date_add` ASC 
                ' . ((int)$limit ? 'LIMIT ' . (int)$n . ', ' . (int)$limit : '');

        $sql_count = 'SELECT count(*) cuenta 
                FROM `' . _DB_PREFIX_ . 'customer`
                WHERE 1 ' . Shop::addSqlRestriction(Shop::SHARE_CUSTOMER) .
            ($only_active ? ' AND `active` = 1' : '') . '
                ' . ($where_params ? ' AND (' . $where_params . ')' : '') . '
                ORDER BY `date_add` ASC';

        $count = Db::getInstance()->getValue($sql_count);
        header('Total-Records: ' . $count);

        return Db::getInstance()->executeS($sql);
    }

    public function getAddressCustomer($id_customer, $active = true)
    {
        $result = Db::getInstance(_PS_USE_SQL_SLAVE_)->getRow(
            'SELECT *
            FROM `' . _DB_PREFIX_ . 'address`
            WHERE `id_customer` = ' . (int)$id_customer . ' AND `deleted` = 0' . ($active ? ' AND `active` = 1' : '')
        );
        return $result;
    }

    public function getAddressCompanyCustomer($id_customer, $active = true)
    {
        $result = Db::getInstance(_PS_USE_SQL_SLAVE_)->getRow(
            'SELECT *
            FROM `' . _DB_PREFIX_ . 'address`
            WHERE `id_customer` = ' . (int)$id_customer . ' '
            . "AND company <> '' "
            . 'AND `deleted` = 0' . ($active ? ' AND `active` = 1' : '')
        );
        return $result;
    }

    public function formatCustomerDataToVTEX($customer)
    {
        $data = array(
            'email' => $customer['email'],
            'approved' => $customer['active'],
            'attach' => null,
            'birthDate' => $customer['birthday'],
            'firstName' => $customer['firstname'],
            'gender' => $customer['id_gender'],
            'isNewsletterOptIn' => $customer['newsletter'],
            'lastName' => $customer['lastname'],
            'localeDefault' => $customer['localeDefault'],
            'nickName' => null,
            'tradeName' => null,
            'userId' => $customer['id_customer']
        );
        $data += array(
            'phone' => (($customer['address_data_object']) ? $customer['address_data_object']['phone_mobile'] : null),
            'homePhone' => (($customer['address_data_object']) ? $customer['address_data_object']['phone'] : null),
            'document' => (($customer['address_data_object']) ? $customer['address_data_object']['dni'] : null),
            'documentType' => (($customer['address_data_object']) ?
                ($customer['address_data_object']['dni'] ? 'dni' : null) : null),
        );
        $data += array(
            'businessPhone' => (($customer['address_company_object']) ?
                $customer['address_company_object']['phone'] : null),
            'corporateName' => (($customer['address_company_object']) ?
                $customer['address_company_object']['company'] : null),
            'isCorporate' => (($customer['address_company_object']) ?
                ($customer['address_company_object']['company'] ? 1 : null) : null),
        );
        return $data;
    }

    public function getStatusList(Context $context = null)
    {
        if (!$context) {
            $context = Context::getContext();
        }
        $orderStatus = OrderState::getOrderStates((int)$context->language->id);
        exit(json_encode($orderStatus));
    }

    /**
     * REST endpoint: catálogo completo, una fila por SKU real (combinación).
     *
     * Parámetros GET:
     *   page          int    página, base 1 (por defecto 1)
     *   per_page      int    filas por página (por defecto 50, máximo 200)
     *   id_product    int    id exacto de producto
     *   sku           string referencia / EAN / UPC de producto o combinación (coincidencia parcial)
     *   name          string nombre del producto (coincidencia parcial)
     *   search        string texto libre: id, referencia, EAN o nombre
     *   active        int    1 solo activos, 0 solo inactivos, ausente = todos
     *   updated_since string fecha; filtra por product.date_upd
     *   orderBy       string "<campo>,<asc|desc>"; campo en {id, reference, name, price, quantity, dateUpdated}
     *   with_tax      int    1 (por defecto) precios con impuestos, 0 sin impuestos
     *   id_lang       int    idioma; por defecto el del contexto
     */
    public function getProducts()
    {
        $id_lang = (int)Tools::getValue('id_lang');
        if (!$id_lang && Validate::isLoadedObject($this->context->language)) {
            $id_lang = (int)$this->context->language->id;
        }
        if (!$id_lang) {
            $id_lang = (int)Configuration::get('PS_LANG_DEFAULT');
        }

        $id_shop = (int)$this->context_id_shop;
        if (!$id_shop) {
            $id_shop = (int)$this->context->shop->id;
        }

        /* El controlador no llama a parent::init(), así que hay que asegurar el contexto
           del que dependen Product::getPriceStatic() y Link::getProductLink() */
        if ((int)$this->context->shop->id != $id_shop) {
            Shop::setContext(Shop::CONTEXT_SHOP, $id_shop);
            $this->context->shop = new Shop($id_shop);
        }
        if (!Validate::isLoadedObject($this->context->country)) {
            $this->context->country = new Country((int)Configuration::get('PS_COUNTRY_DEFAULT'), $id_lang);
        }
        if (!Validate::isLoadedObject($this->context->currency)) {
            $this->context->currency = new Currency((int)Configuration::get('PS_CURRENCY_DEFAULT'));
        }

        /* PrestaShop 8 y 9 exigen un carrito o un empleado en el contexto para calcular precios:
           Product::getPriceStatic() lanza "If no employee is assigned in the context, cart ID must
           be provided to this method". Una API de lectura no tiene ni cliente ni empleado, así que
           se prepara un carrito vacío (y un empleado, por si la comprobación cambia de forma). */
        if (!Validate::isLoadedObject($this->context->cart)) {
            $cart = new Cart();
            $cart->id_shop = $id_shop;
            $cart->id_lang = $id_lang;
            $cart->id_currency = (int)$this->context->currency->id;
            $cart->id_customer = 0;
            $this->context->cart = $cart;
        }
        if (!isset($this->context->employee)) {
            $this->context->employee = new Employee();
        }

        $page = (int)Tools::getValue('page');
        if ($page < 1) {
            $page = 1;
        }

        $limit = (int)Tools::getValue('per_page');
        if ($limit < 1) {
            $limit = self::CATALOG_PER_PAGE_DEFAULT;
        }
        if ($limit > self::CATALOG_PER_PAGE_MAX) {
            $limit = self::CATALOG_PER_PAGE_MAX;
        }

        $offset = ($page - 1) * $limit;
        $with_tax = (Tools::getValue('with_tax') === '0' ? false : true);

        /* El FROM y el WHERE se generan una sola vez y se reutilizan en la query de datos y en la de
           COUNT, para que el total y el listado no puedan desincronizarse */
        $from = $this->getProductsSqlFrom($id_lang, $id_shop);
        $where = $this->getProductsSqlFilters();

        $sql = $this->getProductsSqlSelect($id_lang, $id_shop) . $from . '
                WHERE 1 ' . $where . '
                ORDER BY ' . $this->getProductsSqlOrder() . '
                LIMIT ' . (int)$offset . ', ' . (int)$limit;

        $this->catalog_debug_sql = $sql;

        $rows = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql);

        /* En producción un SQL erróneo devuelve false sin lanzar excepción y el listado saldría vacío */
        if ($rows === false && $this->catalog_debug) {
            $this->exitCatalogDebug(array(
                'type' => 'SQL error',
                'message' => Db::getInstance()->getMsgError(),
                'code' => Db::getInstance()->getNumberError(),
            ));
        }

        $count = (int)Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue(
            'SELECT COUNT(*) ' . $from . ' WHERE 1 ' . $where
        );

        $products = array();
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if ($this->catalog_debug) {
                    /* Deja rastro de la última fila procesada: si un producto concreto provoca un
                       fatal no capturable, el shutdown handler dirá cuál */
                    $this->catalog_debug_row = 'id_product=' . (int)$row['id_product']
                        . ' id_product_attribute=' . (int)$row['id_product_attribute'];
                }
                $products[] = $this->formatProductRow($row, $id_lang, $id_shop, $with_tax);
            }
        }

        $data = array(
            'products' => $products,
            'paging' => array(
                'total' => $count,
                'pages' => (int)ceil($count / $limit),
                'currentPage' => $page,
                'perPage' => $limit,
            ),
        );

        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $this->setError('Cannot encode catalog payload - ' . json_last_error_msg(), 500);
        }

        $this->controllerSetRespondeHeaders();
        header('Total-Records: ' . $count);
        exit($json);
    }

    /**
     * Modo diagnóstico del endpoint de catálogo (debug=1). Solo es alcanzable con credenciales
     * válidas, porque authorizeRequest() se ejecuta antes.
     *
     * No envuelve la ejecución en try/catch: instala manejadores globales, de modo que el camino
     * normal del código no cambia en absoluto cuando el diagnóstico está desactivado.
     */
    public function enableCatalogDebug()
    {
        $this->catalog_debug = true;

        /* Los errores se devuelven en el JSON, nunca impresos en medio de la respuesta */
        @ini_set('display_errors', 0);

        set_exception_handler(array($this, 'catalogDebugException'));
        /* Captura lo que un try/catch no puede: agotamiento de memoria o de tiempo */
        register_shutdown_function(array($this, 'catalogDebugShutdown'));
    }

    public function catalogDebugException($exception)
    {
        $this->handleCatalogError($exception);
    }

    /**
     * Registra siempre el error en los logs de PrestaShop (Parámetros avanzados -> Logs), de modo que
     * quede rastro aunque la respuesta HTTP no llegue a mostrarse, y lo devuelve en JSON.
     */
    public function handleCatalogError($exception)
    {
        $detail = array(
            'type' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile() . ':' . $exception->getLine(),
            'trace' => explode("\n", $exception->getTraceAsString()),
        );

        $this->exitCatalogDebug($detail);
    }

    /**
     * Respuesta mínima que confirma que la ruta, el controlador y la autenticación funcionan
     * sin llegar a consultar el catálogo (parámetro ping=1).
     */
    public function exitCatalogPing()
    {
        $this->controllerSetRespondeHeaders();

        exit(json_encode(array(
            'ping' => 'ok',
            'module' => $this->name,
            'moduleVersion' => $this->version,
            'psVersion' => _PS_VERSION_,
            'phpVersion' => phpversion(),
            'idShop' => (int)$this->context_id_shop,
            'contextShop' => (isset($this->context->shop) ? (int)$this->context->shop->id : null),
            'contextLang' => (isset($this->context->language) ? (int)$this->context->language->id : null),
            'memoryLimit' => ini_get('memory_limit'),
            'maxExecutionTime' => ini_get('max_execution_time'),
        ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function catalogDebugShutdown()
    {
        $error = error_get_last();
        $fatal = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);

        /* En una respuesta correcta ya se ha hecho exit() con el JSON y aquí no hay error fatal */
        if (!$error || !in_array($error['type'], $fatal)) {
            return;
        }

        $this->exitCatalogDebug(array(
            'type' => 'PHP Fatal error',
            'message' => $error['message'],
            'file' => $error['file'] . ':' . $error['line'],
        ));
    }

    protected function exitCatalogDebug($error)
    {
        /* El error de SQL no trae fichero ni traza */
        $error = array_merge(array('type' => 'unknown', 'message' => '', 'file' => ''), $error);

        $error['lastSql'] = $this->catalog_debug_sql;
        $error['lastRow'] = $this->catalog_debug_row;
        $error['psVersion'] = _PS_VERSION_;
        $error['phpVersion'] = phpversion();
        $error['memoryLimit'] = ini_get('memory_limit');
        $error['memoryPeak'] = round(memory_get_peak_usage(true) / 1048576, 1) . ' MB';
        $error['maxExecutionTime'] = ini_get('max_execution_time');

        /* Se registra siempre, con o sin debug: si PrestaShop intercepta la respuesta y muestra su
           página 500, el error queda igualmente en Parámetros avanzados -> Logs */
        PrestaShopLogger::addLog(
            'ICOMMKTCONNECTOR - CATALOG: ' . $error['type'] . ' - ' . $error['message']
            . ' @ ' . $error['file'] . ' - lastRow: ' . $error['lastRow'],
            3
        );

        $this->controllerSetRespondeHeaders();
        header('HTTP/1.1 500 Internal Server Error');

        exit(json_encode(array('error' => $error), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Joins que definen la cardinalidad del resultado: una fila por SKU real.
     * Un producto con N combinaciones produce N filas; uno sin combinaciones, una sola.
     */
    protected function getProductsSqlFrom($id_lang, $id_shop)
    {
        return ' FROM `' . _DB_PREFIX_ . 'product` p
                INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps
                    ON (ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . (int)$id_shop . ')
                INNER JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                    ON (pl.`id_product` = p.`id_product`
                        AND pl.`id_lang` = ' . (int)$id_lang . '
                        AND pl.`id_shop` = ' . (int)$id_shop . ')
                LEFT JOIN `' . _DB_PREFIX_ . 'product_attribute` pa
                    ON (pa.`id_product` = p.`id_product`)
                LEFT JOIN `' . _DB_PREFIX_ . 'product_attribute_shop` pas
                    ON (pas.`id_product_attribute` = pa.`id_product_attribute`
                        AND pas.`id_shop` = ' . (int)$id_shop . ')
                LEFT JOIN `' . _DB_PREFIX_ . 'manufacturer` m
                    ON (m.`id_manufacturer` = p.`id_manufacturer`)
                LEFT JOIN `' . _DB_PREFIX_ . 'category_lang` cl
                    ON (cl.`id_category` = p.`id_category_default`
                        AND cl.`id_lang` = ' . (int)$id_lang . '
                        AND cl.`id_shop` = ' . (int)$id_shop . ')';
    }

    /**
     * Stock, imagen y nombre de la combinación van como subconsultas escalares (no como joins) para
     * no alterar la cardinalidad: MySQL solo las evalúa para las filas que sobreviven al LIMIT.
     */
    protected function getProductsSqlSelect($id_lang, $id_shop)
    {
        $stock_restriction = StockAvailable::addSqlShopRestriction(null, (int)$id_shop, 'sa');

        return 'SELECT
                p.`id_product`,
                IFNULL(pa.`id_product_attribute`, 0) AS `id_product_attribute`,
                IFNULL(NULLIF(pa.`reference`, \'\'), p.`reference`) AS `sku_reference`,
                IFNULL(NULLIF(pa.`ean13`, \'\'), p.`ean13`) AS `sku_ean13`,
                IFNULL(NULLIF(pa.`upc`, \'\'), p.`upc`) AS `sku_upc`,
                p.`date_add`, p.`date_upd`,
                (p.`weight` + IFNULL(pa.`weight`, 0)) AS `sku_weight`,
                ps.`active`, ps.`visibility`,
                (ps.`price` + IFNULL(pas.`price`, 0)) AS `base_price`,
                pl.`name`, pl.`link_rewrite`, pl.`description_short`,
                m.`name` AS `manufacturer_name`,
                cl.`name` AS `category_name`, cl.`link_rewrite` AS `category_link_rewrite`,
                (SELECT sa.`quantity`
                    FROM `' . _DB_PREFIX_ . 'stock_available` sa
                    WHERE sa.`id_product` = p.`id_product`
                        AND sa.`id_product_attribute` = IFNULL(pa.`id_product_attribute`, 0)
                        ' . $stock_restriction . '
                    LIMIT 1) AS `quantity`,
                (SELECT pai.`id_image`
                    FROM `' . _DB_PREFIX_ . 'product_attribute_image` pai
                    WHERE pai.`id_product_attribute` = pa.`id_product_attribute`
                    ORDER BY pai.`id_image` ASC LIMIT 1) AS `id_image_attribute`,
                (SELECT ims.`id_image`
                    FROM `' . _DB_PREFIX_ . 'image_shop` ims
                    WHERE ims.`id_product` = p.`id_product`
                        AND ims.`id_shop` = ' . (int)$id_shop . '
                        AND ims.`cover` = 1
                    LIMIT 1) AS `id_image_cover`,
                (SELECT GROUP_CONCAT(CONCAT(agl.`name`, \': \', al.`name`)
                            ORDER BY ag.`position` ASC SEPARATOR \', \')
                    FROM `' . _DB_PREFIX_ . 'product_attribute_combination` pac
                    INNER JOIN `' . _DB_PREFIX_ . 'attribute` a
                        ON (a.`id_attribute` = pac.`id_attribute`)
                    INNER JOIN `' . _DB_PREFIX_ . 'attribute_group` ag
                        ON (ag.`id_attribute_group` = a.`id_attribute_group`)
                    INNER JOIN `' . _DB_PREFIX_ . 'attribute_lang` al
                        ON (al.`id_attribute` = a.`id_attribute` AND al.`id_lang` = ' . (int)$id_lang . ')
                    INNER JOIN `' . _DB_PREFIX_ . 'attribute_group_lang` agl
                        ON (agl.`id_attribute_group` = ag.`id_attribute_group`
                            AND agl.`id_lang` = ' . (int)$id_lang . ')
                    WHERE pac.`id_product_attribute` = pa.`id_product_attribute`) AS `combination_name`';
    }

    protected function getProductsSqlFilters()
    {
        $where = '';

        if ($id_product = (int)Tools::getValue('id_product')) {
            $where .= ' AND p.`id_product` = ' . (int)$id_product;
        }

        if ($sku = Tools::getValue('sku')) {
            $sku = $this->escapeLikeValue($sku);
            $where .= ' AND (p.`reference` LIKE \'%' . $sku . '%\'
                        OR pa.`reference` LIKE \'%' . $sku . '%\'
                        OR p.`ean13` LIKE \'%' . $sku . '%\'
                        OR pa.`ean13` LIKE \'%' . $sku . '%\'
                        OR p.`upc` LIKE \'%' . $sku . '%\'
                        OR pa.`upc` LIKE \'%' . $sku . '%\')';
        }

        if ($name = Tools::getValue('name')) {
            $where .= ' AND pl.`name` LIKE \'%' . $this->escapeLikeValue($name) . '%\'';
        }

        if ($search = Tools::getValue('search')) {
            $like = $this->escapeLikeValue($search);
            $where .= ' AND (pl.`name` LIKE \'%' . $like . '%\'
                        OR p.`reference` LIKE \'%' . $like . '%\'
                        OR pa.`reference` LIKE \'%' . $like . '%\'
                        OR p.`ean13` LIKE \'%' . $like . '%\'
                        OR pa.`ean13` LIKE \'%' . $like . '%\'';
            if (Validate::isUnsignedId($search)) {
                $where .= ' OR p.`id_product` = ' . (int)$search;
            }
            $where .= ')';
        }

        $active = Tools::getValue('active');
        if ($active !== false && $active !== '') {
            $where .= ' AND ps.`active` = ' . ((int)$active ? 1 : 0);
        }

        if ($updated_since = Tools::getValue('updated_since')) {
            $timestamp = strtotime($updated_since);
            if ($timestamp) {
                $where .= ' AND p.`date_upd` >= \'' . pSQL(date('Y-m-d H:i:s', $timestamp)) . '\'';
            }
        }

        /* Descarta combinaciones no asociadas a esta tienda sin perder los productos sin combinaciones */
        $where .= ' AND (pa.`id_product_attribute` IS NULL OR pas.`id_product_attribute` IS NOT NULL)';

        if (Tools::version_compare(_PS_VERSION_, '1.7.0.0', '>=') == true) {
            /* 1.7 marca con state = 0 los productos en borrador; en 1.6 la columna no existe */
            $where .= ' AND p.`state` = 1';
        }

        return $where;
    }

    protected function getProductsSqlOrder()
    {
        $field = 'p.`id_product`';
        $way = 'ASC';

        if ($orderBy = Tools::getValue('orderBy')) {
            $orderParams = explode(',', $orderBy);

            switch ($orderParams[0]) {
                case 'reference':
                    $field = '`sku_reference`';
                    break;
                case 'name':
                    $field = 'pl.`name`';
                    break;
                case 'price':
                    $field = '`base_price`';
                    break;
                case 'quantity':
                    $field = '`quantity`';
                    break;
                case 'dateUpdated':
                    $field = 'p.`date_upd`';
                    break;
                case 'id':
                default:
                    $field = 'p.`id_product`';
                    break;
            }

            if (isset($orderParams[1]) && Tools::strtolower(trim($orderParams[1])) == 'desc') {
                $way = 'DESC';
            }
        }

        /* Desempate obligatorio: sin él la paginación puede repetir o perder filas */
        return $field . ' ' . $way . ', p.`id_product` ASC, IFNULL(pa.`id_product_attribute`, 0) ASC';
    }

    /**
     * pSQL() primero y después los comodines: al revés, pSQL() duplicaría la contrabarra
     * y MySQL interpretaría contrabarra literal + comodín.
     */
    protected function escapeLikeValue($value)
    {
        return str_replace(array('%', '_'), array('\\%', '\\_'), pSQL(trim($value)));
    }

    /**
     * 1.6 expone ImageType::getFormatedName() (una sola "t") y 1.7 getFormattedName().
     * method_exists es más fiable que comparar versiones: 1.7.0-1.7.5 mantuvieron el alias.
     */
    protected function getCatalogImageType()
    {
        if (method_exists('ImageType', 'getFormattedName')) {
            return ImageType::getFormattedName('large');
        }

        return ImageType::getFormatedName('large');
    }

    /**
     * $usereduc a false devuelve el precio sin descuentos aplicados (listPrice).
     *
     * El cálculo de precios de PrestaShop depende de mucho contexto y puede fallar para un producto
     * concreto; un catálogo completo no debe caerse por eso, así que se cae al precio base de la
     * consulta dejando rastro en los logs.
     */
    protected function getCatalogPrice($id_product, $ipa, $with_tax, $usereduc, $row)
    {
        try {
            return (float)Product::getPriceStatic($id_product, $with_tax, $ipa, 2, null, false, $usereduc);
        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                'ICOMMKTCONNECTOR - CATALOG: precio de respaldo para id_product=' . (int)$id_product
                . ' (' . $e->getMessage() . ')',
                2
            );

            return round((float)$row['base_price'], 2);
        }
    }

    protected function formatProductRow($row, $id_lang, $id_shop, $with_tax)
    {
        $id_product = (int)$row['id_product'];
        $id_product_attribute = (int)$row['id_product_attribute'];
        $ipa = ($id_product_attribute ? $id_product_attribute : null);

        $link = $this->context->link;
        if (!$link) {
            $link = new Link();
        }

        /* La imagen de la combinación tiene prioridad sobre la portada del producto */
        $id_image = (int)$row['id_image_attribute'];
        if (!$id_image) {
            $id_image = (int)$row['id_image_cover'];
        }

        $image_url = null;
        if ($id_image) {
            $image_url = $link->getImageLink(
                $row['link_rewrite'],
                $id_product . '-' . $id_image,
                $this->getCatalogImageType()
            );
        }

        $combination_name = ($row['combination_name'] ? $row['combination_name'] : null);
        $quantity = (int)$row['quantity'];

        return array(
            'sku' => (string)$id_product . ($id_product_attribute ? '-' . $id_product_attribute : ''),
            'idProduct' => $id_product,
            'idProductAttribute' => $id_product_attribute,
            'reference' => $row['sku_reference'],
            'ean13' => $row['sku_ean13'],
            'upc' => $row['sku_upc'],
            'name' => $row['name'],
            'combinationName' => $combination_name,
            'fullName' => $row['name'] . ($combination_name ? ' - ' . $combination_name : ''),
            'descriptionShort' => Tools::substr(strip_tags((string)$row['description_short']), 0, 500),
            'price' => $this->getCatalogPrice($id_product, $ipa, $with_tax, true, $row),
            'listPrice' => $this->getCatalogPrice($id_product, $ipa, $with_tax, false, $row),
            'basePriceTaxExcl' => (float)$row['base_price'],
            'priceIncludesTax' => (bool)$with_tax,
            'currency' => $this->context->currency->iso_code,
            'quantity' => $quantity,
            'available' => ($quantity > 0),
            'active' => (bool)$row['active'],
            'visibility' => $row['visibility'],
            'manufacturer' => ($row['manufacturer_name'] ? $row['manufacturer_name'] : null),
            'category' => ($row['category_name'] ? $row['category_name'] : null),
            'weight' => (float)$row['sku_weight'],
            /* Se le pasan todos los campos para que Link no instancie un Product por fila */
            'url' => $link->getProductLink(
                $id_product,
                $row['link_rewrite'],
                $row['category_link_rewrite'],
                $row['sku_ean13'],
                (int)$id_lang,
                (int)$id_shop,
                $id_product_attribute
            ),
            'imageUrl' => $image_url,
            'dateAdd' => gmdate('c', strtotime($row['date_add'])),
            'dateUpd' => gmdate('c', strtotime($row['date_upd'])),
        );
    }

    public function sanitizeWhereParams($where)
    {
        return (str_replace(array("'", '"'), array("''", '""'), $where));
    }

    public function getFormattedLink($params)
    {
        $base_url = Tools::getHttpHost(true);
        $url_end = '';
        if (Configuration::get('ICOMMKT_FRIENDLY_URL', null) == 1) {
            $url = $base_url . '/abandomentcart';
            $url_end = '/'.$params['action']. '/'.$params['secure_token']. '/'.$params['id_cart']. '?d=0';
        } else {
            $url = $base_url . '/index.php?fc=module&controller=abandomentcart&module=icommktconnector&d=0&';

            foreach ($params as $key => $param) {
                $url_end .= $key . '=' . $param;
                if (next($params)) {
                    $url_end .= '&';
                }
            }
        }

        $url = $url . $url_end;

        return $url;
    }

}
