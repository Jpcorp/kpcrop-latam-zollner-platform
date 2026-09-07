# Manual de pruebas — alta multi-tienda (N tiendas PrestaShop, un Bsale)

Cómo probar `sql/migrate.php` y `scripts/onboard-cliente.sh` antes de tocar la tienda de
un cliente. Las cuatro fases van de menor a mayor riesgo: **no saltes a la 3 sin pasar la 2**.

Caso de referencia: un cliente con 3 e-commerce PrestaShop contra una sola cuenta Bsale.
El escenario está soportado a propósito — ver el comentario del fan-out en
`packages/bot-miki/src/routes/webhooks.ts`.

---

## Fase 0 — Local, sin red ni servidores

Valida que las migraciones declaradas cubren lo mismo que los `.sql` históricos.

```bash
cd packages/cms-prestashop
composer test                       # o: php vendor/phpunit/phpunit/phpunit --no-coverage
```

Esperado: **97 tests verdes**, 9 de ellos de `MigrationsTest`.

Para correr solo los de migraciones:

```bash
php vendor/phpunit/phpunit/phpunit --no-coverage --filter MigrationsTest
```

### Qué protege exactamente

`MigrationsTest` parsea los 11 `migrate_*.sql` y los compara contra `sql/migrations.php`.
Si alguien agrega una migración a los `.sql` y olvida registrarla en el array, falla con el
nombre de la que falta. Ese es justo el error que dejó `#56` y `#130` seis semanas sin
aplicar en producción.

Comprobá que el test de verdad muerde (debe fallar y luego volver a verde):

```bash
# borrá a mano una línea del array de sql/migrations.php, p.ej. order_auto_mode
php vendor/phpunit/phpunit/phpunit --no-coverage --filter MigrationsTest
#   -> Hay columnas en los migrate_*.sql que migrations.php no aplica: order_auto_mode
git checkout packages/cms-prestashop/synkrop/sql/migrations.php
```

---

## Fase 1 — Dry-run contra una tienda real

`--dry-run` **no escribe nada**: solo reporta qué haría. Es seguro correrlo en producción.

```bash
bash ssh/strainmachine.sh run \
  "cd /home/strainma/public_html/modules/synkrop/sql && php migrate.php --dry-run"
```

En una tienda **ya migrada** (strainmachine.com hoy) esperá:

```
Prefijo: pr_   [DRY RUN — no escribe nada]

[SKIP] test_mode ya existe en pr_synkrop_config
...
Resultado: 0 aplicadas, 22 omitidas
```

Lo importante de esa salida no es el `0 aplicadas`, sino la **primera línea**: `Prefijo: pr_`.
Confirma que el runner tomó el prefijo real de `_DB_PREFIX_`. Los `.sql` viejos habrían
buscado `ps_` y no habrían hecho nada, sin error visible.

En una tienda **nueva** vas a ver `[OK]` en vez de `[SKIP]`, y al final `22 aplicadas`.

### Si sale `[!] <tabla> no existe — instala el modulo primero`

El módulo no está instalado en esa tienda: las tablas base las crea `install.sql` al
instalar Synkrop desde el back-office. El runner **no** las crea a medias a propósito.
Instalá el módulo y volvé a correr.

### Si sale `No encuentro config.inc.php bajo ...`

El runner sube 3 niveles desde `sql/` (`sql → synkrop → modules → raíz`). Si esa tienda
tiene otra estructura, pasale la raíz explícita:

```bash
PS_ROOT_DIR=/ruta/a/prestashop php migrate.php --dry-run
```

---

## Fase 2 — Una sola tienda, de verdad

**No arranques con las tres.** Dejá una sola entrada en `STORES` dentro de
`scripts/onboard-cliente.sh` y corré el flujo completo. Recién cuando esa tienda esté
sincronizando bien, agregá las otras dos y volvé a correr el script (es idempotente).

```bash
export BSALE_TOKEN=...          # token de la integración Bsale del cliente
export ADMIN_KEY=...            # X-Admin-Key de bot-miki
export TENANT_ID=cliente-real
export SSH_KEY=ssh/id_rsa

bash scripts/onboard-cliente.sh https://api.bsale.io/v1
```

