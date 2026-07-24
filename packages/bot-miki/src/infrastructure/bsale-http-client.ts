import { createHash } from 'node:crypto';
import { Redis as IORedis } from 'ioredis';
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

// #104: token bucket en Redis, atomico via script Lua (EVAL). El limitador
// anterior era estado de instancia (this.lastRequestAt) — BsaleHttpClient se
// crea por job (processJob), asi que ni siquiera protegia a un mismo tenant
// con jobs concurrentes (ej. webhook + polling al mismo tiempo), y con
// concurrency:5 del worker se podia superar el limite real de Bsale.
// Compartido por token via Redis: correcto entre jobs concurrentes del mismo
// proceso Y entre instancias/procesos distintos si bot-miki llega a escalar
// horizontalmente (#113).
const TOKEN_BUCKET_SCRIPT = `
local bucket_key   = KEYS[1]
local capacity      = tonumber(ARGV[1])
local refill_per_ms = tonumber(ARGV[2])

local time_result = redis.call('TIME')
local now_ms = tonumber(time_result[1]) * 1000 + tonumber(time_result[2]) / 1000

local bucket      = redis.call('HMGET', bucket_key, 'tokens', 'last_refill')
local tokens       = tonumber(bucket[1])
local last_refill  = tonumber(bucket[2])

if tokens == nil then
  tokens = capacity
  last_refill = now_ms
end

local elapsed = math.max(0, now_ms - last_refill)
tokens = math.min(capacity, tokens + elapsed * refill_per_ms)

if tokens >= 1 then
  tokens = tokens - 1
  redis.call('HMSET', bucket_key, 'tokens', tokens, 'last_refill', now_ms)
  redis.call('EXPIRE', bucket_key, 60)
  return 0
else
  local wait_ms = math.ceil((1 - tokens) / refill_per_ms)
  redis.call('HMSET', bucket_key, 'tokens', tokens, 'last_refill', now_ms)
  redis.call('EXPIRE', bucket_key, 60)
  return wait_ms
end
`;

let sharedRedis: IORedis | null = null;
function getRedis(): IORedis {
  if (!sharedRedis) {
    sharedRedis = new IORedis(config.REDIS_URL, { maxRetriesPerRequest: null });
  }
  return sharedRedis;
}

/** Permite inyectar un cliente Redis mockeado en tests sin tocar el singleton real. */
export function __setRedisClientForTests(client: IORedis | null): void {
  sharedRedis = client;
}

// No exponemos el access_token en claves/logs de Redis — solo un hash.
function bucketKeyFor(accessToken: string): string {
  const hash = createHash('sha256').update(accessToken).digest('hex').slice(0, 16);
  return `bsale:ratelimit:${hash}`;
}

export class BsaleHttpClient {
  constructor(private readonly accessToken: string) {}

  async get<T>(path: string): Promise<T> {
    await this.acquireRateLimitSlot();

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

  private async acquireRateLimitSlot(): Promise<void> {
    const capacity     = config.BSALE_RATE_LIMIT_RPS;
    const refillPerMs   = capacity / 1000;
    const bucketKey     = bucketKeyFor(this.accessToken);

    for (;;) {
      const waitMs = await getRedis().eval(
        TOKEN_BUCKET_SCRIPT, 1, bucketKey, capacity, refillPerMs,
      ) as number;
      if (waitMs <= 0) return;
      await sleep(waitMs);
    }
  }
}

const sleep = (ms: number) => new Promise(r => setTimeout(r, ms));
