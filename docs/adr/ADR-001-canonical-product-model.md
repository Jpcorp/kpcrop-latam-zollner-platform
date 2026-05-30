# ADR-001 — Canonical Product Model

**Estado:** Aceptado — implementado en `packages/shared`  
**Fecha:** 2026-05-22  
**Autores:** Equipo kpcrop-latam  

---

## Contexto

La plataforma sincroniza productos entre Bsale y 6 CMS distintos (WordPress, PrestaShop, Shopify, WooCommerce, Magento, Jumpseller). Cada CMS tiene su propio modelo de datos para un "producto": diferentes nombres de campo, tipos, estructuras de variantes, y representaciones de precio/stock.

Sin un modelo canonico compartido, cada adapter de CMS que se construya inventara su propia traduccion directa desde Bsale, resultando en:
- Inconsistencias entre como WordPress y Shopify interpretan un producto Bsale
- Imposibilidad de reutilizar logica de validacion entre adapters
- Bugs silenciosos cuando Bsale cambia un campo (el cambio rompe un adapter pero no los demas)

## Decision

Definir un **Canonical Product Model** en `packages/shared` que actua como el lenguaje interno de la plataforma. Todos los adapters CMS traducen **desde Bsale → Canonical** y luego **desde Canonical → CMS especifico**. Ningun adapter traduce directamente de Bsale al CMS.

```
Bsale API → [BsaleAdapter] → CanonicalProduct → [CmsAdapter] → CMS
```

El Canonical Product Model vive en `packages/shared/src/models/canonical-product.ts` y es el unico punto de verdad tipado para el concepto de "producto" en la plataforma.

---

## Canonical Product Model

> **Nota de implementacion:** El modelo se implemento con **Zod** (no con TypeScript interfaces simples) para obtener validacion en runtime ademas de tipado estatico. La alternativa Zod fue "considerada para v2" en el borrador original, pero se adopto en la implementacion inicial al verificar que el costo es bajo y el beneficio inmediato.

```typescript
// packages/shared/src/models/canonical-product.ts (implementacion real)

export const CanonicalProductSchema = z.object({
  bsaleId: z.number().int().positive(),
  code: z.string().min(1),        // SKU — clave de idempotencia en todos los CMS
  name: z.string().min(1),
  description: z.string().optional(),
  status: z.enum(['active', 'inactive']),

  price: z.object({
    net: z.number().nonnegative(),
    gross: z.number().nonnegative(),
    currency: z.enum(['CLP', 'USD', 'ARS', 'MXN', 'PEN', 'COP']),
    taxRate: z.number().min(0).max(1),  // 0.19 para IVA Chile; 0 para exento
    priceListId: z.number().int().positive().optional(),
  }),

  stock: z.object({
    quantity: z.number().int(),
    allowNegative: z.boolean(),
    officeId: z.number().int().positive().optional(),  // undefined = todas las sucursales
  }),

  category: z.object({
    id: z.number().int().positive(),
    name: z.string().min(1),
    path: z.array(z.string()).optional(),  // ['Electronica', 'Computacion']
  }).optional(),

  brand: z.string().optional(),
  variants: z.array(CanonicalVariantSchema).optional(),
  images: z.array(z.object({
    url: z.string().url(),
    isPrimary: z.boolean(),
    order: z.number().int().nonnegative(),
  })).optional(),

  bsaleUpdatedAt: z.date(),
  syncedAt: z.date().optional(),
});

export type CanonicalProduct = z.infer<typeof CanonicalProductSchema>;

export const CanonicalVariantSchema = z.object({
  bsaleId: z.number().int().positive(),
  code: z.string().min(1),
  attributes: z.record(z.string(), z.string()),  // {color: 'Rojo', talla: 'M'}
  price: PriceSchema.partial().optional(),         // Override del precio base
  stock: StockSchema,
  barcode: z.string().optional(),
});
```

**Diferencias respecto al borrador original:**
- `stock.ledgerAccount` eliminado — no se usa en el sync actual y Bsale no lo expone directamente
- `stock.officeId` reemplaza la idea de filtro por sucursal
- `bsalePaginatedResponseSchema<T>` agregado como factory generico para respuestas paginadas de Bsale

---

## Mapeos por CMS

Cada adapter implementa la interfaz `CmsAdapter`:

```typescript
// packages/shared/src/adapters/cms-adapter.interface.ts (implementacion real)

export interface CmsAdapter<TCmsProduct = unknown> {
  fromBsale(bsaleProduct: unknown): CanonicalProduct;   // unknown: Bsale cambia sin previo aviso
  toCms(canonical: CanonicalProduct): TCmsProduct;
  idempotencyKey(canonical: CanonicalProduct): string;
}

export interface SyncResult {
  updated: number;
  failed: number;
  errors: Array<{ code: string; message: string }>;
  durationMs: number;
}
```

Cada `packages/cms-*` implementa su propio `CmsAdapter`. Los adapters viven en el package del CMS, no en `shared`.

---

## Consecuencias

**Positivas:**
- Cambio en Bsale API → solo se actualiza `BsaleAdapter`, todos los CMS se benefician
- Validaciones del modelo (precio > 0, stock >= 0, code no vacio) se escriben una vez en `shared`
- Tests de integracion pueden usar fixtures del `CanonicalProduct` sin instanciar un CMS real

**Negativas:**
- Agrega un paso de traduccion adicional (Bsale → Canonical → CMS) vs traduccion directa
- El `CanonicalProduct` puede no capturar todos los campos de un CMS especifico — los campos extras se manejan via `extensions?: Record<string, unknown>` por adapter

**Neutral:**
- Los campos del `CanonicalProduct` deben ser un subconjunto de lo que todos los CMS pueden representar. Si un CMS no tiene un campo, ese campo se ignora en el adapter de ese CMS.

---

## Alternativas Consideradas

**Traduccion directa Bsale → CMS por adapter:** Rechazada. Escala mal — 6 CMS × N campos = 6N puntos de fallo independientes.

**Schema con JSON Schema / Zod en lugar de TypeScript interface:** Adoptada en v1 (no diferida a v2). El costo de adopcion fue nulo — Zod ya era dependencia de `bot-miki` via `config.ts`. La validacion en runtime se usa en `processWebhookEvent` para verificar respuestas de Bsale antes de pasarlas al adapter.
