import { describe, it, expect, vi, beforeEach } from 'vitest';
import type { Job } from 'bullmq';
import type { BsaleHttpClient } from '../../infrastructure/bsale-http-client.js';
import type { SyncJobData } from '../sync-worker.js';

// ── Hoisted mocks ─────────────────────────────────────────────────────────────

const {
  mockSelectExecuteTakeFirstOrThrow,
  mockSnapshotExecuteTakeFirst,
  mockInsertExecute,
  mockUpdateExecute,
  mockResolveWebhookResource,
} = vi.hoisted(() => ({
  mockSelectExecuteTakeFirstOrThrow: vi.fn(),
  mockSnapshotExecuteTakeFirst:      vi.fn(),
  mockInsertExecute:                 vi.fn().mockResolvedValue([]),
  mockUpdateExecute:                 vi.fn().mockResolvedValue([]),
  mockResolveWebhookResource:        vi.fn(),
}));

vi.mock('../../config.js', () => ({
  config: {
    NODE_ENV: 'test',
    REDIS_URL: 'redis://localhost:6379',
    BSALE_RATE_LIMIT_RPS: 10,
    TOKEN_ENCRYPTION_KEY: 'test_token_encryption_key_minimum_32_chars',
  },
}));

vi.mock('../../infrastructure/database.js', () => {
  const selectChain = {
    selectAll: () => selectChain,
    select:    () => selectChain,
    where:     () => selectChain,
    executeTakeFirstOrThrow: mockSelectExecuteTakeFirstOrThrow,
    executeTakeFirst:        mockSnapshotExecuteTakeFirst,
  };
  const updateChain = {
    set:     () => updateChain,
    where:   () => updateChain,
    execute: mockUpdateExecute,
  };
  const insertChain: Record<string, unknown> = {};
  insertChain['values']     = () => insertChain;
  insertChain['onConflict'] = () => insertChain;
  insertChain['execute']    = mockInsertExecute;
  return {
    db: {
      selectFrom:  () => selectChain,
      insertInto:  () => insertChain,
      updateTable: () => updateChain,
    },
  };
});

vi.mock('../../adapters/bsale-webhook-resolver.js', () => ({
  resolveWebhookResource: mockResolveWebhookResource,
}));

// ── Mock global fetch ─────────────────────────────────────────────────────────

const mockFetch = vi.fn();
vi.stubGlobal('fetch', mockFetch);

// ── Import bajo test (después de los mocks) ───────────────────────────────────

const { processWebhookEvent, processManualSync, processPollingCycle, handleJobFailed, PermanentSyncError } = await import('../sync-worker.js');

// ── Fixtures ──────────────────────────────────────────────────────────────────

const mockBsale = {} as BsaleHttpClient;

const baseStore = {
  cms_url:            'https://tienda.cl',
  cms_webhook_secret: 'kp_e56ee148secret',
};

const baseJobData: SyncJobData = {
  storeId:     'store-uuid-1',
  tenantId:    'allgrano-cl',
  syncType:    'webhook',
  resourceUrl: '/v1/stocks/88765.json',
  resourceId:  '88765',
  topic:       'stock',
  action:      'put',
};

const okFetchResponse = (updated = 1) => ({
  ok:   true,
  json: () => Promise.resolve({ success: true, updated }),
});

