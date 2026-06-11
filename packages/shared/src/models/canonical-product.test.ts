import { describe, expect, it } from 'vitest';
import {
  CanonicalProductSchema,
  CanonicalVariantSchema,
  bsalePaginatedResponseSchema,
} from './canonical-product.js';

// --- fixtures ---

const validPrice = {
  net: 10000,
  gross: 11900,
  currency: 'CLP' as const,
  taxRate: 0.19,
};

const validStock = {
  quantity: 50,
  allowNegative: false,
};

const minimalProduct = {
  bsaleId: 1,
  code: 'SKU-001',
  name: 'Producto de prueba',
  status: 'active' as const,
  price: validPrice,
  stock: validStock,
  bsaleUpdatedAt: new Date('2026-01-01'),
};

const fullProduct = {
  ...minimalProduct,
  description: 'Descripcion larga',
  category: { id: 5, name: 'Tecnologia', path: ['Electronica', 'Computacion'] },
  brand: 'Marca X',
  images: [
    { url: 'https://cdn.example.com/img.jpg', isPrimary: true, order: 0 },
    { url: 'https://cdn.example.com/img2.jpg', isPrimary: false, order: 1 },
  ],
  variants: [
    {
      bsaleId: 10,
      code: 'SKU-001-ROJO-M',
      attributes: { color: 'Rojo', talla: 'M' },
      stock: { quantity: 3, allowNegative: false },
      barcode: '7891234567890',
    },
  ],
  syncedAt: new Date('2026-01-02'),
};

// --- CanonicalProductSchema ---

describe('CanonicalProductSchema — casos validos', () => {
  it('acepta un producto minimo con solo los campos requeridos', () => {
    const result = CanonicalProductSchema.safeParse(minimalProduct);
    expect(result.success).toBe(true);
  });

  it('acepta un producto completo con todos los campos opcionales', () => {
    const result = CanonicalProductSchema.safeParse(fullProduct);
    expect(result.success).toBe(true);
  });

  it('acepta status inactive', () => {
    const result = CanonicalProductSchema.safeParse({ ...minimalProduct, status: 'inactive' });
    expect(result.success).toBe(true);
  });

  it('acepta todas las monedas validas', () => {
    const currencies = ['CLP', 'USD', 'ARS', 'MXN', 'PEN', 'COP'] as const;
    for (const currency of currencies) {
      const result = CanonicalProductSchema.safeParse({
        ...minimalProduct,
        price: { ...validPrice, currency },
      });
      expect(result.success, `currency ${currency} deberia ser valida`).toBe(true);
    }
  });

  it('acepta taxRate 0 (producto exento de IVA)', () => {
    const result = CanonicalProductSchema.safeParse({
      ...minimalProduct,
      price: { ...validPrice, taxRate: 0 },
    });
    expect(result.success).toBe(true);
  });

  it('acepta stock negativo cuando allowNegative es true', () => {
    const result = CanonicalProductSchema.safeParse({
      ...minimalProduct,
      stock: { quantity: -5, allowNegative: true },
    });
    expect(result.success).toBe(true);
  });

  it('acepta price.priceListId opcional', () => {
    const result = CanonicalProductSchema.safeParse({
      ...minimalProduct,
      price: { ...validPrice, priceListId: 3 },
    });
    expect(result.success).toBe(true);
  });

  it('acepta category sin path', () => {
    const result = CanonicalProductSchema.safeParse({
      ...minimalProduct,
      category: { id: 1, name: 'Ropa' },
    });
    expect(result.success).toBe(true);
  });
});

