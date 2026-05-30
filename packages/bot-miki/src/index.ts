import { readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import pg from 'pg';
import { Queue } from 'bullmq';
import { Redis as IORedis } from 'ioredis';
import { buildApp } from './app.js';
import { config } from './config.js';
import { startSyncWorker } from './workers/sync-worker.js';
import { startScheduler } from './scheduler/index.js';
import type { SyncJobData } from './workers/sync-worker.js';

async function applyMigrations(): Promise<void> {
  const pool = new pg.Pool({ connectionString: config.DATABASE_URL });
  const dir = join(dirname(fileURLToPath(import.meta.url)), '..', 'migrations');
  const sql = readFileSync(join(dir, '001_initial_schema.sql'), 'utf8');
  try {
    await pool.query(sql);
    console.log('[migrations] schema aplicado');
  } catch (err: unknown) {
    if ((err as { code?: string }).code !== '42P07') throw err;
    console.log('[migrations] schema ya existia');
  } finally {
    await pool.end();
  }
}

await applyMigrations();

// Single Redis connection shared by queue producer and scheduler
const redis     = new IORedis(config.REDIS_URL, { maxRetriesPerRequest: null });
const syncQueue = new Queue<SyncJobData>('sync', { connection: redis });

const app       = buildApp(syncQueue);
const worker    = startSyncWorker();
const scheduler = startScheduler(syncQueue);

const shutdown = async (signal: string) => {
  app.log.info(`${signal} received — shutting down`);
  clearInterval(scheduler);
  await worker.close();
  await syncQueue.close();
  await redis.quit();
  await app.close();
  process.exit(0);
};

process.on('SIGTERM', () => { shutdown('SIGTERM').catch(console.error); });
process.on('SIGINT',  () => { shutdown('SIGINT').catch(console.error); });

try {
  await app.listen({ port: config.PORT, host: '0.0.0.0' });
} catch (err) {
  app.log.error(err);
  process.exit(1);
}
