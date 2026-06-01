# Definicion del MVP

El MVP es el minimo que le demuestra valor real a un primer cliente de pago. No es un prototipo — es un producto funcional con el subconjunto mas pequeño de features que justifica una suscripcion.

---

## Estado actual — 2026-05-31

> **MVP COMPLETADO** — El sync end-to-end esta validado en produccion.
> Primer tenant activo: `tienda-zollner-cl` (plan growth).

### Checklist de completion

#### Plugin PrestaShop (cms-prestashop)
- [x] Scaffold del modulo PHP (`synkrop/synkrop.php`) — install/uninstall, tab, SQL
- [x] `BsaleApiClient.php` — paginacion, rate limiting, manejo de errores
- [x] `LicenseClient.php` — obtiene y cachea JWT de bot-miki (sin tenantId — resuelve por API Key)
- [x] `SynkropService.php` — orquesta sync products/stock/prices, idempotente por SKU
- [x] Tests PHPUnit para BsaleApiClient y SynkropService
- [x] CLI `synkrop/cli/sync.php` para sync por linea de comandos / cron de PS
- [x] `AdminSynkropController.php` — panel completo con AJAX
- [x] Pantalla de configuracion inline: token Bsale + API Key con botones Verificar
- [x] Verificacion de conexion a Bsale (muestra email de la cuenta conectada)
- [x] Barra de progreso con contador de tiempo durante el sync
- [x] Resultado del sync: "N productos actualizados / X errores" con detalle por SKU
- [x] Log de los ultimos 20 syncs con colores de estado y modal de errores
- [x] `set_time_limit(0)` en sync para catalogos grandes sin timeout PHP
- [ ] Soporte verificado en PrestaShop 8.x (validado en 1.7.8.11)

#### bot-miki (servicio central)
- [x] Endpoint `GET /v1/license/token` — valida API Key, devuelve JWT. `tenantId` opcional.
- [x] Endpoint `POST /v1/sync/report` — recibe reporte del sync del plugin
- [x] Endpoint `POST /v1/admin/tenants` — crea tenant + licencia + genera API Key
- [x] Endpoint `POST /v1/webhooks/bsale` — recibe webhooks y encola jobs
- [x] Scheduler + Worker BullMQ
- [x] BsaleHttpClient con rate limiting + reintentos
- [x] Schema de BD completo (`licenses`, `tenant_stores`, `sync_events`, etc.)
- [x] CanonicalProduct model con Zod (`packages/shared`)
- [x] **Deployment en Railway** — corriendo desde 2026-05-30

#### Infraestructura
- [x] bot-miki en Railway — `https://kpcrop-latam-zollner-platform-production.up.railway.app`
- [x] PostgreSQL en Railway — provisionado y con migracion aplicada en startup
- [x] Redis en Railway — provisionado
- [x] SSL activo en URL de Railway
- [x] Primer tenant `tienda-zollner-cl` creado con plan growth
- [ ] Dominio `api.espaciobits.com` — DNS OK, SSL bloqueado en Railway (ver nota abajo)

> **Nota dominio:** `api.espaciobits.com` apunta a Railway via CNAME pero Let's Encrypt
> no puede emitir el cert porque `espaciobits.com` raiz apunta a otro hosting (GoDaddy).
> **Workaround activo:** usar la URL de Railway directamente. Cuando se compre un dominio
> dedicado (ej. `kpcrop.com`), el cambio es una linea en `daemon_api_url`.

---

## Criterio de MVP

> Un comercio chileno con PrestaShop y Bsale puede sincronizar sus productos manualmente con un clic desde el backoffice de PrestaShop, sin errores, con feedback visual del resultado.

**Validado el 2026-05-31:** sync de `ZAP.RUN.XL` desde sandbox Bsale → PrestaShop local exitoso end-to-end.

---

## Scope MVP — Lo que NO entra (diferido)

| Feature | Por que se difiere |
|---|---|
| Sync automatico / scheduler | Requiere validacion de mercado primero |
| Sync de clientes | Baja prioridad — los productos son el dolor principal |
| Sync de ordenes | Requiere escritura en Bsale — mas riesgo |
| Dashboard web para agencias | No hay agencias en MVP |
| Multi-CMS (WordPress, Shopify...) | PrestaShop primero |
| Dropshipping | Feature complejo — segunda fase |
| Alertas y observabilidad completa | En MVP: logs basicos en Railway |
| PrestaShop 8.x verificado | Probado en 1.7.8.11 — 8.x pendiente |

---

## Definition of Done del MVP

1. [x] Un comercio **instala el modulo** en su PrestaShop — ver `docs/deployment/plugin-install.md`
2. [x] El comercio **configura** Bsale API Token + API Key de licencia sin soporte tecnico
3. [x] El comercio hace clic en "Sincronizar" y **los productos de Bsale aparecen en PS**
4. [x] Si el sync falla, el comercio **ve un mensaje de error util** (no pantalla blanca)
5. [x] El comercio puede **repetir el sync** sin duplicados (idempotencia por SKU)
6. [x] `set_time_limit(0)` — sin timeout para catalogos grandes

---

## Metricas de Exito del MVP

| Metrica | Target | Estado |
|---|---|---|
| Tiempo de instalacion y configuracion | < 10 minutos | Pendiente medir con cliente real |
| Tasa de exito del sync (productos sin error) | > 95% | Pendiente medir con cliente real |
| Tiempo de sync para 1000 productos | < 5 minutos | Pendiente medir con cliente real |
| Crashes / errores criticos en primer mes | 0 | Monitoreo activo via Railway logs |
| NPS del primer cliente despues de 30 dias | > 8 | Pendiente — primer cliente por onboardear |

---

## Lo que Aprenderemos con el MVP

1. **¿El dolor es real?** ¿El comercio usa el sync o lo instala y olvida?
2. **¿Cuantos productos tiene un cliente tipico?** Afecta paginacion y tiempo de sync
3. **¿Donde falla la integracion Bsale?** Edge cases de variantes, listas de precios, sucursales
4. **¿Cuanto vale para el cliente?** Informa el modelo de precios
5. **¿Manual o automatico?** Si no usa manual, automatico tampoco servira
