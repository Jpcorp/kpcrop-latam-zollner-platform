import { describe, it, expect, vi } from 'vitest';

vi.mock('../../config.js', () => ({
  config: { TOKEN_ENCRYPTION_KEY: 'test_token_encryption_key_minimum_32_chars' },
}));

import { encryptToken, decryptToken } from '../token-crypto.js';

describe('token-crypto (#107)', () => {
  it('round-trip: decryptToken(encryptToken(x)) === x', () => {
    const original = 'bsale_access_token_secreto_del_tenant_xyz123';
    const encrypted = encryptToken(original);
    expect(decryptToken(encrypted)).toBe(original);
  });

  it('el texto cifrado nunca contiene el texto plano', () => {
    const original = 'un_token_bsale_muy_identificable_12345';
    const encrypted = encryptToken(original);
    expect(encrypted).not.toContain(original);
  });

  it('cifrar el mismo valor dos veces da resultados distintos (IV aleatorio)', () => {
    const original = 'mismo-token';
    const a = encryptToken(original);
    const b = encryptToken(original);
    expect(a).not.toBe(b);
    // pero ambos decodifican al mismo valor original
    expect(decryptToken(a)).toBe(original);
    expect(decryptToken(b)).toBe(original);
  });

  it('rechaza un blob manipulado (auth tag de GCM detecta el tamper)', () => {
    const encrypted = encryptToken('token-original');
    const raw = Buffer.from(encrypted, 'base64');
    raw[raw.length - 1] ^= 0xff; // corrompe el ultimo byte del ciphertext
    const tampered = raw.toString('base64');

    expect(() => decryptToken(tampered)).toThrow();
  });

  it('rechaza texto plano legado (no es un blob AES-256-GCM valido)', () => {
    // Este es el caso que distingue el script de migracion #107: un valor que
    // todavia esta en texto plano de instalaciones viejas.
    expect(() => decryptToken('esto-no-esta-cifrado-es-texto-plano')).toThrow();
  });

  it('soporta strings largos (tokens reales de Bsale suelen ser >40 chars)', () => {
    const original = 'kp_' + 'a'.repeat(80);
    expect(decryptToken(encryptToken(original))).toBe(original);
  });

  it('soporta caracteres unicode', () => {
    const original = 'tökén-cön-ñ-y-emoji-🔒';
    expect(decryptToken(encryptToken(original))).toBe(original);
  });
});
