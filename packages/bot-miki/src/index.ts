import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { Queue } from 'bullmq';
import { Redis as IORedis } from 'ioredis';
import { buildApp } from './app.js';
import { config } from './config.js';
import { applyMigrations } from './infrastructure/migrations-runner.js';
import { startSyncWorker } from './workers/sync-worker.js';
import { startScheduler } from './scheduler/index.js';
import type { SyncJobData } from './workers/sync-worker.js';

// #113: preparacion para poder separar API/worker/scheduler en servicios
// Railway distintos mas adelante — el proceso monolitico (todo en un solo
// deploy) impide escalar el worker independientemente de la API, y hace que
// un pico de webhooks compita por CPU/memoria con las requests HTTP.
//
// PROCESS_ROLE no seteado (o "all") preserva el comportamiento actual EXACTO
// (todo en un proceso) — cero cambio de despliegue hasta que se decida
// separar los servicios en Railway. Las migraciones solo corren en "all" o
// "api": si cada replica de worker/scheduler intentara aplicarlas al
// arrancar, correrian en paralelo contra la misma BD.
type ProcessRole = 'all' | 'api' | 'worker' | 'scheduler';
const role = (process.env.PROCESS_ROLE ?? 'all') as ProcessRole;

if (role === 'all' || role === 'api') {
  const migrationsDir = join(dirname(fileURLToPath(import.meta.url)), '..', 'migrations');
  await applyMigrations(migrationsDir, config.DATABASE_URL);
}

// Single Redis connection shared por quien encole/lea de la cola
const redis     = new IORedis(config.REDIS_URL, { maxRetriesPerRequest: null });
const syncQueue = new Queue<SyncJobData>('sync', { connection: redis });

const app       = (role === 'all' || role === 'api') ? buildApp(syncQueue) : null;
const worker    = (role === 'all' || role === 'worker') ? startSyncWorker() : null;
const scheduler = (role === 'all' || role === 'scheduler') ? startScheduler(syncQueue) : null;

const shutdown = async (signal: string) => {
  const msg = `${signal} received — shutting down (role=${role})`;
  if (app) app.log.info(msg); else console.log(msg);
  if (scheduler) clearInterval(scheduler);
  if (worker) await worker.close();
  await syncQueue.close();
  await redis.quit();
  if (app) await app.close();
  process.exit(0);
};

process.on('SIGTERM', () => { shutdown('SIGTERM').catch(console.error); });
process.on('SIGINT',  () => { shutdown('SIGINT').catch(console.error); });

if (app) {
  try {
    await app.listen({ port: config.PORT, host: '0.0.0.0' });
  } catch (err) {
    app.log.error(err);
    process.exit(1);
  }
} else {
  console.log(`[bot-miki] rol=${role} — sin servidor HTTP, proceso en background`);
}
