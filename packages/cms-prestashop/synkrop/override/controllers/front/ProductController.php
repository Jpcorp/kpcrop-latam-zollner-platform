<?php
/**
 * Override de ProductController — evita el fatal "Cannot use object of type Product
 * as array" en modules/ps_emailalerts/views/templates/hook/product.tpl.
 *
 * El partial catalog/_partials/product-additional-info.tpl (y quickview.tpl) invocan
 * {hook h='displayProductAdditionalInfo' product=$product}. ps_emailalerts accede a
 * $product con notacion de punto ($product.id_product), asi que espera el ARRAY
 * presentado por getTemplateVarProduct(), no el objeto Product crudo.
 *
 * Los metodos AJAX del core renderizan esos partials sin pasar 'product' al scope,
 * de modo que Smarty resuelve la variable contra el objeto crudo y revienta.
 *
 * Ojo: este archivo vive en el modulo a proposito. PrestaShop copia
 * modules/synkrop/override/** a /override/** en parent::install(), y el rsync de
 * ssh/deploy_synkrop.sh sincroniza el arbol completo del modulo. Un fix escrito
 * directo en /override/ (o hand-patcheado sobre el core) queda fuera del repo y se
 * pierde en el siguiente restore: ya paso una vez.
 */
class ProductController extends ProductControllerCore
{
    public function displayAjaxQuickview()
    {
        $productForTemplate = $this->getTemplateVarProduct();
        ob_end_clean();
        header('Content-Type: application/json');
        $this->ajaxRender(Tools::jsonEncode(array(
            'quickview_html' => $this->render(
                'catalog/_partials/quickview',
                array('product' => $productForTemplate)
            ),
            'product' => $productForTemplate,
        )));
    }

    /**
     * displayAjaxRefresh() (el AJAX que dispara al cambiar de combinacion) renderiza
     * 9 partials sin pasarles scope propio. FrontController::render() arma un scope
     * hijo de $this->context->smarty, asi que esos partials resuelven $product contra
     * el padre — donde el core nunca lo asigna, porque initContent() no corre en esta
     * request. El hook displayProductAdditionalInfo recibia entonces el objeto Product
     * crudo y ps_emailalerts reventaba con "Cannot use object of type Product as array".
     *
     * Poblar el scope padre antes de delegar cubre los 9 renders de una. Se prefiere
     * esto a sobreescribir el metodo completo: no duplica ~40 lineas del core ni queda
     * desincronizado en el proximo upgrade de PrestaShop.
     *
     * ponytail: getTemplateVarProduct() termina llamandose dos veces (aca y en el core).
     * Es una request AJAX; si alguna vez pesa, cachear el array en una propiedad.
     */
    public function displayAjaxRefresh()
    {
        $this->context->smarty->assign('product', $this->getTemplateVarProduct());

        parent::displayAjaxRefresh();
    }
}