describe('CanonicalProductSchema — campos requeridos faltantes', () => {
  it('rechaza producto sin bsaleId', () => {
    const { bsaleId: _, ...rest } = minimalProduct;
    const result = CanonicalProductSchema.safeParse(rest);
    expect(result.success).toBe(false);
  });

  it('rechaza producto sin code', () => {
    const { code: _, ...rest } = minimalProduct;
    const result = CanonicalProductSchema.safeParse(rest);
    expect(result.success).toBe(false);
  });

  it('rechaza producto sin name', () => {
    const { name: _, ...rest } = minimalProduct;
    const result = CanonicalProductSchema.safeParse(rest);
    expect(result.success).toBe(false);
  });

  it('rechaza producto sin price', () => {
    const { price: _, ...rest } = minimalProduct;
    const result = CanonicalProductSchema.safeParse(rest);
    expect(result.success).toBe(false);
  });

  it('rechaza producto sin stock', () => {
    const { stock: _, ...rest } = minimalProduct;
    const result = CanonicalProductSchema.safeParse(rest);
    expect(result.success).toBe(false);
  });

  it('rechaza producto sin bsaleUpdatedAt', () => {
    const { bsaleUpdatedAt: _, ...rest } = minimalProduct;
    const result = CanonicalProductSchema.safeParse(rest);
    expect(result.success).toBe(false);
  });
});

describe('CanonicalProductSchema — tipos invalidos', () => {
  it('rechaza bsaleId = 0 (debe ser entero positivo)', () => {
    const result = CanonicalProductSchema.safeParse({ ...minimalProduct, bsaleId: 0 });
    expect(result.success).toBe(false);
  });

  it('rechaza bsaleId negativo', () => {
    const result = CanonicalProductSchema.safeParse({ ...minimalProduct, bsaleId: -1 });
    expect(result.success).toBe(false);
  });

  it('rechaza code vacio', () => {
    const result = CanonicalProductSchema.safeParse({ ...minimalProduct, code: '' });
    expect(result.success).toBe(false);
  });

  it('rechaza name vacio', () => {
    const result = CanonicalProductSchema.safeParse({ ...minimalProduct, name: '' });
    expect(result.success).toBe(false);
  });

  it('rechaza status con valor fuera del enum', () => {
    const result = CanonicalProductSchema.safeParse({ ...minimalProduct, status: 'deleted' });
    expect(result.success).toBe(false);
  });

  it('rechaza currency desconocida', () => {
    const result = CanonicalProductSchema.safeParse({
      ...minimalProduct,
      price: { ...validPrice, currency: 'EUR' },
    });
    expect(result.success).toBe(false);
  });

  it('rechaza price.net negativo', () => {
    const result = CanonicalProductSchema.safeParse({
      ...minimalProduct,
      price: { ...validPrice, net: -1 },
    });
    expect(result.success).toBe(false);
  });

  it('rechaza price.gross negativo', () => {
    const result = CanonicalProductSchema.safeParse({
      ...minimalProduct,
      price: { ...validPrice, gross: -100 },
    });
    expect(result.success).toBe(false);
  });

  it('rechaza taxRate mayor a 1', () => {
    const result = CanonicalProductSchema.safeParse({
      ...minimalProduct,
      price: { ...validPrice, taxRate: 1.5 },
    });
    expect(result.success).toBe(false);
  });

  it('rechaza taxRate negativo', () => {
    const result = CanonicalProductSchema.safeParse({
      ...minimalProduct,
      price: { ...validPrice, taxRate: -0.1 },
    });
    expect(result.success).toBe(false);
  });

  it('rechaza price como string (simulando JSON crudo de Bsale)', () => {
    const result = CanonicalProductSchema.safeParse({
      ...minimalProduct,
      price: { ...validPrice, net: '10000' },
    });
    expect(result.success).toBe(false);
  });

  it('rechaza bsaleUpdatedAt como string ISO (debe ser Date object)', () => {
    const result = CanonicalProductSchema.safeParse({
      ...minimalProduct,
      bsaleUpdatedAt: '2026-01-01T00:00:00Z',
    });
    expect(result.success).toBe(false);
  });

  it('rechaza imagen con URL invalida', () => {
    const result = CanonicalProductSchema.safeParse({
      ...minimalProduct,
      images: [{ url: 'not-a-url', isPrimary: true, order: 0 }],
    });
    expect(result.success).toBe(false);
  });

  it('rechaza imagen con order negativo', () => {
    const result = CanonicalProductSchema.safeParse({
      ...minimalProduct,
      images: [{ url: 'https://cdn.example.com/img.jpg', isPrimary: true, order: -1 }],
    });
    expect(result.success).toBe(false);
  });

  it('expone el campo que fallo en error.issues', () => {
    const result = CanonicalProductSchema.safeParse({ ...minimalProduct, bsaleId: -99 });
    expect(result.success).toBe(false);
    if (!result.success) {
      const paths = result.error.issues.map((i) => i.path.join('.'));
      expect(paths).toContain('bsaleId');
    }
  });
});

