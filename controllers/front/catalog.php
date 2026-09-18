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

class IcommktconnectorCatalogModuleFrontController extends ModuleFrontController
{

    public $php_self;

    public function __construct()
    {
        $this->php_self = 'catalog';
        parent::__construct();
        $this->context = Context::getContext();
    }

    public function init()
    {
        $module = Module::getInstanceByName('icommktconnector');

        /* Se instala antes de authorizeRequest() para cubrir también los fallos de autenticación */
        if (Tools::getValue('debug')) {
            $module->enableCatalogDebug();
        }

        /* PrestaShop captura las excepciones del dispatcher y muestra su página 500 genérica,
           así que el try/catch es la única forma de ver el error real */
        try {
            $module->authorizeRequest();

            if (Tools::getValue('ping')) {
                $module->exitCatalogPing();
            }

            $module->getProducts();
        } catch (Exception $e) {
            $module->handleCatalogError($e);
        } catch (Throwable $e) {
            $module->handleCatalogError($e);
        }
    }
}
