import { describe, it, expect, vi, beforeEach } from 'vitest';
import { buildApp } from '../../app.js';
import type { Queue } from 'bullmq';

// ── Hoisted mocks ─────────────────────────────────────────────────────────────

const { mockExecuteTakeFirst, mockExecute, mockQueueAdd } = vi.hoisted(() => ({
  mockExecuteTakeFirst: vi.fn(),
  mockExecute: vi.fn().mockResolvedValue([]),
  mockQueueAdd: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('../../config.js', () => ({
  config: {
    NODE_ENV: 'test',
    LOG_LEVEL: 'silent',
    PORT: 3000,
    DATABASE_URL: 'postgresql://test:test@localhost:5432/test',
    REDIS_URL: 'redis://localhost:6379',
    JWT_SECRET: 'test_jwt_secret_minimum_32_characters_long',
    ADMIN_KEY: 'test_admin_key_minimum_32_characters_long',
    BSALE_RATE_LIMIT_RPS: 10,
    TOKEN_ENCRYPTION_KEY: 'test_token_encryption_key_minimum_32_chars',
  },
}));

vi.mock('../../infrastructure/database.js', () => {
  const chain: Record<string, unknown> = {
    executeTakeFirst: mockExecuteTakeFirst,
    execute: mockExecute,
  };
  chain['selectFrom'] = () => chain;
  chain['select']     = () => chain;
  chain['where']       = () => chain;
  chain['orderBy']     = () => chain;
  chain['limit']       = () => chain;
  return { db: { selectFrom: () => chain } };
});

// ── Helpers ────────────────────────────────────────────────────────────────────

const mockQueue = { add: mockQueueAdd } as unknown as Queue;

function buildTestApp() {
  return buildApp(mockQueue);
}

const activeLicense = { id: 'license-uuid-1', tenant_id: 'agencia-demo', status: 'active' };

beforeEach(() => {
  vi.clearAllMocks();
  mockExecute.mockResolvedValue([]);
});

// ── Tests ──────────────────────────────────────────────────────────────────────

describe('GET /v1/agency/clients', () => {
  it('returns 401 when X-API-Key is missing', async () => {
    const app = buildTestApp();
    await app.ready();

    const res = await app.inject({ method: 'GET', url: '/v1/agency/clients' });

    expect(res.statusCode).toBe(401);
    await app.close();
  });

  it('returns 404 when the API Key does not match any license', async () => {
    mockExecuteTakeFirst.mockResolvedValueOnce(undefined);
    const app = buildTestApp();
    await app.ready();

    const res = await app.inject({
      method: 'GET',
      url: '/v1/agency/clients',
      headers: { 'x-api-key': 'kp_bogus' },
    });

    expect(res.statusCode).toBe(404);
    await app.close();
  });

  it('returns 402 when the license is not active', async () => {
    mockExecuteTakeFirst.mockResolvedValueOnce({ ...activeLicense, status: 'suspended' });
    const app = buildTestApp();
    await app.ready();

    const res = await app.inject({
      method: 'GET',
      url: '/v1/agency/clients',
      headers: { 'x-api-key': 'kp_agency' },
    });

    expect(res.statusCode).toBe(402);
    await app.close();
  });

  it('returns 200 with the list of stores scoped to the caller license', async () => {
    mockExecuteTakeFirst.mockResolvedValueOnce(activeLicense);
    mockExecute.mockResolvedValueOnce([
      { id: 'store-1', store_name: 'Cliente A', cms_type: 'prestashop', last_sync_at: new Date('2026-07-23T10:00:00Z'), last_sync_status: 'success' },
      { id: 'store-2', store_name: 'Cliente B', cms_type: 'shopify', last_sync_at: null, last_sync_status: null },
    ]);

    const app = buildTestApp();
    await app.ready();

    const res = await app.inject({
      method: 'GET',
      url: '/v1/agency/clients',
      headers: { 'x-api-key': 'kp_agency' },
    });

    expect(res.statusCode).toBe(200);
    const body = res.json();
    expect(body.clients).toHaveLength(2);
    expect(body.clients[0]).toMatchObject({ id: 'store-1', storeName: 'Cliente A', lastSyncStatus: 'success' });
    expect(body.clients[1]).toMatchObject({ id: 'store-2', lastSyncAt: null });
    await app.close();
  });
});

describe('POST /v1/agency/clients/:storeId/sync', () => {
  it('returns 401 when X-API-Key is missing', async () => {
    const app = buildTestApp();
    await app.ready();

    const res = await app.inject({ method: 'POST', url: '/v1/agency/clients/store-1/sync' });

    expect(res.statusCode).toBe(401);
    expect(mockQueueAdd).not.toHaveBeenCalled();
    await app.close();
  });

  it('#54: returns 404 (no 200) when storeId belongs to a different tenant — evita IDOR', async () => {
    mockExecuteTakeFirst
      .mockResolvedValueOnce(activeLicense) // resuelve la licencia del caller
      .mockResolvedValueOnce(undefined);    // la tienda no aparece con ese license_id -> no es suya

    const app = buildTestApp();
    await app.ready();

    const res = await app.inject({
      method: 'POST',
      url: '/v1/agency/clients/store-de-otro-tenant/sync',
      headers: { 'x-api-key': 'kp_agency' },
    });

    expect(res.statusCode).toBe(404);
    expect(mockQueueAdd).not.toHaveBeenCalled();
    await app.close();
  });

  it('returns 400 for an invalid entity', async () => {
    mockExecuteTakeFirst.mockResolvedValueOnce(activeLicense);

    const app = buildTestApp();
    await app.ready();

    const res = await app.inject({
      method: 'POST',
      url: '/v1/agency/clients/store-1/sync',
      headers: { 'x-api-key': 'kp_agency' },
      payload: { entity: 'clientes' },
    });

    expect(res.statusCode).toBe(400);
    expect(mockQueueAdd).not.toHaveBeenCalled();
    await app.close();
  });

  it('enqueues a manual sync job scoped to the owned store and returns 202', async () => {
    mockExecuteTakeFirst
      .mockResolvedValueOnce(activeLicense)
      .mockResolvedValueOnce({ id: 'store-1' });

    const app = buildTestApp();
    await app.ready();

    const res = await app.inject({
      method: 'POST',
      url: '/v1/agency/clients/store-1/sync',
      headers: { 'x-api-key': 'kp_agency' },
      payload: { entity: 'stock' },
    });

    expect(res.statusCode).toBe(202);
    expect(mockQueueAdd).toHaveBeenCalledTimes(1);

    const [jobName, jobData, jobOpts] = mockQueueAdd.mock.calls[0];
    expect(jobName).toBe('bsale-webhook');
    expect(jobData).toMatchObject({
      storeId:    'store-1',
      tenantId:   'agencia-demo',
      syncType:   'manual',
      entityType: 'stock',
    });
    expect(jobOpts.jobId).toMatch(/^manual_store-1_stock_\d+$/);
    await app.close();
  });

  it('defaults entity to "products" when not provided', async () => {
    mockExecuteTakeFirst
      .mockResolvedValueOnce(activeLicense)
      .mockResolvedValueOnce({ id: 'store-1' });

    const app = buildTestApp();
    await app.ready();

    await app.inject({
      method: 'POST',
      url: '/v1/agency/clients/store-1/sync',
      headers: { 'x-api-key': 'kp_agency' },
    });

    const [, jobData] = mockQueueAdd.mock.calls[0];
    expect(jobData).toMatchObject({ entityType: 'products' });
    await app.close();
  });
});

describe('GET /v1/agency/clients/:storeId/logs', () => {
  it('#54: returns 404 when storeId belongs to a different tenant', async () => {
    mockExecuteTakeFirst
      .mockResolvedValueOnce(activeLicense)
      .mockResolvedValueOnce(undefined);

    const app = buildTestApp();
    await app.ready();

    const res = await app.inject({
      method: 'GET',
      url: '/v1/agency/clients/store-de-otro-tenant/logs',
      headers: { 'x-api-key': 'kp_agency' },
    });

    expect(res.statusCode).toBe(404);
    await app.close();
  });

  it('returns 200 with the sync history for the owned store', async () => {
    mockExecuteTakeFirst
      .mockResolvedValueOnce(activeLicense)
      .mockResolvedValueOnce({ id: 'store-1' });
    mockExecute.mockResolvedValueOnce([
      {
        id: 'evt-1', sync_type: 'manual', entity_type: 'products', status: 'success',
        records_updated: 12, records_failed: 0, error_message: null,
        created_at: new Date('2026-07-23T10:00:00Z'),
      },
    ]);

    const app = buildTestApp();
    await app.ready();

    const res = await app.inject({
      method: 'GET',
      url: '/v1/agency/clients/store-1/logs',
      headers: { 'x-api-key': 'kp_agency' },
    });

    expect(res.statusCode).toBe(200);
    const body = res.json();
    expect(body.storeId).toBe('store-1');
    expect(body.logs).toHaveLength(1);
    expect(body.logs[0]).toMatchObject({ status: 'success', recordsUpdated: 12 });
    await app.close();
  });
});