beforeEach(() => vi.clearAllMocks());

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('processWebhookEvent', () => {
  describe('modo quirúrgico — stock', () => {
    it('llama a resolveWebhookResource con topic y resourceUrl correctos', async () => {
      const stockData = { id: 88765, quantityAvailable: 5, variant: { id: 9506, href: '' } };
      mockSelectExecuteTakeFirstOrThrow.mockResolvedValueOnce(baseStore);
      mockResolveWebhookResource.mockResolvedValueOnce({ topic: 'stock', data: stockData });
      mockFetch.mockResolvedValueOnce(okFetchResponse());

      await processWebhookEvent(baseJobData, mockBsale);

      expect(mockResolveWebhookResource).toHaveBeenCalledWith(mockBsale, 'stock', '/v1/stocks/88765.json');
    });

    it('envía { topic, bsaleData } al webhook del CMS', async () => {
      const stockData = { id: 88765, quantityAvailable: 5, variant: { id: 9506, href: '' } };
      mockSelectExecuteTakeFirstOrThrow.mockResolvedValueOnce(baseStore);
      mockResolveWebhookResource.mockResolvedValueOnce({ topic: 'stock', data: stockData });
      mockFetch.mockResolvedValueOnce(okFetchResponse());

      await processWebhookEvent(baseJobData, mockBsale);

      const [, fetchOpts] = mockFetch.mock.calls[0];
      expect(JSON.parse(fetchOpts.body)).toEqual({ topic: 'stock', bsaleData: stockData });
    });

    it('#115: incluye send en el payload quirurgico (para descartar eventos fuera de orden)', async () => {
      const stockData = { id: 88765, quantityAvailable: 5, variant: { id: 9506, href: '' } };
      const jobDataWithSend = { ...baseJobData, send: 1_700_000_000 };
      mockSelectExecuteTakeFirstOrThrow.mockResolvedValueOnce(baseStore);
      mockResolveWebhookResource.mockResolvedValueOnce({ topic: 'stock', data: stockData });
      mockFetch.mockResolvedValueOnce(okFetchResponse());

      await processWebhookEvent(jobDataWithSend, mockBsale);

      const [, fetchOpts] = mockFetch.mock.calls[0];
      const body = JSON.parse(fetchOpts.body);
      expect(body.send).toBe(1_700_000_000);
    });

    it('envía X-Synkrop-Secret correcto en el header', async () => {
      mockSelectExecuteTakeFirstOrThrow.mockResolvedValueOnce(baseStore);
      mockResolveWebhookResource.mockResolvedValueOnce({ topic: 'stock', data: {} });
      mockFetch.mockResolvedValueOnce(okFetchResponse());

      await processWebhookEvent(baseJobData, mockBsale);

      const [, fetchOpts] = mockFetch.mock.calls[0];
      expect(fetchOpts.headers['X-Synkrop-Secret']).toBe('kp_e56ee148secret');
    });

    it('construye el URL del CMS correctamente', async () => {
      mockSelectExecuteTakeFirstOrThrow.mockResolvedValueOnce(baseStore);
      mockResolveWebhookResource.mockResolvedValueOnce({ topic: 'stock', data: {} });
      mockFetch.mockResolvedValueOnce(okFetchResponse());

      await processWebhookEvent(baseJobData, mockBsale);

      const [url] = mockFetch.mock.calls[0];
      expect(url).toBe('https://tienda.cl/modules/synkrop/webhook.php');
    });

    it('elimina trailing slash de cms_url para evitar doble barra', async () => {
      mockSelectExecuteTakeFirstOrThrow.mockResolvedValueOnce({
        ...baseStore,
        cms_url: 'https://tienda.cl/',
      });
      mockResolveWebhookResource.mockResolvedValueOnce({ topic: 'stock', data: {} });
      mockFetch.mockResolvedValueOnce(okFetchResponse());

      await processWebhookEvent(baseJobData, mockBsale);

      const [url] = mockFetch.mock.calls[0];
      expect(url).toBe('https://tienda.cl/modules/synkrop/webhook.php');
    });

    it('envía X-Synkrop-Job-Id cuando se provee jobId', async () => {
      mockSelectExecuteTakeFirstOrThrow.mockResolvedValueOnce(baseStore);
      mockResolveWebhookResource.mockResolvedValueOnce({ topic: 'stock', data: {} });
      mockFetch.mockResolvedValueOnce(okFetchResponse());

      await processWebhookEvent(baseJobData, mockBsale, 'webhook_store-1_stock_88765_1719100800');

      const [, fetchOpts] = mockFetch.mock.calls[0];
      expect(fetchOpts.headers['X-Synkrop-Job-Id']).toBe('webhook_store-1_stock_88765_1719100800');
    });

    it('no envía X-Synkrop-Job-Id cuando jobId es undefined', async () => {
      mockSelectExecuteTakeFirstOrThrow.mockResolvedValueOnce(baseStore);
      mockResolveWebhookResource.mockResolvedValueOnce({ topic: 'stock', data: {} });
      mockFetch.mockResolvedValueOnce(okFetchResponse());

      await processWebhookEvent(baseJobData, mockBsale);

      const [, fetchOpts] = mockFetch.mock.calls[0];
      expect(fetchOpts.headers['X-Synkrop-Job-Id']).toBeUndefined();
    });
  });

  describe('modo quirúrgico — variant', () => {
    it('envía bsaleData de variante con topic=variant', async () => {
      const variantData = { id: 9506, code: 'GLA2014', cost: 1000, quantity: 3, state: 0, description: '' };
      const jobData = { ...baseJobData, topic: 'variant', resourceUrl: '/v1/variants/9506.json' };

      mockSelectExecuteTakeFirstOrThrow.mockResolvedValueOnce(baseStore);
      mockResolveWebhookResource.mockResolvedValueOnce({ topic: 'variant', data: variantData });
      mockFetch.mockResolvedValueOnce(okFetchResponse());

      await processWebhookEvent(jobData, mockBsale);

      const [, fetchOpts] = mockFetch.mock.calls[0];
      expect(JSON.parse(fetchOpts.body)).toEqual({ topic: 'variant', bsaleData: variantData });
    });
  });

  describe('action=delete — variant (#109)', () => {
    const deleteJobData: SyncJobData = {
      ...baseJobData,
      topic:      'variant',
      action:     'delete',
      resourceId: '9506',
    };

    it('NO llama a resolveWebhookResource (el recurso ya no existe en Bsale)', async () => {
      mockSelectExecuteTakeFirstOrThrow.mockResolvedValueOnce(baseStore);
      mockFetch.mockResolvedValueOnce(okFetchResponse());

      await processWebhookEvent(deleteJobData, mockBsale);

      expect(mockResolveWebhookResource).not.toHaveBeenCalled();
    });

    it('despacha { topic, action, resourceId } directo al CMS', async () => {
      mockSelectExecuteTakeFirstOrThrow.mockResolvedValueOnce(baseStore);
      mockFetch.mockResolvedValueOnce(okFetchResponse());

      await processWebhookEvent(deleteJobData, mockBsale);

      const [, fetchOpts] = mockFetch.mock.calls[0];
      expect(JSON.parse(fetchOpts.body)).toEqual({ topic: 'variant', action: 'delete', resourceId: '9506' });
    });

    it('topic=stock con action=delete NO usa el atajo (no hay forma de resolver el variantId)', async () => {
      const stockDeleteJob: SyncJobData = { ...baseJobData, topic: 'stock', action: 'delete' };
      mockSelectExecuteTakeFirstOrThrow.mockResolvedValueOnce(baseStore);
      mockResolveWebhookResource.mockResolvedValueOnce({ topic: 'stock', data: null });

      await processWebhookEvent(stockDeleteJob, mockBsale);

      expect(mockResolveWebhookResource).toHaveBeenCalled();
    });
  });

  describe('stock v2 colección vacía', () => {
    it('no llama a fetch cuando resolveWebhookResource devuelve stock data=null', async () => {
      mockSelectExecuteTakeFirstOrThrow.mockResolvedValueOnce(baseStore);
      mockResolveWebhookResource.mockResolvedValueOnce({ topic: 'stock', data: null });

      await processWebhookEvent(baseJobData, mockBsale);

      expect(mockFetch).not.toHaveBeenCalled();
    });
  });

  describe('fallback bulk — price', () => {
    it('envía { entity: "prices" } cuando resolveWebhookResource devuelve data=null', async () => {
      const jobData = { ...baseJobData, topic: 'price', resourceUrl: '/v1/price_lists/1/details/456.json' };

      mockSelectExecuteTakeFirstOrThrow.mockResolvedValueOnce(baseStore);
      mockResolveWebhookResource.mockResolvedValueOnce({ topic: 'price', data: null });
      mockFetch.mockResolvedValueOnce(okFetchResponse(150));

      await processWebhookEvent(jobData, mockBsale);

      const [, fetchOpts] = mockFetch.mock.calls[0];
      expect(JSON.parse(fetchOpts.body)).toEqual({ entity: 'prices' });
    });
  });

  describe('#130: aviso de documento emitido (topic=document)', () => {
    it('NO se salta el dispatch aunque resolveWebhookResource devuelva data=null', async () => {
      const jobData = {
        ...baseJobData, topic: 'document', action: 'put', resourceId: '25',
        resourceUrl: '/v1/documents/25.json',
      };

      mockSelectExecuteTakeFirstOrThrow.mockResolvedValueOnce(baseStore);
      mockResolveWebhookResource.mockResolvedValueOnce({ topic: 'document', data: null });
      mockFetch.mockResolvedValueOnce(okFetchResponse(0));

      await processWebhookEvent(jobData, mockBsale);

      expect(mockFetch).toHaveBeenCalledTimes(1);
    });

    it('envía { topic, action, resourceId, send } sin bsaleData (no hay nada que resolver)', async () => {
      const jobData = {
        ...baseJobData, topic: 'document', action: 'put', resourceId: '25',
        resourceUrl: '/v1/documents/25.json', send: 1700000001,
      };

      mockSelectExecuteTakeFirstOrThrow.mockResolvedValueOnce(baseStore);
      mockResolveWebhookResource.mockResolvedValueOnce({ topic: 'document', data: null });
      mockFetch.mockResolvedValueOnce(okFetchResponse(0));

      await processWebhookEvent(jobData, mockBsale);

      const [, fetchOpts] = mockFetch.mock.calls[0];
      expect(JSON.parse(fetchOpts.body)).toEqual({
        topic: 'document', action: 'put', resourceId: '25', send: 1700000001,
      });
    });
  });

  describe('casos de error', () => {
    it('lanza error si cms_url no está configurada', async () => {
      mockSelectExecuteTakeFirstOrThrow.mockResolvedValueOnce({
        cms_url: null,
        cms_webhook_secret: 'secret',
      });

      await expect(processWebhookEvent(baseJobData, mockBsale))
        .rejects.toThrow('no tiene cms_url configurada');
    });

    it('lanza error si el CMS responde con status HTTP no-ok', async () => {
      mockSelectExecuteTakeFirstOrThrow.mockResolvedValueOnce(baseStore);
      mockResolveWebhookResource.mockResolvedValueOnce({ topic: 'stock', data: {} });
      mockFetch.mockResolvedValueOnce({ ok: false, status: 503 });

      await expect(processWebhookEvent(baseJobData, mockBsale))
        .rejects.toThrow('503');
    });

    it('lanza error si el CMS responde success=false', async () => {
      mockSelectExecuteTakeFirstOrThrow.mockResolvedValueOnce(baseStore);
      mockResolveWebhookResource.mockResolvedValueOnce({ topic: 'stock', data: {} });
      mockFetch.mockResolvedValueOnce({
        ok:   true,
        json: () => Promise.resolve({ success: false, message: 'Secret inválido' }),
      });

      await expect(processWebhookEvent(baseJobData, mockBsale))
        .rejects.toThrow('Secret inválido');
    });

    it('#93: 5xx del CMS lanza error genérico (transitorio → reintentar)', async () => {
      mockSelectExecuteTakeFirstOrThrow.mockResolvedValueOnce(baseStore);
      mockResolveWebhookResource.mockResolvedValueOnce({ topic: 'stock', data: {} });
      mockFetch.mockResolvedValueOnce({ ok: false, status: 503 });

      await expect(processWebhookEvent(baseJobData, mockBsale))
        .rejects.not.toBeInstanceOf(PermanentSyncError);
    });

    it('#93: 4xx del CMS lanza PermanentSyncError (permanente → descartar)', async () => {
      mockSelectExecuteTakeFirstOrThrow.mockResolvedValueOnce(baseStore);
      mockResolveWebhookResource.mockResolvedValueOnce({ topic: 'stock', data: {} });
      mockFetch.mockResolvedValueOnce({ ok: false, status: 422 });

      await expect(processWebhookEvent(baseJobData, mockBsale))
        .rejects.toBeInstanceOf(PermanentSyncError);
    });

    it('#93: success=false con retryable=false lanza PermanentSyncError', async () => {
      mockSelectExecuteTakeFirstOrThrow.mockResolvedValueOnce(baseStore);
      mockResolveWebhookResource.mockResolvedValueOnce({ topic: 'stock', data: {} });
      mockFetch.mockResolvedValueOnce({
        ok:   true,
        json: () => Promise.resolve({
          success: false, retryable: false, message: 'Variante no mapeada',
        }),
      });

      await expect(processWebhookEvent(baseJobData, mockBsale))
        .rejects.toBeInstanceOf(PermanentSyncError);
    });

    it('no llama a fetch si resourceUrl está vacío', async () => {
      await processWebhookEvent({ ...baseJobData, resourceUrl: undefined }, mockBsale);

      expect(mockFetch).not.toHaveBeenCalled();
    });
  });
});

