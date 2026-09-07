# Implementación de las 3 tiendas del cliente (allgrano · semillasdecanamo · strainmachine)

Plan concreto para **estas tres tiendas**. El procedimiento genérico está en
`docs/testing/tutorial-alta-3-tiendas.md`; acá va solo lo que las diferencia, que es mucho:
**las tres arrancan de puntos distintos y una de ellas no admite el script de alta**.

🔐 Las credenciales viven en `ssh/Implementacion_eccomerce.txt` (gitignored). No las copies a
ningún otro archivo, no las pegues en issues ni en chats. Estuvieron en texto plano en una
carpeta versionada: **pedile al cliente que las rote**.

---

## Estado verificado (07-sep-2026)

| Tienda | PrestaShop | Acceso al hosting | Synkrop | Qué falta |
|---|---|---|---|---|
| **strainmachine.com** | ✅ | SSH (`ssh/strainmachine.sh`) | Instalado v1.3.0, 8.337 productos mapeados | Config vacía (`bsale_integration_id` sin valor) y sin actividad en el log |
| **semillasdecanamo.cl** | ✅ | ❓ desconocido | No | Averiguar hosting y si tiene SSH |
| **allgrano.com** | ✅ | ⚠️ **FTP únicamente** (DirectAdmin, SSH deshabilitado) | No | El script de alta no le sirve; además tiene `syncBsale` (plugin de terceros) |

---

## Lo que hay que conseguir antes de empezar

Las credenciales del archivo **no alcanzan**: son el admin de PrestaShop y el login web de
Bsale. Para implementar hacen falta otras dos cosas:

1. **Token de API de Bsale** — no es la contraseña del panel. Se genera dentro de Bsale
   (Configuración → Integraciones/API). Con el login que hay en `ssh/` se puede sacar.
2. **Acceso al hosting de semillasdecanamo.cl** (SSH o FTP). Es lo único que falta averiguar:
   de las otras dos ya se conoce.

También hay que **confirmar que las tres tiendas usan la misma cuenta Bsale**. Todo el diseño
multi-tienda depende de eso: comparten `cpnId`, y por eso un solo webhook alcanza para las
tres. Si alguna usa otra cuenta, es otro escenario y hay que replantearlo.

---

## Orden recomendado: de menor a mayor riesgo

### Etapa 1 — strainmachine.com (la más avanzada)

Ya tiene el plugin y el catálogo mapeado. **No hay que instalar nada**: hay que entender por
qué quedó inactiva y completarle la configuración.

1. Averiguar qué pasó. Tiene 8.337 productos mapeados —o sea, sincronizó en algún momento—
   pero hoy `bsale_integration_id` está vacío y el log no registra actividad. Algo se cortó:
   una reinstalación del módulo, una restauración del sitio, o alguien vació la config.
   **Entender esto antes de sumar tiendas**, o se repite en las otras dos.
2. Completar la configuración en el panel de Synkrop: token de Bsale, integración (`cpnId`),
   lista de precios, sucursal y qué flujos activar.
3. Registrarla en bot-miki bajo el tenant del cliente (plan `growth`, 3 tiendas).
4. Verificar con una sincronización manual desde el panel antes de continuar.

### Etapa 2 — semillasdecanamo.cl (la que sigue el camino estándar)

Primero averiguá si el hosting tiene SSH:

- **Con SSH:** es el caso estándar. Seguí `docs/testing/tutorial-alta-3-tiendas.md` tal cual,
  con **una sola entrada** en `STORES` dentro de `scripts/onboard-cliente.sh`.
- **Sin SSH:** aplica lo mismo que allgrano (etapa 3).

Instalá el módulo desde el back-office **antes** de correr el script: las tablas base las crea
el instalador de PrestaShop, no las migraciones.

### Etapa 3 — allgrano.com (la que necesita trabajo aparte)

Dos obstáculos, ninguno técnicamente imposible, pero los dos requieren decisión:

**a) Sin SSH.** El hosting es DirectAdmin con SSH deshabilitado — solo FTP (ver
`ssh/allgrano.sh`, la cuenta está enjaulada en `/home/allgraadm/domains/allgrano.com`).
`scripts/onboard-cliente.sh` usa `rsync` sobre SSH, así que **no funciona acá**. Alternativas:

- Subir el plugin por FTP y correr `sql/migrate.php` desde el navegador (con una URL temporal
  protegida), o pedirle al hosting que habilite SSH aunque sea temporalmente.
- La configuración se puede cargar a mano desde el panel de Synkrop, sin script.

**b) Tiene `syncBsale` instalado**, un plugin de terceros que hace lo mismo que Synkrop
(ver issue #128). Antes de instalar hay que decidir con el cliente: se reemplaza, o conviven.
**Convivir es mala idea**: dos plugins escribiendo el mismo stock desde el mismo ERP se pisan,
y depurar eso después es carísimo. La recomendación es desinstalar `syncBsale` primero.

---

## Después de las tres: la verificación que importa

Es la única prueba que **solo falla con más de una tienda**, así que no se saltea:

1. En Bsale, un **único** webhook a `https://miki.keepcrop.com/v1/webhooks/bsale`.
   No uno por tienda: bot-miki lo reparte por `cpnId`.
2. Cambiar el stock de un producto en Bsale.
3. En los logs del worker buscar `webhook abanicado a varias tiendas` con `stores: 3`.

```bash
cmd.exe /c "railway logs --service bot-miki-worker"
```

Si dice `stores: 1`, las tiendas no comparten `bsale_integration_id`: revisá que las tres se
hayan registrado con el mismo `cpnId`.

---

## Lo que hay que hablar con el cliente

**Las tres tiendas van a compartir el mismo stock.** No son inventarios separados: si en Bsale
hay 10 unidades, las tres muestran 10, y son las mismas 10.

**La última unidad.** Si dos compradores de tiendas distintas la compran casi a la vez, los dos
completan el checkout. Después, la primera nota de venta reserva en Bsale y la segunda es
rechazada; ese pedido queda en `backorder` en el panel, sin reintentarse solo, esperando que
alguien decida (reponer, cambiar el producto o reembolsar).

**La mitigación real es stock de seguridad en Bsale**, que las tiendas no ofrezcan la última
unidad o las dos últimas. El software achica la ventana —desde el fix de sep-2026 las tres se
enteran de las reservas de las otras— pero no la elimina.

---

## Referencia rápida

| Qué | Dónde |
|---|---|
| Procedimiento genérico paso a paso | `docs/testing/tutorial-alta-3-tiendas.md` |
| Cómo probarlo antes de tocar al cliente | `docs/testing/manual-multi-tienda.md` |
| Script de alta (requiere SSH) | `scripts/onboard-cliente.sh` |
| Migraciones (cualquier prefijo) | `packages/cms-prestashop/synkrop/sql/migrate.php` |
| Acceso a strainmachine | `ssh/strainmachine.sh` |
| Acceso a allgrano (FTP) | `ssh/allgrano.sh` |
| Credenciales del cliente | `ssh/Implementacion_eccomerce.txt` (gitignored) |
