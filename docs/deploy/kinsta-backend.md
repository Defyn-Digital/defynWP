# Kinsta Backend Deploy Runbook

> Operator runbook for deploying the DefynWP dashboard + connector plugins to a Kinsta-hosted Bedrock WordPress site. Foundation phase; the codebase reaches `foundation-complete` after F10 merges, then this runbook is the operator path to production.

## Prerequisites

- Kinsta account with site quota available
- Domain ready for the backend (e.g. `defyn.com` or `api.defyn.example`)
- DNS provider access (Cloudflare recommended for both backend + SPA so CORS origins are simple)
- Local clone of `defynWP` (this repo) up to date with `main` at the `f10-deploy-harden-complete` tag
- SSH keypair added to Kinsta

## 1. Kinsta site setup

1. **MyKinsta → Sites → Add Site**
   - Display name: `<your-site-name>` (e.g. `defyn-dashboard`)
   - Data center: closest to your operator base
   - PHP version: **8.2** (8.1 is the floor; 8.2 is current LTS)
   - WordPress install: **Don't install** — we're using Bedrock, not stock WP

2. **Wait for provisioning to complete** (3-5 min). MyKinsta will show the green "Live" status and a `<site>-test.kinsta.cloud` staging URL.

3. **Note the credentials** under Info -> SSH/SFTP. You'll need:
   - SSH host + port
   - SSH user
   - DB host, name, user, password
   - DocumentRoot path (typically `/www/<site>/public`)

## 2. Deploy the Bedrock + plugins

The standard pattern is to push from GitHub via Kinsta's "git push deploy" or pull via SSH+Composer. Foundation is small enough that direct SFTP-after-Composer-install works fine too.

### 2a. SSH + Composer install path (recommended)

```bash
# 1. SSH into Kinsta
ssh <ssh-user>@<ssh-host> -p <ssh-port>

# 2. Clone the dashboard repo to a working directory
cd /tmp
git clone https://github.com/Defyn-Digital/defynWP.git
cd defynWP

# 3. Install Composer deps (Bedrock root)
composer install --no-dev --optimize-autoloader

# 4. Install Composer deps inside each plugin
cd packages/dashboard-plugin && composer install --no-dev --optimize-autoloader
cd ../connector-plugin && composer install --no-dev --optimize-autoloader

# 5. Build the SPA (covered in cloudflare-pages-spa.md; skip here)

# 6. Sync to the Kinsta DocumentRoot
rsync -av --exclude=.git --exclude=node_modules --exclude=apps/web \
      /tmp/defynWP/  /www/<site>/public/
```

