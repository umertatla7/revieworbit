# Email delivery

B Review sends registration, custom-plan, password-reset, and other operational email through one platform SMTP connection.

## Administration

Super administrators configure delivery under **Admin → Platform settings → Outgoing email (SMTP)**. The SMTP password is encrypted using Laravel's application key and is write-only: API responses, audit records, and application logs never contain it. Saving a configuration changes its status to `draft`; only a successful test email changes the status to `verified`.

The connection test performs a real SMTP-authenticated delivery attempt to the selected recipient. Its recorded error is deliberately sanitized so provider responses cannot leak the username, password, recipient, or message content. SMTP configuration changes and test outcomes are audited without email addresses or credentials.

Configuration stored in PostgreSQL takes precedence over environment mail variables while it is enabled. HTTP processes load the setting during application boot, and queue workers refresh it before every queued job, so credential rotations do not require a queue restart. Disabling the database setting restores the environment configuration.

## Hostinger defaults

Hostinger documents `smtp.hostinger.com` with SSL/TLS on port `465`. If that connection is unavailable, Hostinger's documented alternative is STARTTLS on port `587`. The SMTP username and From address should be the complete mailbox address.

Reference: [Hostinger email application settings](https://support.hostinger.com/en/articles/4305847-set-up-hostinger-email-on-your-applications-and-devices).

## Operational safety

- Never commit SMTP credentials to Git or place them in deployment logs.
- Rotate a password immediately if it is exposed in chat, a ticket, or shell history.
- Run a test after changing the host, port, encryption, username, password, or From identity.
- A successful SMTP test means the provider accepted the message; inbox placement still depends on SPF, DKIM, DMARC, reputation, and recipient filtering.
- Automated tests use Laravel's fake/array mail transport and never contact the production SMTP server.
