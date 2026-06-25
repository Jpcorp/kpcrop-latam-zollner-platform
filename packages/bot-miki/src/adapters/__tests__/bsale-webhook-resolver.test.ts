import { describe, it, expect, vi, beforeEach } from 'vitest';
import { resolveWebhookResource } from '../bsale-webhook-resolver.js';
import type { BsaleHttpClient } from '../../infrastructure/bsale-http-client.js';

const mockBsale = {
  get: vi.fn(),
} as unknown as BsaleHttpClient;

beforeEach(() => vi.clearAllMocks());

describe('resolveWebhookResource', () => {
  describe('topic=stock', () => {
    it('v1 con variant.id explícito — lo usa directamente', async () => {
      const stockData = {
        id: 1,
        quantityAvailable: 5,
        quantityReserved: 0,
        variant: { id: 9506, href: 'https://api.bsale.io/v1/variants/9506.json' },
        office: { id: 1, name: 'Casa Matriz' },
      };
      vi.mocked(mockBsale.get).mockResolvedValueOnce(stockData);

      const result = await resolveWebhookResource(mockBsale, 'stock', '/v1/stocks/1.json');

      expect(result.topic).toBe('stock');
      expect((result.data as any)?.variant.id).toBe(9506);
      expect(mockBsale.get).toHaveBeenCalledWith('/v1/stocks/1.json');
    });

    it('v1 sin variant.id pero con variant.href — extrae ID del href', async () => {
      vi.mocked(mockBsale.get).mockResolvedValueOnce({
        id: 14488,
        quantityAvailable: 3,
        quantityReserved: 0,
        variant: { href: '/v1/variants/9506.json' },  // sin id explícito
      });

      const result = await resolveWebhookResource(mockBsale, 'stock', '/v1/stocks/14488.json');

      expect(result.topic).toBe('stock');
      expect((result.data as any)?.variant.id).toBe(9506);
    });

    it('v2 collection con variantId — normaliza a variant.id', async () => {
      vi.mocked(mockBsale.get).mockResolvedValueOnce({
        count: 1,
        data: [{ variantId: 9506, quantityAvailable: 5, quantityReserved: 0 }],
      });

      const result = await resolveWebhookResource(mockBsale, 'stock', '/v1/stocks/14488.json');

      expect(result.topic).toBe('stock');
      expect((result.data as any)?.variant.id).toBe(9506);
    });

    it('v2 collection vacía — devuelve data=null', async () => {
      vi.mocked(mockBsale.get).mockResolvedValueOnce({ count: 0, data: [] });

      const result = await resolveWebhookResource(mockBsale, 'stock', '/v1/stocks/14488.json');

      expect(result).toEqual({ topic: 'stock', data: null });
    });
  });

  describe('topic=variant', () => {
    it('agrega expand=[product] al URL y devuelve BsaleVariantRaw', async () => {
      const variantData = {
        id: 9506,
        description: 'Color Rojo',
        code: 'GLA2014',
        cost: 1000,
        quantity: 10,
        state: 0,
        product: { id: 952, name: 'Producto X', state: 0 },
      };
      vi.mocked(mockBsale.get).mockResolvedValueOnce(variantData);

      const result = await resolveWebhookResource(mockBsale, 'variant', '/v1/variants/9506.json');

      expect(result).toEqual({ topic: 'variant', data: variantData });
      expect(mockBsale.get).toHaveBeenCalledWith('/v1/variants/9506.json?expand=[product]');
    });

    it('concatena expand correctamente si el URL ya tiene query params', async () => {
      vi.mocked(mockBsale.get).mockResolvedValueOnce({});

      await resolveWebhookResource(mockBsale, 'variant', '/v1/variants/9506.json?state=0');

      expect(mockBsale.get).toHaveBeenCalledWith('/v1/variants/9506.json?state=0&expand=[product]');
    });
  });

  describe('topic=product', () => {
    it('agrega expand=[variants] al URL y devuelve BsaleProductRaw', async () => {
      const productData = {
        id: 952,
        name: 'Producto X',
        state: 0,
        variants: { items: [{ id: 9506, code: 'GLA2014', cost: 1000, quantity: 3, state: 0, description: '' }], count: 1 },
      };
      vi.mocked(mockBsale.get).mockResolvedValueOnce(productData);

      const result = await resolveWebhookResource(mockBsale, 'product', '/v1/products/952.json');

      expect(result).toEqual({ topic: 'product', data: productData });
      expect(mockBsale.get).toHaveBeenCalledWith('/v1/products/952.json?expand=[variants]');
    });
  });

  describe('topic=price', () => {
    it('devuelve data=null sin llamar a la API (fallback a bulk)', async () => {
      const result = await resolveWebhookResource(mockBsale, 'price', '/v1/price_lists/1/details/456.json');

      expect(result).toEqual({ topic: 'price', data: null });
      expect(mockBsale.get).not.toHaveBeenCalled();
    });
  });

  describe('topic desconocido', () => {
    it('devuelve price/null como fallback', async () => {
      const result = await resolveWebhookResource(mockBsale, 'document', '/v1/documents/1.json');

      expect(result).toEqual({ topic: 'price', data: null });
      expect(mockBsale.get).not.toHaveBeenCalled();
    });
  });
});
