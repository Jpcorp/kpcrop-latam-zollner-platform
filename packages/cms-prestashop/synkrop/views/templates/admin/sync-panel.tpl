{*
  sync-panel.tpl — Panel principal de sincronizacion Bsale en backoffice PS
  Variables: is_configured, sync_logs, ajax_url, token
*}

<div class="panel" id="synkrop-app">
  <div class="panel-heading">
    <i class="icon-refresh"></i>&nbsp;{l s='Synkrop' mod='synkrop'}
    <span class="panel-heading-action">
      <a class="list-toolbar-btn" href="{$link->getAdminLink('AdminModules')}&configure=synkrop">
        <i class="process-icon-configure"></i>&nbsp;{l s='Configuracion avanzada' mod='synkrop'}
      </a>
    </span>
  </div>

  {* ── CONFIGURACION / ESTADO ─────────────────────────────────────────── *}

  {if $license_banner}
  <div class="panel" style="border-left:4px solid #d9534f;margin:0 0 15px">
    <div class="panel-heading" style="background:#fdf1f0;color:#a94442">
      <i class="icon-warning-sign"></i>&nbsp;{l s='Licencia vencida o suspendida' mod='synkrop'}
    </div>
    <div class="panel-body">
      <p>{$license_banner|escape:'html':'UTF-8'}</p>
      <a href="https://www.keepcrop.com" target="_blank" rel="noopener" class="btn btn-danger">
        <i class="icon-external-link"></i>&nbsp;{l s='Renovar licencia' mod='synkrop'}
      </a>
    </div>
  </div>
  {/if}

  {if !$is_configured}
  <div class="panel" style="border-left:4px solid #f0ad4e;margin:0 0 15px">
    <div class="panel-heading" style="background:#fdf8ef;color:#8a6d3b">
      <i class="icon-warning-sign"></i>&nbsp;{l s='Configuracion requerida' mod='synkrop'}
    </div>
    <div class="panel-body">
      <p class="text-muted" style="margin-bottom:18px">
        {l s='Ingresa tus credenciales para activar la sincronizacion con Bsale.' mod='synkrop'}
      </p>

      <div class="row">
        <div class="col-md-6">
          <div class="form-group">
            <label class="control-label">
              {l s='Token de acceso Bsale' mod='synkrop'}
              <span class="required">&nbsp;*</span>
            </label>
            <div class="input-group">
              <span class="input-group-addon"><i class="icon-key"></i></span>
              <input type="password" id="bs-bsale-token" class="form-control"
                     placeholder="{l s='Bsale > Mi cuenta > Integraciones' mod='synkrop'}"
                     autocomplete="new-password">
              <span class="input-group-btn">
                <button class="btn btn-default" id="bs-verify-bsale-btn" type="button">
                  <i class="icon-check"></i>&nbsp;{l s='Verificar' mod='synkrop'}
                </button>
              </span>
            </div>
            <span id="bs-bsale-status" class="help-block" style="min-height:20px"></span>
          </div>
        </div>

        <div class="col-md-6">
          <div class="form-group">
            <label class="control-label">
              {l s='API Key de licencia kpcrop' mod='synkrop'}
              <span class="required">&nbsp;*</span>
            </label>
            <div class="input-group">
              <span class="input-group-addon"><i class="icon-certificate"></i></span>
              <input type="text" id="bs-api-key" class="form-control" placeholder="kp_...">
              <span class="input-group-btn">
                <button class="btn btn-default" id="bs-verify-license-btn" type="button">
                  <i class="icon-check"></i>&nbsp;{l s='Verificar' mod='synkrop'}
                </button>
              </span>
            </div>
            <span id="bs-license-status" class="help-block" style="min-height:20px"></span>
          </div>
        </div>
      </div>

      <button class="btn btn-primary" id="bs-save-config-btn" type="button">
        <i class="icon-save"></i>&nbsp;{l s='Guardar configuracion' mod='synkrop'}
      </button>
      <span id="bs-save-status" class="help-block"
            style="display:inline-block;margin-left:12px;vertical-align:middle"></span>
    </div>
  </div>

  {else}
  <div class="alert alert-success"
       style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:15px">
    <i class="icon-check-circle" style="font-size:18px"></i>
    <span><strong>{l s='Conexion configurada' mod='synkrop'}</strong></span>
    <span id="bs-bsale-badge"   class="label label-default">Bsale: {l s='sin verificar' mod='synkrop'}</span>
    <span id="bs-license-badge" class="label label-default">{l s='Licencia: sin verificar' mod='synkrop'}</span>
    <span style="flex:1"></span>
    <button class="btn btn-xs btn-default" id="bs-check-connections-btn" type="button">
      <i class="icon-refresh"></i>&nbsp;{l s='Verificar conexiones' mod='synkrop'}
    </button>
  </div>
  {/if}

  {* ── SYNC MANUAL ────────────────────────────────────────────────────── *}

  <div class="row" id="synkrop-panel"
       {if !$is_configured}style="opacity:0.5;pointer-events:none;user-select:none"{/if}>

    <div class="col-md-8">
      <div class="panel panel-default">
        <div class="panel-heading">
          <i class="icon-upload"></i>&nbsp;{l s='Sincronizacion manual' mod='synkrop'}
        </div>
        <div class="panel-body">
          <p class="text-muted small" style="margin-bottom:12px">
            {l s='Selecciona que deseas sincronizar desde Bsale hacia PrestaShop.' mod='synkrop'}
          </p>

          <div class="btn-group" role="group">
            <button class="btn btn-primary btn-sync" data-entity="products"
                    title="{l s='Importar y actualizar productos' mod='synkrop'}">
              <i class="icon-archive"></i>&nbsp;{l s='Productos' mod='synkrop'}
            </button>
            <button class="btn btn-default btn-sync" data-entity="stock"
                    title="{l s='Actualizar stock de productos existentes' mod='synkrop'}">
              <i class="icon-signal"></i>&nbsp;{l s='Stock' mod='synkrop'}
            </button>
            <button class="btn btn-default btn-sync" data-entity="prices"
                    title="{l s='Actualizar precios de productos existentes' mod='synkrop'}">
              <i class="icon-tag"></i>&nbsp;{l s='Precios' mod='synkrop'}
            </button>
            {if $category_sync_enabled}
            <button class="btn btn-default btn-sync" data-entity="categories"
                    title="{l s='Crear/actualizar categorias desde los tipos de producto de Bsale' mod='synkrop'}">
              <i class="icon-sitemap"></i>&nbsp;{l s='Categorias' mod='synkrop'}
            </button>
            {/if}
          </div>

          <div id="synkrop-progress" class="hidden" style="margin-top:16px">
            <div class="progress" style="margin-bottom:4px">
              <div class="progress-bar progress-bar-striped active" role="progressbar" style="width:100%">
                <span id="bs-progress-label">{l s='Sincronizando...' mod='synkrop'}</span>
              </div>
            </div>
            <small class="text-muted">
              <i class="icon-clock-o"></i>&nbsp;
              {l s='Tiempo transcurrido:' mod='synkrop'}
              <strong><span id="bs-elapsed">0</span>s</strong>
            </small>
          </div>

          <div id="synkrop-result" class="hidden" style="margin-top:16px"></div>
        </div>
      </div>
    </div>

    <div class="col-md-4">
      <div class="panel panel-default">
        <div class="panel-heading">
          <i class="icon-info-sign"></i>&nbsp;{l s='Ultimo sync' mod='synkrop'}
        </div>
        <div class="panel-body" style="padding:0">
          {if $sync_logs}
            {assign var='last' value=$sync_logs[0]}
            <table class="table table-condensed" style="margin-bottom:0">
              <tr>
                <td class="text-muted" style="width:45%;padding-left:12px">
                  {l s='Fecha' mod='synkrop'}
                </td>
                <td><small>{$last.created_at}</small></td>
              </tr>
              <tr>
                <td class="text-muted" style="padding-left:12px">{l s='Tipo' mod='synkrop'}</td>
                <td>
                  {if $last.sync_type == 'manual'}
                    <span class="label label-info">{l s='Manual' mod='synkrop'}</span>
                  {else}
                    <span class="label label-default">{l s='Auto' mod='synkrop'}</span>
                  {/if}
                  &nbsp;{$last.entity_type|capitalize}
                </td>
              </tr>
              <tr>
                <td class="text-muted" style="padding-left:12px">{l s='Estado' mod='synkrop'}</td>
                <td>
                  {if $last.status == 'success'}
                    <span class="label label-success"><i class="icon-ok"></i>&nbsp;OK</span>
                  {elseif $last.status == 'partial'}
                    <span class="label label-warning">{l s='Parcial' mod='synkrop'}</span>
                  {else}
                    <span class="label label-danger">{l s='Error' mod='synkrop'}</span>
                  {/if}
                </td>
              </tr>
              <tr>
                <td class="text-muted" style="padding-left:12px">
                  {l s='Actualizados' mod='synkrop'}
                </td>
                <td><strong class="text-success">{$last.records_ok}</strong></td>
              </tr>
              <tr>
                <td class="text-muted" style="padding-left:12px">
                  {l s='Errores' mod='synkrop'}
                </td>
                <td>
                  <strong class="{if $last.records_fail > 0}text-danger{else}text-success{/if}">
                    {$last.records_fail}
                  </strong>
                </td>
              </tr>
              <tr>
                <td class="text-muted" style="padding-left:12px">
                  {l s='Duracion' mod='synkrop'}
                </td>
                <td>
                  {if $last.duration_ms > 0}
                    {if $last.duration_ms < 1000}
                      {$last.duration_ms}ms
                    {else}
                      {($last.duration_ms / 1000)|string_format:"%.1f"}s
                    {/if}
                  {else}
                    <span class="text-muted">—</span>
                  {/if}
                </td>
              </tr>
            </table>
          {else}
            <div class="text-center text-muted" style="padding:28px 12px">
              <i class="icon-clock-o" style="font-size:28px;display:block;margin-bottom:8px"></i>
              <p style="margin:0">{l s='Sin sincronizaciones aun.' mod='synkrop'}</p>
            </div>
          {/if}
        </div>
      </div>
    </div>
  </div>

  {* ── HISTORIAL ──────────────────────────────────────────────────────── *}

  <div class="row">
    <div class="col-md-12">
      <div class="panel panel-default">
        <div class="panel-heading">
          <i class="icon-list"></i>&nbsp;{l s='Historial de sincronizaciones' mod='synkrop'}
          {if $sync_logs}
            <span class="badge" style="margin-left:6px">{$sync_logs|count}</span>
          {/if}
        </div>
        <div class="panel-body" style="padding:0">
          {if $sync_logs}
            <table class="table table-hover table-condensed" style="margin-bottom:0">
              <thead>
                <tr>
                  <th>{l s='Fecha' mod='synkrop'}</th>
                  <th>{l s='Disparador' mod='synkrop'}</th>
                  <th>{l s='Entidad' mod='synkrop'}</th>
                  <th>{l s='Estado' mod='synkrop'}</th>
                  <th class="text-right">{l s='OK' mod='synkrop'}</th>
                  <th class="text-right">{l s='Errores' mod='synkrop'}</th>
                  <th class="text-right">{l s='Duracion' mod='synkrop'}</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {foreach $sync_logs as $log}
                <tr class="{if $log.status == 'error'}danger{elseif $log.status == 'partial'}warning{/if}">
                  <td><small>{$log.created_at}</small></td>
                  <td>
                    {if $log.sync_type == 'manual'}
                      <span class="label label-info">{l s='Manual' mod='synkrop'}</span>
                    {else}
                      <span class="label label-default">{l s='Auto' mod='synkrop'}</span>
                    {/if}
                  </td>
                  <td>{$log.entity_type|capitalize}</td>
                  <td>
                    {if $log.status == 'success'}
                      <span class="label label-success">OK</span>
                    {elseif $log.status == 'partial'}
                      <span class="label label-warning">{l s='Parcial' mod='synkrop'}</span>
                    {else}
                      <span class="label label-danger">{l s='Error' mod='synkrop'}</span>
                    {/if}
                  </td>
                  <td class="text-right">{$log.records_ok}</td>
                  <td class="text-right{if $log.records_fail > 0} text-danger{/if}">
                    {$log.records_fail}
                  </td>
                  <td class="text-right">
                    {if $log.duration_ms > 0}
                      {if $log.duration_ms < 1000}
                        {$log.duration_ms}ms
                      {else}
                        {($log.duration_ms / 1000)|string_format:"%.1f"}s
                      {/if}
                    {else}
                      <span class="text-muted">—</span>
                    {/if}
                  </td>
                  <td>
                    {if $log.error_details}
                      <button class="btn btn-xs btn-default bs-show-errors"
                              data-errors="{$log.error_details|escape:'html'}"
                              title="{l s='Ver errores' mod='synkrop'}">
                        <i class="icon-search"></i>
                      </button>
                    {/if}
                  </td>
                </tr>
                {/foreach}
              </tbody>
            </table>
          {else}
            <div class="text-center text-muted" style="padding:40px">
              <i class="icon-history" style="font-size:36px;display:block;margin-bottom:10px"></i>
              <p>{l s='Sin sincronizaciones registradas.' mod='synkrop'}</p>
            </div>
          {/if}
        </div>
      </div>
    </div>
  </div>

  {* ── VENTAS: PEDIDOS → DOCUMENTOS BSALE ─────────────────────────────── *}

  <div class="row">
    <div class="col-md-12">
      <div class="panel panel-default">
        <div class="panel-heading">
          <i class="icon-shopping-cart"></i>&nbsp;{l s='Ventas — documentos en Bsale' mod='synkrop'}
          {if $orders_enabled && $order_counts}
            {if isset($order_counts.pending)}
              <span class="label label-default" style="margin-left:6px">{$order_counts.pending} {l s='pendientes' mod='synkrop'}</span>
            {/if}
            {if isset($order_counts.generated)}
              <span class="label label-info">{$order_counts.generated} {l s='generadas' mod='synkrop'}</span>
            {/if}
            {if isset($order_counts.closed)}
              <span class="label label-success">{$order_counts.closed} {l s='cerradas' mod='synkrop'}</span>
            {/if}
            {if isset($order_counts.error)}
              <span class="label label-danger">{$order_counts.error} {l s='con error' mod='synkrop'}</span>
            {/if}
            {if isset($order_counts.review)}
              <span class="label label-warning">{$order_counts.review} {l s='por revisar' mod='synkrop'}</span>
            {/if}
          {/if}
        </div>

        {if !$orders_enabled}
        <div class="panel-body">
          <p class="text-muted" style="margin:0">
            <i class="icon-info-sign"></i>&nbsp;
            {l s='El flujo de ventas esta desactivado. Cada pedido pagado puede generar automaticamente su nota de venta en Bsale (la boleta o factura la emite siempre un usuario en Bsale).' mod='synkrop'}
            <a href="{$config_url|escape:'html'}">{l s='Activar en la configuracion del modulo' mod='synkrop'}</a>
          </p>
        </div>
        {else}
        <div class="panel-body">
          <div style="margin-bottom:12px;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
            <button class="btn btn-primary" id="bs-orders-generate-all" type="button"
                    title="{l s='Crea la nota de venta en Bsale para todos los pedidos pendientes' mod='synkrop'}">
              <i class="icon-file-text"></i>&nbsp;{l s='Generar pendientes' mod='synkrop'}
            </button>
            <button class="btn btn-default" id="bs-orders-check-emissions" type="button"
                    title="{l s='Busca en Bsale que notas de venta ya fueron boleteadas/facturadas y cierra los pedidos' mod='synkrop'}">
              <i class="icon-refresh"></i>&nbsp;{l s='Verificar emisiones' mod='synkrop'}
            </button>
            <span id="bs-orders-status" class="help-block" style="margin:0"></span>
          </div>

          {if $order_queue}
          <div style="overflow-x:auto">
            <table class="table table-hover table-condensed" style="margin-bottom:0">
              <thead>
                <tr>
                  <th>{l s='Pedido' mod='synkrop'}</th>
                  <th>{l s='Fecha' mod='synkrop'}</th>
                  <th>{l s='Estado' mod='synkrop'}</th>
                  <th>{l s='Nota de venta' mod='synkrop'}</th>
                  <th>{l s='Documento emitido' mod='synkrop'}</th>
                  <th class="text-right">{l s='Accion' mod='synkrop'}</th>
                </tr>
              </thead>
              <tbody>
                {foreach from=$order_queue item=row}
                <tr>
                  <td>
                    <a href="{$row.order_url|escape:'html'}" target="_blank" rel="noopener">
                      {if $row.order_reference}{$row.order_reference|escape:'html'}{else}#{$row.id_order}{/if}
                    </a>
                  </td>
                  <td><small>{$row.created_at|escape:'html'}</small></td>
                  <td>
                    {if $row.status == 'pending'}
                      <span class="label label-default">{l s='Pendiente' mod='synkrop'}</span>
                    {elseif $row.status == 'generated'}
                      <span class="label label-info">{l s='Generada' mod='synkrop'}</span>
                    {elseif $row.status == 'emitted'}
                      <span class="label label-primary">{l s='Emitida' mod='synkrop'}</span>
                    {elseif $row.status == 'closed'}
                      <span class="label label-success">{l s='Cerrada' mod='synkrop'}</span>
                    {elseif $row.status == 'error'}
                      <span class="label label-danger" {if $row.error_message}title="{$row.error_message|escape:'html'}"{/if}>
                        {l s='Error' mod='synkrop'}
                      </span>
                    {elseif $row.status == 'review'}
                      <span class="label label-warning" {if $row.error_message}title="{$row.error_message|escape:'html'}"{/if}>
                        {l s='Revisar' mod='synkrop'}
                      </span>
                    {else}
                      <span class="label label-default">{$row.status|escape:'html'}</span>
                    {/if}
                  </td>
                  <td>
                    {if $row.bsale_doc_id}
                      {if $row.bsale_doc_url}
                        <a href="{$row.bsale_doc_url|escape:'html'}" target="_blank" rel="noopener">
                          N°{$row.bsale_doc_number|escape:'html'}&nbsp;<i class="icon-external-link"></i>
                        </a>
                      {else}
                        N°{$row.bsale_doc_number|escape:'html'}
                      {/if}
                    {else}
                      <span class="text-muted">—</span>
                    {/if}
                  </td>
                  <td>
                    {if $row.emitted_doc_id}
                      {if $row.emitted_doc_url}
                        <a href="{$row.emitted_doc_url|escape:'html'}" target="_blank" rel="noopener">
                          {$row.emitted_doc_type|escape:'html'} N°{$row.emitted_doc_number|escape:'html'}&nbsp;<i class="icon-external-link"></i>
                        </a>
                      {else}
                        {$row.emitted_doc_type|escape:'html'} N°{$row.emitted_doc_number|escape:'html'}
                      {/if}
                    {else}
                      <span class="text-muted">—</span>
                    {/if}
                  </td>
                  <td class="text-right">
                    {if $row.status == 'pending' || $row.status == 'error'}
                      <button class="btn btn-xs btn-default bs-order-generate" data-order="{$row.id_order}">
                        <i class="icon-file-text"></i>&nbsp;{l s='Generar' mod='synkrop'}
                      </button>
                    {/if}
                    {if $row.error_message}
                      <i class="icon-info-sign text-muted" title="{$row.error_message|escape:'html'}"></i>
                    {/if}
                  </td>
                </tr>
                {/foreach}
              </tbody>
            </table>
          </div>
          {else}
          <div class="text-center text-muted" style="padding:20px 12px">
            <i class="icon-shopping-cart" style="font-size:28px;display:block;margin-bottom:8px"></i>
            <p style="margin:0">
              {l s='Sin pedidos en cola. Cuando un pedido llegue al estado gatillo (Pago aceptado), aparecera aqui.' mod='synkrop'}
            </p>
          </div>
          {/if}
        </div>
        {/if}
      </div>
    </div>
  </div>

