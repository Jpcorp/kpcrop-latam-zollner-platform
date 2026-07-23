import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import type { Queue } from 'bullmq';

const { mockExecute } = vi.hoisted(() => ({ mockExecute: vi.fn() }));

vi.mock('../../config.js', () => ({
  config: { BSALE_RATE_LIMIT_RPS: 10 },
}));

vi.mock('../../infrastructure/database.js', () => {
  const chain: Record<string, unknown> = { execute: mockExecute };
  chain['innerJoin'] = () => chain;
  chain['select'] = () => chain;
  chain['where'] = () => chain;
  return { db: { selectFrom: () => chain } };
});

import { runSchedulerTick } from '../index.js';

const job = {
  id: 'job-1',
  store_id: 'store-1',
  entity_type: 'stock',
  cron_expression: '*/15 * * * *',
  tenant_id: 'tenant-1',
  license_status: 'active',
};

describe('runSchedulerTick — idempotencia (#106)', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    mockExecute.mockResolvedValue([job]);
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.clearAllMocks();
  });

  it('genera jobId distinto para dos ticks del mismo cron en minutos distintos de la misma hora', async () => {
    const addMock = vi.fn().mockResolvedValue(undefined);
    const queue = { add: addMock } as unknown as Queue;

    vi.setSystemTime(new Date('2026-05-22T14:15:00.000Z'));
    await runSchedulerTick(queue);

    vi.setSystemTime(new Date('2026-05-22T14:30:00.000Z'));
    await runSchedulerTick(queue);

    expect(addMock).toHaveBeenCalledTimes(2);
    const jobId1 = addMock.mock.calls[0][2].jobId as string;
    const jobId2 = addMock.mock.calls[1][2].jobId as string;

    // Antes del fix, ambos ticks caian en la misma ventana de HORA ("...T14")
    // y BullMQ deduplicaba el segundo -> el cron */15 corria 1 vez/hora en vez de 4.
    expect(jobId1).not.toBe(jobId2);
    expect(jobId1).toContain('14:15');
    expect(jobId2).toContain('14:30');
  });

  it('genera el mismo jobId para dos ticks dentro del mismo minuto (dedup correcto)', async () => {
    const addMock = vi.fn().mockResolvedValue(undefined);
    const queue = { add: addMock } as unknown as Queue;

    vi.setSystemTime(new Date('2026-05-22T14:15:10.000Z'));
    await runSchedulerTick(queue);

    vi.setSystemTime(new Date('2026-05-22T14:15:45.000Z'));
    await runSchedulerTick(queue);

    const jobId1 = addMock.mock.calls[0][2].jobId as string;
    const jobId2 = addMock.mock.calls[1][2].jobId as string;
    expect(jobId1).toBe(jobId2);
  });
});
