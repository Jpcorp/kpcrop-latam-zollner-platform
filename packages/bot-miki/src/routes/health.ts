import type { FastifyInstance } from 'fastify';

export async function healthRoute(app: FastifyInstance) {
  app.get('/health', {
    schema: {
      tags: ['sistema'],
      summary: 'Health check',
      description: 'Verifica que el servicio esta corriendo correctamente.',
      response: {
        200: {
          type: 'object',
          properties: {
            status:  { type: 'string', example: 'ok' },
            version: { type: 'string', example: '0.0.1' },
            uptime:  { type: 'number', description: 'Segundos desde el arranque' },
          },
        },
      },
    },
  }, async () => ({
    status: 'ok',
    version: process.env.npm_package_version ?? '0.0.1',
    uptime: Math.floor(process.uptime()),
  }));
}
