import Fastify from 'fastify';
import { config } from './config.js';
import { healthRoute } from './routes/health.js';
import { licenseRoute } from './routes/license.js';
import { syncReportRoute } from './routes/sync-report.js';
import { webhooksRoute } from './routes/webhooks.js';

export function buildApp() {
  const app = Fastify({
    logger: {
      level: config.LOG_LEVEL,
      transport: config.NODE_ENV === 'development'
        ? { target: 'pino-pretty' }
        : undefined,
    },
  });

  app.register(healthRoute);
  app.register(licenseRoute,    { prefix: '/v1' });
  app.register(syncReportRoute, { prefix: '/v1' });
  app.register(webhooksRoute,   { prefix: '/v1' });

  return app;
}
