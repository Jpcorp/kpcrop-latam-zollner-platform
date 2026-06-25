import type { BsaleHttpClient } from '../infrastructure/bsale-http-client.js';

// ── Tipos crudos de la API de Bsale ──────────────────────────────────────────

export interface BsaleStockRaw {
  id: number;
  quantityAvailable: number;
  quantityReserved: number;
  variant: { id: number; href: string };
  office?: { id: number; name: string };
}

// Bsale v2 envía stocks como colección de búsqueda, no como recurso individual
interface BsaleStockV2Item {
  variantId: number;
  quantityAvailable: number;
  quantityReserved: number;
  office?: { id: number; href: string };
}
interface BsaleStockV2Collection {
  count: number;
  data: BsaleStockV2Item[];
}

export interface BsaleVariantRaw {
  id: number;
  description: string;
  code: string;
  barCode?: string;
  cost: number;
  price?: number;
  quantity: number;
  state: number;
  product?: {
    id: number;
    name: string;
    description?: string;
    state: number;
  };
}

export interface BsaleProductRaw {
  id: number;
  name: string;
  description?: string;
  state: number;
  variants?: {
    items: BsaleVariantRaw[];
    count: number;
  };
}

// ── Payload tipado por topic ──────────────────────────────────────────────────

export type BsaleWebhookPayload =
  | { topic: 'stock';   data: BsaleStockRaw | null }  // null = colección v2 vacía
  | { topic: 'variant'; data: BsaleVariantRaw }
  | { topic: 'product'; data: BsaleProductRaw }
  | { topic: 'price';   data: null };  // price no tiene endpoint directo: fallback a bulk

/**
 * Obtiene el recurso concreto de Bsale para un webhook dado.
 * El resultado se envía al plugin CMS para sync quirúrgico (solo ese recurso).
 *
 * Para topic=price no hay endpoint único accesible sin el ID de lista de precios
 * (configurado en el plugin), así que devuelve data=null y el plugin hace bulk de precios.
 */
export async function resolveWebhookResource(
  bsale: BsaleHttpClient,
  topic: string,
  resourceUrl: string,
): Promise<BsaleWebhookPayload> {
  switch (topic) {
    case 'stock': {
      const raw = await bsale.get<BsaleStockRaw | BsaleStockV2Collection>(resourceUrl);
      // Bsale v2 retorna una colección {count, data:[...]}; v1 retorna un recurso individual {id, variant:{...}}
      if ('data' in raw && Array.isArray((raw as BsaleStockV2Collection).data)) {
        const col = raw as BsaleStockV2Collection;
        if (col.count === 0 || col.data.length === 0) return { topic: 'stock', data: null };
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const item = col.data[0] as any;
        // La API v2 puede usar variantId, variant_id, o variant.id según versión
        const variantId: number =
          item.variantId ?? item.variant_id ?? item.variant?.id ??
          parseInt(String(item.variant?.href ?? '').match(/\/(\d+)\.json/)?.[1] ?? '0', 10) ?? 0;
        console.log(`[resolver:stock-v2] item.variantId=${item.variantId} variant_id=${item.variant_id} variant.id=${item.variant?.id} → variantId=${variantId}`);
        return {
          topic: 'stock',
          data: {
            id: 0,
            quantityAvailable: item.quantityAvailable,
            quantityReserved: item.quantityReserved ?? 0,
            variant: { id: variantId, href: item.variant?.href ?? '' },
            office: item.office ? { id: item.office.id, name: item.office.name ?? '' } : undefined,
          },
        };
      }
      // v1 recurso individual: variant puede tener solo href sin id explícito
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      const stock = raw as any;
      const explicitId: number = stock.variant?.id ?? 0;
      const hrefId: number = explicitId === 0
        ? parseInt(String(stock.variant?.href ?? '').match(/\/(\d+)\.json/)?.[1] ?? '0', 10)
        : 0;
      const variantId = explicitId || hrefId;
      console.log(`[resolver:stock-v1] stockId=${stock.id} variant.id=${explicitId} href=${stock.variant?.href} → variantId=${variantId}`);
      return {
        topic: 'stock',
        data: { ...stock, variant: { id: variantId, href: stock.variant?.href ?? '' } } as BsaleStockRaw,
      };
    }
    case 'variant': {
      const url = resourceUrl.includes('?')
        ? `${resourceUrl}&expand=[product]`
        : `${resourceUrl}?expand=[product]`;
      const data = await bsale.get<BsaleVariantRaw>(url);
      return { topic: 'variant', data };
    }
    case 'product': {
      const url = resourceUrl.includes('?')
        ? `${resourceUrl}&expand=[variants]`
        : `${resourceUrl}?expand=[variants]`;
      const data = await bsale.get<BsaleProductRaw>(url);
      return { topic: 'product', data };
    }
    default:
      return { topic: 'price', data: null };
  }
}
