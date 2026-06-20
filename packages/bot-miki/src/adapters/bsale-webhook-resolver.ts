import type { BsaleHttpClient } from '../infrastructure/bsale-http-client.js';

// ── Tipos crudos de la API de Bsale ──────────────────────────────────────────

export interface BsaleStockRaw {
  id: number;
  quantityAvailable: number;
  quantityReserved: number;
  variant: { id: number; href: string };
  office?: { id: number; name: string };
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
  | { topic: 'stock';   data: BsaleStockRaw }
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
      const data = await bsale.get<BsaleStockRaw>(resourceUrl);
      return { topic: 'stock', data };
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
