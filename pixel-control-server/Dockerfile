# ── Stage: build ──
FROM node:22-alpine AS build

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY prisma/ prisma/
COPY prisma.config.ts ./
ENV DATABASE_URL="postgresql://dummy:dummy@localhost:5432/dummy"
RUN npx prisma generate

COPY tsconfig.json tsconfig.build.json nest-cli.json ./
COPY src/ src/

RUN npm run build

# ── Stage: production ──
FROM node:22-alpine AS production

WORKDIR /app

COPY --from=build /app/package.json /app/package-lock.json ./
RUN npm ci --omit=dev

# Copy the generated Prisma client into node_modules
COPY --from=build /app/node_modules/.prisma/ node_modules/.prisma/
COPY --from=build /app/node_modules/@prisma/client/ node_modules/@prisma/client/

COPY --from=build /app/dist/ dist/

EXPOSE 3000

CMD ["node", "dist/main.js"]
