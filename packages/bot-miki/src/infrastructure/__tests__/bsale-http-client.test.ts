import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

vi.mock('../../config.js', () => ({
  config: { BSALE_RATE_LIMIT_RPS: 10, REDIS_URL: 'redis://localhost:6379' },
}));

import { BsaleHttpClient, BsaleApiError, __setRedisClientForTests } from '../bsale-http-client.js';

const mockEval = vi.fn();

beforeEach(() => {
  vi.stubGlobal('fetch', vi.fn());
  // Por default el token bucket siempre tiene cupo (wait_ms=0) — los tests que
  // no son sobre rate limiting no necesitan preocuparse por esto.
  mockEval.mockReset().mockResolvedValue(0);
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  __setRedisClientForTests({ eval: mockEval } as any);
});

afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
  __setRedisClientForTests(null);
});

describe('BsaleHttpClient', () => {
  it('#102: pasa un AbortSignal con timeout al fetch', async () => {
    vi.mocked(fetch).mockResolvedValueOnce(
      new Response(JSON.stringify({ ok: true }), { status: 200 }),
    );

    const client = new BsaleHttpClient('token-123');
    await client.get('/v1/offices.json');

    expect(fetch).toHaveBeenCalledTimes(1);
    const options = vi.mocked(fetch).mock.calls[0][1] as RequestInit;
    expect(options.signal).toBeInstanceOf(AbortSignal);
  });

  it('propaga el AbortError cuando el fetch cuelga más del timeout (simulado)', async () => {
    vi.mocked(fetch).mockImplementationOnce(() => {
      const err = new DOMException('The operation was aborted.', 'TimeoutError');
      return Promise.reject(err);
    });

    const client = new BsaleHttpClient('token-123');
    await expect(client.get('/v1/offices.json')).rejects.toThrow();
  });

  it('en un 429 espera Retry-After y reintenta', async () => {
    vi.useFakeTimers();
    vi.mocked(fetch)
      .mockResolvedValueOnce(new Response(null, { status: 429, headers: { 'Retry-After': '1' } }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ ok: true }), { status: 200 }));

    const client = new BsaleHttpClient('token-123');
    const promise = client.get('/v1/offices.json');
    await vi.advanceTimersByTimeAsync(1000);
    const result = await promise;

    expect(result).toEqual({ ok: true });
    expect(fetch).toHaveBeenCalledTimes(2);
    vi.useRealTimers();
  });

  it('en un error 4xx/5xx lanza BsaleApiError con el statusCode', async () => {
    vi.mocked(fetch).mockResolvedValueOnce(new Response('bad request', { status: 400 }));

    const client = new BsaleHttpClient('token-123');
    await expect(client.get('/v1/offices.json')).rejects.toThrow(BsaleApiError);
  });

  describe('rate limiter compartido via Redis (#104)', () => {
    it('consulta el token bucket antes de cada fetch, con key derivada del token (no el token crudo)', async () => {
      vi.mocked(fetch).mockResolvedValueOnce(new Response(JSON.stringify({ ok: true }), { status: 200 }));

      const client = new BsaleHttpClient('token-123-secreto');
      await client.get('/v1/offices.json');

      expect(mockEval).toHaveBeenCalledTimes(1);
      const [script, numKeys, bucketKey, capacity] = mockEval.mock.calls[0];
      expect(script).toContain('HMGET'); // es el script del token bucket, no otro eval
      expect(numKeys).toBe(1);
      expect(bucketKey).not.toContain('token-123-secreto'); // nunca el token crudo en la key
      expect(bucketKey).toMatch(/^bsale:ratelimit:[a-f0-9]{16}$/);
      expect(capacity).toBe(10); // BSALE_RATE_LIMIT_RPS mockeado
    });

    it('la key del bucket es estable para el mismo token entre llamadas', async () => {
      vi.mocked(fetch)
        .mockResolvedValueOnce(new Response(JSON.stringify({}), { status: 200 }))
        .mockResolvedValueOnce(new Response(JSON.stringify({}), { status: 200 }));

      const client = new BsaleHttpClient('mismo-token');
      await client.get('/v1/a.json');
      await client.get('/v1/b.json');

      const key1 = mockEval.mock.calls[0][2];
      const key2 = mockEval.mock.calls[1][2];
      expect(key1).toBe(key2);
    });

    it('tokens de distintos tenants usan buckets distintos', async () => {
      vi.mocked(fetch)
        .mockResolvedValueOnce(new Response(JSON.stringify({}), { status: 200 }))
        .mockResolvedValueOnce(new Response(JSON.stringify({}), { status: 200 }));

      await new BsaleHttpClient('token-tenant-A').get('/v1/a.json');
      await new BsaleHttpClient('token-tenant-B').get('/v1/a.json');

      const keyA = mockEval.mock.calls[0][2];
      const keyB = mockEval.mock.calls[1][2];
      expect(keyA).not.toBe(keyB);
    });

    it('si el bucket no tiene cupo, espera wait_ms antes de hacer fetch', async () => {
      vi.useFakeTimers();
      mockEval
        .mockResolvedValueOnce(250)  // sin cupo — esperar 250ms
        .mockResolvedValueOnce(0);   // segundo intento: ya hay cupo
      vi.mocked(fetch).mockResolvedValueOnce(new Response(JSON.stringify({ ok: true }), { status: 200 }));

      const client = new BsaleHttpClient('token-123');
      const promise = client.get('/v1/offices.json');

      // Antes de que pase el tiempo de espera, no debe haber llamado a fetch todavia
      await vi.advanceTimersByTimeAsync(100);
      expect(fetch).not.toHaveBeenCalled();

      await vi.advanceTimersByTimeAsync(200);
      await promise;

      expect(mockEval).toHaveBeenCalledTimes(2);
      expect(fetch).toHaveBeenCalledTimes(1);
      vi.useRealTimers();
    });
  });
});