(The exact paths depend on your Bedrock layout. The dashboard plugin lands at `web/app/plugins/defyn-dashboard/` and the connector plugin — if you're also using this Kinsta site as a "managed site" for self-testing — at `web/app/plugins/defyn-connector/`.)

### 2b. Git-push deploy path (alternative)

Set up Kinsta's GitHub integration (MyKinsta -> Site -> Add Git deployment). Push to `main` triggers a Composer install + sync. Slower iteration but simpler operationally.

## 3. Required `wp-config.php` constants

Add to the bottom of `wp-config.php` BEFORE the `/* That's all, stop editing! */` line:

```php
// DefynWP foundation constants
define('DEFYN_VAULT_KEY', '<BASE64_ENCODED_32_BYTES>');
define('DEFYN_JWT_SECRET', '<RANDOM_64_PLUS_CHARS>');

// DO NOT define DEFYN_TESTS_RUNNING in production (it disables the DNS gate)
```

### Generating the constants

```bash
# DEFYN_VAULT_KEY - 32 random bytes, base64-encoded (used by libsodium secretbox)
openssl rand -base64 32

# DEFYN_JWT_SECRET - 64+ char random string
openssl rand -base64 48
```

**Store these in a password manager.** Losing `DEFYN_VAULT_KEY` means all stored per-site private keys are unreadable; sites would need to be re-handshaken.

## 4. Plugin activation + DB migration

```bash
# Via WP-CLI (SSH'd in)
wp plugin activate defyn-dashboard --path=/www/<site>/public

# Or via wp-admin: /wp-admin/plugins.php -> Activate "DefynWP Dashboard"
```

Activation runs `Defyn\Dashboard\Activation::activate()` which:
- Creates `wp_defyn_sites`, `wp_defyn_connection_codes`, `wp_defyn_activity_log` via `dbDelta`
- Installs the F7 recurring AS schedules (`defyn_sync_all_sites`, `defyn_health_ping_all`, `defyn_cleanup_expired_codes`)

Action Scheduler creates its own tables on first cron tick.

## 5. Server cron (CRITICAL for F7 schedules)

Kinsta runs WP-Cron via web traffic by default. For production reliability, **switch to server cron**:

1. **Disable WP-Cron in `wp-config.php`:**
   ```php
   define('DISABLE_WP_CRON', true);
   ```

2. **Configure Kinsta server cron** (MyKinsta -> Site -> Cron jobs -> Add):
   - Command: `cd /www/<site>/public && /usr/local/bin/php wp-cron.php`
   - Schedule: every minute
   - User: kinsta site user

The F7 fan-out masters need this to fire reliably or no sync/health-ping ever runs.

## 6. CORS configuration

The dashboard's `Defyn\Dashboard\Rest\Middleware\Cors` middleware reads its allowed origins from somewhere (currently hardcoded; verify in `packages/dashboard-plugin/src/Rest/Middleware/Cors.php`).

For the foundation, you need to allow the SPA's production origin. Options:

- **Hardcoded**: Edit `Cors.php` to add your SPA origin (e.g. `https://app.defyn.dev`) to the allowed list, then redeploy.
- **Constant-driven** (future): wire a `DEFYN_ALLOWED_ORIGINS` constant in `wp-config.php` that `Cors` reads. (Post-foundation enhancement.)

Verify after deploy: `curl -I -H "Origin: https://app.defyn.dev" https://<backend>/wp-json/defyn/v1/auth/me` should return `Access-Control-Allow-Origin: https://app.defyn.dev`.

## 7. Rate limits

F3a's rate limiter uses WP transients. On Kinsta:

- **With Redis** (Kinsta Pro tier + Redis add-on): transients fan out via Redis, so rate limits hold across all PHP-FPM workers. Recommended.
- **Without Redis**: transients fall back to `wp_options` (DB-backed). Still works but slower; rate limits still hold across workers.

Verify by hitting `/wp-json/defyn/v1/auth/login` 6+ times with the same IP - the 6th attempt should return `429 auth.rate_limited`.

## 8. HTTPS

Kinsta auto-issues Let's Encrypt certs once DNS resolves to their endpoint. To trigger:

1. DNS: point `<backend-domain>` (A or CNAME) to the Kinsta site's IP / hostname (shown in MyKinsta -> Site -> Info)
2. Wait for propagation (5-60 min)
3. MyKinsta -> Tools -> SSL -> "Generate Let's Encrypt"
4. After issuance, in `wp-admin/options-general.php` set "Site address" + "WordPress address" to `https://<backend-domain>`

## 9. Verification

```bash
# 1. Health-check
curl -s https://<backend-domain>/wp-json/defyn/v1/auth/me
# Expected: {"error":{"code":"auth.missing_token","message":"..."}}

# 2. Unknown route (F10 envelope fix)
curl -s https://<backend-domain>/wp-json/defyn/v1/this-does-not-exist
# Expected: {"error":{"code":"rest.route_not_found","message":"Route not found."}}

# 3. AS schedules installed
wp action-scheduler list --status=pending --group=defyn --path=/www/<site>/public
# Expected: rows for defyn_sync_all_sites, defyn_health_ping_all, defyn_cleanup_expired_codes
```

## 10. Post-deploy checklist

- [ ] First admin user created via `wp user create` or `wp-admin/users.php`
- [ ] `Tools -> Scheduled Actions` link is NOT visible in `/wp-admin/tools.php` (F10 hide)
- [ ] `wp-config.php` constants verified via `wp eval 'echo (defined("DEFYN_VAULT_KEY") ? "OK" : "MISSING"), "\n";'`
- [ ] CORS smoke test from SPA origin returns the right `Access-Control-Allow-Origin` header
- [ ] Activity log table writes at least one row after first login (test by tailing): `wp db query 'SELECT * FROM wp_defyn_activity_log ORDER BY id DESC LIMIT 5' --path=/www/<site>/public`

## Operator handover

Once the above is green, the dashboard backend is production-ready. The SPA still needs deploying to Cloudflare Pages — see `cloudflare-pages-spa.md`. After both are live, run the manual E2E in `programmatic-e2e.md` (adapted for prod URLs) to end-to-end confirm handshake -> sync -> ping -> disconnect against a real managed WP site.

## Deferred to post-foundation

- Activity log retention TTL job (currently unbounded; consider `DELETE WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)` via a new AS schedule)
- Per-environment CORS via `DEFYN_ALLOWED_ORIGINS` constant
- Webhook for prod incident alerts (Slack / PagerDuty) - currently activity log is the only signal