describe('processManualSync (#55: sync manual disparado desde /v1/agency/clients/:id/sync)', () => {
  const manualJobData: SyncJobData = {
    storeId:    'store-uuid-1',
    tenantId:   'agencia-demo',
    syncType:   'manual',
    entityType: 'stock',
  };

  it('envía { entity } (sin topic/bsaleData) al webhook del CMS — mismo payload bulk que ya soporta webhook.php', async () => {
    mockSelectExecuteTakeFirstOrThrow.mockResolvedValueOnce(baseStore);
    mockFetch.mockResolvedValueOnce(okFetchResponse());

    await processManualSync(manualJobData);

    const [url, fetchOpts] = mockFetch.mock.calls[0];
    expect(url).toBe('https://tienda.cl/modules/synkrop/webhook.php');
    expect(JSON.parse(fetchOpts.body)).toEqual({ entity: 'stock' });
  });

  it('no llama a resolveWebhookResource — no hay recurso de Bsale que resolver', async () => {
    mockSelectExecuteTakeFirstOrThrow.mockResolvedValueOnce(baseStore);
    mockFetch.mockResolvedValueOnce(okFetchResponse());

    await processManualSync(manualJobData);

    expect(mockResolveWebhookResource).not.toHaveBeenCalled();
  });

  it('usa "products" como default cuando entityType no viene', async () => {
    mockSelectExecuteTakeFirstOrThrow.mockResolvedValueOnce(baseStore);
    mockFetch.mockResolvedValueOnce(okFetchResponse());

    await processManualSync({ ...manualJobData, entityType: undefined });

    const [, fetchOpts] = mockFetch.mock.calls[0];
    expect(JSON.parse(fetchOpts.body)).toEqual({ entity: 'products' });
  });

  it('envía X-Synkrop-Job-Id cuando se provee jobId', async () => {
    mockSelectExecuteTakeFirstOrThrow.mockResolvedValueOnce(baseStore);
    mockFetch.mockResolvedValueOnce(okFetchResponse());

    await processManualSync(manualJobData, 'manual_store-uuid-1_stock_1700000000000');

    const [, fetchOpts] = mockFetch.mock.calls[0];
    expect(fetchOpts.headers['X-Synkrop-Job-Id']).toBe('manual_store-uuid-1_stock_1700000000000');
  });

  it('lanza error si cms_url no está configurada', async () => {
    mockSelectExecuteTakeFirstOrThrow.mockResolvedValueOnce({ cms_url: null, cms_webhook_secret: 'secret' });

    await expect(processManualSync(manualJobData)).rejects.toThrow('no tiene cms_url configurada');
  });

  it('propaga PermanentSyncError si el CMS rechaza con 4xx (mismo contrato que el path de webhook)', async () => {
    mockSelectExecuteTakeFirstOrThrow.mockResolvedValueOnce(baseStore);
    mockFetch.mockResolvedValueOnce({ ok: false, status: 422 });

    await expect(processManualSync(manualJobData)).rejects.toBeInstanceOf(PermanentSyncError);
  });
});

