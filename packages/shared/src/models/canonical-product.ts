// Modelo canonico de producto — fuente de verdad entre Bsale y todos los CMS
// Ver ADR-001 para la decision de diseño
import { z } from 'zod';

// #36: PriceSchema sin .refine() a proposito — CanonicalVariantSchema necesita
// .partial() sobre esta forma (precio parcial por variante), y .refine()
// devuelve un ZodEffects que no tiene .partial(). El cross-check
// gross >= net se aplica aparte, solo donde el precio es obligatorio
// (CanonicalProductSchema), via PriceSchemaValidated mas abajo.
const PriceSchema = z.object({
  // #36: positivo (no nonnegative) — un producto con precio neto 0 es un dato
  // corrupto de Bsale, no un caso valido de negocio.
  net: z.number().positive(),
  gross: z.number().nonnegative(),
  currency: z.enum(['CLP', 'USD', 'ARS', 'MXN', 'PEN', 'COP']),
  // 0.19 para IVA Chile; 0 para exento
  taxRate: z.number().min(0).max(1),
  priceListId: z.number().int().positive().optional(),
});

// #36: gross (precio con impuesto) nunca puede ser menor que net (precio sin
// impuesto) — sería un IVA negativo, dato corrupto.
const PriceSchemaValidated = PriceSchema.refine((p) => p.gross >= p.net, {
  message: 'price.gross debe ser mayor o igual a price.net',
  path: ['gross'],
});

// #36: quantity puede ser negativo (backorder) SOLO si allowNegative es true
// — refine en vez de un .nonnegative() ciego, para no romper ese caso de
// negocio legitimo que el propio schema ya modela con el campo allowNegative.
const StockSchema = z.object({
  quantity: z.number().int(),
  allowNegative: z.boolean(),
  // undefined = stock sumado de todas las sucursales
  officeId: z.number().int().positive().optional(),
}).refine((s) => s.allowNegative || s.quantity >= 0, {
  message: 'stock.quantity no puede ser negativo salvo que allowNegative sea true',
  path: ['quantity'],
});

const CategorySchema = z.object({
  id: z.number().int().positive(),
  name: z.string().min(1),
  // ej. ['Electronica', 'Computacion']
  path: z.array(z.string()).optional(),
});

const ImageSchema = z.object({
  url: z.string().url(),
  isPrimary: z.boolean(),
  order: z.number().int().nonnegative(),
});

export const CanonicalVariantSchema = z.object({
  bsaleId: z.number().int().positive(),
  // SKU de la variante — clave de idempotencia en todos los CMS
  code: z.string().min(1),
  // ej. { color: 'Rojo', talla: 'M' }
  attributes: z.record(z.string(), z.string()),
  price: PriceSchema.partial().optional(),
  stock: StockSchema,
  barcode: z.string().optional(),
});

export const CanonicalProductSchema = z.object({
  bsaleId: z.number().int().positive(),
  // SKU de la variante — clave de idempotencia en todos los CMS
  code: z.string().min(1),
  name: z.string().min(1),
  description: z.string().optional(),
  status: z.enum(['active', 'inactive']),
  price: PriceSchemaValidated,
  stock: StockSchema,
  category: CategorySchema.optional(),
  brand: z.string().optional(),
  variants: z.array(CanonicalVariantSchema).optional(),
  images: z.array(ImageSchema).optional(),
  bsaleUpdatedAt: z.date(),
  syncedAt: z.date().optional(),
});

// Factory para validar respuestas paginadas de Bsale con cualquier item schema
export const bsalePaginatedResponseSchema = <T extends z.ZodTypeAny>(itemSchema: T) =>
  z.object({
    href: z.string().url(),
    count: z.number().int().nonnegative(),
    limit: z.number().int().positive(),
    offset: z.number().int().nonnegative(),
    items: z.array(itemSchema),
    next: z.string().url().optional(),
  });

export type CanonicalProduct = z.infer<typeof CanonicalProductSchema>;
export type CanonicalVariant = z.infer<typeof CanonicalVariantSchema>;

// Interfaz generica mantenida para compatibilidad con TypeScript sin schema
export interface BsalePaginatedResponse<T> {
  href: string;
  count: number;
  limit: number;
  offset: number;
  items: T[];
  next?: string;
}
