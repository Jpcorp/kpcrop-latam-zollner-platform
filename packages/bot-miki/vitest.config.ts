import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    environment: 'node',
    pool: 'forks',
    // Los tests de rutas levantan Fastify y usan inject(): sobre WSL con el repo
    // en /mnt/c tardan 8-18s cada uno solo en arrancar. Aislados pasan con el
    // default de 5s, pero con la suite completa en paralelo la contencion los
    // hace fallar por timeout de forma intermitente — verde o rojo segun la
    // carga de la maquina, no segun el codigo. 30s los estabiliza sin tapar
    // nada: ninguno tarda mas de 20s ni siquiera en el peor caso medido.
    testTimeout: 30_000,
  },
});
