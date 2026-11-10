FROM node:24.10.0-bookworm-slim AS build
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts --no-audit --no-fund
COPY tsconfig.json ./
COPY src ./src
RUN npm run build && npm prune --omit=dev --ignore-scripts --no-audit --no-fund

FROM node:24.10.0-bookworm-slim
ENV NODE_ENV=production BOT_DATA_DIR=/data/state
WORKDIR /app
COPY --from=build /app/node_modules ./node_modules
COPY --from=build /app/dist ./dist
COPY package.json ./
COPY harness2/LICENSE harness2/upstream-reference.json ./harness2/
USER node
CMD ["node", "dist/src/main.js"]
