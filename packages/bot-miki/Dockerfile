FROM node:22-alpine AS base
RUN npm install -g pnpm

FROM base AS deps
WORKDIR /app
COPY package.json pnpm-workspace.yaml turbo.json ./
COPY packages/shared/package.json ./packages/shared/
COPY packages/bot-miki/package.json ./packages/bot-miki/
RUN pnpm install --frozen-lockfile

FROM base AS builder
WORKDIR /app
COPY --from=deps /app/node_modules ./node_modules
COPY --from=deps /app/packages/shared/node_modules ./packages/shared/node_modules
COPY --from=deps /app/packages/bot-miki/node_modules ./packages/bot-miki/node_modules
COPY packages/shared ./packages/shared
COPY packages/bot-miki ./packages/bot-miki
RUN pnpm --filter @kpcrop/shared build
RUN pnpm --filter bot-miki build

FROM node:22-alpine AS runner
WORKDIR /app
ENV NODE_ENV=production
COPY --from=builder /app/packages/bot-miki/dist ./dist
COPY --from=builder /app/packages/bot-miki/node_modules ./node_modules
COPY --from=builder /app/packages/shared/dist ./node_modules/@kpcrop/shared/dist
EXPOSE 3000
CMD ["node", "dist/index.js"]
