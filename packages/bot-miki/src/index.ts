import { buildApp } from './app.js';
import { config } from './config.js';

const app = buildApp();

try {
  await app.listen({ port: config.PORT, host: '0.0.0.0' });
} catch (err) {
  app.log.error(err);
  process.exit(1);
}
