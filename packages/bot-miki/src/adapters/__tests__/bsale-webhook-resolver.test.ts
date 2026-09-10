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

    // ── #136: el resolver toma data[0] sin validar la variante ni sumar ───────
    // Estos dos van con it.fails() A PROPOSITO: documentan el comportamiento roto
    // y pasan mientras el bug siga vivo, sin dejar el CI en rojo. Cuando alguien
    // arregle resolveWebhookResource, EMPIEZAN A FALLAR — ahi hay que sacarles el
    // `.fails` y quedan como los tests de regresion definitivos.

    it.fails('multi-sucursal: suma el stock de todas las oficinas (hoy toma solo la primera)', async () => {
      // stocks.json devuelve UNA FILA POR SUCURSAL. El polling ya las suma
      // (sync-worker.ts, fix #133); el resolver de webhooks no.
      vi.mocked(mockBsale.get).mockResolvedValueOnce({
        count: 2,
        data: [
          { variantId: 9506, quantityAvailable: 5, quantityReserved: 0, office: { id: 1 } },
          { variantId: 9506, quantityAvailable: 3, quantityReserved: 0, office: { id: 2 } },
        ],
      });

      const result = await resolveWebhookResource(mockBsale, 'stock', '/v2/stocks.json?variantid=9506');

      // Lo correcto es 8 (5 + 3), que es lo que escribiria el polling para la
      // misma variante. Hoy devuelve 5: el CMS publica el stock de una sucursal.
      expect((result.data as any)?.quantityAvailable).toBe(8);
    });

    it.fails('descarta la coleccion si la variante no es la que pidio el resourceUrl', async () => {
      // Si el filtro se ignora, Bsale devuelve la coleccion entera y data[0] es
      // una variante cualquiera del catalogo. El resolver la despacha igual y el
      // plugin le escribe el stock — a la variante equivocada, sin un solo error.
      vi.mocked(mockBsale.get).mockResolvedValueOnce({
        count: 2983,
        data: [
          { variantId: 9506, quantityAvailable: 0, quantityReserved: 0 },
          { variantId: 8721, quantityAvailable: 42, quantityReserved: 0 },
        ],
      });

      const result = await resolveWebhookResource(mockBsale, 'stock', '/v2/stocks.json?variantid=8721');

      // Se pidio la 8721: devolver la 9506 es peor que no devolver nada.
      expect((result.data as any)?.variant.id).not.toBe(9506);
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

  describe('topic=document (#130: aviso de emisión, sin datos que resolver)', () => {
    it('devuelve data=null sin llamar a la API', async () => {
      const result = await resolveWebhookResource(mockBsale, 'document', '/v1/documents/25.json');

      expect(result).toEqual({ topic: 'document', data: null });
      expect(mockBsale.get).not.toHaveBeenCalled();
    });
  });

  describe('topic desconocido', () => {
    it('devuelve price/null como fallback', async () => {
      const result = await resolveWebhookResource(mockBsale, 'client', '/v1/clients/1.json');

      expect(result).toEqual({ topic: 'price', data: null });
      expect(mockBsale.get).not.toHaveBeenCalled();
    });
  });
});
