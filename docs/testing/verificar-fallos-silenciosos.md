# Manual — verificar los fallos silenciosos (con una sola tienda)

Cubre los 4 arreglos de los commits `1e3742b`, `2f25bc9` y `93c40d1`.

**Por qué esto necesita su propio manual:** los 4 bugs eran *silenciosos*. Usar la tienda
normalmente no los muestra — ese era exactamente el problema. La única forma de comprobar
que están arreglados es **provocar el fallo a propósito** y ver que ahora se nota.

Está pensado para el escenario real de hoy: **una tienda en producción y dos por montar.**

---

## Los 4 arreglos y qué se ve ahora

| # | Bug | Antes se veía | Ahora se ve |
|---|---|---|---|
| 1 | `sync.php stock --shop=2` descartaba `--shop=2` | Sincronizaba la tienda 1, log con `id_shop=1` | `exit 1` + "La opción '--shop=2' va antes de la entidad" |
| 2 | `UPDATE` fallido tras crear la nota en Bsale | "Nota creada" y a la vuelta **otra** nota | Pedido en `review`, mensaje explícito, el lote se corta |
| 3 | `Mail::Send()` devolvía `false` | El cliente sin boleta, cero rastro | `error_details` en el pedido + `notify_failed` / `exit 1` en el cron |
| 4 | `purgeOldLogs()` con `DELETE` fallido | "Completado." cada noche, tabla creciendo | `[ERROR]` + `exit 1` |

---

## Nivel 0 — La suite local (2 minutos, empezá por acá)

No necesita PrestaShop, ni red, ni Bsale.

```bash
cd packages/cms-prestashop
/mnt/c/tools/php82/php.exe vendor/phpunit/phpunit/phpunit --no-coverage
# esperado: OK (122 tests, 449 assertions)
```

**Comprobá que los tests sirven de algo.** Un test que no falla al revertir el arreglo no
prueba nada. Rompé el código a mano y mirá que se ponga rojo:

```bash
# Rompé el guard de purgeOldLogs: en synkrop/classes/SynkropService.php
# cambiá   if (!$ok) {     por   if (false) {
/mnt/c/tools/php82/php.exe vendor/phpunit/phpunit/phpunit --no-coverage --filter purgeOldLogs
# esperado: 1 failure — "Failed asserting that exception of type RuntimeException is thrown"

git checkout -- synkrop/classes/SynkropService.php
```

Los 4 arreglos ya se verificaron así. Si alguna vez la suite queda verde con el código roto,
el test está de adorno.

---

## Nivel 1 — PrestaShop en Docker (sin tocar la tienda del cliente)

Acá se prueba de verdad, y no hay nada que romper: es tu PrestaShop desechable.

```bash
cd packages/cms-prestashop
docker compose up -d
# PrestaShop  http://localhost:8080
# MySQL       localhost:3307
# Mailhog     http://localhost:8025   <-- clave para las pruebas de email
```

### Caso 1 — El CLI ya no ignora las opciones

```bash
docker compose exec prestashop php modules/synkrop/cli/sync.php stock --shop=2
# esperado: [ERROR] La opción '--shop=2' va antes de la entidad
echo "exit code: $?"        # 1

docker compose exec prestashop php modules/synkrop/cli/sync.php --shop=1 stock
# esperado: arranca de verdad (ya no descarta nada)
```

### Caso 3 — Email que no sale

Mailhog captura todo, así que para simular el fallo hay que apuntar PrestaShop a un SMTP
que **no** existe:

1. Backoffice → *Parámetros avanzados* → *E-mail* → SMTP manual, servidor `localhost`,
   puerto `1099` (nadie escuchando).
2. Generá y "emití" un documento de prueba (con `test_mode=1` alcanza un documento manual
   en Bsale — ver `tutorial-e2e-fase1.md`).
3. Apretá **Verificar emisiones**.

Qué mirar:

```sql
-- El pedido igual se cierra (eso es #128, a propósito), pero ahora deja rastro:
SELECT id_order, status, error_details
FROM ps_synkrop_order_queue WHERE id_order = <N>;
-- error_details debe traer: "Documento emitido en Bsale, pero NO se pudo avisar..."
```

Y el cron de pendientes:

```bash
docker compose exec prestashop php modules/synkrop/cli/order-notify.php --shop=1
# con SMTP roto y pedidos pendientes:
#   [ERROR] Mail::Send() devolvio false: el aviso de N pedido(s) ... no salio.
echo "exit code: $?"        # 1  <-- antes era 0 y decía "Notificado: N"
```

Devolvé el SMTP a `localhost:1025` y repetí: debe volver a `exit 0` y el correo aparece en
`http://localhost:8025`.

### Caso 4 — Purga que falla

El modo más simple de provocar el fallo es quitarle el permiso de `DELETE` al usuario MySQL:

```bash
docker compose exec mysql mysql -uroot -pprestashop -e \
  "REVOKE DELETE ON prestashop.* FROM 'prestashop'@'%'; FLUSH PRIVILEGES;"

docker compose exec prestashop php modules/synkrop/cli/retention.php --days=1
# esperado: [ERROR] No se pudo purgar synkrop_log (>1 dias, lote de 5000)
echo "exit code: $?"        # 1  <-- antes era 0 y decía "Completado."

docker compose exec mysql mysql -uroot -pprestashop -e \
  "GRANT DELETE ON prestashop.* TO 'prestashop'@'%'; FLUSH PRIVILEGES;"
```

