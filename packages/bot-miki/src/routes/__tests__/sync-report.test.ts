import { describe, it, expect, vi, beforeEach } from 'vitest';
import { buildApp } from '../../app.js';
import type { Queue } from 'bullmq';

// ── Hoisted mocks ─────────────────────────────────────────────────────────────

const { mockExecuteTakeFirst, mockInsertExecute, mockValues } = vi.hoisted(() => ({
  mockExecuteTakeFirst: vi.fn(),
  mockInsertExecute: vi.fn().mockResolvedValue([]),
  mockValues: vi.fn(),
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
    execute: mockInsertExecute,
  };
  chain['selectFrom'] = () => chain;
  chain['select'] = () => chain;
  chain['where'] = () => chain;
  chain['insertInto'] = () => chain;
  chain['values'] = (v: unknown) => { mockValues(v); return chain; };
  chain['onConflict'] = () => chain;
  return { db: chain };
});

// ── Helpers ────────────────────────────────────────────────────────────────────

const mockQueue = { add: vi.fn() } as unknown as Queue;

const validReport = {
  syncType: 'webhook',
  entityType: 'stock',
  status: 'success',
  idempotencyKey: 'webhook_store_stock_1_1700000000',
};

beforeEach(() => {
  vi.clearAllMocks();
  mockInsertExecute.mockResolvedValue([]);
});

// ── Tests ──────────────────────────────────────────────────────────────────────

describe('POST /v1/sync/report', () => {
  it('returns 401 and does not write when X-API-Key is missing (#91)', async () => {
    const app = buildApp(mockQueue);
    await app.ready();

    const res = await app.inject({
      method: 'POST',
      url: '/v1/sync/report',
      payload: validReport,
    });

    expect(res.statusCode).toBe(401);
    expect(mockInsertExecute).not.toHaveBeenCalled();
    await app.close();
  });

  it('returns 401 when the API key matches no license (#91)', async () => {
    mockExecuteTakeFirst.mockResolvedValueOnce(undefined);

    const app = buildApp(mockQueue);
    await app.ready();

    const res = await app.inject({
      method: 'POST',
      url: '/v1/sync/report',
      headers: { 'x-api-key': 'kp_bogus' },
      payload: validReport,
    });

    expect(res.statusCode).toBe(401);
    expect(mockInsertExecute).not.toHaveBeenCalled();
    await app.close();
  });

  it('returns 402 when the license is not active (#91)', async () => {
    mockExecuteTakeFirst.mockResolvedValueOnce({ tenant_id: 'tenant-1', status: 'suspended' });

    const app = buildApp(mockQueue);
    await app.ready();

    const res = await app.inject({
      method: 'POST',
      url: '/v1/sync/report',
      headers: { 'x-api-key': 'kp_valid' },
      payload: validReport,
    });

    expect(res.statusCode).toBe(402);
    expect(mockInsertExecute).not.toHaveBeenCalled();
    await app.close();
  });

  it('writes the event with the tenant from the license, ignoring a spoofed body.tenantId (#91/#99)', async () => {
    mockExecuteTakeFirst.mockResolvedValueOnce({ tenant_id: 'tenant-real', status: 'active' });

    const app = buildApp(mockQueue);
    await app.ready();

    const res = await app.inject({
      method: 'POST',
      url: '/v1/sync/report',
      headers: { 'x-api-key': 'kp_valid' },
      payload: { ...validReport, tenantId: 'tenant-SPOOFED' },
    });

    expect(res.statusCode).toBe(204);
    expect(mockInsertExecute).toHaveBeenCalledTimes(1);
    expect(mockValues).toHaveBeenCalledWith(
      expect.objectContaining({ tenant_id: 'tenant-real' }),
    );
    await app.close();
  });
});
