import type { CanonicalProduct } from '../models/canonical-product.js';

// Cada packages/cms-* implementa esta interfaz
export interface CmsAdapter<TCmsProduct = unknown> {
  // Traduce la respuesta cruda de Bsale al modelo canonico
  fromBsale(bsaleProduct: unknown): CanonicalProduct;

  // Traduce el modelo canonico al formato nativo del CMS
  toCms(canonical: CanonicalProduct): TCmsProduct;

  // Campo que identifica el producto en el CMS (para upsert idempotente)
  idempotencyKey(canonical: CanonicalProduct): string;
}

export interface SyncResult {
  updated: number;
  failed: number;
  errors: Array<{ code: string; message: string }>;
  durationMs: number;
}
