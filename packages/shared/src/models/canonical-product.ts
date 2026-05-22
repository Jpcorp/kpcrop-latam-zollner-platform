// Modelo canonico de producto — fuente de verdad entre Bsale y todos los CMS
// Ver ADR-001 para la decision de diseño

export interface CanonicalProduct {
  bsaleId: number;
  code: string;           // SKU de la variante — clave de idempotencia en todos los CMS
  name: string;
  description?: string;
  status: 'active' | 'inactive';

  price: {
    net: number;          // Sin IVA
    gross: number;        // Con IVA — lo que paga el cliente final
    currency: 'CLP' | 'USD' | 'ARS' | 'MXN' | 'PEN' | 'COP';
    taxRate: number;      // 0.19 para IVA Chile
    priceListId?: number;
  };

  stock: {
    quantity: number;
    allowNegative: boolean;
    officeId?: number;    // null = stock sumado de todas las sucursales
  };

  category?: {
    id: number;
    name: string;
    path?: string[];      // ['Electronica', 'Computacion']
  };

  brand?: string;

  variants?: CanonicalVariant[];

  images?: Array<{
    url: string;
    isPrimary: boolean;
    order: number;
  }>;

  bsaleUpdatedAt: Date;
  syncedAt?: Date;
}

export interface CanonicalVariant {
  bsaleId: number;
  code: string;           // SKU de la variante
  attributes: Record<string, string>;  // { color: 'Rojo', talla: 'M' }
  price?: Partial<CanonicalProduct['price']>;
  stock: CanonicalProduct['stock'];
  barcode?: string;
}

// Respuesta paginada de Bsale (estructura comun en todos los endpoints)
export interface BsalePaginatedResponse<T> {
  href: string;
  count: number;
  limit: number;
  offset: number;
  items: T[];
  next?: string;
}