// --- CanonicalVariantSchema ---

describe('CanonicalVariantSchema', () => {
  const validVariant = {
    bsaleId: 10,
    code: 'SKU-001-ROJO-M',
    attributes: { color: 'Rojo', talla: 'M' },
    stock: validStock,
  };

  it('acepta variante minima sin price ni barcode', () => {
    const result = CanonicalVariantSchema.safeParse(validVariant);
    expect(result.success).toBe(true);
  });

  it('acepta variante con precio parcial (solo net)', () => {
    const result = CanonicalVariantSchema.safeParse({
      ...validVariant,
      price: { net: 5000 },
    });
    expect(result.success).toBe(true);
  });

  it('acepta variante con barcode', () => {
    const result = CanonicalVariantSchema.safeParse({
      ...validVariant,
      barcode: '7891234567890',
    });
    expect(result.success).toBe(true);
  });

  it('acepta attributes vacio (mapa vacio)', () => {
    const result = CanonicalVariantSchema.safeParse({ ...validVariant, attributes: {} });
    expect(result.success).toBe(true);
  });

  it('rechaza variante sin code', () => {
    const { code: _, ...rest } = validVariant;
    const result = CanonicalVariantSchema.safeParse(rest);
    expect(result.success).toBe(false);
  });

  it('rechaza variante con bsaleId = 0', () => {
    const result = CanonicalVariantSchema.safeParse({ ...validVariant, bsaleId: 0 });
    expect(result.success).toBe(false);
  });

  it('rechaza attributes con valor no-string', () => {
    const result = CanonicalVariantSchema.safeParse({
      ...validVariant,
      attributes: { color: 42 },
    });
    expect(result.success).toBe(false);
  });
});

// --- bsalePaginatedResponseSchema ---

describe('bsalePaginatedResponseSchema', () => {
  const productListSchema = bsalePaginatedResponseSchema(CanonicalProductSchema);

  it('acepta respuesta paginada valida con items', () => {
    const result = productListSchema.safeParse({
      href: 'https://api.bsale.cl/v1/products.json',
      count: 1,
      limit: 25,
      offset: 0,
      items: [minimalProduct],
    });
    expect(result.success).toBe(true);
  });

  it('acepta respuesta paginada vacia (items: [])', () => {
    const result = productListSchema.safeParse({
      href: 'https://api.bsale.cl/v1/products.json',
      count: 0,
      limit: 25,
      offset: 0,
      items: [],
    });
    expect(result.success).toBe(true);
  });

  it('acepta next como URL valida cuando hay mas paginas', () => {
    const result = productListSchema.safeParse({
      href: 'https://api.bsale.cl/v1/products.json',
      count: 100,
      limit: 25,
      offset: 0,
      items: [minimalProduct],
      next: 'https://api.bsale.cl/v1/products.json?offset=25',
    });
    expect(result.success).toBe(true);
  });

  it('rechaza next con URL invalida', () => {
    const result = productListSchema.safeParse({
      href: 'https://api.bsale.cl/v1/products.json',
      count: 100,
      limit: 25,
      offset: 0,
      items: [],
      next: 'not-a-url',
    });
    expect(result.success).toBe(false);
  });

  it('rechaza respuesta con item invalido dentro del array', () => {
    const result = productListSchema.safeParse({
      href: 'https://api.bsale.cl/v1/products.json',
      count: 1,
      limit: 25,
      offset: 0,
      items: [{ ...minimalProduct, bsaleId: -1 }],
    });
    expect(result.success).toBe(false);
  });

  it('rechaza href con URL invalida', () => {
    const result = productListSchema.safeParse({
      href: 'no-es-url',
      count: 0,
      limit: 25,
      offset: 0,
      items: [],
    });
    expect(result.success).toBe(false);
  });

  it('rechaza count negativo', () => {
    const result = productListSchema.safeParse({
      href: 'https://api.bsale.cl/v1/products.json',
      count: -1,
      limit: 25,
      offset: 0,
      items: [],
    });
    expect(result.success).toBe(false);
  });
});
