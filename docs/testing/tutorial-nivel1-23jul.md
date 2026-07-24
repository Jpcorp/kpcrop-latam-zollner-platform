# Tutorial: probar lo construido en la ronda del 23-jul (Nivel 1 del backlog)

Guía práctica para validar a mano, en el entorno local, todo lo implementado en esta ronda:
modo degradado por licencia (#127), polling diff (#79), notificación al cliente + modo
degradado por caída de bot-miki (#128), sync de categorías (#87). Las validaciones Zod
(#36/#37) no tienen UI — quedan cubiertas por los tests automatizados, no hace falta probarlas
a mano.

## El entorno

| Qué | Dónde |
|---|---|
| Tienda | http://localhost:8080 |
| Admin PS | http://localhost:8080/admin-dev |
| Panel Synkrop | Admin → Módulos → Synkrop → Configurar (toggles) / Catálogo → Synkrop (sync manual) |
| bot-miki local | http://localhost:3000 (Swagger en `/docs`, pide `X-Admin-Key`) |
| Panel sandbox Bsale | https://account.bsale.dev |
| Mailhog (correo capturado) | http://localhost:8025 |

**Ya apliqué las migraciones nuevas** (`degraded_manual_sync_at`, `sync_categories` +
`category_parent_id` + tabla `synkrop_category_map`, y `005_add_polling_sync_type.sql` en
bot-miki) contra las bases locales — no hace falta que las corras vos si tu checkout es de
después del commit `57de5c0`.

**`SYNKROP_DAEMON_URL`**: si tu checkout es de antes del commit `4da435f`, el plugin local
apunta hardcodeado a producción (`https://miki.keepcrop.com`) en vez de a bot-miki local —
cualquier validación de licencia da 404. El `docker-compose.yml` de `cms-prestashop` ya
setea `SYNKROP_DAEMON_URL=http://host.docker.internal:3000` para el contenedor de PS.

### Arrancar todo

```bash
# 1. PrestaShop + Mailhog (si no están arriba)
cd packages/cms-prestashop && docker compose up -d

# 2. Postgres + Redis de bot-miki (raíz del repo)
cd ../.. && docker compose up postgres redis -d

# 3. bot-miki: COMPILAR primero (el script "dev" solo hace `node --watch
#    dist/*.js`, NO compila TypeScript — si tocaste código y no reconstruiste,
#    vas a estar probando una version vieja sin darte cuenta)
pnpm --filter bot-miki build

# 4. bot-miki en modo dev (deja esta terminal corriendo). OJO: "pnpm dev" no
#    carga el .env solo (no hay dotenv en el proyecto) -- si te tira
#    "Variables de entorno invalidas", exportá las variables de
#    packages/bot-miki/.env en tu shell antes de correrlo.
pnpm --filter bot-miki dev
```

Confirmá que responde: `curl http://localhost:3000/health` → `{"status":"ok",...}`.

> Si cambiás código de bot-miki mientras probás, `node --watch` reinicia solo con los cambios
> del `dist/` **ya compilado** — acordate de correr `pnpm --filter bot-miki build` de nuevo
> antes de reintentar, si no vas a seguir pegándole a la versión vieja.

El `.env` de bot-miki ya tiene todo lo necesario (incluido `TOKEN_ENCRYPTION_KEY`). El seed de
desarrollo (`002_seed_dev.sql`) ya crea una licencia `dev-tenant-001` (plan `agency`, `api_key
kp_dev_api_key_para_desarrollo_local_no_usar_en_prod`) que coincide con lo que ya está
configurado en el `daemon_api_key` del PrestaShop local — no hace falta tocar nada ahí.

---

## Caso 1 — Sync de categorías, modo automático (#87)

1. Admin → Módulos → Synkrop → **Configurar**.
2. Activá **"Sincronizar categorías desde Bsale"**.
3. En **"Categoría padre (ID)"** poné el ID de una categoría existente (ej. `2` = Inicio en una
   instalación nueva de PS — confirmalo en Catálogo → Categorías si no estás seguro).
4. Guardar.
5. Admin → Catálogo → Synkrop (el panel de sync manual) → ahora debería aparecer un 4º botón
   **"Categorías"** junto a Productos/Stock/Precios (solo aparece si el toggle está activo).
6. Click en **Categorías**.
7. Resultado esperado: el historial de abajo muestra una fila `categories` con la cantidad de
   tipos de producto traídos desde Bsale (`/v1/product_types.json` del sandbox).
8. Catálogo → Categorías en PS: deberías ver las categorías nuevas creadas bajo la que elegiste
   en el paso 3.
9. **Repetí el click en Categorías una segunda vez** — no debería duplicar nada (reutiliza las
   que ya existen con el mismo nombre bajo el mismo padre).

**Qué validaste**: creación automática + reutilización (criterio de aceptación clave de #87).

---

## Caso 2 — Banner de licencia vencida + modo degradado (#127)

1. Con bot-miki corriendo, conectate a su Postgres local y suspendé la licencia de desarrollo:
   ```sql
   -- psql $DATABASE_URL (o via el cliente que uses)
   UPDATE licenses SET status = 'suspended' WHERE tenant_id = 'dev-tenant-001';
   ```
2. Refrescá Catálogo → Synkrop en el admin de PS.
3. **Resultado esperado**: aparece el banner rojo "Licencia vencida o suspendida" arriba del
   panel — el resto del panel (historial, config) sigue visible y usable.
4. Click en cualquier botón de sync manual (ej. Productos).
5. **Primera vez**: debería funcionar igual (modo degradado permite 1 sync manual).
6. **Segunda vez, inmediatamente después**: debería fallar con un mensaje tipo "el sync manual
   está limitado a 1 vez cada 24h... Próximo disponible: [fecha]".
7. Para revertir: `UPDATE licenses SET status = 'active' WHERE tenant_id = 'dev-tenant-001';`
   y, si querés resetear el límite de 24h para seguir probando:
   `UPDATE ps_synkrop_config SET degraded_manual_sync_at = NULL WHERE id_shop = 1;` (en el
   MySQL de PS).

**Qué validaste**: el negocio no se detiene con licencia vencida (sync manual limitado), pero
el auto-sync (webhook/cron) sí queda cortado — para probar esto último necesitarías un webhook
real de Bsale llegando mientras la licencia está suspendida (más difícil de simular a mano;
podés confiar en los tests automatizados para ese caso, `webhooks.test.ts`).

---

## Caso 3 — Modo degradado por caída de bot-miki (#128)

Este es distinto al Caso 2: acá la licencia sigue activa, pero **bot-miki no responde**.

1. Asegurate de que la licencia esté `active` de nuevo (paso 7 del Caso 2).
2. Hacé un sync manual exitoso una vez (para tener un JWT cacheado reciente).
3. **Apagá bot-miki** (`Ctrl+C` en la terminal donde corre `pnpm --filter bot-miki dev`).
4. Click en cualquier botón de sync manual en el panel de PS.
5. **Resultado esperado**: el sync **igual funciona** — cae al JWT cacheado aunque bot-miki no
   responda (ventana de gracia de 24h, ver `LicenseClient::isWithinStaleJwtGrace`).
6. Volvé a levantar bot-miki (`pnpm --filter bot-miki dev`) cuando termines.

**Qué validaste**: una caída de bot-miki (no una licencia vencida) no detiene el sync manual —
el matiz importante del Caso 3 vs el Caso 2 es que acá no hay ningún límite de frecuencia (no
es el mismo mecanismo que #127).

---

## Caso 4 — Notificación al cliente cuando se emite un documento (#128)

El `docker-compose.yml` local ya trae **Mailhog** (contenedor `kpcrop-ps178-mailhog`, UI en
http://localhost:8025) — PS está configurado para mandar el correo saliente ahí en vez de a un
MTA real. Si tu entorno es más viejo que el 24-jul-2026, corré `docker compose up -d mailhog`
y aplicá la config de `docker/post-install.sh` (bloque "Correo saliente → Mailhog") a mano una
vez.

1. Avanzá un pedido por el flujo de ventas (ver `tutorial-e2e-fase1.md`, Caso 1) hasta
   **[Verificar emisiones]** con la boleta ya emitida en Bsale sandbox — la fila debe pasar a
   `emitted`/`closed`.
2. Abrí **http://localhost:8025** — debería aparecer un correo nuevo.
3. Confirmá: asunto **"Tu documento de compra está listo"**, destinatario el cliente real de
   la orden, cuerpo con el número de documento y el link de descarga (HTML y texto plano).
4. Sin Mailhog (o si preferís no configurarlo), alternativa: confirmá igual que el pedido
   cerró bien (`emitted`/`closed`, no `error`) — eso ya prueba que un email fallido no bloquea
   el flujo (el punto central de #128), aunque no veas el contenido.

**Qué validaste**: el cliente final es notificado cuando su documento está listo (contenido
real confirmado vía Mailhog), y un fallo de email nunca bloquea el cierre del pedido.

---

## Caso 5 — Panel self-service de agencia (#54/#55, solo API)

No tiene UI todavía — se prueba con `curl` directo a bot-miki, usando el `api_key` de
desarrollo.

```bash
API_KEY="kp_dev_api_key_para_desarrollo_local_no_usar_en_prod"

# 1. Listar las tiendas de la agencia
curl -s http://localhost:3000/v1/agency/clients \
  -H "X-API-Key: $API_KEY" | python3 -m json.tool

# 2. Ver el storeId que devolvió el paso 1, y disparar un sync manual para esa tienda
curl -s -X POST "http://localhost:3000/v1/agency/clients/<storeId-del-paso-1>/sync" \
  -H "X-API-Key: $API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"entity":"products"}'

# 3. Ver el historial de esa tienda
curl -s "http://localhost:3000/v1/agency/clients/<storeId-del-paso-1>/logs" \
  -H "X-API-Key: $API_KEY" | python3 -m json.tool

# 4. Probar la protección IDOR: un storeId inventado debe dar 404, no datos de otro tenant
curl -s -o /dev/null -w "%{http_code}\n" \
  "http://localhost:3000/v1/agency/clients/00000000-0000-0000-0000-000000000000/logs" \
  -H "X-API-Key: $API_KEY"
```

**Qué validaste**: la agencia puede listar y gestionar sus propias tiendas con su propio
`api_key` (no el `X-Admin-Key` interno), y no puede tocar tiendas de otro tenant.

---

## Caso 6 — Polling diff (#79)

El más difícil de probar bajo demanda — corre con el scheduler de bot-miki, no con un click.
El seed de desarrollo agrega un job de `stock` cada 15 minutos. Para no esperar:

1. En el Postgres de bot-miki, acortá el intervalo temporalmente:
   ```sql
   UPDATE scheduled_jobs SET cron_expression = '* * * * *' WHERE entity_type = 'stock';
   ```
2. Mirá los logs de la terminal de `pnpm --filter bot-miki dev` — dentro del próximo minuto
   deberías ver una línea `[polling] jobId=... store=... changed=X dispatched=Y failed=Z
   total=N`.
3. Si `changed > 0`, revisá también que aparezcan líneas `[webhook:dispatch]` (así se llama el
   dispatch interno, aunque el trigger haya sido polling, no un webhook real) apuntando a
   `webhook.php`.
4. Revertí el cron cuando termines: `UPDATE scheduled_jobs SET cron_expression = '*/15 * * * *' WHERE entity_type = 'stock';`

**Qué validaste**: el worker pagina el catálogo completo (no solo los primeros 50 productos) y
solo despacha al CMS las variantes que realmente cambiaron.

> **Nota (23-jul, post-recorrido en vivo):** este caso encontró y arregló 2 bugs reales que
> hacían que el polling automático estuviera completamente roto (nunca se había ejercitado un
> tick real antes de esta prueba manual): el `jobId` del scheduler usaba `:`, que BullMQ
> rechaza, y `sync_events.sync_type` no incluía `'polling'` en su CHECK constraint — lo segundo
> **crasheaba todo el proceso de bot-miki** cuando un job de polling caía en dead-letter. Ambos
> arreglados (commit `57de5c0`) — si estás en un checkout más viejo, actualizá.

---

*Escrito 23-jul-2026, contra el estado del repo en el commit `e5ce1d9`; actualizado el mismo
día tras el recorrido en vivo (commits `4da435f`, `57de5c0`, Mailhog).*
