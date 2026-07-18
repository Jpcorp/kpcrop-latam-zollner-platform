<?php
/**
 * Recibe por AJAX la solicitud de factura del checkout y la guarda por carrito.
 * La fila en synkrop_invoice_request es lo que buildClient() anexa a la nota de
 * venta; el usuario Bsale decide con esos datos si emite boleta o factura.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SynkropInvoicerequestModuleFrontController extends ModuleFrontController
{
    public function postProcess(): void
    {
        header('Content-Type: application/json');

        if (Tools::getValue('token') !== Tools::getToken(false)) {
            $this->respond(false, $this->module->l('Sesion invalida — recarga la pagina', 'invoicerequest'));
        }

        $idCart = (int)($this->context->cart->id ?? 0);
        if (!$idCart) {
            $this->respond(false, $this->module->l('No hay un carrito activo', 'invoicerequest'));
        }

        switch (Tools::getValue('action_synkrop')) {
            case 'save':
                $this->saveRequest($idCart);
                break;
            case 'remove':
                Db::getInstance()->delete('synkrop_invoice_request', 'id_cart = ' . $idCart);
                $this->respond(true, $this->module->l('Se emitira boleta', 'invoicerequest'));
                break;
            default:
                $this->respond(false, 'Accion desconocida');
        }
    }

    private function saveRequest(int $idCart): void
    {
        $rut = OrderDocumentService::formatRut((string)Tools::getValue('rut', ''));
        if ($rut === null) {
            $this->respond(false, $this->module->l('RUT invalido', 'invoicerequest'));
        }

        $razonSocial = trim((string)Tools::getValue('razon_social', ''));
        $giro        = trim((string)Tools::getValue('giro', ''));
        if ($razonSocial === '' || $giro === '') {
            $this->respond(false, $this->module->l('Razon social y giro son obligatorios para factura', 'invoicerequest'));
        }

        // PK = id_cart: reenviar el formulario actualiza, nunca duplica
        Db::getInstance()->execute(
            'REPLACE INTO `' . _DB_PREFIX_ . 'synkrop_invoice_request`
             (id_cart, rut, razon_social, giro, direccion, telefono, ciudad, comuna, created_at)
             VALUES (' . $idCart . ", '" . pSQL($rut) . "', '" . pSQL($razonSocial) . "', '"
                . pSQL($giro) . "', '" . pSQL(trim((string)Tools::getValue('direccion', ''))) . "', '"
                . pSQL(trim((string)Tools::getValue('telefono', ''))) . "', '"
                . pSQL(trim((string)Tools::getValue('ciudad', ''))) . "', '"
                . pSQL(trim((string)Tools::getValue('comuna', ''))) . "', NOW())"
        );

        $this->respond(true, $this->module->l('Datos de factura guardados', 'invoicerequest'), ['rut' => $rut]);
    }

    private function respond(bool $ok, string $message, array $extra = []): void
    {
        die(json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra)));
    }
}
