# ReviewOrbit

ReviewOrbit is a multi-tenant Laravel + Next.js SaaS for consent-aware automated review requests. It records delivery and **Review link clicked** events without claiming that a Google review was submitted.

## Local prerequisites

Docker Desktop with Compose and GNU Make are recommended. Native development uses PHP 8.4/Composer 2 and Node.js 24 LTS/npm.

## Start locally

```bash
cp .env.example .env
make setup
make up
make migrate
make seed
```

- Web: <http://localhost:3000>
- API: <http://localhost:8080>
- API liveness: <http://localhost:8080/health/live>
- API readiness: <http://localhost:8080/health/ready>
- Mailpit: <http://localhost:8025>
- MinIO console: <http://localhost:9001>

Without Make, use the equivalent `docker compose build`, `docker compose up -d`, `docker compose exec api php artisan migrate`, and `docker compose exec api php artisan db:seed` commands.

Never place real provider secrets in committed files. Fake messaging is the default. Square and Twilio configuration steps are documented in `docs/SQUARE_INTEGRATION.md`, `docs/TWILIO_INTEGRATION.md`, and `.env.example`.

## Laravel Herd

This monorepo root is not directly servable by Herd: the browser application is Next.js in `apps/web`, while Laravel lives in `apps/api`. Configure them as separate sites:

```bash
# Remove an old link that points revieworbit.test at the monorepo root.
herd unlink revieworbit

# Serve Laravel from its real application directory.
cd apps/api
herd link api.revieworbit --secure --isolate=8.4
cd ../..

# Route the friendly HTTP frontend domain to the Next.js development server.
herd proxy revieworbit http://127.0.0.1:3000 --secure
make dev-web
# Run separately when exercising personalized media and later queued workflows.
make dev-worker
```

For the native Herd environment, set `SESSION_DOMAIN=.revieworbit.test`, `SANCTUM_STATEFUL_DOMAINS=revieworbit.test,api.revieworbit.test`, and `NEXT_PUBLIC_API_URL=https://api.revieworbit.test` in the application-specific environment files. Then open <https://revieworbit.test>. The API is available at <https://api.revieworbit.test/health/live>. Keep `make dev-web` running while using the frontend; a Herd proxy returns `502` when the Next.js process is stopped.

For local seed data, set `SEED_DEFAULT_PASSWORD` in `apps/api/.env`, then run `cd apps/api && php artisan db:seed`. The seeded super-admin email defaults to `admin@revieworbit.test`, and the separate customer owner defaults to `owner@albarbershop.test`; the password is intentionally never defined in committed files.

## Current status

Milestones 1–7 and the first Square integration slice are implemented, together with customer/platform dashboard separation and the tenant-administration portion of Milestone 8. Customer signup enters guided onboarding; Square OAuth can securely import locations, customer profiles, and previous/upcoming appointments; generic POS/API credentials remain available; and platform admins use audited time-limited support sessions when configuring a customer tenant. SMS/WhatsApp delivery now includes isolated Twilio configuration, channel-specific consent, approved WhatsApp templates, signed callbacks, opt-outs, and masked delivery history. Live sending requires customer sender approval and external Twilio configuration. Continuous Square webhooks remain a separate next milestone.

The customer and platform consoles use separate responsive sidebar navigation. All specification modules are represented. Unfinished modules open clearly labelled static roadmap screens and do not expose data-changing placeholder actions.

See `docs/IMPLEMENTATION_PLAN.md` for sequence, assumptions, risks, and external prerequisites.
