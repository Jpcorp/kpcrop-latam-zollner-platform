import { describe, it, expect, vi, beforeEach } from 'vitest';
import { buildApp } from '../../app.js';
import type { Queue } from 'bullmq';

// ── Hoisted mocks ─────────────────────────────────────────────────────────────

const { mockExecute, mockQueueAdd, mockWhere } = vi.hoisted(() => ({
  mockExecute: vi.fn(),
  mockQueueAdd: vi.fn().mockResolvedValue(undefined),
  mockWhere: vi.fn(),
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
    // el lookup de tiendas por cpnId trae N filas (varias tiendas pueden
    // compartir una integracion Bsale), no una sola
    execute: mockExecute,
  };
  chain['selectAll'] = () => chain;
  chain['innerJoin'] = () => chain; // #105: webhooks.ts hace join con licenses
  chain['select'] = () => chain;    // #105: select explicito de columnas tras el join
  chain['where'] = mockWhere.mockImplementation(() => chain);
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

  it('#97: returns 400 when resource points to an endpoint unrelated to the topic', async () => {
    const app = buildTestApp();
    await app.ready();

    const res = await app.inject({
      method: 'POST',
      url: '/v1/webhooks/bsale',
      // topic=product pero resource apunta a un endpoint totalmente distinto —
      // sin la whitelist, bot-miki haria bsale.get('/v1/clients.json') con el
      // token del tenant, filtrando datos ajenos al sync.
      payload: { ...validPayload, topic: 'product', resource: '/v1/clients.json' },
    });

    expect(res.statusCode).toBe(400);
    expect(mockQueueAdd).not.toHaveBeenCalled();
    await app.close();
  });

  it('#97: rechaza resource de un topic distinto (variant apuntando a products)', async () => {
    const app = buildTestApp();
    await app.ready();

    const res = await app.inject({
      method: 'POST',
      url: '/v1/webhooks/bsale',
      payload: { ...validPayload, topic: 'variant', resource: '/v2/products/952.json', resourceId: '952' },
    });

    expect(res.statusCode).toBe(400);
    await app.close();
  });

  it('#97: acepta la forma coleccion de stock v2 sin id en el path', async () => {
    mockExecute.mockResolvedValueOnce([{
        id: 'store-uuid-3',
        license_id: 'lic-uuid-3',
        bsale_integration_id: 42,
        store_name: 'Tienda Test 3',
        cms_type: 'prestashop',
      }]);

    const app = buildTestApp();
    await app.ready();

    const res = await app.inject({
      method: 'POST',
      url: '/v1/webhooks/bsale',
      payload: { ...validPayload, topic: 'stock', resource: '/v2/stocks.json?variantid=123', resourceId: '123' },
    });

    expect(res.statusCode).toBe(200);
    expect(mockQueueAdd).toHaveBeenCalledTimes(1);
    await app.close();
  });

  it('returns 200 and does not enqueue for irrelevant topics', async () => {
    const app = buildTestApp();
    await app.ready();

    const res = await app.inject({
      method: 'POST',
      url: '/v1/webhooks/bsale',
      payload: { ...validPayload, topic: 'client' },
    });

    expect(res.statusCode).toBe(200);
    expect(mockQueueAdd).not.toHaveBeenCalled();
    await app.close();
  });

  it('#130: topic=document ya no se ignora — encola con entityType=orders', async () => {
    mockExecute.mockResolvedValueOnce([{
        id: 'store-uuid-6',
        license_id: 'lic-uuid-6',
        bsale_integration_id: 42,
        store_name: 'Tienda Test 6',
        cms_type: 'prestashop',
      }]);

    const app = buildTestApp();
    await app.ready();

    const res = await app.inject({
      method: 'POST',
      url: '/v1/webhooks/bsale',
      payload: { ...validPayload, topic: 'document', resource: '/v1/documents/25.json', resourceId: '25' },
    });

    expect(res.statusCode).toBe(200);
    expect(mockQueueAdd).toHaveBeenCalledTimes(1);

    const [, jobData] = mockQueueAdd.mock.calls[0];
    expect(jobData).toMatchObject({ topic: 'document', entityType: 'orders' });
    await app.close();
  });

  it('returns 200 and does not enqueue when cpnId is unknown', async () => {
    mockExecute.mockResolvedValueOnce([]);

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
    mockExecute.mockResolvedValueOnce([{
        id: 'store-uuid-1',
        license_id: 'lic-uuid-1',
        bsale_integration_id: 42,
        store_name: 'Tienda Test',
        cms_type: 'prestashop',
      }]);

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
    expect(jobOpts.jobId).toBe('webhook_store-uuid-1_product_952_1700000000');
    expect(jobOpts.attempts).toBe(5);
    await app.close();
  });

  it('assigns exponential backoff with 30s base delay', async () => {
    mockExecute.mockResolvedValueOnce([{
        id: 'store-uuid-2',
        license_id: 'lic-uuid-2',
        bsale_integration_id: 42,
        store_name: 'Tienda Test 2',
        cms_type: 'prestashop',
      }]);

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

  it('#115: limpia jobs completados/fallidos de Redis (removeOnComplete/removeOnFail)', async () => {
    mockExecute.mockResolvedValueOnce([{
        id: 'store-uuid-3',
        license_id: 'lic-uuid-3',
        bsale_integration_id: 42,
        store_name: 'Tienda Test 3',
        cms_type: 'prestashop',
      }]);

    const app = buildTestApp();
    await app.ready();

    await app.inject({
      method: 'POST',
      url: '/v1/webhooks/bsale',
      payload: validPayload,
    });

    const [, , jobOpts] = mockQueueAdd.mock.calls[0];
    expect(jobOpts.removeOnComplete).toEqual({ age: 86_400 });
    expect(jobOpts.removeOnFail).toEqual({ age: 604_800 });
    await app.close();
  });

  it('#127: filtra por status=active de la licencia antes de encolar', async () => {
    mockExecute.mockResolvedValueOnce([{
        id: 'store-uuid-5',
        license_id: 'lic-uuid-5',
        bsale_integration_id: 42,
        store_name: 'Tienda Test 5',
        cms_type: 'prestashop',
      }]);

    const app = buildTestApp();
    await app.ready();

    await app.inject({
      method: 'POST',
      url: '/v1/webhooks/bsale',
      payload: validPayload,
    });

    expect(mockWhere).toHaveBeenCalledWith('l.status', '=', 'active');
    await app.close();
  });

  it('#127: con licencia suspendida (query no devuelve store) no encola — igual que tenant desconocido', async () => {
    mockExecute.mockResolvedValueOnce([]);

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

  it('#115: incluye send en el job encolado (para descartar eventos de stock fuera de orden)', async () => {
    mockExecute.mockResolvedValueOnce([{
        id: 'store-uuid-4',
        license_id: 'lic-uuid-4',
        bsale_integration_id: 42,
        store_name: 'Tienda Test 4',
        cms_type: 'prestashop',
      }]);

    const app = buildTestApp();
    await app.ready();

    await app.inject({
      method: 'POST',
      url: '/v1/webhooks/bsale',
      payload: validPayload,
    });

    const [, jobData] = mockQueueAdd.mock.calls[0];
    expect(jobData.send).toBe(validPayload.send);
    await app.close();
  });

  it('abanica el webhook a TODAS las tiendas que comparten el mismo cpnId', async () => {
    // 3 e-commerce contra un solo Bsale: dos bajo una licencia multi-tienda
    // (growth/agency) y uno bajo licencia propia. Antes esto era
    // executeTakeFirst() sin order by — se encolaba para UNA sola tienda
    // elegida arbitrariamente por Postgres y las otras dos no se enteraban.
    mockExecute.mockResolvedValueOnce([
      { id: 'store-a', license_id: 'lic-1', tenant_id: 'tenant-1' },
      { id: 'store-b', license_id: 'lic-2', tenant_id: 'tenant-2' },
      { id: 'store-c', license_id: 'lic-2', tenant_id: 'tenant-2' },
    ]);

    const app = buildTestApp();
    await app.ready();

    const res = await app.inject({
      method: 'POST',
      url: '/v1/webhooks/bsale',
      payload: validPayload,
    });

    expect(res.statusCode).toBe(200);
    expect(mockQueueAdd).toHaveBeenCalledTimes(3);

    expect(mockQueueAdd.mock.calls.map(c => c[1].storeId)).toEqual(['store-a', 'store-b', 'store-c']);
    // cada tienda conserva el tenant de SU licencia — sin esto, las tiendas de
    // licencias distintas escribirian sync_events bajo el tenant equivocado
    expect(mockQueueAdd.mock.calls.map(c => c[1].tenantId)).toEqual(['tenant-1', 'tenant-2', 'tenant-2']);

    // el jobId lleva store.id: la dedup de reintentos de Bsale sigue viva por
    // tienda, pero un mismo webhook ya no colisiona entre tiendas
    const jobIds = mockQueueAdd.mock.calls.map(c => c[2].jobId);
    expect(jobIds).toEqual([
      'webhook_store-a_product_952_1700000000',
      'webhook_store-b_product_952_1700000000',
      'webhook_store-c_product_952_1700000000',
    ]);
    await app.close();
  });
});
