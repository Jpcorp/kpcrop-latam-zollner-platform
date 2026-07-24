import crypto from 'node:crypto';
import { config } from '../config.js';

// #92: comparacion en tiempo constante para evitar timing attacks sobre ADMIN_KEY.
// Se comparan los digests SHA-256 (siempre 32 bytes) para no filtrar la longitud ni
// que timingSafeEqual lance por buffers de distinto tamaño.
// Extraido de admin.ts (#108) para reusarlo tambien protegiendo /docs.
export function adminKeyMatches(provided: string | string[] | undefined): boolean {
  if (typeof provided !== 'string' || provided.length === 0) return false;
  const a = crypto.createHash('sha256').update(provided).digest();
  const b = crypto.createHash('sha256').update(config.ADMIN_KEY).digest();
  return crypto.timingSafeEqual(a, b);
}
