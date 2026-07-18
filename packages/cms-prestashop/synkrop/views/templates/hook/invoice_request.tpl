{* Selector boleta/factura — se muestra sobre las opciones de pago (Fase 1) *}
<div id="synkrop-invoice-request" class="card cart-summary"
     data-ajax-url="{$synkrop_ajax_url|escape:'htmlall':'UTF-8'}"
     data-token="{$synkrop_token|escape:'htmlall':'UTF-8'}">
  <div class="card-block">
    <h3 class="h4">{l s='Documento de compra' mod='synkrop'}</h3>

    <div class="form-group">
      <label class="radio-inline">
        <input type="radio" name="synkrop_doc_choice" value="boleta"
               {if !$synkrop_invoice_request}checked{/if}>
        {l s='Boleta' mod='synkrop'}
      </label>
      <label class="radio-inline">
        <input type="radio" name="synkrop_doc_choice" value="factura"
               {if $synkrop_invoice_request}checked{/if}>
        {l s='Factura' mod='synkrop'}
      </label>
    </div>

    <div id="synkrop-invoice-fields" {if !$synkrop_invoice_request}style="display:none"{/if}>
      <div class="row">
        <div class="form-group col-md-4">
          <label for="synkrop_rut">{l s='RUT' mod='synkrop'} *</label>
          <input type="text" id="synkrop_rut" class="form-control" placeholder="76.543.210-K"
                 value="{if $synkrop_invoice_request}{$synkrop_invoice_request.rut|escape:'htmlall':'UTF-8'}{/if}">
          <small id="synkrop-rut-feedback" class="form-text"></small>
        </div>
        <div class="form-group col-md-4">
          <label for="synkrop_razon_social">{l s='Razon social' mod='synkrop'} *</label>
          <input type="text" id="synkrop_razon_social" class="form-control" maxlength="120"
                 value="{if $synkrop_invoice_request}{$synkrop_invoice_request.razon_social|escape:'htmlall':'UTF-8'}{/if}">
        </div>
        <div class="form-group col-md-4">
          <label for="synkrop_giro">{l s='Giro' mod='synkrop'} *</label>
          <input type="text" id="synkrop_giro" class="form-control" maxlength="100"
                 value="{if $synkrop_invoice_request}{$synkrop_invoice_request.giro|escape:'htmlall':'UTF-8'}{/if}">
        </div>
      </div>
      <div class="row">
        <div class="form-group col-md-4">
          <label for="synkrop_direccion">{l s='Direccion (opcional)' mod='synkrop'}</label>
          <input type="text" id="synkrop_direccion" class="form-control" maxlength="200"
                 value="{if $synkrop_invoice_request}{$synkrop_invoice_request.direccion|escape:'htmlall':'UTF-8'}{/if}">
        </div>
        <div class="form-group col-md-4">
          <label for="synkrop_comuna">{l s='Comuna (opcional)' mod='synkrop'}</label>
          <input type="text" id="synkrop_comuna" class="form-control" maxlength="50"
                 value="{if $synkrop_invoice_request}{$synkrop_invoice_request.comuna|escape:'htmlall':'UTF-8'}{/if}">
        </div>
        <div class="form-group col-md-4">
          <label for="synkrop_ciudad">{l s='Ciudad (opcional)' mod='synkrop'}</label>
          <input type="text" id="synkrop_ciudad" class="form-control" maxlength="50"
                 value="{if $synkrop_invoice_request}{$synkrop_invoice_request.ciudad|escape:'htmlall':'UTF-8'}{/if}">
        </div>
      </div>
      <button type="button" id="synkrop-save-invoice" class="btn btn-secondary btn-sm">
        {l s='Guardar datos de factura' mod='synkrop'}
      </button>
    </div>

    <p id="synkrop-invoice-status" class="form-text" role="status"></p>
  </div>
</div>