describe('processPollingCycle (#79: diff contra bsale_variant_snapshots)', () => {
  // Mismo algoritmo que computeHash() en sync-worker.ts — para poder construir
  // en el test un snapshot que "matchea" o "no matchea" a propósito.
  const computeExpectedHash = (variant: Record<string, unknown>, quantityAvailable?: number): string => {
    const relevant = { code: variant['code'], cost: variant['cost'], quantity: variant['quantity'], state: variant['state'], quantityAvailable };
    return Buffer.from(JSON.stringify(relevant)).toString('base64');
  };

  // Fixture con forma real de Bsale (GET /v1/products.json?expand=[variants]).
  const bsaleVariantFixture = (overrides: Record<string, unknown> = {}) => ({
    id: 9001, code: 'POL-001', cost: 9990, quantity: 12, state: 1, description: 'Talla M',
    ...overrides,
  });
  const bsaleProductFixture = (variants: Array<Record<string, unknown>>) => ({
    id: 500, name: 'Polera Básica', description: '', state: 1,
    variants: { items: variants },
  });

  const pollJobData: SyncJobData = {
    storeId:  'store-uuid-1',
    tenantId: 'allgrano-cl',
    syncType: 'polling',
  };

  let mockBsaleGet: ReturnType<typeof vi.fn>;
  let bsalePolling: BsaleHttpClient;

  beforeEach(() => {
    mockBsaleGet = vi.fn();
    bsalePolling = { get: mockBsaleGet } as unknown as BsaleHttpClient;
  });

  // Fila de /v1/stocks.json (#133): es el UNICO lugar donde vive quantityAvailable.
  const bsaleStockFixture = (variantId: number, quantityAvailable: number) => ({
    id: 70000 + variantId, quantityAvailable, quantityReserved: 0,
    variant: { id: variantId, href: `https://api.bsale.io/v1/variants/${variantId}.json` },
  });

  // El ciclo hace: N paginas de products.json y despues N paginas de stocks.json.
  function onePageResponse(products: Array<Record<string, unknown>>, stocks: Array<Record<string, unknown>> = []) {
    mockBsaleGet.mockResolvedValueOnce({ items: products, count: products.length });
    mockBsaleGet.mockResolvedValueOnce({ items: stocks, count: stocks.length });
  }

  it('pagina hasta que una página viene incompleta', async () => {
    mockSelectExecuteTakeFirstOrThrow.mockResolvedValueOnce(baseStore);
    const fullPage = Array.from({ length: 50 }, (_, i) => bsaleProductFixture([bsaleVariantFixture({ id: 9000 + i })]));
    mockBsaleGet
      .mockResolvedValueOnce({ items: fullPage, count: 50 })      // pagina 1: llena -> sigue
      .mockResolvedValueOnce({ items: [], count: 0 })             // pagina 2: vacia -> corta
      .mockResolvedValueOnce({ items: [], count: 0 });            // #133: stocks.json
    mockSnapshotExecuteTakeFirst.mockResolvedValue(undefined);
    mockFetch.mockResolvedValue(okFetchResponse());

    await processPollingCycle(pollJobData, bsalePolling, 'store-uuid-1');

    const productCalls = mockBsaleGet.mock.calls.filter(c => String(c[0]).includes('/v1/products.json'));
    expect(productCalls).toHaveLength(2);
    expect(productCalls[0][0]).toContain('offset=0');
    expect(productCalls[1][0]).toContain('offset=50');
  });

  it('variante nueva (sin snapshot previo) se despacha al CMS y se guarda el snapshot', async () => {
    mockSelectExecuteTakeFirstOrThrow.mockResolvedValueOnce(baseStore);
    const variant = bsaleVariantFixture();
    onePageResponse([bsaleProductFixture([variant])]);
    mockSnapshotExecuteTakeFirst.mockResolvedValueOnce(undefined); // sin snapshot
    mockFetch.mockResolvedValueOnce(okFetchResponse());

    await processPollingCycle(pollJobData, bsalePolling, 'store-uuid-1');

    expect(mockFetch).toHaveBeenCalledTimes(1);
    const [, fetchOpts] = mockFetch.mock.calls[0];
    const body = JSON.parse(fetchOpts.body);
    expect(body.topic).toBe('variant');
    expect(body.bsaleData.id).toBe(variant.id);
    expect(body.bsaleData.product.id).toBe(500); // producto padre incluido
    expect(mockInsertExecute).toHaveBeenCalledTimes(1);
  });

  it('variante sin cambios (mismo hash) NO se despacha', async () => {
    mockSelectExecuteTakeFirstOrThrow.mockResolvedValueOnce(baseStore);
    const variant = bsaleVariantFixture();
    onePageResponse([bsaleProductFixture([variant])]);
    mockSnapshotExecuteTakeFirst.mockResolvedValueOnce({ content_hash: computeExpectedHash(variant) });

    await processPollingCycle(pollJobData, bsalePolling, 'store-uuid-1');

    expect(mockFetch).not.toHaveBeenCalled();
    expect(mockInsertExecute).not.toHaveBeenCalled();
  });

  it('variante con hash distinto al snapshot se despacha (cambio real detectado)', async () => {
    mockSelectExecuteTakeFirstOrThrow.mockResolvedValueOnce(baseStore);
    const variant = bsaleVariantFixture({ quantity: 3 }); // cambio de stock respecto al snapshot
    onePageResponse([bsaleProductFixture([variant])]);
    mockSnapshotExecuteTakeFirst.mockResolvedValueOnce({ content_hash: computeExpectedHash(bsaleVariantFixture({ quantity: 12 })) });
    mockFetch.mockResolvedValueOnce(okFetchResponse());

    await processPollingCycle(pollJobData, bsalePolling, 'store-uuid-1');

    expect(mockFetch).toHaveBeenCalledTimes(1);
    expect(mockInsertExecute).toHaveBeenCalledTimes(1);
  });

  it('si el dispatch de una variante falla, no rompe el ciclo y no actualiza su snapshot (se reintenta el próximo ciclo)', async () => {
    mockSelectExecuteTakeFirstOrThrow.mockResolvedValueOnce(baseStore);
    const variantA = bsaleVariantFixture({ id: 1 });
    const variantB = bsaleVariantFixture({ id: 2 });
    onePageResponse([bsaleProductFixture([variantA, variantB])]);
    mockSnapshotExecuteTakeFirst.mockResolvedValue(undefined);
    mockFetch
      .mockResolvedValueOnce({ ok: false, status: 503 }) // variante A falla (transitorio)
      .mockResolvedValueOnce(okFetchResponse());          // variante B ok

    await expect(processPollingCycle(pollJobData, bsalePolling, 'store-uuid-1')).resolves.toBeUndefined();

    expect(mockFetch).toHaveBeenCalledTimes(2);
    expect(mockInsertExecute).toHaveBeenCalledTimes(1); // solo la que sí se pudo despachar
  });

  // ── #133: stock reservado ───────────────────────────────────────────────────
  // Bsale reserva al crear una nota de venta y `quantity` NO se mueve
  // (sandbox: quantity=86 reserved=8 available=78 -> reserved=86 available=0).
  // Si el hash solo mira `quantity`, el polling no detecta la reserva y las otras
  // tiendas del mismo Bsale siguen vendiendo -> sobreventa.
  describe('#133: cambios de stock reservado', () => {
    it('mismo quantity pero distinto quantityAvailable => hash distinto => se despacha', async () => {
      mockSelectExecuteTakeFirstOrThrow.mockResolvedValueOnce(baseStore);
      const variant = bsaleVariantFixture({ id: 9001, quantity: 86 });
      onePageResponse([bsaleProductFixture([variant])], [bsaleStockFixture(9001, 0)]);
      // snapshot guardado cuando available=78: mismo quantity=86, hash del algoritmo VIEJO
      // (sin quantityAvailable) — si se revierte el fix, este hash matchea y no hay dispatch.
      mockSnapshotExecuteTakeFirst.mockResolvedValueOnce({ content_hash: computeExpectedHash(variant) });
      mockFetch.mockResolvedValueOnce(okFetchResponse());

      await processPollingCycle(pollJobData, bsalePolling, 'store-uuid-1');

      expect(mockFetch).toHaveBeenCalledTimes(1);
      const body = JSON.parse(mockFetch.mock.calls[0][1].body);
      expect(body.bsaleData.quantity).toBe(86);          // el fisico sigue igual
      expect(body.bsaleData.quantityAvailable).toBe(0);  // lo que el plugin va a preferir
      expect(mockInsertExecute).toHaveBeenCalledTimes(1);
    });

    it('variante sin fila de stock: NO manda quantityAvailable y conserva el hash previo', async () => {
      mockSelectExecuteTakeFirstOrThrow.mockResolvedValueOnce(baseStore);
      const variant = bsaleVariantFixture({ id: 9002 });
      onePageResponse([bsaleProductFixture([variant])], [bsaleStockFixture(7777, 5)]); // stock de otra variante
      mockSnapshotExecuteTakeFirst.mockResolvedValueOnce({ content_hash: computeExpectedHash(variant) });

      await processPollingCycle(pollJobData, bsalePolling, 'store-uuid-1');

      // sin fila de stock el hash no cambia -> no se re-despacha, y nunca se manda 0 (#101)
      expect(mockFetch).not.toHaveBeenCalled();
    });

    it('suma las filas por sucursal y resuelve el variantId por href si falta el id', async () => {
      mockSelectExecuteTakeFirstOrThrow.mockResolvedValueOnce(baseStore);
      const variant = bsaleVariantFixture({ id: 9003 });
      const soloHref = {
        id: 71, quantityAvailable: 4, quantityReserved: 0,
        variant: { href: 'https://api.bsale.io/v1/variants/9003.json' },
      };
      onePageResponse([bsaleProductFixture([variant])], [bsaleStockFixture(9003, 6), soloHref]);
      mockSnapshotExecuteTakeFirst.mockResolvedValueOnce(undefined);
      mockFetch.mockResolvedValueOnce(okFetchResponse());

      await processPollingCycle(pollJobData, bsalePolling, 'store-uuid-1');

      expect(JSON.parse(mockFetch.mock.calls[0][1].body).bsaleData.quantityAvailable).toBe(10);
    });

    // Verificado contra el sandbox real: los dos endpoints tipan el id DISTINTO.
    //   products.json?expand=[variants] -> variant.id = 9004   (number)
    //   stocks.json                     -> variant.id = '9004' (string)
    // Sin normalizar con Number(), el Map queda con claves string, Map.get(9004)
    // devuelve undefined y quantityAvailable no llega nunca: el fix no hace nada,
    // en silencio. Los fixtures de arriba usan number en ambos lados, asi que este
    // caso solo se ve con el tipo real — por eso el test va aparte.
    it('resuelve el variantId aunque stocks.json lo devuelva como string (tipo real de Bsale)', async () => {
      mockSelectExecuteTakeFirstOrThrow.mockResolvedValueOnce(baseStore);
      const variant = bsaleVariantFixture({ id: 9004, quantity: 86 });
      const filaComoLaMandaBsale = {
        id: 79004, quantityAvailable: 0, quantityReserved: 86,
        variant: { id: '9004', href: 'https://api.bsale.io/v1/variants/9004.json' },
      };
      onePageResponse([bsaleProductFixture([variant])], [filaComoLaMandaBsale]);
      mockSnapshotExecuteTakeFirst.mockResolvedValueOnce({ content_hash: computeExpectedHash(variant) });
      mockFetch.mockResolvedValueOnce(okFetchResponse());

      await processPollingCycle(pollJobData, bsalePolling, 'store-uuid-1');

      expect(mockFetch).toHaveBeenCalledTimes(1);
      expect(JSON.parse(mockFetch.mock.calls[0][1].body).bsaleData.quantityAvailable).toBe(0);
    });
  });

  it('lanza error si la tienda no tiene cms_url configurada', async () => {
    mockSelectExecuteTakeFirstOrThrow.mockResolvedValueOnce({ cms_url: null, cms_webhook_secret: 'secret' });

    await expect(processPollingCycle(pollJobData, bsalePolling, 'store-uuid-1'))
      .rejects.toThrow('no tiene cms_url configurada');
  });
});

