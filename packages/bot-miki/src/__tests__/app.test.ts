import { describe, it, expect, vi, beforeEach } from 'vitest';
import type { Queue } from 'bullmq';

vi.mock('../config.js', () => ({
  config: {
    NODE_ENV: 'test',
    LOG_LEVEL: 'silent',
    PORT: 3000,
    DATABASE_URL: 'postgresql://test:test@localhost:5432/test',
    REDIS_URL: 'redis://localhost:6379',
    JWT_SECRET: 'test_jwt_secret_minimum_32_characters_long',
    ADMIN_KEY: 'test_admin_key_minimum_32_characters_long_x',
    BSALE_RATE_LIMIT_RPS: 10,
    TOKEN_ENCRYPTION_KEY: 'test_token_encryption_key_minimum_32_chars',
  },
}));

vi.mock('../infrastructure/database.js', () => {
  const chain: Record<string, unknown> = {};
  chain['selectAll'] = () => chain;
  chain['select'] = () => chain;
  chain['where'] = () => chain;
  chain['executeTakeFirst'] = vi.fn().mockResolvedValue(undefined);
  chain['executeTakeFirstOrThrow'] = vi.fn().mockRejectedValue(new Error('not found'));
  chain['execute'] = vi.fn().mockResolvedValue([]);
  return { db: { selectFrom: () => chain, insertInto: () => chain, updateTable: () => chain } };
});

const { buildApp } = await import('../app.js');
const mockQueue = { add: vi.fn().mockResolvedValue(undefined) } as unknown as Queue;

beforeEach(() => vi.clearAllMocks());

describe('buildApp (#108)', () => {
  describe('/docs protegido con X-Admin-Key', () => {
    it('devuelve 403 sin X-Admin-Key', async () => {
      const app = buildApp(mockQueue);
      await app.ready();

      const res = await app.inject({ method: 'GET', url: '/docs' });

      expect(res.statusCode).toBe(403);
      await app.close();
    });

    it('devuelve 403 con X-Admin-Key incorrecta', async () => {
      const app = buildApp(mockQueue);
      await app.ready();

      const res = await app.inject({
        method: 'GET',
        url: '/docs',
        headers: { 'x-admin-key': 'clave-incorrecta' },
      });

      expect(res.statusCode).toBe(403);
      await app.close();
    });

    it('no devuelve 403 con X-Admin-Key correcta', async () => {
      const app = buildApp(mockQueue);
      await app.ready();

      const res = await app.inject({
        method: 'GET',
        url: '/docs',
        headers: { 'x-admin-key': 'test_admin_key_minimum_32_characters_long_x' },
      });

      expect(res.statusCode).not.toBe(403);
      await app.close();
    });
  });

  describe('cabeceras de seguridad (helmet)', () => {
    it('incluye X-Frame-Options y X-Content-Type-Options en las respuestas', async () => {
      const app = buildApp(mockQueue);
      await app.ready();

      const res = await app.inject({ method: 'GET', url: '/health' });

      expect(res.headers['x-frame-options']).toBeDefined();
      expect(res.headers['x-content-type-options']).toBe('nosniff');
      await app.close();
    });
  });

  describe('rate limit', () => {
    it('la ruta /v1/license/token tiene un limite mas estricto configurado', async () => {
      const app = buildApp(mockQueue);
      await app.ready();

      const res = await app.inject({
        method: 'GET',
        url: '/v1/license/token',
        headers: { 'x-api-key': 'kp_invalid' },
      });

      // No nos interesa el resultado de negocio (401 por API key invalida) sino
      // que el plugin de rate-limit esta activo en la ruta.
      expect(res.headers['x-ratelimit-limit']).toBe('20');
      await app.close();
    });
  });
});
