FROM node:22-alpine AS builder

WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm ci
COPY tsconfig.json ./
COPY src/ ./src/
RUN npm run build

FROM node:22-alpine

WORKDIR /app

# Nur Produktions-Abhängigkeiten
COPY package.json package-lock.json* ./
RUN npm ci --omit=dev && npm cache clean --force

# Kompilierter Code
COPY --from=builder /app/dist ./dist

# Nicht als root ausführen
USER node

EXPOSE 3100

HEALTHCHECK --interval=30s --timeout=10s --start-period=10s --retries=3 \
  CMD ["node", "-e", "fetch('http://localhost:3100/health').then(r=>{if(!r.ok)throw 1}).catch(()=>process.exit(1))"]

CMD ["node", "dist/index.js"]
