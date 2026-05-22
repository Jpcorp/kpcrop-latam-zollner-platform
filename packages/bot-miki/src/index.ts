import { Queue } from 'bullmq';
import IORedis from 'ioredis';
import { buildApp } from './app.js';
import { config } from './config.js';
import { startSyncWorker } from './workers/sync-worker.js';
import { startScheduler } from './scheduler/index.js';
import type { SyncJobData } from './workers/sync-worker.js';

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
