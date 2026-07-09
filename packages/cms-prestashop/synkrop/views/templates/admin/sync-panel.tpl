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

}());
</script>
