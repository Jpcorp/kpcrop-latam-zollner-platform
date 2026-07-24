import { describe, it, expect, vi, beforeEach } from 'vitest';
import { buildApp } from '../../app.js';
import { Queue } from 'bullmq';

// ── Hoisted mocks (must run before any import resolves) ───────────────────────

const { mockExecuteTakeFirst } = vi.hoisted(() => ({
  mockExecuteTakeFirst: vi.fn(),
}));

vi.mock('../../config.js', () => ({
  config: {
    NODE_ENV: 'test',
    LOG_LEVEL: 'silent',
    PORT: 3000,
    DATABASE_URL: 'postgresql://test:test@localhost:5432/test',
    REDIS_URL: 'redis://localhost:6379',
    JWT_SECRET: 'test_jwt_secret_minimum_32_characters_long',
    BSALE_RATE_LIMIT_RPS: 10,
    TOKEN_ENCRYPTION_KEY: 'test_token_encryption_key_minimum_32_chars',
  },
}));

vi.mock('../../infrastructure/database.js', () => {
  const chain: Record<string, unknown> = {
    executeTakeFirst: mockExecuteTakeFirst,
  };
  chain['selectAll'] = () => chain;
  chain['where'] = () => chain;
  return { db: { selectFrom: () => chain } };
});

// ── Test setup ─────────────────────────────────────────────────────────────────

const mockQueue = { add: vi.fn().mockResolvedValue(undefined) } as unknown as Queue;

function buildTestApp() {
  return buildApp(mockQueue);
}

beforeEach(() => {
  vi.clearAllMocks();
});

// ── Tests ──────────────────────────────────────────────────────────────────────

describe('GET /v1/license/token', () => {
  it('returns 401 when X-API-Key header is missing', async () => {
    const app = buildTestApp();
    await app.ready();

    const res = await app.inject({
      method: 'GET',
      url: '/v1/license/token?tenantId=tenant-001',
    });

    expect(res.statusCode).toBe(401);
    expect(res.json()).toMatchObject({ code: 'MISSING_API_KEY' });
    await app.close();
  });

  it('returns 404 when tenant is not found', async () => {
    mockExecuteTakeFirst.mockResolvedValueOnce(undefined);

    const app = buildTestApp();
    await app.ready();

    const res = await app.inject({
      method: 'GET',
      url: '/v1/license/token?tenantId=unknown-tenant',
      headers: { 'x-api-key': 'kp_invalid_key' },
    });

    expect(res.statusCode).toBe(404);
    expect(res.json()).toMatchObject({ code: 'TENANT_NOT_FOUND' });
    await app.close();
  });

  it('returns 402 when license is suspended', async () => {
    mockExecuteTakeFirst.mockResolvedValueOnce({
      id: 'lic-1',
      tenant_id: 'tenant-001',
      api_key: 'kp_test_key',
      status: 'suspended',
      plan: 'starter',
      features: ['sync_products'],
      max_stores: 1,
    });

    const app = buildTestApp();
    await app.ready();

    const res = await app.inject({
      method: 'GET',
      url: '/v1/license/token?tenantId=tenant-001',
      headers: { 'x-api-key': 'kp_test_key' },
    });

    expect(res.statusCode).toBe(402);
    expect(res.json()).toMatchObject({ code: 'LICENSE_EXPIRED' });
    await app.close();
  });

  it('returns 200 with JWT and non-cacheable Cache-Control when license is active (#96)', async () => {
    mockExecuteTakeFirst.mockResolvedValueOnce({
      id: 'lic-1',
      tenant_id: 'tenant-001',
      api_key: 'kp_valid_key',
      status: 'active',
      plan: 'growth',
      features: ['sync_products', 'sync_prices', 'sync_stock'],
      max_stores: 3,
    });

    const app = buildTestApp();
    await app.ready();

    const res = await app.inject({
      method: 'GET',
      url: '/v1/license/token?tenantId=tenant-001',
      headers: { 'x-api-key': 'kp_valid_key' },
    });

    expect(res.statusCode).toBe(200);
    const body = res.json();
    expect(body.token).toBeTruthy();
    expect(body.plan).toBe('growth');
    expect(body.maxStores).toBe(3);
    expect(body.features).toContain('sync_products');
    // #96: la respuesta contiene un JWT bearer — nunca debe cachearse en un
    // edge/CDN compartido (una cache publica filtraria el token de un tenant a otro).
    expect(res.headers['cache-control']).toBe('private, no-store');
    await app.close();
  });
});
