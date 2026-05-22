# ADR-001 — Canonical Product Model

**Estado:** Propuesto  
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

```typescript
// packages/shared/src/models/canonical-product.ts

export interface CanonicalProduct {
  // Identidad
  bsaleId: number;
  code: string;              // SKU — clave de idempotencia en todos los CMS
  name: string;
  description?: string;
  status: 'active' | 'inactive';

  // Precio
  price: {
    net: number;             // Precio neto (sin IVA)
    gross: number;           // Precio bruto (con IVA) — lo que paga el cliente
    currency: 'CLP' | 'USD' | 'ARS' | 'MXN' | 'PEN' | 'COP';
    taxRate: number;         // Ej: 0.19 para IVA Chile
    priceListId?: number;    // ID de lista de precios en Bsale
  };

  // Stock
  stock: {
    quantity: number;
    allowNegative: boolean;
    ledgerAccount?: string;  // Codigo contable en Bsale
  };

  // Clasificacion
  category?: {
    id: number;
    name: string;
    path?: string[];          // Ruta jerarquica ["Electronica", "Computacion"]
  };

  brand?: string;

  // Variantes (ej: talla S/M/L, color rojo/azul)
  variants?: CanonicalVariant[];

  // Imagenes
  images?: Array<{
    url: string;
    isPrimary: boolean;
    order: number;
  }>;

  // Metadatos de sync
  bsaleUpdatedAt: Date;
  syncedAt?: Date;
}

export interface CanonicalVariant {
  bsaleId: number;
  code: string;              // SKU de la variante
  attributes: Record<string, string>;  // {color: "Rojo", talla: "M"}
  price?: Partial<CanonicalProduct['price']>;  // Override de precio base
  stock: CanonicalProduct['stock'];
  barcode?: string;
}
```

---

## Mapeos por CMS

Cada adapter implementa la interfaz `CmsAdapter`:

```typescript
// packages/shared/src/adapters/cms-adapter.interface.ts

export interface CmsAdapter {
  fromBsale(bsaleProduct: BsaleProduct): CanonicalProduct;
  toCms(canonical: CanonicalProduct): unknown;           // tipo especifico por CMS
  idempotencyKey(canonical: CanonicalProduct): string;   // campo clave en el CMS
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

**Schema con JSON Schema / Zod en lugar de TypeScript interface:** Considerada para v2. Zod permite validacion en runtime (no solo compilacion), util cuando bot-miki recibe productos de fuentes externas. Migracion no rompe contratos.
