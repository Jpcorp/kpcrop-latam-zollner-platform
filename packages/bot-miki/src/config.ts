import { z } from 'zod';

const schema = z.object({
  NODE_ENV: z.enum(['development', 'staging', 'production']).default('development'),
  PORT: z.coerce.number().default(3000),
  LOG_LEVEL: z.enum(['debug', 'info', 'warn', 'error']).default('info'),
  DATABASE_URL: z.string().url(),
  REDIS_URL: z.string().url(),
  JWT_SECRET: z.string().min(32),
  BSALE_RATE_LIMIT_RPS: z.coerce.number().default(10),
  STRIPE_SECRET_KEY: z.string().optional(),
  STRIPE_WEBHOOK_SECRET: z.string().optional(),
});

const result = schema.safeParse(process.env);

if (!result.success) {
  console.error('Variables de entorno invalidas:');
  console.error(result.error.flatten().fieldErrors);
  process.exit(1);
}

export const config = result.data;
