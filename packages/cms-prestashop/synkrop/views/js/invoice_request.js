/**
 * Formulario boleta/factura del checkout: toggle, validación de RUT (módulo 11)
 * en vivo y guardado AJAX por carrito. El backend re-valida todo.
 */
(function () {
  'use strict';

  var root = document.getElementById('synkrop-invoice-request');
  if (!root) {
    return;
  }

  var fields = document.getElementById('synkrop-invoice-fields');
  var status = document.getElementById('synkrop-invoice-status');
  var rutInput = document.getElementById('synkrop_rut');
  var rutFeedback = document.getElementById('synkrop-rut-feedback');

  function validaRut(rut) {
    var clean = rut.replace(/[^0-9kK]/g, '');
    if (clean.length < 8 || clean.length > 9) {
      return false;
    }
    var dv = clean.slice(-1).toUpperCase();
    var num = clean.slice(0, -1);
    if (!/^\d+$/.test(num) || parseInt(num, 10) <= 0) {
      return false;
    }
    var sum = 0;
    var mul = 2;
    for (var i = num.length - 1; i >= 0; i--) {
      sum += parseInt(num[i], 10) * mul;
      mul = mul === 7 ? 2 : mul + 1;
    }
    var exp = 11 - (sum % 11);
    var expected = exp === 11 ? '0' : exp === 10 ? 'K' : String(exp);
    return dv === expected;
  }

  function post(data, cb) {
    data.token = root.dataset.token;
    data.ajax = 1;
    var body = Object.keys(data)
      .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(data[k]); })
      .join('&');
    var xhr = new XMLHttpRequest();
    xhr.open('POST', root.dataset.ajaxUrl, true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onload = function () {
      try {
        cb(JSON.parse(xhr.responseText));
      } catch (e) {
        cb({ ok: false, message: 'Error de comunicación — intenta de nuevo' });
      }
    };
    xhr.onerror = function () {
      cb({ ok: false, message: 'Error de comunicación — intenta de nuevo' });
    };
    xhr.send(body);
  }

  function showStatus(res) {
    status.textContent = res.message || '';
    status.style.color = res.ok ? 'green' : '#c0392b';
  }

  rutInput.addEventListener('blur', function () {
    if (rutInput.value.trim() === '') {
      rutFeedback.textContent = '';
      return;
    }
    var ok = validaRut(rutInput.value);
    rutFeedback.textContent = ok ? '✓ RUT válido' : 'RUT inválido — revísalo';
    rutFeedback.style.color = ok ? 'green' : '#c0392b';
  });

  root.querySelectorAll('input[name="synkrop_doc_choice"]').forEach(function (radio) {
    radio.addEventListener('change', function () {
      var esFactura = this.value === 'factura';
      fields.style.display = esFactura ? '' : 'none';
      if (!esFactura) {
        post({ action_synkrop: 'remove' }, showStatus);
      } else {
        status.textContent = '';
      }
    });
  });

  document.getElementById('synkrop-save-invoice').addEventListener('click', function () {
    if (!validaRut(rutInput.value)) {
      showStatus({ ok: false, message: 'RUT inválido — revísalo antes de guardar' });
      return;
    }
    post(
      {
        action_synkrop: 'save',
        rut: rutInput.value,
        razon_social: document.getElementById('synkrop_razon_social').value,
        giro: document.getElementById('synkrop_giro').value,
        direccion: document.getElementById('synkrop_direccion').value,
        comuna: document.getElementById('synkrop_comuna').value,
        ciudad: document.getElementById('synkrop_ciudad').value
      },
      function (res) {
        if (res.ok && res.rut) {
          rutInput.value = res.rut;
        }
        showStatus(res);
      }
    );
  });
})();
