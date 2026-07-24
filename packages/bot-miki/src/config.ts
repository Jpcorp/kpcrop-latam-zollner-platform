import { z } from 'zod';

const schema = z.object({
  NODE_ENV: z.enum(['development', 'staging', 'production', 'test']).default('development'),
  PORT: z.coerce.number().default(3000),
  LOG_LEVEL: z.enum(['debug', 'info', 'warn', 'error']).default('info'),
  DATABASE_URL: z.string().url(),
  REDIS_URL: z.string().url(),
  JWT_SECRET: z.string().min(32),
  // #92: obligatorio y sin default (un default publico era bypass de auth admin).
  // Debe estar seteado en el entorno (Railway) antes de arrancar, o el proceso aborta.
  ADMIN_KEY: z.string().min(32),
  BSALE_RATE_LIMIT_RPS: z.coerce.number().default(10),
  // #107: cifra tenant_stores.bsale_access_token en reposo (antes texto plano).
  // Llave dedicada, no compartida con JWT_SECRET/ADMIN_KEY — si una se filtra
  // no compromete las otras.
  TOKEN_ENCRYPTION_KEY: z.string().min(32),
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
