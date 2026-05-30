FROM node:22-alpine AS base
RUN npm install -g pnpm

FROM base AS deps
WORKDIR /app
COPY package.json pnpm-workspace.yaml pnpm-lock.yaml turbo.json ./
COPY packages/shared/package.json ./packages/shared/
COPY packages/bot-miki/package.json ./packages/bot-miki/
RUN pnpm install --frozen-lockfile

FROM base AS builder
WORKDIR /app
COPY package.json pnpm-workspace.yaml pnpm-lock.yaml turbo.json ./
COPY --from=deps /app/node_modules ./node_modules
COPY --from=deps /app/packages/shared/node_modules ./packages/shared/node_modules
COPY --from=deps /app/packages/bot-miki/node_modules ./packages/bot-miki/node_modules
COPY packages/shared ./packages/shared
COPY packages/bot-miki ./packages/bot-miki
RUN pnpm --filter @kpcrop/shared build
RUN pnpm --filter bot-miki build
# pnpm deploy crea standalone con modulos reales (sin symlinks) — resuelve el pnpm virtual store
RUN pnpm deploy --filter bot-miki --prod /deploy
# Copiar dist/ explicitamente (pnpm deploy no incluye archivos en .gitignore)
RUN cp -r /app/packages/bot-miki/dist/. /deploy/dist/
# Asegurar que @kpcrop/shared tenga su build
RUN cp -r /app/packages/shared/dist/. /deploy/node_modules/@kpcrop/shared/dist/ 2>/dev/null || true

FROM node:22-alpine AS runner
WORKDIR /app
ENV NODE_ENV=production
COPY --from=builder /deploy/node_modules ./node_modules
COPY --from=builder /deploy/dist ./dist
COPY --from=builder /app/packages/bot-miki/migrations ./migrations
EXPOSE 3000
CMD ["node", "dist/index.js"]