describe('handleJobFailed (hallazgo 23-jul: dead-letter no debe poder tumbar el proceso)', () => {
  const deadLetterJob = {
    id: 'job-1',
    attemptsMade: 5,
    opts: { attempts: 5 },
    data: { tenantId: 'allgrano-cl', storeId: 'store-uuid-1', syncType: 'polling', entityType: 'stock' },
  } as unknown as Job<SyncJobData>;

  it('no hace nada si el job todavia tiene reintentos pendientes (no es dead-letter)', async () => {
    const job = { ...deadLetterJob, attemptsMade: 2, opts: { attempts: 5 } } as unknown as Job<SyncJobData>;

    await handleJobFailed(job, new Error('timeout transitorio'));

    expect(mockInsertExecute).not.toHaveBeenCalled();
  });

  it('no hace nada si job es undefined', async () => {
    await handleJobFailed(undefined, new Error('no deberia importar'));
    expect(mockInsertExecute).not.toHaveBeenCalled();
  });

  it('registra el dead-letter cuando se agotaron los reintentos', async () => {
    await handleJobFailed(deadLetterJob, new Error('Bsale API 500'));

    expect(mockInsertExecute).toHaveBeenCalledTimes(1);
  });

  // #115-bis: el hallazgo real de hoy -- si el INSERT mismo falla (ej. violacion
  // de constraint), antes esto era un unhandled rejection que tumbaba bot-miki
  // completo. Ahora debe quedar contenido acá.
  it('no relanza si el INSERT del dead-letter falla (constraint, conexion, etc.)', async () => {
    mockInsertExecute.mockRejectedValueOnce(new Error('violates check constraint "sync_events_sync_type_check"'));
    const consoleErrorSpy = vi.spyOn(console, 'error').mockImplementation(() => {});

    await expect(handleJobFailed(deadLetterJob, new Error('Bsale API 500'))).resolves.toBeUndefined();

    expect(consoleErrorSpy).toHaveBeenCalled();
    consoleErrorSpy.mockRestore();
  });
});
