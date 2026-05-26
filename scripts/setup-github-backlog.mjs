#!/usr/bin/env node
/**
 * setup-github-backlog.mjs
 *
 * Crea labels, milestones e issues del backlog en GitHub
 * para el proyecto kpcrop-latam-zollner-platform.
 *
 * Uso:
 *   node scripts/setup-github-backlog.mjs
 *
 * Requiere Node.js 18+ (fetch nativo).
 */

const TOKEN = process.env.GITHUB_TOKEN ?? '';
const OWNER = 'Jpcorp';
const REPO  = 'kpcrop-latam-zollner-platform';
const PROJECT_NUMBER = 3;

const BASE = 'https://api.github.com';
const headers = {
  Authorization: `token ${TOKEN}`,
  Accept: 'application/vnd.github.v3+json',
  'Content-Type': 'application/json',
  'User-Agent': 'kpcrop-backlog-setup',
};

// ──────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────
async function api(method, path, body) {
  const res = await fetch(`${BASE}${path}`, {
    method,
    headers,
    body: body ? JSON.stringify(body) : undefined,
  });
  const text = await res.text();
  const data = text ? JSON.parse(text) : {};
  if (!res.ok && res.status !== 422) {
    console.error(`  ✗ ${method} ${path} → ${res.status}`, data.message ?? '');
  }
  return { status: res.status, data };
}

function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

// ──────────────────────────────────────────────
// Labels
// ──────────────────────────────────────────────
const LABELS = [
  // Prioridad
  { name: 'P0: blocker',    color: 'B60205', description: 'Bloquea el avance del MVP' },
  { name: 'P1: high',       color: 'D93F0B', description: 'Alta prioridad' },
  { name: 'P2: medium',     color: 'E4E669', description: 'Prioridad media' },
  { name: 'P3: low',        color: '0E8A16', description: 'Baja prioridad / nice-to-have' },
  // Tipo
  { name: 'type: feature',  color: '1D76DB', description: 'Nueva funcionalidad' },
  { name: 'type: bug',      color: 'EE0701', description: 'Error o comportamiento incorrecto' },
  { name: 'type: security', color: '5319E7', description: 'Problema de seguridad' },
  { name: 'type: test',     color: '006B75', description: 'Tests / cobertura' },
  { name: 'type: devops',   color: 'FBCA04', description: 'CI/CD, infra, deployment' },
  { name: 'type: tech-debt',color: 'C5DEF5', description: 'Deuda técnica / refactor' },
  { name: 'type: docs',     color: 'BFD4F2', description: 'Documentación' },
  // Componente
  { name: 'comp: bot-miki',      color: '0075CA', description: 'Daemon central' },
  { name: 'comp: prestashop',    color: 'E99695', description: 'Plugin PrestaShop' },
  { name: 'comp: wordpress',     color: '21BBD0', description: 'Plugin WordPress/WooCommerce' },
  { name: 'comp: shopify',       color: '96E49A', description: 'App Shopify' },
  { name: 'comp: shared',        color: 'F9D0C4', description: 'Paquete compartido' },
  { name: 'comp: dropshipping',  color: 'D4C5F9', description: 'Módulo dropshipping' },
  // Fase
  { name: 'phase: 1-stabilize',  color: 'EDEDED', description: 'Fase 1 — Estabilizar MVP' },
  { name: 'phase: 2-plugins',    color: 'EDEDED', description: 'Fase 2 — Completar plugins' },
  { name: 'phase: 3-production', color: 'EDEDED', description: 'Fase 3 — Production-ready' },
  { name: 'phase: 4-advanced',   color: 'EDEDED', description: 'Fase 4 — Features avanzadas' },
  { name: 'phase: 5-ecosystem',  color: 'EDEDED', description: 'Fase 5 — Ecosistema' },
];

async function createLabels() {
  console.log('\n── Creando labels ──');
  for (const label of LABELS) {
    const { status, data } = await api('POST', `/repos/${OWNER}/${REPO}/labels`, label);
    if (status === 201) console.log(`  ✓ ${label.name}`);
    else if (status === 422) console.log(`  ~ ${label.name} (ya existe)`);
    await sleep(150);
  }
}

// ──────────────────────────────────────────────
// Milestones
// ──────────────────────────────────────────────
const MILESTONES = [
  { title: 'Fase 1 — Estabilizar MVP',       description: 'Seguridad, tests, features core incompletas', due_on: '2026-06-30T00:00:00Z' },
  { title: 'Fase 2 — Completar plugins',     description: 'WordPress, Shopify, multi-shop', due_on: '2026-07-31T00:00:00Z' },
  { title: 'Fase 3 — Production-ready',      description: 'Stripe, feature flags, E2E tests, monitoring', due_on: '2026-08-31T00:00:00Z' },
  { title: 'Fase 4 — Features avanzadas',    description: 'Dropshipping, order sync, custom field mapping', due_on: '2026-09-30T00:00:00Z' },
  { title: 'Fase 5 — Ecosistema',            description: 'WooCommerce, Magento, Jumpseller, portal dev', due_on: '2026-10-31T00:00:00Z' },
];

