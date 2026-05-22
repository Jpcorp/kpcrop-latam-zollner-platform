import type { FastifyInstance } from 'fastify';

export async function healthRoute(app: FastifyInstance) {
  app.get('/health', async () => ({
    status: 'ok',
    version: process.env.npm_package_version ?? '0.0.1',
    uptime: Math.floor(process.uptime()),
  }));
}
