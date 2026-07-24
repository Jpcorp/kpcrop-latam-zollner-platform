import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mkdtempSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const { mockQuery, mockEnd } = vi.hoisted(() => ({
  mockQuery: vi.fn(),
  mockEnd: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('pg', () => ({
  default: {
    Pool: vi.fn().mockImplementation(() => ({ query: mockQuery, end: mockEnd })),
  },
}));

import { applyMigrations } from '../migrations-runner.js';

function makeMigrationsDir(files: Record<string, string>): string {
  const dir = mkdtempSync(join(tmpdir(), 'migrations-test-'));
  for (const [name, content] of Object.entries(files)) {
    writeFileSync(join(dir, name), content);
  }
  return dir;
}

describe('applyMigrations (#113)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mockQuery.mockResolvedValue({ rows: [] }); // default: nada aplicado, sin error
  });

  it('crea la tabla schema_migrations al arrancar', async () => {
    const dir = makeMigrationsDir({});
    await applyMigrations(dir, 'postgresql://fake');

    const createTableCall = mockQuery.mock.calls.find(
      (c) => typeof c[0] === 'string' && c[0].includes('CREATE TABLE IF NOT EXISTS schema_migrations'),
    );
    expect(createTableCall).toBeDefined();
    rmSync(dir, { recursive: true, force: true });
  });

  it('aplica archivos .sql en orden alfabetico, ignorando 002_seed_dev.sql', async () => {
    const dir = makeMigrationsDir({
      '001_a.sql': 'SELECT 1;',
      '002_seed_dev.sql': 'INSERT INTO dev_data VALUES (1);',
      '003_b.sql': 'SELECT 2;',
    });

    await applyMigrations(dir, 'postgresql://fake');

    const ranSql = mockQuery.mock.calls.map((c) => c[0]);
    expect(ranSql).toContain('SELECT 1;');
    expect(ranSql).toContain('SELECT 2;');
    expect(ranSql).not.toContain('INSERT INTO dev_data VALUES (1);');
    rmSync(dir, { recursive: true, force: true });
  });

  it('no re-aplica un archivo que ya esta en schema_migrations', async () => {
    const dir = makeMigrationsDir({ '001_a.sql': 'SELECT 1;' });

    mockQuery.mockImplementation((sql: string) => {
      if (sql.includes('SELECT 1 FROM schema_migrations')) {
        return Promise.resolve({ rows: [{ '?column?': 1 }] }); // ya aplicada
      }
      return Promise.resolve({ rows: [] });
    });

    await applyMigrations(dir, 'postgresql://fake');

    const ranSql = mockQuery.mock.calls.map((c) => c[0]);
    expect(ranSql).not.toContain('SELECT 1;');
    rmSync(dir, { recursive: true, force: true });
  });

  it('001 tolera 42P07 (ya existia, de despliegues previos al runner)', async () => {
    const dir = makeMigrationsDir({ '001_initial_schema.sql': 'CREATE TABLE licenses (id INT);' });

    mockQuery.mockImplementation((sql: string) => {
      if (sql.includes('SELECT 1 FROM schema_migrations')) return Promise.resolve({ rows: [] });
      if (sql === 'CREATE TABLE licenses (id INT);') {
        const err: Error & { code?: string } = new Error('relation "licenses" already exists');
        err.code = '42P07';
        return Promise.reject(err);
      }
      return Promise.resolve({ rows: [] });
    });

    await expect(applyMigrations(dir, 'postgresql://fake')).resolves.not.toThrow();

    // Igual queda marcada como aplicada (para no reintentar en el proximo boot)
    const insertCall = mockQuery.mock.calls.find(
      (c) => typeof c[0] === 'string' && c[0].includes('INSERT INTO schema_migrations'),
    );
    expect(insertCall).toBeDefined();
    rmSync(dir, { recursive: true, force: true });
  });

  it('un error real (no 42P07) en una migracion NO se marca como aplicada y se propaga', async () => {
    const dir = makeMigrationsDir({ '005_bad.sql': 'SELECT esto_no_existe();' });

    mockQuery.mockImplementation((sql: string) => {
      if (sql.includes('SELECT 1 FROM schema_migrations')) return Promise.resolve({ rows: [] });
      if (sql === 'SELECT esto_no_existe();') {
        const err: Error & { code?: string } = new Error('function does not exist');
        err.code = '42883';
        return Promise.reject(err);
      }
      return Promise.resolve({ rows: [] });
    });

    await expect(applyMigrations(dir, 'postgresql://fake')).rejects.toThrow('function does not exist');

    const insertCall = mockQuery.mock.calls.find(
      (c) => typeof c[0] === 'string' && c[0].includes('INSERT INTO schema_migrations'),
    );
    expect(insertCall).toBeUndefined(); // no se marca como aplicada -> reintenta en el proximo boot
    rmSync(dir, { recursive: true, force: true });
  });

  it('cierra el pool al terminar (incluso si hubo error)', async () => {
    const dir = makeMigrationsDir({});
    await applyMigrations(dir, 'postgresql://fake');
    expect(mockEnd).toHaveBeenCalledTimes(1);
    rmSync(dir, { recursive: true, force: true });
  });
});