async function createMilestones() {
  console.log('\n── Creando milestones ──');
  const milestoneMap = {};
  for (const ms of MILESTONES) {
    const { status, data } = await api('POST', `/repos/${OWNER}/${REPO}/milestones`, ms);
    if (status === 201) {
      milestoneMap[ms.title] = data.number;
      console.log(`  ✓ ${ms.title} → #${data.number}`);
    } else if (status === 422) {
      // Ya existe — buscarla
      const { data: existing } = await api('GET', `/repos/${OWNER}/${REPO}/milestones?state=open&per_page=100`);
      const found = existing.find(m => m.title === ms.title);
      if (found) {
        milestoneMap[ms.title] = found.number;
        console.log(`  ~ ${ms.title} (ya existe → #${found.number})`);
      }
    }
    await sleep(200);
  }
  return milestoneMap;
}

// ──────────────────────────────────────────────
// Issues
// ──────────────────────────────────────────────
function issues(milestoneMap) {
  const ms = (title) => milestoneMap[title];

  return [
    // ════════════════════════════════════════
    // FASE 1 — ESTABILIZAR MVP
    // ════════════════════════════════════════
    {
      title: '[Security] Agregar middleware CORS a bot-miki',
      body: `## Problema
El servidor Fastify acepta requests de cualquier origen. Esto expone la API a llamadas no autorizadas desde el browser.

## Solución
- Instalar \`@fastify/cors\` (ya está en package.json)
- Configurar origenes permitidos desde variable de entorno \`ALLOWED_ORIGINS\`
- En desarrollo: \`*\`, en producción: solo dominios de clientes

## Criterio de aceptación
- [ ] CORS rechaza requests de origenes no autorizados en producción
- [ ] Variable \`ALLOWED_ORIGINS\` documentada en \`.env.example\`
- [ ] Test de integración verifica el rechazo`,
      labels: ['P0: blocker', 'type: security', 'comp: bot-miki', 'phase: 1-stabilize'],
      milestone: ms('Fase 1 — Estabilizar MVP'),
    },
    {
      title: '[Security] Validar firma HMAC en webhooks de Bsale',
      body: `## Problema
El endpoint \`POST /v1/webhooks\` acepta cualquier payload sin verificar que proviene de Bsale. Un atacante podría enviar eventos falsos y disparar sincronizaciones arbitrarias.

## Solución
- Leer header \`X-Bsale-Signature\` (o similar) de los webhooks de Bsale
- Verificar HMAC-SHA256 con el secreto configurado (\`WEBHOOK_SECRET\`)
- Rechazar con 401 si la firma no coincide

## Criterio de aceptación
- [ ] Middleware de verificación implementado
- [ ] Requests sin firma o con firma inválida retornan 401
- [ ] Variable \`WEBHOOK_SECRET\` en \`.env.example\`
- [ ] Test unitario del middleware`,
      labels: ['P0: blocker', 'type: security', 'comp: bot-miki', 'phase: 1-stabilize'],
      milestone: ms('Fase 1 — Estabilizar MVP'),
    },
    {
      title: '[Security] Hashear API keys en la tabla de licencias',
      body: `## Problema
Las API keys de Bsale se almacenan en texto plano en la tabla \`licenses\` de PostgreSQL y en la tabla de configuración de PrestaShop. Si la DB es comprometida, las credenciales quedan expuestas.

## Solución
- Usar bcrypt o AES-256-GCM para cifrar el campo \`bsale_api_key\` antes de guardar
- Descifrar al momento de uso
- Migración para cifrar los valores existentes

## Criterio de aceptación
- [ ] API keys cifradas en reposo en PostgreSQL
- [ ] API keys cifradas en config table de PrestaShop con clave dedicada
- [ ] Migración \`003_encrypt_api_keys.sql\` creada
- [ ] No se logean API keys en ningún nivel`,
      labels: ['P0: blocker', 'type: security', 'comp: bot-miki', 'comp: prestashop', 'phase: 1-stabilize'],
      milestone: ms('Fase 1 — Estabilizar MVP'),
    },
    {
      title: '[Security] Rate limiting global y por tenant en bot-miki',
      body: `## Problema
No hay middleware de rate limiting. Un cliente (o atacante) puede enviar miles de requests por segundo y saturar el servidor o la cola de BullMQ.

## Solución
- Instalar \`@fastify/rate-limit\`
- Rate limit global: 100 req/min por IP
- Rate limit por tenant (JWT sub): 30 sync requests/min
- Retornar 429 con header \`Retry-After\`

## Criterio de aceptación
- [ ] Rate limiting global activo
- [ ] Rate limiting por tenant activo
- [ ] Headers \`X-RateLimit-*\` presentes en respuestas
- [ ] Test que verifica el 429`,
      labels: ['P0: blocker', 'type: security', 'comp: bot-miki', 'phase: 1-stabilize'],
      milestone: ms('Fase 1 — Estabilizar MVP'),
    },
    {
      title: '[Feature] Implementar webhook worker completo',
      body: `## Problema
El endpoint \`POST /v1/webhooks\` encola el job pero el worker **no implementa** la traducción del payload a \`CanonicalProduct\` ni la sincronización al CMS.

El código actual tiene el TODO:
\`\`\`ts
// TODO: traducir resource a CanonicalProduct y sincronizar al CMS
\`\`\`

## Solución
Crear \`src/workers/webhook-worker.ts\` que:
1. Recibe el job de BullMQ con el payload de Bsale
2. Llama a la API de Bsale para obtener el producto completo
3. Transforma a \`CanonicalProduct\` (usando shared)
4. Identifica el CMS de destino por \`tenantId\`
5. Llama al adaptador CMS correspondiente
6. Registra el resultado en \`sync_events\`

## Criterio de aceptación
- [ ] Worker procesa jobs de tipo \`webhook\`
- [ ] Producto se sincroniza al CMS correctamente
- [ ] Errores 4xx de Bsale se descartan (no reintentan)
- [ ] Errores 5xx de Bsale reintentam con backoff
- [ ] Test de integración end-to-end`,
      labels: ['P0: blocker', 'type: feature', 'comp: bot-miki', 'phase: 1-stabilize'],
      milestone: ms('Fase 1 — Estabilizar MVP'),
    },
    {
      title: '[Test] Crear suite de tests para bot-miki con vitest',
      body: `## Problema
No existe ningún test en el proyecto. El CI solo corre lint y build. Esto bloquea refactors seguros y genera riesgo en producción.

## Solución
Configurar vitest y escribir tests mínimos:

### Tests unitarios
- [ ] \`license.test.ts\` — generación y validación de JWT
- [ ] \`scheduler.test.ts\` — evaluación de expresiones cron
- [ ] \`sync-worker.test.ts\` — manejo de errores 4xx vs 5xx

### Tests de integración (con DB y Redis reales via docker)
- [ ] \`license-route.test.ts\` — flujo completo de token
- [ ] \`webhook-route.test.ts\` — encolado de jobs
- [ ] \`sync-worker.integration.test.ts\` — procesamiento completo

## Criterio de aceptación
- [ ] \`pnpm test\` corre la suite completa
- [ ] Cobertura ≥ 70% en bot-miki
- [ ] CI ejecuta tests en cada PR`,
      labels: ['P0: blocker', 'type: test', 'comp: bot-miki', 'phase: 1-stabilize'],
      milestone: ms('Fase 1 — Estabilizar MVP'),
    },
    {
      title: '[Feature] Implementar syncPrices() en plugin PrestaShop',
      body: `## Problema
El método \`syncPrices()\` en \`BsaleSyncService.php\` existe pero su cuerpo está **vacío**. Los precios no se actualizan durante la sincronización.

## Solución
\`\`\`php
private function syncPrices(): void {
    // 1. Obtener lista de precios de Bsale (price list configurada)
    // 2. Para cada producto en PrestaShop mapeado
    //    a. Buscar precio en Bsale por SKU
    //    b. Considerar impuestos (IVA)
    //    c. Actualizar price en ps_product / ps_product_attribute
    // 3. Registrar resultado
}
\`\`\`

## Criterio de aceptación
- [ ] Precios se actualizan correctamente en PrestaShop
- [ ] Manejo de listas de precio configurables (price list ID en config)
- [ ] IVA aplicado correctamente según config de impuestos de PS
- [ ] Precio especial / precio tachado si hay descuento en Bsale
- [ ] Test con fixtures de respuesta de Bsale`,
      labels: ['P0: blocker', 'type: feature', 'comp: prestashop', 'phase: 1-stabilize'],
      milestone: ms('Fase 1 — Estabilizar MVP'),
    },
    {
      title: '[Feature] Implementar sync de imágenes en PrestaShop',
      body: `## Problema
El formulario de configuración tiene el toggle \`sync_images\` pero el código **nunca descarga ni importa imágenes**. La tabla \`bsalesync_images\` está definida pero vacía.

## Solución
- Descargar imagen desde URL de Bsale
- Guardar en directorio de imágenes de PrestaShop
- Asociar al producto/combinación correspondiente
- Cachear en \`bsalesync_images\` para evitar re-descargas

## Criterio de aceptación
- [ ] Imágenes se descargan y asocian al producto
- [ ] Fallos de descarga no bloquean el sync del producto
- [ ] Imágenes ya sincronizadas no se re-descargan si el hash no cambió
- [ ] Toggle \`sync_images\` respetado`,
      labels: ['P1: high', 'type: feature', 'comp: prestashop', 'phase: 1-stabilize'],
      milestone: ms('Fase 1 — Estabilizar MVP'),
    },
    {
      title: '[DevOps] Integrar runner de migraciones en bot-miki',
      body: `## Problema
Las migraciones SQL deben correrse manualmente con psql. No hay automatización en el deploy ni en los tests.

## Solución
- Integrar \`node-pg-migrate\` o script propio que corra migraciones al iniciar
- En tests: correr migraciones antes del suite
- En CI: correr migraciones en el paso de test
- En Docker: comando de entrada corre migraciones antes de levantar el servidor

## Criterio de aceptación
- [ ] \`pnpm db:migrate\` aplica migraciones pendientes
- [ ] \`pnpm db:rollback\` revierte la última migración
- [ ] Docker Compose corre migraciones automáticamente al iniciar`,
      labels: ['P1: high', 'type: devops', 'comp: bot-miki', 'phase: 1-stabilize'],
      milestone: ms('Fase 1 — Estabilizar MVP'),
    },
    {
      title: '[DevOps] Habilitar tests y cobertura en CI',
      body: `## Problema
El workflow de CI corre lint y build pero **no ejecuta tests**. Los tests de PHP están marcados con \`|| true\` (fallos ignorados).

## Cambios necesarios
1. Agregar step \`pnpm test\` en \`.github/workflows/ci.yml\`
2. Configurar servicio de cobertura (Codecov o similar)
3. Remover \`|| true\` de tests PHP
4. Agregar step de migración antes de los tests de integración

## Criterio de aceptación
- [ ] CI falla si algún test falla
- [ ] Reporte de cobertura publicado en cada PR
- [ ] Badge de cobertura en README`,
      labels: ['P1: high', 'type: devops', 'phase: 1-stabilize'],
      milestone: ms('Fase 1 — Estabilizar MVP'),
    },
    {
      title: '[Feature] Alertas a Slack desde dead-letter queue',
      body: `## Problema
Cuando un job falla 5 veces y va a la dead-letter queue, el evento se registra en la tabla pero **no hay alerta activa**. El equipo no se entera de fallos silenciosos.

## Solución
- Webhook de Slack configurado via variable de entorno \`SLACK_WEBHOOK_URL\`
- Alerta cuando un job entra al dead-letter queue con: tenant, tipo de error, payload resumido
- Alerta cuando la tasa de error supera 5% en 5 minutos

## Criterio de aceptación
- [ ] Mensaje de Slack recibido al superar el threshold de errores
- [ ] Formato del mensaje incluye link al job en BullMQ dashboard (si aplica)
- [ ] Variable \`SLACK_WEBHOOK_URL\` documentada en \`.env.example\``,
      labels: ['P1: high', 'type: feature', 'comp: bot-miki', 'phase: 1-stabilize'],
      milestone: ms('Fase 1 — Estabilizar MVP'),
    },
    {
      title: '[Tech Debt] Agregar logging estructurado con correlation IDs',
      body: `## Problema
El logging actual con Pino no incluye correlation IDs, lo que hace imposible trazar un request a través de la cola → worker → CMS.

## Solución
- Generar \`correlationId\` (UUID) en cada request entrante
- Propagarlo al job de BullMQ como metadata
- Incluirlo en todos los logs del worker
- Agregar \`requestId\` en respuestas HTTP

## Criterio de aceptación
- [ ] Cada log tiene \`correlationId\`, \`tenantId\`, \`timestamp\`
- [ ] Posible rastrear un request de punta a punta por su correlationId
- [ ] Logs en formato JSON estructurado (listo para Grafana/Axiom)`,
      labels: ['P1: high', 'type: tech-debt', 'comp: bot-miki', 'phase: 1-stabilize'],
      milestone: ms('Fase 1 — Estabilizar MVP'),
    },
    {
      title: '[Tech Debt] Corregir riesgos de SQL injection en PrestaShop',
      body: `## Problema
El plugin de PrestaShop mezcla uso inconsistente de \`pSQL()\` con concatenación directa de variables en queries SQL.

## Solución
- Auditar todas las queries en \`BsaleSyncService.php\` e \`install.php\`
- Reemplazar concatenación directa con \`pSQL()\` o queries preparadas
- Usar \`(int)\` para IDs numéricos

## Criterio de aceptación
- [ ] Ninguna variable de usuario concatenada directamente en SQL
- [ ] Revisión de seguridad documentada en PR`,
      labels: ['P0: blocker', 'type: security', 'type: tech-debt', 'comp: prestashop', 'phase: 1-stabilize'],
      milestone: ms('Fase 1 — Estabilizar MVP'),
    },

    // ════════════════════════════════════════
    // FASE 2 — COMPLETAR PLUGINS
    // ════════════════════════════════════════
    {
      title: '[Feature] Implementar plugin WordPress/WooCommerce',
      body: `## Descripción
El directorio \`packages/cms-wordpress/\` existe pero está **completamente vacío**. Implementar el plugin completo.

## Alcance
- [ ] Scaffold del plugin WordPress (header comments, activación/desactivación)
- [ ] Página de configuración en admin (Bsale token, API key, office, price list)
- [ ] Validación de licencia contra bot-miki
- [ ] Adapter WooCommerce implementando la interfaz \`CmsAdapter\`
  - [ ] \`syncProduct()\` — crear/actualizar producto en WooCommerce
  - [ ] \`syncStock()\` — actualizar stock de variante
  - [ ] \`syncPrice()\` — actualizar precio base y precio especial
  - [ ] \`syncImages()\` — importar imágenes desde URL
- [ ] Botón de sync manual en admin
- [ ] Log de sincronización visible en admin
- [ ] Tablas de BD: config, product_map, sync_log
- [ ] Tests PHPUnit básicos

## Criterio de aceptación
- [ ] Plugin activable en WordPress 6.x + WooCommerce 8.x
- [ ] Sync manual funciona con cuenta real de Bsale Sandbox`,
      labels: ['P0: blocker', 'type: feature', 'comp: wordpress', 'phase: 2-plugins'],
      milestone: ms('Fase 2 — Completar plugins'),
    },
    {
      title: '[Feature] Implementar app Shopify',
      body: `## Descripción
El directorio \`packages/cms-shopify/\` existe pero está **completamente vacío**. Implementar la app Shopify.

## Alcance
- [ ] Scaffold app Shopify (Remix + Shopify CLI)
- [ ] Instalación OAuth + sesión persistente
- [ ] Página de configuración admin (Bsale token, API key, etc.)
- [ ] Validación de licencia
- [ ] Adapter Shopify:
  - [ ] Sync productos y variantes (Admin API REST)
  - [ ] Sync stock por location
  - [ ] Sync precios
  - [ ] Sync imágenes
- [ ] Webhook receiver para actualizaciones desde Bsale
- [ ] UI de sincronización manual (Polaris)
- [ ] Tests básicos

## Criterio de aceptación
- [ ] App instalable en tienda Shopify de desarrollo
- [ ] Sync manual funciona con cuenta Bsale Sandbox`,
      labels: ['P0: blocker', 'type: feature', 'comp: shopify', 'phase: 2-plugins'],
      milestone: ms('Fase 2 — Completar plugins'),
    },
    {
      title: '[Feature] Soporte multi-tienda en plugin PrestaShop',
      body: `## Problema
El plugin asume siempre una sola tienda (\`$this->context->shop->id\`). La tabla tiene columna \`id_shop\` pero el código nunca la usa.

## Solución
- Detectar modo multishop activo
- Guardar configuración por tienda
- Filtrar mapeos de producto por \`id_shop\`
- UI en admin permite seleccionar a qué tiendas aplica la sync

## Criterio de aceptación
- [ ] Cada tienda puede tener su propia config de Bsale
- [ ] Sync solo afecta la tienda configurada
- [ ] Instalación/desinstalación maneja el contexto multishop`,
      labels: ['P1: high', 'type: feature', 'comp: prestashop', 'phase: 2-plugins'],
      milestone: ms('Fase 2 — Completar plugins'),
    },
    {
      title: '[Feature] UI de logs de sincronización en admin PrestaShop',
      body: `## Problema
La tabla \`bsalesync_log\` existe y se escribe, pero no hay ninguna vista en el admin de PrestaShop para ver el historial de sincronizaciones, errores y estado.

## Solución
- Nueva tab en el módulo: "Historial de sincronizaciones"
- Grid con: fecha, tipo (product/stock/price/image), SKU, estado, mensaje de error
- Filtros por fecha, estado, SKU
- Botón de reintento por ítem fallido

## Criterio de aceptación
- [ ] Visible en backoffice PrestaShop
- [ ] Paginación para logs grandes
- [ ] Exportación a CSV`,
      labels: ['P2: medium', 'type: feature', 'comp: prestashop', 'phase: 2-plugins'],
      milestone: ms('Fase 2 — Completar plugins'),
    },

    // ════════════════════════════════════════
    // FASE 3 — PRODUCTION-READY
    // ════════════════════════════════════════
    {
      title: '[Feature] Integración completa con Stripe (suscripciones)',
      body: `## Problema
Las variables de entorno \`STRIPE_SECRET_KEY\` y \`STRIPE_WEBHOOK_SECRET\` existen en la config pero **no hay ningún handler** para eventos de Stripe. El sistema de licencias no reacciona a pagos.

## Solución
Implementar handler en \`src/routes/stripe-webhooks.ts\`:
- \`customer.subscription.created\` → activar licencia
- \`customer.subscription.updated\` → actualizar plan
- \`customer.subscription.deleted\` → suspender licencia
- \`invoice.payment_failed\` → período de gracia (7 días), luego suspender

## Criterio de aceptación
- [ ] Licencia se activa automáticamente tras pago exitoso
- [ ] Licencia se suspende tras fallo de pago (con período de gracia)
- [ ] Firma de webhook verificada con \`STRIPE_WEBHOOK_SECRET\`
- [ ] Tests con Stripe CLI para simular eventos`,
      labels: ['P0: blocker', 'type: feature', 'comp: bot-miki', 'phase: 3-production'],
      milestone: ms('Fase 3 — Production-ready'),
    },
    {
      title: '[Feature] Implementar validación de feature flags en licencias',
      body: `## Problema
El campo \`features\` JSONB en la tabla \`licenses\` existe con flags como \`sync_auto\`, \`dropshipping\`, \`multi_store\`, pero **nunca se consultan en el código**. Cualquier cliente puede usar cualquier feature sin importar su plan.

## Solución
- Middleware \`requireFeature(flag: string)\` en bot-miki
- Verificar features antes de encolar jobs de sync automático
- Verificar \`multi_store\` antes de aceptar múltiples tiendas
- Retornar 403 con mensaje claro si el plan no incluye la feature

## Criterio de aceptación
- [ ] Clientes en plan starter no pueden usar \`sync_auto\`
- [ ] Respuesta 403 incluye qué plan se necesita
- [ ] Tests para cada feature flag`,
      labels: ['P0: blocker', 'type: feature', 'comp: bot-miki', 'phase: 3-production'],
      milestone: ms('Fase 3 — Production-ready'),
    },
    {
      title: '[Test] Tests E2E del flujo completo de sincronización',
      body: `## Descripción
No existen tests que cubran el flujo completo: Bsale → webhook → queue → worker → CMS.

## Alcance
- [ ] Setup de entorno E2E con Docker Compose (postgres, redis, mock-bsale-api, mock-cms)
- [ ] Test: webhook recibido → job procesado → producto actualizado en CMS
- [ ] Test: error de Bsale 5xx → reintento → éxito en segundo intento
- [ ] Test: licencia inválida → sync rechazada
- [ ] Test: job en dead-letter → alerta enviada
- [ ] Test: sync de imágenes con CDN fallando

## Herramientas sugeridas
- Playwright o supertest para HTTP
- testcontainers para PostgreSQL y Redis
- msw o nock para mock de APIs externas`,
      labels: ['P0: blocker', 'type: test', 'phase: 3-production'],
      milestone: ms('Fase 3 — Production-ready'),
    },
    {
      title: '[Feature] Dashboard de administración de licencias',
      body: `## Descripción
No existe una UI para gestionar tenants, licencias y ver el estado de las sincronizaciones.

## Alcance
- [ ] Vista de todos los tenants activos y sus planes
- [ ] Ver/editar fechas de expiración y features habilitadas
- [ ] Historial de sync_events por tenant
- [ ] Vista de dead-letter queue con opción de reintento
- [ ] Métricas: tasa de éxito, latencia promedio, eventos por día

## Tech sugerido
- Next.js o simple HTML+htmx servido desde bot-miki
- Auth básica o magic link para admin`,
      labels: ['P1: high', 'type: feature', 'comp: bot-miki', 'phase: 3-production'],
      milestone: ms('Fase 3 — Production-ready'),
    },
    {
      title: '[DevOps] Configurar APM y métricas (Pino + Prometheus)',
      body: `## Problema
No hay observabilidad en producción. No es posible saber la latencia de sync, tasa de errores, o estado de la cola en tiempo real.

## Solución
- Exportar métricas Prometheus desde Fastify (\`fastify-metrics\`)
- Instrumentar: duración de jobs, tasa de éxito/error, tamaño de cola
- Enviar logs estructurados a Axiom o Grafana Loki
- Dashboard Grafana básico para bot-miki

## Criterio de aceptación
- [ ] Endpoint \`/metrics\` disponible (scrapeado por Prometheus)
- [ ] Dashboard básico funcionando con datos reales`,
      labels: ['P1: high', 'type: devops', 'comp: bot-miki', 'phase: 3-production'],
      milestone: ms('Fase 3 — Production-ready'),
    },
    {
      title: '[Feature] Optimización bulk: batch upserts en PrestaShop',
      body: `## Problema
El plugin hace una query SQL por cada producto/variante. Con catálogos grandes (1000+ productos) esto genera cientos de queries individuales y la sync tarda minutos.

## Solución
- Agrupar inserts/updates en batches de 100
- Usar \`INSERT ... ON DUPLICATE KEY UPDATE\` multi-row
- Implementar transacción por batch (rollback parcial si falla)

## Criterio de aceptación
- [ ] Sync de 1000 productos en < 30 segundos
- [ ] Benchmark documentado antes/después`,
      labels: ['P2: medium', 'type: tech-debt', 'comp: prestashop', 'phase: 3-production'],
      milestone: ms('Fase 3 — Production-ready'),
    },

    // ════════════════════════════════════════
    // FASE 4 — FEATURES AVANZADAS
    // ════════════════════════════════════════
    {
      title: '[Feature] Implementar módulo dropshipping (servi-dropi)',
      body: `## Descripción
El paquete \`packages/app-servi-dropi/\` está mencionado en docs pero **no existe**. Este módulo permite que un tenant exponga su catálogo de Bsale a distribuidores.

## Alcance
- [ ] Scaffold del servicio
- [ ] API de exposición de catálogo (con autenticación por API key de distribuidor)
- [ ] Sincronización de stock/precio hacia clientes suscritos
- [ ] Registro de distribuidores (tenant-to-tenant)
- [ ] Webhooks para notificar cambios a clientes

## Dependencias
- Completar implementación de bot-miki (Fase 1)
- Feature flag \`dropshipping\` en licencias`,
      labels: ['P1: high', 'type: feature', 'comp: dropshipping', 'phase: 4-advanced'],
      milestone: ms('Fase 4 — Features avanzadas'),
    },
    {
      title: '[Feature] Implementar módulo dropshipping (client-dropi)',
      body: `## Descripción
El paquete \`packages/app-client-dropi/\` no existe. Este módulo permite a un distribuidor recibir catálogos de proveedores y sincronizarlos a su CMS.

## Alcance
- [ ] Scaffold del cliente dropshipping
- [ ] Registro de proveedores (con API key)
- [ ] Polling o webhook para recibir actualizaciones de catálogo
- [ ] Mapeo proveedor-SKU → CMS-SKU
- [ ] Reglas de margen (markup % configurable)
- [ ] Adapter hacia CMS destino (PrestaShop/WordPress/Shopify)`,
      labels: ['P1: high', 'type: feature', 'comp: dropshipping', 'phase: 4-advanced'],
      milestone: ms('Fase 4 — Features avanzadas'),
    },
    {
      title: '[Feature] Sync de pedidos Bsale → CMS (dropshipping fase 2)',
      body: `## Descripción
En el flujo de dropshipping, cuando el distribuidor vende un producto, el pedido debe crearse automáticamente en Bsale del proveedor.

## Alcance
- [ ] Leer pedidos desde CMS del distribuidor (webhook o polling)
- [ ] Crear documento de venta en Bsale del proveedor
- [ ] Sincronizar estado del pedido de vuelta al distribuidor
- [ ] Manejar casos de stock insuficiente

## Dependencias
- app-servi-dropi implementado
- app-client-dropi implementado`,
      labels: ['P2: medium', 'type: feature', 'comp: dropshipping', 'phase: 4-advanced'],
      milestone: ms('Fase 4 — Features avanzadas'),
    },
    {
      title: '[Feature] Mapeo de campos personalizado (custom field mapping)',
      body: `## Descripción
Actualmente los campos de Bsale se mapean a campos fijos del CMS. Algunos clientes necesitan mapear campos custom de Bsale a atributos custom del CMS.

## Alcance
- [ ] Interfaz de configuración: campo Bsale → campo CMS
- [ ] Soporte en PrestaShop (feature_value, custom attributes)
- [ ] Soporte en WooCommerce (product_meta)
- [ ] Pre/post hooks para transformaciones custom
- [ ] Documentación para desarrolladores

## Criterio de aceptación
- [ ] Admin puede definir al menos 10 mapeos custom
- [ ] Mapeos persisten entre sincronizaciones`,
      labels: ['P2: medium', 'type: feature', 'phase: 4-advanced'],
      milestone: ms('Fase 4 — Features avanzadas'),
    },

    // ════════════════════════════════════════
    // FASE 5 — ECOSISTEMA
    // ════════════════════════════════════════
    {
      title: '[Feature] Plugin WooCommerce standalone',
      body: `## Descripción
Crear \`packages/cms-woocommerce/\` como plugin independiente de WordPress, enfocado específicamente en WooCommerce (sin necesitar el plugin genérico de WordPress).

## Alcance
Similar al plugin WordPress pero optimizado para WooCommerce:
- [ ] Sync de variaciones con atributos de WC
- [ ] Soporte para productos simples y variables
- [ ] Sync de categorías desde Bsale
- [ ] Stock por ubicación (si aplica)
- [ ] Compatible con HPOS (High-Performance Order Storage)`,
      labels: ['P2: medium', 'type: feature', 'phase: 5-ecosystem'],
      milestone: ms('Fase 5 — Ecosistema'),
    },
    {
      title: '[Feature] Plugin Magento 2',
      body: `## Descripción
Crear \`packages/cms-magento/\` para integración con Magento 2.

## Alcance
- [ ] Módulo Magento 2 (composer.json, registration.php)
- [ ] Adapter implementando CmsAdapter
- [ ] Sync de productos (simple y configurable)
- [ ] Sync de stock (MSI multi-source)
- [ ] Sync de precios (tier prices)
- [ ] Cron job de Magento para sync periódico`,
      labels: ['P3: low', 'type: feature', 'phase: 5-ecosystem'],
      milestone: ms('Fase 5 — Ecosistema'),
    },
    {
      title: '[Feature] Plugin Jumpseller',
      body: `## Descripción
Crear \`packages/cms-jumpseller/\` para integración con Jumpseller (plataforma popular en LATAM).

## Alcance
- [ ] App Jumpseller (OAuth + API REST)
- [ ] Adapter implementando CmsAdapter
- [ ] Sync de productos y variantes
- [ ] Sync de stock
- [ ] Sync de precios
- [ ] Webhook receiver`,
      labels: ['P3: low', 'type: feature', 'phase: 5-ecosystem'],
      milestone: ms('Fase 5 — Ecosistema'),
    },
    {
      title: '[Docs] Portal de desarrolladores y SDK de adaptadores',
      body: `## Descripción
No existe documentación para que terceros puedan crear adaptadores CMS nuevos.

## Alcance
- [ ] Guía "Cómo crear un nuevo adaptador CMS"
- [ ] Documentación de la interfaz \`CmsAdapter\` con ejemplos
- [ ] Documentación del modelo \`CanonicalProduct\` con todos los campos
- [ ] OpenAPI spec completa de bot-miki (\`/docs/api-contracts/\`)
- [ ] Guía de entorno de desarrollo (setup en < 15 minutos)
- [ ] Playground/sandbox para testear adaptadores`,
      labels: ['P2: medium', 'type: docs', 'phase: 5-ecosystem'],
      milestone: ms('Fase 5 — Ecosistema'),
    },
    {
      title: '[Docs] Wiki del proyecto: arquitectura y onboarding',
      body: `## Descripción
Poblar la wiki de GitHub (\`https://github.com/Jpcorp/kpcrop-latam-zollner-platform/wiki\`) con documentación navegable.

## Páginas propuestas
- [ ] **Home** — Descripción general, links rápidos
- [ ] **Arquitectura** — Diagrama hub-and-spoke, flujo de datos
- [ ] **Setup local** — Requisitos, pasos de instalación, primer sync
- [ ] **bot-miki** — API endpoints, queue, scheduler
- [ ] **Plugin PrestaShop** — Instalación, configuración, troubleshooting
- [ ] **Plugin WordPress/WooCommerce** — Igual
- [ ] **App Shopify** — Igual
- [ ] **Sistema de licencias** — Planes, features, Stripe
- [ ] **Dropshipping** — Flujo, configuración
- [ ] **Troubleshooting** — Errores comunes y soluciones
- [ ] **Contribuir** — Convenciones de código, PR guidelines`,
      labels: ['P1: high', 'type: docs', 'phase: 5-ecosystem'],
      milestone: ms('Fase 5 — Ecosistema'),
    },
  ];
}

