import Fastify from 'fastify';
import swagger from '@fastify/swagger';
import swaggerUi from '@fastify/swagger-ui';
import cors from '@fastify/cors';
import helmet from '@fastify/helmet';
import rateLimit from '@fastify/rate-limit';
import { Redis as IORedis } from 'ioredis';
import type { Queue } from 'bullmq';
import { config } from './config.js';
import { healthRoute } from './routes/health.js';
import { licenseRoute } from './routes/license.js';
import { syncReportRoute } from './routes/sync-report.js';
import { webhooksRoute } from './routes/webhooks.js';
import { adminRoute } from './routes/admin.js';
import { agencyRoute } from './routes/agency.js';
import { adminKeyMatches } from './infrastructure/admin-key.js';
import type { SyncJobData } from './workers/sync-worker.js';

export function buildApp(syncQueue: Queue<SyncJobData>) {
  const app = Fastify({
    logger: {
      level: config.LOG_LEVEL,
      transport: config.NODE_ENV === 'development'
        ? { target: 'pino-pretty' }
        : undefined,
    },
    ajv: {
      customOptions: {
        keywords: ['example'],   // permitir el campo example en schemas (usado por Swagger)
      },
    },
  });

  // #108: helmet (cabeceras de seguridad HSTS/X-Frame-Options/etc — CSP
  // desactivado a proposito, choca con los scripts inline de Swagger UI y
  // /docs ya esta protegido con X-Admin-Key mas abajo), CORS explicito
  // (sin clientes browser conocidos hoy — todo es server-to-server via el
  // plugin CMS o Bsale — asi que se deniega por defecto en vez de heredar
  // el comportamiento implicito de no tener el plugin registrado) y
  // rate-limit global sobre Redis (mismo REDIS_URL que BullMQ y el token
  // bucket de Bsale — correcto entre instancias si bot-miki escala, #113).
  app.register(helmet, { contentSecurityPolicy: false });
  app.register(cors, { origin: false });
  app.register(rateLimit, {
    global: true,
    max: 100,
    timeWindow: '1 minute',
    // Store en memoria para tests: todas las requests via `.inject()` comparten
    // la misma IP (127.0.0.1) — con Redis real, el conteo persistiria entre
    // corridas de test dentro de la misma ventana y podria disparar 429s
    // espurios. En dev/prod, Redis (mismo REDIS_URL que BullMQ).
    redis: config.NODE_ENV === 'test'
      ? undefined
      : new IORedis(config.REDIS_URL, { maxRetriesPerRequest: null }),
  });

  app.register(swagger, {
    openapi: {
      openapi: '3.1.0',
      info: {
        title: 'bot-miki API',
        description: 'Hub central de sincronizacion kpcrop — valida licencias, recibe webhooks de Bsale y registra eventos de sync.',
        version: '0.0.1',
      },
      servers: [
        { url: 'http://localhost:3000', description: 'Local' },
        { url: 'https://api.kpcrop.com', description: 'Produccion' },
      ],
      components: {
        securitySchemes: {
          apiKey: {
            type: 'apiKey',
            name: 'X-API-Key',
            in: 'header',
            description: 'API Key del tenant (formato: kp_...)',
          },
          adminKey: {
            type: 'apiKey',
            name: 'X-Admin-Key',
            in: 'header',
            description: 'Clave de administración interna — solo para endpoints /admin',
          },
        },
      },
      tags: [
        { name: 'sistema',   description: 'Health y estado del servicio' },
        { name: 'licencias', description: 'Validacion de licencias y JWT' },
        { name: 'webhooks',  description: 'Recepcion de eventos de Bsale' },
        { name: 'sync',      description: 'Reporte de sincronizaciones' },
        { name: 'admin',     description: 'Endpoints internos de administracion (requieren X-Admin-Key)' },
        { name: 'agency',    description: 'Panel self-service para agencias — gestion de sus propias tiendas (requieren X-API-Key)' },
      ],
    },
  });

  // #108: /docs documentaba TODOS los endpoints, incluidos /admin, sin auth —
  // cualquiera podia leer la superficie completa de la API. Mismo guard que
  // /admin/*: X-Admin-Key.
  app.addHook('onRequest', async (request, reply) => {
    if (request.url.startsWith('/docs') && !adminKeyMatches(request.headers['x-admin-key'])) {
      return reply.code(403).send({ code: 'FORBIDDEN', message: 'Admin key invalida o faltante' });
    }
  });

  app.register(swaggerUi, {
    routePrefix: '/docs',
    uiConfig: {
      docExpansion: 'list',
      deepLinking: true,
      tryItOutEnabled: true,
    },
    staticCSP: true,
  });

  app.register(healthRoute);
  app.register(licenseRoute,    { prefix: '/v1' });
  app.register(syncReportRoute, { prefix: '/v1' });
  app.register(webhooksRoute,   { prefix: '/v1', queue: syncQueue });
  app.register(adminRoute,      { prefix: '/v1', queue: syncQueue });
  app.register(agencyRoute,     { prefix: '/v1', queue: syncQueue });

  return app;
}
