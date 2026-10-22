FROM node:24-alpine

WORKDIR /invalidator
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY . .
RUN npm run build && npm prune --omit=dev

CMD ["node", "dist/src/adapters/sqs-worker.js"]