// ──────────────────────────────────────────────
// Crear issues
// ──────────────────────────────────────────────
async function createIssues(milestoneMap) {
  console.log('\n── Creando issues ──');
  const allIssues = issues(milestoneMap);
  const created = [];

  for (const issue of allIssues) {
    const body = {
      title: issue.title,
      body: issue.body,
      labels: issue.labels,
      milestone: issue.milestone,
    };

    const { status, data } = await api('POST', `/repos/${OWNER}/${REPO}/issues`, body);
    if (status === 201) {
      console.log(`  ✓ #${data.number} ${issue.title}`);
      created.push({ number: data.number, node_id: data.node_id, title: issue.title });
    } else {
      console.log(`  ✗ ${issue.title} → ${status}`);
    }
    await sleep(400); // Respetar rate limit de GitHub
  }

  return created;
}

// ──────────────────────────────────────────────
// Agregar issues al project board (GraphQL)
// ──────────────────────────────────────────────
async function getProjectNodeId() {
  const query = `
    query {
      user(login: "${OWNER}") {
        projectV2(number: ${PROJECT_NUMBER}) {
          id
          title
        }
      }
    }
  `;
  const res = await fetch('https://api.github.com/graphql', {
    method: 'POST',
    headers: { ...headers, Accept: 'application/vnd.github.v3+json' },
    body: JSON.stringify({ query }),
  });
  const data = await res.json();
  return data?.data?.user?.projectV2;
}

