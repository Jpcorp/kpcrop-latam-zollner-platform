import type { FastifyInstance } from 'fastify';
import { db } from '../infrastructure/database.js';
import { signLicenseJwt } from '../domain/license.js';

export async function licenseRoute(app: FastifyInstance) {
  app.get<{ Querystring: { tenantId?: string } }>(
    '/license/token',
    {
      schema: {
        tags: ['licencias'],
        summary: 'Obtener JWT de licencia',
        description: 'Valida la API Key del tenant y devuelve un JWT firmado (TTL 5 min). tenantId es opcional — si se omite, se resuelve desde la API Key. La respuesta lleva Cache-Control para que Cloudflare la cachee 4 min en el edge.',
        security: [{ apiKey: [] }],
        querystring: {
          type: 'object',
          required: [],
          properties: {
            tenantId: { type: 'string', description: 'ID unico del tenant (opcional)', example: 'dev-tenant-001' },
          },
        },
        response: {
          200: {
            type: 'object',
            properties: {
              token:     { type: 'string', description: 'JWT firmado con HS256' },
              expiresAt: { type: 'string', format: 'date-time' },
              plan:      { type: 'string', enum: ['starter', 'growth', 'agency'] },
              features:  { type: 'array', items: { type: 'string' } },
              maxStores: { type: 'number' },
            },
          },
          401: { type: 'object', properties: { code: { type: 'string' }, message: { type: 'string' } } },
          402: { type: 'object', properties: { code: { type: 'string' }, message: { type: 'string' } } },
        },
      },
    },
    async (request, reply) => {
      const { tenantId } = request.query;
      const apiKey = request.headers['x-api-key'] as string | undefined;

      if (!apiKey) {
        return reply.code(401).send({ code: 'MISSING_API_KEY', message: 'Header X-API-Key requerido' });
      }

      let query = db.selectFrom('licenses').selectAll().where('api_key', '=', apiKey);
      if (tenantId) {
        query = query.where('tenant_id', '=', tenantId);
      }
      const license = await query.executeTakeFirst();

      if (!license) {
        return reply.code(404).send({ code: 'TENANT_NOT_FOUND', message: 'Tenant no encontrado' });
      }

      if (license.status !== 'active') {
        return reply.code(402).send({
          code: 'LICENSE_EXPIRED',
          message: `Licencia ${license.status}. Renueva en kpcrop.com/billing`,
        });
      }

      const token = signLicenseJwt({
        tenantId: license.tenant_id,
        plan: license.plan,
        features: license.features as string[],
        maxStores: license.max_stores,
      });

      return reply
        .header('Cache-Control', 'private, no-store')
        .send({
          token,
          expiresAt: new Date(Date.now() + 5 * 60 * 1000).toISOString(),
          features: license.features,
          plan: license.plan,
          maxStores: license.max_stores,
        });
    },
  );
}
