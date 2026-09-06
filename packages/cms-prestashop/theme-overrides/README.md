# Overrides de tema (strainmachine.com / AngarTheme)

Fixes que **no** pertenecen al modulo Synkrop pero que la tienda necesita, versionados
aca para que no se pierdan. Se despliegan a mano: `deploy_synkrop.sh` solo sincroniza
`synkrop/`, no este arbol.

Cada archivo replica su ruta bajo `themes/AngarTheme/` en el servidor. PrestaShop
resuelve el template del tema por sobre el del modulo, asi que el fix sobrevive a un
upgrade del modulo parcheado.

## ps_emailalerts/.../hook/product.tpl

`ps_emailalerts` v2.4.2 trae un template desincronizado de su propio handler:
`hookDisplayProductAdditionalInfo()` asigna `id_product` / `id_product_attribute`,
pero el `.tpl` lee `{$product.id_product}`. Smarty no encuentra `product` en el scope
local, cae al heredado, y donde ahi vive el objeto `Product` crudo revienta con
"Cannot use object of type Product as array" (product.tpl:43). Iba a ~2 fatales/min
en produccion.

El fix son 2 tokens: usar las variables que el handler si asigna.

Deploy:

    bash ssh/strainmachine.sh upload \
      packages/cms-prestashop/theme-overrides/AngarTheme/modules/ps_emailalerts/views/templates/hook/product.tpl \
      /home/strainma/public_html/themes/AngarTheme/modules/ps_emailalerts/views/templates/hook/product.tpl

Despues hay que invalidar el compilado de Smarty para que tome el template nuevo.
