FROM node:24-alpine AS dependencies
WORKDIR /app
COPY apps/web/package.json apps/web/package-lock.json* ./
RUN npm install

FROM node:24-alpine AS development
WORKDIR /app
COPY --from=dependencies /app/node_modules ./node_modules
COPY apps/web ./
EXPOSE 3000
CMD ["npm", "run", "dev", "--", "--hostname", "0.0.0.0"]

FROM development AS build
RUN npm run build

FROM node:24-alpine AS production
ENV NODE_ENV=production
WORKDIR /app
COPY --from=build /app/.next/standalone ./
COPY --from=build /app/.next/static ./.next/static
COPY --from=build /app/public ./public
EXPOSE 3000
CMD ["node", "server.js"]