</div>

{* ── MODAL detalle de errores ────────────────────────────────────────────── *}

<div class="modal fade" id="bs-errors-modal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
          <span aria-hidden="true">&times;</span>
        </button>
        <h4 class="modal-title">
          <i class="icon-exclamation-triangle text-danger"></i>&nbsp;
          {l s='Detalle de errores' mod='synkrop'}
        </h4>
      </div>
      <div class="modal-body" id="bs-errors-modal-body"></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal">
          {l s='Cerrar' mod='synkrop'}
        </button>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  'use strict';

  var AJAX_URL = '{$ajax_url|escape:'javascript'}';
  var timer    = null;

  // ── Helpers ────────────────────────────────────────────────────────────────

  function esc(str) {
    return String(str)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function post(action, params) {
    var body = Object.keys(params)
      .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); })
      .join('&');
    return fetch(AJAX_URL + '&action=' + action, {
      method:  'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body:    body,
    }).then(function (r) { return r.json(); });
  }

  function fieldStatus(el, type, msg) {
    if (!el) return;
    var colors = { success: '#3c763d', error: '#a94442', loading: '#888' };
    el.style.color = colors[type] || '#888';
    el.textContent = msg;
  }

  function setBadge(el, state, text) {
    if (!el) return;
    el.className   = 'label ' + (state === 'ok' ? 'label-success' : state === 'err' ? 'label-danger' : 'label-default');
    el.textContent = text;
  }

  // ── Sync manual ────────────────────────────────────────────────────────────

  document.querySelectorAll('.btn-sync').forEach(function (btn) {
    btn.addEventListener('click', function () { triggerSync(btn.dataset.entity); });
  });

  function triggerSync(entity) {
    var t0       = Date.now();
    var progress = document.getElementById('synkrop-progress');
    var result   = document.getElementById('synkrop-result');
    var label    = document.getElementById('bs-progress-label');
    var elapsed  = document.getElementById('bs-elapsed');
    var btns     = document.querySelectorAll('.btn-sync');
    var names    = { products: 'productos', stock: 'stock', prices: 'precios' };

    clearInterval(timer);
    if (progress) progress.classList.remove('hidden');
    if (result)   result.classList.add('hidden');
    btns.forEach(function (b) { b.disabled = true; });

    if (label)   label.textContent = 'Sincronizando ' + (names[entity] || entity) + '…';
    if (elapsed) {
      elapsed.textContent = '0';
      timer = setInterval(function () {
        elapsed.textContent = (((Date.now() - t0) / 1000) | 0);
      }, 500);
    }

    post('syncNow', { entity: entity })
      .then(function (data) {
        clearInterval(timer);
        if (progress) progress.classList.add('hidden');
        btns.forEach(function (b) { b.disabled = false; });

        var cls  = data.status === 'success' ? 'alert-success'
                 : data.status === 'partial' ? 'alert-warning' : 'alert-danger';
        var icon = data.status === 'success' ? 'icon-check-circle'
                 : data.status === 'partial' ? 'icon-warning-sign' : 'icon-times-circle';
        var dur  = (((Date.now() - t0) / 100) | 0) / 10;

        var html = '<div class="alert ' + cls + '" style="margin-bottom:0">'
                 + '<i class="' + icon + '"></i> <strong>' + esc(data.message || '') + '</strong>'
                 + ' <small class="text-muted">(' + dur + 's)</small>'
                 + '</div>';

        if (data.errors && data.errors.length) {
          html += '<div class="panel panel-default" style="margin-top:8px">'
                + '<div class="panel-heading" style="padding:6px 12px">'
                + data.errors.length + ' error(s)</div>'
                + '<ul class="list-group" style="margin-bottom:0;max-height:180px;overflow:auto">';
          data.errors.forEach(function (e) {
            var sku = e.sku || e.code || '';
            html += '<li class="list-group-item list-group-item-danger" style="padding:4px 12px">'
                  + (sku ? '<strong>' + esc(sku) + '</strong> — ' : '') + esc(e.message || String(e))
                  + '</li>';
          });
          html += '</ul></div>';
        }

        if (result) {
          result.innerHTML = html;
          result.classList.remove('hidden');
        }

        if (data.success) setTimeout(function () { location.reload(); }, 2500);
      })
      .catch(function (err) {
        clearInterval(timer);
        if (progress) progress.classList.add('hidden');
        btns.forEach(function (b) { b.disabled = false; });
        if (result) {
          result.innerHTML = '<div class="alert alert-danger"><i class="icon-times"></i> Error de red: ' + esc(err.message) + '</div>';
          result.classList.remove('hidden');
        }
      });
  }

  // ── Verificar token Bsale (formulario inline) ──────────────────────────────

  var verifyBsaleBtn = document.getElementById('bs-verify-bsale-btn');
  if (verifyBsaleBtn) {
    verifyBsaleBtn.addEventListener('click', function () {
      var inp    = document.getElementById('bs-bsale-token');
      var status = document.getElementById('bs-bsale-status');
      if (!inp || !inp.value.trim()) {
        fieldStatus(status, 'error', '✗ Ingresa el token primero.');
        return;
      }
      fieldStatus(status, 'loading', 'Verificando…');
      verifyBsaleBtn.disabled = true;

      post('verifyBsale', { token: inp.value.trim() })
        .then(function (data) {
          verifyBsaleBtn.disabled = false;
          if (data.success) {
            fieldStatus(status, 'success', '✓ Conectado' + (data.business ? ' — ' + data.business : ''));
          } else {
            fieldStatus(status, 'error', '✗ ' + (data.message || 'Error desconocido'));
          }
        })
        .catch(function (err) {
          verifyBsaleBtn.disabled = false;
          fieldStatus(status, 'error', '✗ Error de red: ' + err.message);
        });
    });
  }

  // ── Verificar licencia (formulario inline) ─────────────────────────────────

  var verifyLicenseBtn = document.getElementById('bs-verify-license-btn');
  if (verifyLicenseBtn) {
    verifyLicenseBtn.addEventListener('click', function () {
      var inp    = document.getElementById('bs-api-key');
      var status = document.getElementById('bs-license-status');
      if (!inp || !inp.value.trim()) {
        fieldStatus(status, 'error', '✗ Ingresa la API Key primero.');
        return;
      }
      fieldStatus(status, 'loading', 'Verificando…');
      verifyLicenseBtn.disabled = true;

      post('verifyLicense', { api_key: inp.value.trim() })
        .then(function (data) {
          verifyLicenseBtn.disabled = false;
          if (data.success) {
            fieldStatus(status, 'success', '✓ Licencia activa');
          } else {
            fieldStatus(status, 'error', '✗ ' + (data.message || 'Error desconocido'));
          }
        })
        .catch(function (err) {
          verifyLicenseBtn.disabled = false;
          fieldStatus(status, 'error', '✗ Error de red: ' + err.message);
        });
    });
  }

  // ── Guardar configuracion (formulario inline) ──────────────────────────────

  var saveConfigBtn = document.getElementById('bs-save-config-btn');
  if (saveConfigBtn) {
    saveConfigBtn.addEventListener('click', function () {
      var tokenInp   = document.getElementById('bs-bsale-token');
      var apiKeyInp  = document.getElementById('bs-api-key');
      var saveStatus = document.getElementById('bs-save-status');

      if (!tokenInp.value.trim() || !apiKeyInp.value.trim()) {
        fieldStatus(saveStatus, 'error', '✗ El token de Bsale y la API Key son obligatorios.');
        return;
      }

      fieldStatus(saveStatus, 'loading', 'Guardando…');
      saveConfigBtn.disabled = true;

      post('saveConfig', { bsale_token: tokenInp.value.trim(), api_key: apiKeyInp.value.trim() })
        .then(function (data) {
          saveConfigBtn.disabled = false;
          if (data.success) {
            fieldStatus(saveStatus, 'success', '✓ ' + (data.message || 'Guardado correctamente'));
            setTimeout(function () { location.reload(); }, 1200);
          } else {
            fieldStatus(saveStatus, 'error', '✗ ' + (data.message || 'Error al guardar'));
          }
        })
        .catch(function (err) {
          saveConfigBtn.disabled = false;
          fieldStatus(saveStatus, 'error', '✗ Error de red: ' + err.message);
        });
    });
  }

  // ── Verificar conexiones (estado configurado) ──────────────────────────────

  var checkBtn = document.getElementById('bs-check-connections-btn');
  if (checkBtn) {
    checkBtn.addEventListener('click', function () {
      var bsaleBadge   = document.getElementById('bs-bsale-badge');
      var licenseBadge = document.getElementById('bs-license-badge');
      var done = 0;

      checkBtn.disabled = true;
      checkBtn.innerHTML = '<i class="icon-spinner icon-spin"></i> Verificando…';

      function finish() {
        if (++done === 2) {
          checkBtn.disabled = false;
          checkBtn.innerHTML = '<i class="icon-refresh"></i> Verificar conexiones';
        }
      }

      post('verifyBsale', { use_saved: '1' })
        .then(function (data) {
          if (data.success) setBadge(bsaleBadge, 'ok',  'Bsale: ' + (data.business || 'OK'));
          else               setBadge(bsaleBadge, 'err', 'Bsale: error');
          finish();
        })
        .catch(function () { setBadge(bsaleBadge, 'err', 'Bsale: sin respuesta'); finish(); });

      post('verifyLicense', { use_saved: '1' })
        .then(function (data) {
          if (data.success) setBadge(licenseBadge, 'ok',  'Licencia: activa');
          else               setBadge(licenseBadge, 'err', 'Licencia: inactiva');
          finish();
        })
        .catch(function () { setBadge(licenseBadge, 'err', 'Licencia: sin respuesta'); finish(); });
    });
  }

  // ── Modal detalle de errores ───────────────────────────────────────────────

  document.querySelectorAll('.bs-show-errors').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var errors = [];
      try   { errors = JSON.parse(btn.dataset.errors); }
      catch (e) { errors = [{ message: btn.dataset.errors }]; }

      if (!Array.isArray(errors)) errors = [errors];
      var html = '<ul class="list-group" style="margin-bottom:0">';
      errors.forEach(function (e) {
        var sku = e.sku || e.code || '';
        var msg = e.message || String(e);
        html += '<li class="list-group-item list-group-item-danger">'
              + (sku ? '<strong>' + esc(sku) + '</strong>: ' : '') + esc(msg)
              + '</li>';
      });
      html += '</ul>';

      var body = document.getElementById('bs-errors-modal-body');
      if (body) body.innerHTML = html;
      if (window.jQuery) jQuery('#bs-errors-modal').modal('show');
    });
  });

  // ── Ventas: pedidos → documentos Bsale ─────────────────────────────────────

  var ordersStatus = document.getElementById('bs-orders-status');

  function ordersAction(action, params, btn, busyLabel) {
    var buttons = document.querySelectorAll(
      '#bs-orders-generate-all, #bs-orders-check-emissions, .bs-order-generate'
    );
    buttons.forEach(function (b) { b.disabled = true; });
    fieldStatus(ordersStatus, 'loading', busyLabel);

    post(action, params)
      .then(function (data) {
        fieldStatus(ordersStatus, data.success ? 'success' : 'error', data.message || '');
        // Refresca la tabla para reflejar estados y links nuevos
        if (data.generated || data.failed || data.emitted || data.closed) {
          setTimeout(function () { location.reload(); }, 1200);
        } else {
          buttons.forEach(function (b) { b.disabled = false; });
        }
      })
      .catch(function () {
        fieldStatus(ordersStatus, 'error', 'Error de red — reintenta');
        buttons.forEach(function (b) { b.disabled = false; });
      });
  }

  var genAllBtn = document.getElementById('bs-orders-generate-all');
  if (genAllBtn) {
    genAllBtn.addEventListener('click', function () {
      ordersAction('GenerateOrderDoc', {}, genAllBtn, 'Generando notas de venta...');
    });
  }

  var checkBtn = document.getElementById('bs-orders-check-emissions');
  if (checkBtn) {
    checkBtn.addEventListener('click', function () {
      ordersAction('CheckEmissions', {}, checkBtn, 'Consultando emisiones en Bsale...');
    });
  }

  document.querySelectorAll('.bs-order-generate').forEach(function (btn) {
    btn.addEventListener('click', function () {
      ordersAction('GenerateOrderDoc', { id_order: btn.dataset.order }, btn,
        'Generando nota de venta del pedido...');
    });
  });

}());
</script>
