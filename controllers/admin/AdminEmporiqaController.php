<?php
/**
 * Admin controller stub that redirects to the module configuration page.
 * Registered via getTabs() so Emporiqa appears in the back-office menu.
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminEmporiqaController extends ModuleAdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->bootstrap = true;
    }

    public function initContent()
    {
        $redirectUrl = $this->context->link->getAdminLink('AdminModules', true, [], [
            'configure' => 'emporiqa',
        ]);

        Tools::redirectAdmin($redirectUrl);
    }
}
