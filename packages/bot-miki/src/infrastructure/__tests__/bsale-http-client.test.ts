import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

vi.mock('../../config.js', () => ({
  config: { BSALE_RATE_LIMIT_RPS: 1000 }, // alto para no frenar los tests con el throttle real
}));

import { BsaleHttpClient, BsaleApiError } from '../bsale-http-client.js';

describe('BsaleHttpClient', () => {
  beforeEach(() => {
    vi.stubGlobal('fetch', vi.fn());
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

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
});
