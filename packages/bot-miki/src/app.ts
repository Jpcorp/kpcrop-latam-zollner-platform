import Fastify from 'fastify';
import swagger from '@fastify/swagger';
import swaggerUi from '@fastify/swagger-ui';
import type { Queue } from 'bullmq';
import { config } from './config.js';
import { healthRoute } from './routes/health.js';
import { licenseRoute } from './routes/license.js';
import { syncReportRoute } from './routes/sync-report.js';
import { webhooksRoute } from './routes/webhooks.js';
import { adminRoute } from './routes/admin.js';
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
      ],
    },
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

  return app;
}
