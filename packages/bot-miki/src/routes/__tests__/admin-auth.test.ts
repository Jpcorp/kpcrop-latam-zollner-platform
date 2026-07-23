import { describe, it, expect, vi, beforeEach } from 'vitest';
import { buildApp } from '../../app.js';
import type { Queue } from 'bullmq';

// ── Hoisted mocks ─────────────────────────────────────────────────────────────

// ADMIN_KEY va dentro de vi.hoisted: el factory de vi.mock se eleva por encima de
// las constantes de modulo, asi que referenciar una const normal falla al inicializar.
const { ADMIN_KEY, mockExecuteTakeFirst } = vi.hoisted(() => ({
  ADMIN_KEY: 'test_admin_key_minimum_32_characters_long',
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
    ADMIN_KEY,
    BSALE_RATE_LIMIT_RPS: 10,
    TOKEN_ENCRYPTION_KEY: 'test_token_encryption_key_minimum_32_chars',
  },
}));

vi.mock('../../infrastructure/database.js', () => {
  const chain: Record<string, unknown> = {
    executeTakeFirst: mockExecuteTakeFirst,
  };
  chain['selectFrom'] = () => chain;
  chain['selectAll'] = () => chain;
  chain['where'] = () => chain;
  return { db: chain };
});

// ── Helpers ────────────────────────────────────────────────────────────────────

const mockQueue = { add: vi.fn() } as unknown as Queue;

beforeEach(() => {
  vi.clearAllMocks();
});

// ── Tests ──────────────────────────────────────────────────────────────────────

describe('Admin auth hook (#92)', () => {
  it('returns 403 when X-Admin-Key is missing', async () => {
    const app = buildApp(mockQueue);
    await app.ready();

    const res = await app.inject({
      method: 'GET',
      url: '/v1/admin/tenants/tenant-1',
    });

    expect(res.statusCode).toBe(403);
    expect(mockExecuteTakeFirst).not.toHaveBeenCalled();
    await app.close();
  });

  it('returns 403 when X-Admin-Key is wrong', async () => {
    const app = buildApp(mockQueue);
    await app.ready();

    const res = await app.inject({
      method: 'GET',
      url: '/v1/admin/tenants/tenant-1',
      headers: { 'x-admin-key': 'wrong_key_of_a_totally_different_length' },
    });

    expect(res.statusCode).toBe(403);
    expect(mockExecuteTakeFirst).not.toHaveBeenCalled();
    await app.close();
  });

  it('passes the hook with the correct X-Admin-Key (reaches the handler)', async () => {
    mockExecuteTakeFirst.mockResolvedValueOnce(undefined); // tenant no encontrado → 404

    const app = buildApp(mockQueue);
    await app.ready();

    const res = await app.inject({
      method: 'GET',
      url: '/v1/admin/tenants/tenant-1',
      headers: { 'x-admin-key': ADMIN_KEY },
    });

    expect(res.statusCode).toBe(404); // pasó la auth, el handler corrió
    expect(mockExecuteTakeFirst).toHaveBeenCalledTimes(1);
    await app.close();
  });
});