El script aborta temprano —antes de tocar ninguna tienda— si:

| Síntoma | Causa |
|---|---|
| `Bsale respondio 401` | token inválido o URL de API equivocada |
| `Falta BSALE_TOKEN` / `Falta ADMIN_KEY` | faltan variables de entorno |
| `No pude detectar el cpnId` | pasalo a mano: `export BSALE_INTEGRATION_ID=...` |
| `el plan no permite mas tiendas` | la licencia es `starter` (1) — subí a `growth` (3) o `agency` (50) |

### Verificar después de correrlo

```bash
# 1. la tienda quedó registrada, y con el cpnId correcto
curl -s -H "X-Admin-Key: $ADMIN_KEY" \
  https://miki.keepcrop.com/admin/tenants/$TENANT_ID/stores

# 2. las migraciones quedaron aplicadas en esa tienda
ssh <host> "cd <ps_root>/modules/synkrop/sql && php migrate.php --dry-run"
#    -> debe decir "0 aplicadas, 22 omitidas"

# 3. no quedó ningún archivo temporal con el token en el servidor
ssh <host> "ls <ps_root>/modules/synkrop/_cfg.php 2>/dev/null || echo limpio"
```

---

## Fase 3 — Las tres tiendas juntas

Con las 3 entradas en `STORES`, lo que hay que probar es lo que **solo falla con más de
una tienda**: el fan-out del webhook.

1. En Bsale, apuntá el webhook a `https://miki.keepcrop.com/v1/webhooks/bsale`.
   Es **uno solo** para las tres: bot-miki lo reparte por `cpnId`.
2. Cambiá el stock de un producto en Bsale.
3. En los logs de `bot-miki-worker` buscá la línea del abanico:

```bash
cmd.exe /c "railway logs --service bot-miki-worker"
#   -> 'webhook abanicado a varias tiendas'  con  stores: 3
```

Si dice `stores: 1`, las tiendas **no** comparten `bsale_integration_id`: revisá que las
tres tengan el mismo `cpnId`. Ese era el bug que arregló el commit `8802d61` — antes se
elegía una tienda arbitrariamente y las otras se enteraban recién en el siguiente polling.

4. Confirmá que el stock llegó a las tres tiendas (en cada una, tabla `synkrop_log`).

---

## Lo que este manual NO cubre

Se dice acá para que nadie lo dé por probado:

- **El flujo completo de `onboard-cliente.sh` nunca se corrió end-to-end.** Se validaron
  sus guardas (aborta sin parámetro, sin token, sin `ADMIN_KEY`) y el runner de
  migraciones contra producción real, pero el alta de las 3 tiendas requiere las
  credenciales y hosts del cliente. Por eso la Fase 2 arranca con una sola tienda.
- **Sobreventa.** Las tiendas comparten el stock de un mismo Bsale y Synkrop lo *refleja*,
  no lo *reserva*: si las tres venden la última unidad en la misma ventana, se vende tres
  veces. No es un bug del código; es una conversación con el cliente y, si corresponde,
  stock de seguridad en Bsale.
- **Volumen.** El piloto en producción es una sola tienda. Tres contra el mismo Bsale
  triplican las llamadas, y el manejo de `429` hace `sleep` respetando `Retry-After`, lo
  que bloquea ese worker mientras espera.

---

## Referencia rápida

| Qué | Dónde |
|---|---|
| Runner de migraciones | `packages/cms-prestashop/synkrop/sql/migrate.php` |
| Las migraciones, como datos | `packages/cms-prestashop/synkrop/sql/migrations.php` |
| Test de paridad | `packages/cms-prestashop/tests/MigrationsTest.php` |
| Alta de cliente | `scripts/onboard-cliente.sh` |
| Fan-out por `cpnId` | `packages/bot-miki/src/routes/webhooks.ts` |
| Límite de tiendas por plan | `packages/bot-miki/src/routes/admin.ts` |
