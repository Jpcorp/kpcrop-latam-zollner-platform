import { describe, expect, it } from 'vitest';
import type { CmsAdapter, SyncResult } from './cms-adapter.interface.js';
import type { CanonicalProduct } from '../models/canonical-product.js';

// #37: CmsAdapter no tiene implementacion en `shared` (cada packages/cms-*
// la implementa) — un adapter minimo que satisface la interfaz sirve como
// test de contrato: si la forma de CmsAdapter cambia de manera incompatible,
// esto deja de compilar / fallar en tiempo de test.
class FakeCmsAdapter implements CmsAdapter<{ id: string; title: string }> {
  fromBsale(bsaleProduct: unknown): CanonicalProduct {
    const raw = bsaleProduct as { sku: string; name: string };
    return {
      bsaleId: 1,
      code: raw.sku,
      name: raw.name,
      status: 'active',
      price: { net: 1000, gross: 1190, currency: 'CLP', taxRate: 0.19 },
      stock: { quantity: 10, allowNegative: false },
      bsaleUpdatedAt: new Date('2026-01-01'),
    };
  }

  toCms(canonical: CanonicalProduct): { id: string; title: string } {
    return { id: canonical.code, title: canonical.name };
  }

  idempotencyKey(canonical: CanonicalProduct): string {
    return canonical.code;
  }
}

describe('CmsAdapter (contrato de interfaz, #37)', () => {
  const adapter = new FakeCmsAdapter();

  it('fromBsale traduce el payload crudo al modelo canónico', () => {
    const canonical = adapter.fromBsale({ sku: 'SKU-1', name: 'Producto 1' });
    expect(canonical.code).toBe('SKU-1');
    expect(canonical.name).toBe('Producto 1');
  });

  it('toCms traduce el modelo canónico al formato nativo del CMS', () => {
    const canonical = adapter.fromBsale({ sku: 'SKU-2', name: 'Producto 2' });
    const cmsFormat = adapter.toCms(canonical);
    expect(cmsFormat).toEqual({ id: 'SKU-2', title: 'Producto 2' });
  });

  it('idempotencyKey devuelve una clave estable para el mismo producto', () => {
    const canonical = adapter.fromBsale({ sku: 'SKU-3', name: 'Producto 3' });
    expect(adapter.idempotencyKey(canonical)).toBe('SKU-3');
  });
});

describe('SyncResult (forma de acumulación, #37)', () => {
  // #37: no hay una funcion acumuladora en `shared` — cada CMS acumula a mano
  // (ver SynkropService.php: $result->updated++, $result->errors[] = [...]).
  // Este test verifica que la forma del tipo soporta ese patron de uso real.
  function accumulate(result: SyncResult, ok: boolean, error?: { code: string; message: string }): void {
    if (ok) {
      result.updated++;
    } else {
      result.failed++;
      if (error) result.errors.push(error);
    }
  }

  it('acumula éxitos y fallos correctamente sobre una serie de operaciones', () => {
    const result: SyncResult = { updated: 0, failed: 0, errors: [], durationMs: 0 };

    accumulate(result, true);
    accumulate(result, true);
    accumulate(result, false, { code: 'SKU-X', message: 'sin código' });
    accumulate(result, true);

    expect(result.updated).toBe(3);
    expect(result.failed).toBe(1);
    expect(result.errors).toHaveLength(1);
    expect(result.errors[0]).toEqual({ code: 'SKU-X', message: 'sin código' });
  });

  it('un resultado sin operaciones queda en cero, no undefined', () => {
    const result: SyncResult = { updated: 0, failed: 0, errors: [], durationMs: 0 };
    expect(result.updated).toBe(0);
    expect(result.failed).toBe(0);
    expect(result.errors).toEqual([]);
  });
});
