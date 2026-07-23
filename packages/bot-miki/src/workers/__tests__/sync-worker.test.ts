import { describe, it, expect, vi, beforeEach } from 'vitest';
import type { BsaleHttpClient } from '../../infrastructure/bsale-http-client.js';
import type { SyncJobData } from '../sync-worker.js';

// ── Hoisted mocks ─────────────────────────────────────────────────────────────

const {
  mockSelectExecuteTakeFirstOrThrow,
  mockInsertExecute,
  mockUpdateExecute,
  mockResolveWebhookResource,
} = vi.hoisted(() => ({
  mockSelectExecuteTakeFirstOrThrow: vi.fn(),
  mockInsertExecute:                 vi.fn().mockResolvedValue([]),
  mockUpdateExecute:                 vi.fn().mockResolvedValue([]),
  mockResolveWebhookResource:        vi.fn(),
}));

vi.mock('../../config.js', () => ({
  config: {
    NODE_ENV: 'test',
    REDIS_URL: 'redis://localhost:6379',
    BSALE_RATE_LIMIT_RPS: 10,
  },
}));

vi.mock('../../infrastructure/database.js', () => {
  const selectChain = {
    selectAll: () => selectChain,
    select:    () => selectChain,
    where:     () => selectChain,
    executeTakeFirstOrThrow: mockSelectExecuteTakeFirstOrThrow,
  };
  const updateChain = {
    set:     () => updateChain,
    where:   () => updateChain,
    execute: mockUpdateExecute,
  };
  const insertChain = {
    values:  () => ({ execute: mockInsertExecute }),
  };
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

const { processWebhookEvent, PermanentSyncError } = await import('../sync-worker.js');

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
