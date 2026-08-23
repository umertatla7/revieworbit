# Deployment

## Local

Copy `.env.example` to `.env`, run `make setup`, then `make up`, `make migrate`, and `make seed`. The web app, API proxy, Mailpit, and MinIO console ports are documented in the root README. Equivalent Compose/Composer/npm commands are printed by `make help`.

## Production

Build immutable Next.js and PHP/Nginx images. Inject secrets from a secret manager, terminate TLS at ingress, run migrations as a controlled release job, and deploy API, Horizon/queue workers, and scheduler separately from the same application revision. Run only one scheduler leader. Private S3-compatible storage, managed PostgreSQL/Redis, transactional mail, centralized structured logs, alerts, and health probes are required.

Workers must subscribe to `default,webhooks,integrations,messaging,media,analytics`. Scale messaging with provider-rate limits and preserve ordered/idempotent status transitions. Scheduler runs every minute and dispatches due work; it does not perform long tasks inline.

## Local Herd web process

Herd proxies `revieworbit.test` to `127.0.0.1:3000`. On the development Mac, the three files in `infrastructure/macos` are installed as user LaunchAgents. `com.revieworbit.web` keeps Next.js available and avoids a recurring Herd 502. `com.revieworbit.queue` processes webhook, integration, messaging, media, analytics, and default jobs. `com.revieworbit.scheduler` evaluates scheduled tasks each minute. Their standard output and error logs use `/tmp/revieworbit-{web,queue,scheduler}*.log`.

Back up PostgreSQL with encrypted point-in-time recovery and routinely tested restores. Version object storage where required and apply lifecycle rules to personalized media. Before deploy, verify migrations, tests, API-client drift, production builds, dependency audits, secrets, queue compatibility, and rollback behavior.

Rollback application images independently when migrations are backward-compatible. Schema removals use expand/migrate/contract over multiple releases. If a migration fails, halt rollout, restore the prior application, and apply a reviewed forward repair; do not mutate an already-applied shared migration.

Health endpoints distinguish liveness from readiness (database, Redis, and storage where appropriate) and never reveal secrets or internal exception details.
