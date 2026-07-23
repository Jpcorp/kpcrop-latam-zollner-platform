import { Queue } from 'bullmq';
import { Redis as IORedis } from 'ioredis';
import { config } from '../config.js';
import { db } from '../infrastructure/database.js';
import type { SyncJobData } from '../workers/sync-worker.js';

// #113: si bot-miki llega a escalar horizontalmente (varias replicas del
// mismo proceso), cada una tendria su propio setInterval corriendo el mismo
// tick al mismo tiempo -> jobs de polling duplicados (mitigado mecanicamente
// por el idempotencyKey de BullMQ, pero es trabajo redundante innecesario).
// Lock distribuido simple sobre Redis (SET NX PX): la primera replica que
// llega en cada ventana de 60s corre el tick, las demas lo saltean.
let lockRedis: IORedis | null = null;
function getLockRedis(): IORedis {
  if (!lockRedis) {
    lockRedis = new IORedis(config.REDIS_URL, { maxRetriesPerRequest: null });
  }
  return lockRedis;
}

/** Permite inyectar un cliente Redis mockeado en tests sin tocar el singleton real. */
export function __setLockRedisClientForTests(client: IORedis | null): void {
  lockRedis = client;
}

const SCHEDULER_LOCK_KEY = 'scheduler:lock';
const SCHEDULER_LOCK_TTL_MS = 55_000; // < 60s del interval — se libera solo antes del proximo tick

async function acquireSchedulerLock(): Promise<boolean> {
  const result = await getLockRedis().set(SCHEDULER_LOCK_KEY, process.pid.toString(), 'PX', SCHEDULER_LOCK_TTL_MS, 'NX');
  return result === 'OK';
}

function cronMatches(expression: string, now: Date): boolean {
  // Evaluacion minimalista de cron — solo patrones usados en la plataforma:
  //   "0 * * * *"    → en punto de cada hora
  //   "*/15 * * * *" → cada 15 minutos
  //   "*/30 * * * *" → cada 30 minutos
  //   "0 2 * * *"    → a las 02:00 todos los dias
  const [min, hour] = expression.split(' ');
  const m = now.getMinutes();
  const h = now.getHours();

  const matchMin  = min  === '*' ? true : min.startsWith('*/') ? m % parseInt(min.slice(2)) === 0 : parseInt(min) === m;
  const matchHour = hour === '*' ? true : parseInt(hour) === h;

  return matchMin && matchHour;
}

export async function runSchedulerTick(queue: Queue<SyncJobData>): Promise<void> {
  const now = new Date();

  const jobs = await db
    .selectFrom('scheduled_jobs')
    .innerJoin('tenant_stores', 'tenant_stores.id', 'scheduled_jobs.store_id')
    .innerJoin('licenses', 'licenses.id', 'tenant_stores.license_id')
    .select([
      'scheduled_jobs.id',
      'scheduled_jobs.store_id',
      'scheduled_jobs.entity_type',
      'scheduled_jobs.cron_expression',
      // #99: antes seleccionaba tenant_stores.license_id (UUID) aliaseado como
      // tenant_id — no coincidia con licenses.tenant_id (string) que usan
      // webhooks.ts y sync-report.ts, rompiendo la correlacion por tenant en
      // sync_events para los jobs de polling.
      'licenses.tenant_id as tenant_id',
      'licenses.status as license_status',
    ])
    .where('scheduled_jobs.active', '=', true)
    .where('licenses.status', '=', 'active')
    .execute();

  for (const job of jobs) {
    if (!cronMatches(job.cron_expression, now)) continue;

    const windowId       = now.toISOString().slice(0, 16); // "2026-05-22T14:15" (#106: granularidad de minuto, no de hora)
    const idempotencyKey = `polling:${job.store_id}:${job.entity_type}:${windowId}`;

    await queue.add(
      'polling',
      {
        storeId:    job.store_id,
        tenantId:   job.tenant_id,
        syncType:   'polling',
        entityType: job.entity_type,
      },
      {
        jobId:    idempotencyKey,  // BullMQ deduplica — idempotente por minuto
        attempts: 3,
        backoff:  { type: 'exponential', delay: 60_000 },
        removeOnComplete: { age: 86_400 },
        removeOnFail:     { age: 604_800 },
      },
    );
  }
}

export async function runIfLeader(queue: Queue<SyncJobData>): Promise<void> {
  if (await acquireSchedulerLock()) {
    await runSchedulerTick(queue);
  }
}

export function startScheduler(queue: Queue<SyncJobData>): NodeJS.Timeout {
  // Tick cada minuto para evaluar cron expressions (solo la replica que gana el lock)
  const tick = setInterval(() => runIfLeader(queue).catch(console.error), 60_000);

  // #111: sync_events crece sin techo (un INSERT por webhook/sync). Sweep de
  // retencion cada 24h — sin cron dedicado en bot-miki, se resuelve dentro del
  // mismo proceso del scheduler en vez de agregar infraestructura nueva.
  setInterval(() => purgeOldSyncEvents().catch(console.error), 24 * 60 * 60 * 1000);

  return tick;
}

/** #111: purga sync_events mas viejo que `days` (default 90). */
export async function purgeOldSyncEvents(days = 90): Promise<void> {
  const cutoff = new Date(Date.now() - days * 24 * 60 * 60 * 1000);
  await db.deleteFrom('sync_events').where('created_at', '<', cutoff).execute();
}
