import { Queue } from 'bullmq';
import IORedis from 'ioredis';
import { config } from '../config.js';
import { db } from '../infrastructure/database.js';
import type { SyncJobData } from '../workers/sync-worker.js';

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
    .selectFrom('scheduled_jobs as sj')
    .innerJoin('tenant_stores as ts', 'ts.id', 'sj.store_id')
    .innerJoin('licenses as l', 'l.id', 'ts.license_id')
    .select([
      'sj.id', 'sj.store_id', 'sj.entity_type', 'sj.cron_expression',
      'ts.license_id as tenant_id', 'l.status as license_status',
    ])
    .where('sj.active', '=', true)
    .where('l.status', '=', 'active')
    .execute();

  for (const job of jobs) {
    if (!cronMatches(job.cron_expression, now)) continue;

    const windowId       = now.toISOString().slice(0, 13); // "2026-05-22T14"
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
        jobId:    idempotencyKey,  // BullMQ deduplica — idempotente por hora
        attempts: 3,
        backoff:  { type: 'exponential', delay: 60_000 },
        removeOnComplete: { age: 86_400 },
        removeOnFail:     { age: 604_800 },
      },
    );
  }
}

export function startScheduler(queue: Queue<SyncJobData>): NodeJS.Timeout {
  // Tick cada minuto para evaluar cron expressions
  return setInterval(() => runSchedulerTick(queue).catch(console.error), 60_000);
}
