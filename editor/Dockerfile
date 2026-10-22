FROM node:24-alpine

WORKDIR /editor
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY . .
RUN npm run build && npm prune --omit=dev

CMD ["node", "dist/main.js"]
