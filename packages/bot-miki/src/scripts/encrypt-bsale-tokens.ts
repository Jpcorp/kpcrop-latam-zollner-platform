/**
 * #107 — migracion de datos: cifra en el lugar cualquier bsale_access_token
 * que todavia este en texto plano en tenant_stores (instalaciones existentes,
 * de antes de que el codigo empezara a cifrar en escritura).
 *
 * Idempotente: por cada fila intenta decryptToken() primero — si funciona, ya
 * esta migrada y se saltea; si falla (no es un blob AES-256-GCM valido), se
 * asume texto plano legado y se cifra.
 *
 * Uso (despues de `pnpm build`, con TOKEN_ENCRYPTION_KEY seteado en el env):
 *   node dist/scripts/encrypt-bsale-tokens.js
 */
import { db } from '../infrastructure/database.js';
import { encryptToken, decryptToken } from '../infrastructure/token-crypto.js';

async function main() {
  const rows = await db
    .selectFrom('tenant_stores')
    .select(['id', 'bsale_access_token'])
    .where('bsale_access_token', 'is not', null)
    .execute();

  let migrated = 0;
  let alreadyEncrypted = 0;

  for (const row of rows) {
    const value = row.bsale_access_token as string;
    try {
      decryptToken(value);
      alreadyEncrypted++;
      continue;
    } catch {
      // No es un blob cifrado valido -> texto plano legado
    }

    await db
      .updateTable('tenant_stores')
      .set({ bsale_access_token: encryptToken(value) })
      .where('id', '=', row.id)
      .execute();
    migrated++;
    console.log(`[migrado] store ${row.id}`);
  }

  console.log(`\nTotal: ${rows.length} | migrados: ${migrated} | ya cifrados: ${alreadyEncrypted}`);
  await db.destroy();
}

main().catch((err) => {
  console.error('[ERROR]', err);
  process.exit(1);
});
