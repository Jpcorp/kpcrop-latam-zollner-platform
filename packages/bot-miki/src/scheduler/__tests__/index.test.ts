import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import type { Queue } from 'bullmq';

const { mockExecute, mockDeleteExecute, mockDeleteWhere } = vi.hoisted(() => ({
  mockExecute:       vi.fn(),
  mockDeleteExecute: vi.fn().mockResolvedValue(undefined),
  mockDeleteWhere:   vi.fn(),
}));

vi.mock('../../config.js', () => ({
  config: { BSALE_RATE_LIMIT_RPS: 10 },
}));

vi.mock('../../infrastructure/database.js', () => {
  const chain: Record<string, unknown> = { execute: mockExecute };
  chain['innerJoin'] = () => chain;
  chain['select'] = () => chain;
  chain['where'] = () => chain;

  const deleteChain: Record<string, unknown> = { execute: mockDeleteExecute };
  deleteChain['where'] = (...args: unknown[]) => { mockDeleteWhere(...args); return deleteChain; };

  return { db: { selectFrom: () => chain, deleteFrom: () => deleteChain } };
});

import { runSchedulerTick, purgeOldSyncEvents } from '../index.js';

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

  it('#99: usa licenses.tenant_id (no tenant_stores.license_id) en el job encolado', async () => {
    const addMock = vi.fn().mockResolvedValue(undefined);
    const queue = { add: addMock } as unknown as Queue;

    vi.setSystemTime(new Date('2026-05-22T14:15:00.000Z'));
    await runSchedulerTick(queue);

    const [, jobData] = addMock.mock.calls[0];
    expect(jobData.tenantId).toBe('tenant-1'); // el fixture `job` simula la fila ya con licenses.tenant_id
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

describe('purgeOldSyncEvents (#111)', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    vi.clearAllMocks();
  });

  afterEach(() => vi.useRealTimers());

  it('borra sync_events mas viejo que el corte de dias por default (90)', async () => {
    vi.setSystemTime(new Date('2026-07-22T12:00:00.000Z'));

    await purgeOldSyncEvents();

    expect(mockDeleteWhere).toHaveBeenCalledTimes(1);
    const [column, op, cutoff] = mockDeleteWhere.mock.calls[0];
    expect(column).toBe('created_at');
    expect(op).toBe('<');
    expect((cutoff as Date).toISOString()).toBe('2026-04-23T12:00:00.000Z'); // 90 dias antes
    expect(mockDeleteExecute).toHaveBeenCalledTimes(1);
  });

  it('respeta un valor custom de dias', async () => {
    vi.setSystemTime(new Date('2026-07-22T00:00:00.000Z'));

    await purgeOldSyncEvents(30);

    const [, , cutoff] = mockDeleteWhere.mock.calls[0];
    expect((cutoff as Date).toISOString()).toBe('2026-06-22T00:00:00.000Z');
  });
});
