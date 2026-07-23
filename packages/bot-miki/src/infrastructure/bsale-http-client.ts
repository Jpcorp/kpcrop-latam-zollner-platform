import { config } from '../config.js';

const BASE_URL = 'https://api.bsale.io';

export class BsaleApiError extends Error {
  constructor(
    public readonly statusCode: number,
    message: string,
  ) {
    super(`Bsale API ${statusCode}: ${message}`);
  }

  get isClientError() { return this.statusCode >= 400 && this.statusCode < 500; }
  get isRateLimit()   { return this.statusCode === 429; }
  get isServerError() { return this.statusCode >= 500; }
}

// Rate limiter conservador: 10 req/s hasta confirmar el limite real de Bsale (ADR-004)
export class BsaleHttpClient {
  private lastRequestAt = 0;
  private readonly minIntervalMs: number;

  constructor(private readonly accessToken: string) {
    this.minIntervalMs = 1000 / config.BSALE_RATE_LIMIT_RPS;
  }

  async get<T>(path: string): Promise<T> {
    await this.throttle();

    const res = await fetch(`${BASE_URL}${path}`, {
      headers: { access_token: this.accessToken },
      signal: AbortSignal.timeout(30_000),
    });

    if (res.status === 429) {
      const retryAfter = parseInt(res.headers.get('Retry-After') ?? '60', 10);
      await sleep(retryAfter * 1000);
      return this.get<T>(path);
    }

    if (!res.ok) {
      throw new BsaleApiError(res.status, await res.text());
    }

    return res.json() as Promise<T>;
  }

  private async throttle(): Promise<void> {
    const elapsed = Date.now() - this.lastRequestAt;
    if (elapsed < this.minIntervalMs) {
      await sleep(this.minIntervalMs - elapsed);
    }
    this.lastRequestAt = Date.now();
  }
}

const sleep = (ms: number) => new Promise(r => setTimeout(r, ms));