async function addIssueToProject(projectId, issueNodeId) {
  const mutation = `
    mutation {
      addProjectV2ItemById(input: { projectId: "${projectId}", contentId: "${issueNodeId}" }) {
        item { id }
      }
    }
  `;
  const res = await fetch('https://api.github.com/graphql', {
    method: 'POST',
    headers: { ...headers, Accept: 'application/vnd.github.v3+json' },
    body: JSON.stringify({ query: mutation }),
  });
  return res.json();
}

async function addIssuesToProject(createdIssues) {
  console.log('\n── Vinculando issues al project board ──');

  const project = await getProjectNodeId();
  if (!project) {
    console.log('  ✗ No se encontró el proyecto #3. Verifica que el token tenga permisos de project.');
    return;
  }
  console.log(`  Proyecto: "${project.title}" (${project.id})`);

  for (const issue of createdIssues) {
    const result = await addIssueToProject(project.id, issue.node_id);
    if (result?.data?.addProjectV2ItemById?.item) {
      console.log(`  ✓ #${issue.number} agregado al board`);
    } else {
      console.log(`  ✗ #${issue.number} falló:`, JSON.stringify(result?.errors?.[0]?.message ?? result));
    }
    await sleep(300);
  }
}

// ──────────────────────────────────────────────
// Main
// ──────────────────────────────────────────────
async function main() {
  console.log('🚀 Setup de backlog para kpcrop-latam-zollner-platform');
  console.log(`   Repo: ${OWNER}/${REPO}`);
  console.log(`   Project: #${PROJECT_NUMBER}\n`);

  await createLabels();
  const milestoneMap = await createMilestones();
  const createdIssues = await createIssues(milestoneMap);
  await addIssuesToProject(createdIssues);

  console.log(`\n✅ Listo. ${createdIssues.length} issues creados.`);
  console.log(`   Issues: https://github.com/${OWNER}/${REPO}/issues`);
  console.log(`   Project: https://github.com/users/${OWNER}/projects/${PROJECT_NUMBER}`);
}

main().catch(console.error);
