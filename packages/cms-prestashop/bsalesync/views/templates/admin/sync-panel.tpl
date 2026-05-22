{*
  Panel principal de sincronizacion — Admin > Catalogo > Bsale Sync
  Variables disponibles: is_configured, sync_logs, ajax_url, token
*}

<div class="panel">
  <div class="panel-heading">
    <i class="icon-refresh"></i> {l s='Bsale Sync' mod='bsalesync'}
  </div>

  {* Estado de conexion *}
  <div class="row">
    <div class="col-md-12">
      {if $is_configured}
        <div class="alert alert-success">
          <i class="icon-check"></i>
          {l s='Conexion configurada.' mod='bsalesync'}
          <a href="{$link->getAdminLink('AdminModules')}&configure=bsalesync" class="btn btn-xs btn-default pull-right">
            <i class="icon-cog"></i> {l s='Configurar' mod='bsalesync'}
          </a>
        </div>
      {else}
        <div class="alert alert-warning">
          <i class="icon-warning-sign"></i>
          {l s='Configura tu token de Bsale y API Key de licencia antes de sincronizar.' mod='bsalesync'}
          <a href="{$link->getAdminLink('AdminModules')}&configure=bsalesync" class="btn btn-xs btn-primary pull-right">
            <i class="icon-cog"></i> {l s='Ir a Configuracion' mod='bsalesync'}
          </a>
        </div>
      {/if}
    </div>
  </div>

  {* Panel de sync manual *}
  <div class="row" id="bsalesync-panel" {if !$is_configured}style="opacity:0.5;pointer-events:none;"{/if}>
    <div class="col-md-6">
      <div class="panel panel-default">
        <div class="panel-heading">{l s='Sincronizacion manual' mod='bsalesync'}</div>
        <div class="panel-body">
          <div class="btn-group" role="group">
            <button class="btn btn-primary btn-sync" data-entity="products">
              <i class="icon-archive"></i> {l s='Productos' mod='bsalesync'}
            </button>
            <button class="btn btn-default btn-sync" data-entity="stock">
              <i class="icon-signal"></i> {l s='Stock' mod='bsalesync'}
            </button>
            <button class="btn btn-default btn-sync" data-entity="prices">
              <i class="icon-tag"></i> {l s='Precios' mod='bsalesync'}
            </button>
          </div>

          {* Progress *}
          <div id="bsalesync-progress" class="hidden" style="margin-top:15px;">
            <div class="progress">
              <div class="progress-bar progress-bar-striped active" style="width:100%">
                {l s='Sincronizando...' mod='bsalesync'}
              </div>
            </div>
          </div>

          {* Resultado *}
          <div id="bsalesync-result" class="hidden" style="margin-top:15px;"></div>
        </div>
      </div>
    </div>

    {* Ultimo sync *}
    <div class="col-md-6">
      <div class="panel panel-default">
        <div class="panel-heading">{l s='Ultimo resultado' mod='bsalesync'}</div>
        <div class="panel-body">
          {if $sync_logs}
            {assign var='last' value=$sync_logs[0]}
            <table class="table table-condensed">
              <tr><td>{l s='Fecha' mod='bsalesync'}</td><td>{$last.created_at}</td></tr>
              <tr><td>{l s='Tipo' mod='bsalesync'}</td><td>{$last.entity_type|capitalize}</td></tr>
              <tr><td>{l s='Estado' mod='bsalesync'}</td>
                <td>
                  {if $last.status == 'success'}<span class="label label-success">OK</span>
                  {elseif $last.status == 'partial'}<span class="label label-warning">{l s='Parcial' mod='bsalesync'}</span>
                  {else}<span class="label label-danger">{l s='Error' mod='bsalesync'}</span>
                  {/if}
                </td>
              </tr>
              <tr><td>{l s='Actualizados' mod='bsalesync'}</td><td>{$last.records_ok}</td></tr>
              <tr><td>{l s='Errores' mod='bsalesync'}</td><td>{$last.records_fail}</td></tr>
              <tr><td>{l s='Duracion' mod='bsalesync'}</td><td>{($last.duration_ms / 1000)|string_format:"%.1f"}s</td></tr>
            </table>
          {else}
            <p class="text-muted">{l s='Sin sincronizaciones aun.' mod='bsalesync'}</p>
          {/if}
        </div>
      </div>
    </div>
  </div>

  {* Historial *}
  <div class="row">
    <div class="col-md-12">
      <div class="panel panel-default">
        <div class="panel-heading">{l s='Historial de sincronizaciones' mod='bsalesync'}</div>
        <div class="panel-body">
          {if $sync_logs}
            <table class="table table-hover table-condensed">
              <thead>
                <tr>
                  <th>{l s='Fecha' mod='bsalesync'}</th>
                  <th>{l s='Tipo' mod='bsalesync'}</th>
                  <th>{l s='Entidad' mod='bsalesync'}</th>
                  <th>{l s='Estado' mod='bsalesync'}</th>
                  <th>{l s='Actualizados' mod='bsalesync'}</th>
                  <th>{l s='Errores' mod='bsalesync'}</th>
                  <th>{l s='Duracion' mod='bsalesync'}</th>
                </tr>
              </thead>
              <tbody>
                {foreach $sync_logs as $log}
                  <tr>
                    <td>{$log.created_at}</td>
                    <td>{$log.sync_type|capitalize}</td>
                    <td>{$log.entity_type|capitalize}</td>
                    <td>
                      {if $log.status == 'success'}<span class="label label-success">OK</span>
                      {elseif $log.status == 'partial'}<span class="label label-warning">Parcial</span>
                      {else}<span class="label label-danger">Error</span>
                      {/if}
                    </td>
                    <td>{$log.records_ok}</td>
                    <td class="{if $log.records_fail > 0}text-danger{/if}">{$log.records_fail}</td>
                    <td>{($log.duration_ms / 1000)|string_format:"%.1f"}s</td>
                  </tr>
                {/foreach}
              </tbody>
            </table>
          {else}
            <p class="text-muted">{l s='Sin sincronizaciones registradas.' mod='bsalesync'}</p>
          {/if}
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function() {
  var ajaxUrl = '{$ajax_url|escape:"javascript"}';
  var token   = '{$token|escape:"javascript"}';

  document.querySelectorAll('.btn-sync').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var entity = btn.dataset.entity;
      triggerSync(entity);
    });
  });

  function triggerSync(entity) {
    document.getElementById('bsalesync-progress').classList.remove('hidden');
    document.getElementById('bsalesync-result').classList.add('hidden');
    document.querySelectorAll('.btn-sync').forEach(function(b) { b.disabled = true; });

    fetch(ajaxUrl + '&action=syncNow&token=' + token, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'entity=' + encodeURIComponent(entity),
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      document.getElementById('bsalesync-progress').classList.add('hidden');
      document.querySelectorAll('.btn-sync').forEach(function(b) { b.disabled = false; });

      var cls = data.status === 'success' ? 'alert-success'
              : data.status === 'partial' ? 'alert-warning'
              : 'alert-danger';

      var html = '<div class="alert ' + cls + '">' + data.message + '</div>';

      if (data.errors && data.errors.length > 0) {
        html += '<ul class="text-danger small">';
        data.errors.forEach(function(e) {
          html += '<li><b>' + e.code + '</b>: ' + e.message + '</li>';
        });
        html += '</ul>';
      }

      document.getElementById('bsalesync-result').innerHTML = html;
      document.getElementById('bsalesync-result').classList.remove('hidden');

      if (data.success) setTimeout(function() { location.reload(); }, 2000);
    })
    .catch(function(err) {
      document.getElementById('bsalesync-progress').classList.add('hidden');
      document.querySelectorAll('.btn-sync').forEach(function(b) { b.disabled = false; });
      document.getElementById('bsalesync-result').innerHTML =
        '<div class="alert alert-danger">Error de red: ' + err.message + '</div>';
      document.getElementById('bsalesync-result').classList.remove('hidden');
    });
  }
})();
</script>
