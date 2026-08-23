# Security

## Threat model

Primary risks are cross-tenant object access, stolen sessions/API/provider tokens, forged or replayed webhooks, duplicate messages, PII leakage, malicious uploads, open redirects, privilege escalation, and operational misuse.

## Controls

- Tenant context derives from authenticated membership or verified integration mapping; scoped queries, bindings, policies, database constraints, and adversarial tests provide defense in depth.
- Sanctum uses secure HTTP-only same-site cookies, CSRF protection, session rotation, expiration, account-status middleware, email verification, and rate-limited auth flows.
- Provider credentials use framework encryption backed by a managed application key. API keys are high entropy, hashed, scoped, expirable, revocable, and displayed once.
- OAuth uses signed single-use state bound to user/business/session and strict allow-listed callback URLs.
- Webhooks preserve the raw body, verify current official algorithms in constant time, reject stale/replayed requests, persist before dispatch, and process idempotently.
- Uploads are private, randomly named, size/MIME/dimension checked, decoded/re-encoded where appropriate, and exposed only with short signed URLs.
- Logs redact secrets and PII. Audits record actor, tenant, action, target, safe changes, request ID, minimized network metadata, and time.
- Review destinations are saved only after HTTPS URL validation and are not accepted from tracking requests, preventing open redirects.

Security headers include HSTS in production, CSP, frame denial, MIME sniffing prevention, a restrictive referrer policy, and permissions policy. Dependency audits, static analysis, backups, key rotation, alerting, and an incident runbook are deployment requirements.
