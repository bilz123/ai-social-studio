# syntax=docker/dockerfile:1
# React/Vite image. Only the `dev` target exists for now; the production target (static build served
# by nginx) is added with the deployment work in Phase 6.

FROM node:22-alpine AS base
WORKDIR /app

FROM base AS dev
COPY apps/web/package.json apps/web/package-lock.json ./
RUN npm ci
# Source is bind-mounted over /app at runtime; node_modules lives in a named volume.
EXPOSE 5173
CMD ["npm", "run", "dev", "--", "--host", "0.0.0.0"]