### Caso 2 — La nota de venta duplicada

Es el más difícil de provocar a mano, porque hay que hacer fallar **un solo** `UPDATE` justo
después de que Bsale creó el documento. La forma controlada es a nivel de permisos:

```bash
# Quitá UPDATE solo sobre la cola, dejando el resto intacto
docker compose exec mysql mysql -uroot -pprestashop -e \
  "REVOKE UPDATE ON prestashop.ps_synkrop_order_queue FROM 'prestashop'@'%'; FLUSH PRIVILEGES;"
```

Apretá **Generar** sobre un pedido `pending`. Esperado:

- El panel dice: *"Nota de venta N°X creada en Bsale pero NO se pudo registrar… Queda en
  revisión para no duplicarla"*, o corta el lote con *"Se detuvo la generación"*.
- En Bsale hay **una** nota, no dos.
- Al restaurar el permiso, el pedido queda en `review` — **nunca** en `error`, porque `error`
  vuelve al ciclo de reintentos y ahí es donde nacía el duplicado.

```bash
docker compose exec mysql mysql -uroot -pprestashop -e \
  "GRANT UPDATE ON prestashop.ps_synkrop_order_queue TO 'prestashop'@'%'; FLUSH PRIVILEGES;"
```

> Si preferís no jugar con permisos, este caso ya está cubierto por los 3 tests de
> `OrderDocumentServiceTest`, verificados por mutación. La prueba manual sirve para ver el
> mensaje que le va a llegar al operador.

---

## Nivel 2 — La tienda real, sin riesgo

Sobre `strainmachine.com`, **solo lo que no escribe nada**:

```bash
# El CLI rechaza el orden malo antes de tocar la BD (el parseo corre antes del bootstrap)
bash ssh/strainmachine.sh run "cd <ps_root> && php modules/synkrop/cli/sync.php stock --shop=2"
# esperado: exit 1, sin ninguna fila nueva en synkrop_log

# Migraciones: --dry-run no escribe
bash ssh/strainmachine.sh run "cd <ps_root>/modules/synkrop/sql && php migrate.php --dry-run"
```

**No** provoques fallos de SMTP ni de permisos MySQL en la tienda del cliente. Para eso está
el Nivel 1.

---

## Lo que NO se puede probar con una sola tienda

Se dice acá para que nadie lo dé por probado:

- **El arreglo de `LicenseClient` (`--shop`) no es observable en tu topología.** Las 3 tiendas
  van a ser **instalaciones separadas de PrestaShop, cada una en su hosting**
  (`tutorial-alta-3-tiendas.md`), así que en todas `id_shop = 1` y el bug nunca se manifiesta.
  El arreglo es preventivo, para el día que alguien use un multistore real. Su cobertura es el
  test `testElJwtCacheadoSeBuscaPorLaTiendaDelConstructor`, que sí falla si se revierte.
  Para verlo con los ojos hace falta un PrestaShop con multistore activado y 2 tiendas.
- **Sobreventa entre las 3 tiendas.** Synkrop *refleja* el stock, no lo *reserva* en el
  checkout. Nada de esto lo cambia. Es conversación con el cliente + stock de seguridad en Bsale.
- **Volumen.** Tres tiendas contra el mismo Bsale triplican las llamadas; el manejo de `429`
  hace `sleep` respetando `Retry-After` y bloquea ese worker mientras espera.

---

## Orden sugerido para montar las 3

1. **Nivel 0** en local. Si la suite no está verde, no sigas.
2. **Nivel 1** en Docker, los 4 casos. Es donde podés romper cosas gratis.
3. **Tienda 1 (la que ya existe):** desplegá y corré solo el Nivel 2 (read-only).
4. **Tienda 2:** seguí `tutorial-alta-3-tiendas.md`. Antes de conectarla al Bsale del cliente,
   pasale el Nivel 2. Es la primera vez que el webhook se abanica a dos tiendas: mirá en
   bot-miki que diga `webhook abanicado a varias tiendas` con `stores: 2`.
5. **Tienda 3:** igual que la 2. Recién acá tiene sentido mirar volumen y `429`.

Después de cada alta, confirmá que las migraciones **realmente** corrieron — es el error que
en este repo costó 6 semanas de features muertas:

```bash
bash ssh/<tienda>.sh run "cd <ps_root>/modules/synkrop/sql && php migrate.php"
# esperado la segunda vez: "0 aplicadas, N omitidas"
```

---

## Referencia rápida

| Qué | Dónde |
|---|---|
| Suite PHP | `cd packages/cms-prestashop && /mnt/c/tools/php82/php.exe vendor/phpunit/phpunit/phpunit --no-coverage` |
| Laboratorio PS 1.7.8 | `packages/cms-prestashop/docker-compose.yml` (`:8080`, MySQL `:3307`, Mailhog `:8025`) |
| Alta de tiendas nuevas | `docs/testing/tutorial-alta-3-tiendas.md` |
| Pruebas por fases del alta | `docs/testing/manual-multi-tienda.md` |
| Flujo PS→Bsale de punta a punta | `docs/testing/tutorial-e2e-fase1.md` |
| Runner de migraciones | `packages/cms-prestashop/synkrop/sql/migrate.php` |
