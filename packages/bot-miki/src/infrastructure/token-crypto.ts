import { createCipheriv, createDecipheriv, createHash, randomBytes } from 'node:crypto';
import { config } from '../config.js';

// #107: tenant_stores.bsale_access_token estaba en texto plano — un dump/leak
// de la BD exponia los tokens de Bsale de todos los tenants. AES-256-GCM
// (autenticado — a diferencia del AES-256-CBC sin MAC del lado PHP, ver #115)
// con una llave dedicada (TOKEN_ENCRYPTION_KEY, no compartida con JWT_SECRET
// ni ADMIN_KEY). La llave se hashea con SHA-256 para garantizar 32 bytes
// exactos sin importar la longitud/encoding de la passphrase del env var.

const KEY = createHash('sha256').update(config.TOKEN_ENCRYPTION_KEY).digest();
const IV_LENGTH = 12;   // recomendado para GCM
const TAG_LENGTH = 16;

export function encryptToken(plainText: string): string {
  const iv = randomBytes(IV_LENGTH);
  const cipher = createCipheriv('aes-256-gcm', KEY, iv);
  const encrypted = Buffer.concat([cipher.update(plainText, 'utf8'), cipher.final()]);
  const authTag = cipher.getAuthTag();
  return Buffer.concat([iv, authTag, encrypted]).toString('base64');
}

export function decryptToken(encoded: string): string {
  const raw = Buffer.from(encoded, 'base64');
  if (raw.length < IV_LENGTH + TAG_LENGTH) {
    throw new Error('Valor cifrado invalido (demasiado corto)');
  }
  const iv        = raw.subarray(0, IV_LENGTH);
  const authTag   = raw.subarray(IV_LENGTH, IV_LENGTH + TAG_LENGTH);
  const encrypted = raw.subarray(IV_LENGTH + TAG_LENGTH);

  const decipher = createDecipheriv('aes-256-gcm', KEY, iv);
  decipher.setAuthTag(authTag);
  return Buffer.concat([decipher.update(encrypted), decipher.final()]).toString('utf8');
}
