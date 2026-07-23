import { readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import pg from 'pg';

// #113: antes solo se aplicaban 001 (con tolerancia a 42P07 si ya existia) y
// 003 en un loop hardcodeado en index.ts, sin registro de que se aplico —
// no habia mecanismo real para 004+ mas alla de agregar el nombre al array a
// mano. Ahora hay una tabla schema_migrations que trackea cada archivo
// aplicado, asi que agregar una migracion nueva es tan simple como dejar el
// .sql en migrations/ (se detecta y aplica solo, en orden alfabetico).
//
// 002_seed_dev.sql NUNCA se aplica aca — es data de desarrollo, no de
// produccion (se aplica a mano en local si hace falta).

async function ensureMigrationsTable(pool: pg.Pool): Promise<void> {
  await pool.query(`
    CREATE TABLE IF NOT EXISTS schema_migrations (
      filename   TEXT PRIMARY KEY,
      applied_at TIMESTAMPTZ NOT NULL DEFAULT now()
    )
  `);
}

async function isApplied(pool: pg.Pool, filename: string): Promise<boolean> {
  const { rows } = await pool.query('SELECT 1 FROM schema_migrations WHERE filename = $1', [filename]);
  return rows.length > 0;
}

async function markApplied(pool: pg.Pool, filename: string): Promise<void> {
  await pool.query('INSERT INTO schema_migrations (filename) VALUES ($1) ON CONFLICT DO NOTHING', [filename]);
}

export function listMigrationFiles(migrationsDir: string): string[] {
  return readdirSync(migrationsDir)
    .filter((f) => f.endsWith('.sql') && f !== '002_seed_dev.sql')
    .sort();
}

export async function applyMigrations(migrationsDir: string, databaseUrl: string): Promise<void> {
  const pool = new pg.Pool({ connectionString: databaseUrl });
  try {
    await ensureMigrationsTable(pool);

    for (const file of listMigrationFiles(migrationsDir)) {
      if (await isApplied(pool, file)) {
        console.log(`[migrations] ${file} ya aplicada (schema_migrations)`);
        continue;
      }

      try {
        await pool.query(readFileSync(join(migrationsDir, file), 'utf8'));
        console.log(`[migrations] ${file} aplicada`);
      } catch (err: unknown) {
        // 001 puede ya existir en despliegues previos al runner versionado
        // (se aplicaba a mano, sin quedar registrado en schema_migrations).
        if (file === '001_initial_schema.sql' && (err as { code?: string }).code === '42P07') {
          console.log(`[migrations] ${file} ya existia (pre-runner)`);
        } else {
          throw err;
        }
      }

      await markApplied(pool, file);
    }
  } finally {
    await pool.end();
  }
}
