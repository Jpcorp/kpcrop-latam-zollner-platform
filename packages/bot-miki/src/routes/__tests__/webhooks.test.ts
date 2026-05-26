import { describe, it, expect, vi, beforeEach } from 'vitest';
import { buildApp } from '../../app.js';
import type { Queue } from 'bullmq';

// ── Hoisted mocks ─────────────────────────────────────────────────────────────

const { mockExecuteTakeFirst, mockQueueAdd } = vi.hoisted(() => ({
  mockExecuteTakeFirst: vi.fn(),
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
    BSALE_RATE_LIMIT_RPS: 10,
  },
}));

vi.mock('../../infrastructure/database.js', () => {
  const chain: Record<string, unknown> = {
    executeTakeFirst: mockExecuteTakeFirst,
  };
  chain['selectAll'] = () => chain;
  chain['where'] = () => chain;
  return { db: { selectFrom: () => chain, insertInto: () => chain, values: () => chain, execute: vi.fn().mockResolvedValue([]) } };
});

// ── Helpers ────────────────────────────────────────────────────────────────────

const mockQueue = { add: mockQueueAdd } as unknown as Queue;

function buildTestApp() {
  return buildApp(mockQueue);
}

const validPayload = {
  cpnId: 42,
  resource: '/v2/products/952.json',
  resourceId: '952',
  topic: 'product',
  action: 'put',
  send: 1700000000,
};

beforeEach(() => {
  vi.clearAllMocks();
});

// ── Tests ──────────────────────────────────────────────────────────────────────

describe('POST /v1/webhooks/bsale', () => {
  it('returns 400 when resource does not start with /v', async () => {
    const app = buildTestApp();
    await app.ready();

    const res = await app.inject({
      method: 'POST',
      url: '/v1/webhooks/bsale',
      payload: { ...validPayload, resource: 'https://api.bsale.cl/v2/products/1.json' },
    });

    expect(res.statusCode).toBe(400);
    await app.close();
  });

  it('returns 200 and does not enqueue for irrelevant topics', async () => {
    const app = buildTestApp();
    await app.ready();

    const res = await app.inject({
      method: 'POST',
      url: '/v1/webhooks/bsale',
      payload: { ...validPayload, topic: 'document' },
    });

    expect(res.statusCode).toBe(200);
    expect(mockQueueAdd).not.toHaveBeenCalled();
    await app.close();
  });

  it('returns 200 and does not enqueue when cpnId is unknown', async () => {
    mockExecuteTakeFirst.mockResolvedValueOnce(undefined);

    const app = buildTestApp();
    await app.ready();

    const res = await app.inject({
      method: 'POST',
      url: '/v1/webhooks/bsale',
      payload: validPayload,
    });

    expect(res.statusCode).toBe(200);
    expect(mockQueueAdd).not.toHaveBeenCalled();
    await app.close();
  });

  it('returns 200 and enqueues job when store is found', async () => {
    mockExecuteTakeFirst.mockResolvedValueOnce({
      id: 'store-uuid-1',
      license_id: 'lic-uuid-1',
      bsale_integration_id: 42,
      store_name: 'Tienda Test',
      cms_type: 'prestashop',
    });

    const app = buildTestApp();
    await app.ready();

    const res = await app.inject({
      method: 'POST',
      url: '/v1/webhooks/bsale',
      payload: validPayload,
    });

    expect(res.statusCode).toBe(200);
    expect(mockQueueAdd).toHaveBeenCalledTimes(1);

    const [jobName, jobData, jobOpts] = mockQueueAdd.mock.calls[0];
    expect(jobName).toBe('bsale-webhook');
    expect(jobData).toMatchObject({
      storeId: 'store-uuid-1',
      topic: 'product',
      resourceId: '952',
    });
    expect(jobOpts.jobId).toContain('webhook:store-uuid-1:product:952');
    expect(jobOpts.attempts).toBe(5);
    await app.close();
  });

  it('assigns exponential backoff with 30s base delay', async () => {
    mockExecuteTakeFirst.mockResolvedValueOnce({
      id: 'store-uuid-2',
      license_id: 'lic-uuid-2',
      bsale_integration_id: 42,
      store_name: 'Tienda Test 2',
      cms_type: 'prestashop',
    });

    const app = buildTestApp();
    await app.ready();

    await app.inject({
      method: 'POST',
      url: '/v1/webhooks/bsale',
      payload: validPayload,
    });

    const [, , jobOpts] = mockQueueAdd.mock.calls[0];
    expect(jobOpts.backoff).toEqual({ type: 'exponential', delay: 30_000 });
    await app.close();
  });
});
