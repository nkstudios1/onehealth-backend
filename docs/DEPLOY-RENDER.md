# Deploying the OneHealth API with Docker and Render

This guide covers what was added to the backend, then **everything you set up on Render**, then how the frontend must connect (there is one trap here, read section 7).

> Status: the Docker files were written and syntax-checked, but **I could not run `docker build` in my workspace**. Do the local test in section 3 first. If the build fails, send me the error text.

---

## 1. What was added

| File | What it is |
|---|---|
| `Dockerfile` | Recipe for the image. Stage 1 installs PHP packages with Composer. Stage 2 is FrankenPHP (PHP and a web server in one process) plus the Postgres driver. |
| `docker/Caddyfile` | Web server config. Listens on `$PORT` (Render sets it), serves `public/`, hides dotfiles. |
| `docker/entrypoint.sh` | Runs on every start: checks the secrets exist, caches config, runs migrations, optionally seeds the demo data, starts the scheduler, then starts the server. |
| `.dockerignore` | Files kept OUT of the image (`.env`, `vendor`, tests...). Keeps secrets out and the build fast. |
| `render.yaml` | A Render "Blueprint": creates the database and web service from one file. |
| `docker-compose.yml` | Runs the API plus a Postgres on your own machine to test the image before deploying. |
| `bootstrap/app.php` | New line `trustProxies(at: '*')`. |
| `DatabaseSeeder` | Now skips itself when users already exist, so seeding on every deploy is safe. |

### Concepts, once

- **Image**: a frozen copy of your app plus everything it needs to run (PHP, extensions, packages). Built once.
- **Container**: a running copy of an image. Render starts one for you. When it restarts, anything written inside it is **gone**. That is why the database must be Postgres (a separate service) and not the SQLite file we used in development.
- **Blueprint** (`render.yaml`): a file that tells Render what to create. Optional, you can click it all together by hand (section 5B).
- **`trustProxies`**: on Render your app sits behind Render's load balancer. Without this line Laravel thinks every visitor has the load balancer's address, so the rate limits (5 scans per card per hour, login lockouts) would count everyone as one person. With it, Laravel reads the real address from the `X-Forwarded-For` header.

---

## 2. Before you touch Render

1. **Run `composer install` once on your machine** and commit the `composer.lock` file it creates. The zip has no lock file, so without it Docker installs "whatever is newest" at build time, and a build that worked yesterday can break today.
2. **Put the API in a GitHub repo.** If it lives inside your monorepo (for example `apps/api`), remember that folder for "Root Directory" below.
3. **Generate two secrets** (keep them in a password manager; losing `NIN_HASH_KEY` later means every stored NIN stops matching):

```
php artisan key:generate --show          # prints base64:....   = APP_KEY
php -r "echo bin2hex(random_bytes(32));" # 64 hex characters   = NIN_HASH_KEY
```

---

## 3. Test the image on your machine (Windows)

Install Docker Desktop, then in the API folder:

```
docker compose up --build
```

First build takes a few minutes. Then:

```
curl http://localhost:8080/up
curl http://localhost:8080/api/ping
```

`/up` answering 200 and `/api/ping` returning `{"success":true,...}` means the image, the database connection and the migrations all work. Seeded accounts exist (`SEED_DEMO` is true in the compose file). Stop with Ctrl+C, wipe the database with `docker compose down -v`.

The keys in `docker-compose.yml` are throwaway values for local use only.

---

## 4. Database first: Neon (default) or Render Postgres

**Recommended for the hackathon: Neon.** Its free database is **not deleted after 30 days** (Render's is), and it has 0.5 GB, which is plenty. The API image already supports it.

### 4.0 Neon setup

1. Sign up at neon.tech, **Create project**. Name `onehealth`, Postgres 16 or newer.
2. **Region**: pick the one closest to your Render region (both in Europe, for example Frankfurt). Query speed depends on this distance.
3. Click **Connect** on the project dashboard. The dialog offers a **Connection pooling** toggle. Copy **both** strings:

| Toggle | Looks like | Goes into |
|---|---|---|
| On (pooled) | `postgresql://USER:PASS@ep-xxxx-pooler.REGION.aws.neon.tech/neondb?sslmode=require` | `DB_URL` |
| Off (direct) | `postgresql://USER:PASS@ep-xxxx.REGION.aws.neon.tech/neondb?sslmode=require` | `DB_DIRECT_URL` |

4. If a string ends with `&channel_binding=require`, **delete that part**. Keep `sslmode=require` (Neon refuses unencrypted connections).

**Why two strings? (concept: connection pooling).** Every web request opens a database connection, and Postgres only allows a limited number at once. Neon's pooler (the `-pooler` host) shares a few real connections among many requests, so the running app should use it. But schema changes (migrations) are long conversations that the pooler can interrupt, so migrations use the direct address. The container's start-up script does that for you: it runs `migrate` and the seeder with `DB_DIRECT_URL`, then the app itself uses `DB_URL`. If you only set `DB_URL`, everything still works, using that one string for both.

**Neon good to know**

- It **scales to zero** after about 5 minutes without queries: the next query takes roughly half a second to a second extra while it wakes. Not a problem, but do one request before recording.
- Passwords with special characters must be URL-encoded in the string (Neon's copy button already does this).
- Use the branch feature later for a safe copy of the data (a "staging" branch) without a second project.

### 4.0b Render Postgres instead

New +, **PostgreSQL**, same region as the web service, copy the **Internal Database URL**, put it in `DB_URL`, and leave `DB_DIRECT_URL` unset. Free tier is deleted after 30 days. If you use the Blueprint, uncomment the `databases:` block in `render.yaml` as its header explains.

---

## 5. Render setup

### 5A. The fast way: Blueprint

1. Render dashboard, **New +**, **Blueprint**, connect your GitHub repo.
2. Render reads `render.yaml` and shows one web service (the database is Neon, section 4). If the API is in a subfolder, edit `render.yaml` first and add `rootDir: apps/api` under the service.
3. It asks for the values marked `sync: false`: `APP_KEY`, `NIN_HASH_KEY`, `DB_URL`, `DB_DIRECT_URL`, `APP_URL`, `CORS_ALLOWED_ORIGINS`, `APPROVAL_LINK_BASE`. For `APP_URL` you do not know the address yet: type `https://onehealth-api.onrender.com`, then correct it after the first deploy if Render gave a different name.
4. **Apply.** The first build takes about 5 to 10 minutes.

### 5B. By hand (what each setting means)

**Step 1: the database.** Already done in section 4.

**Step 2: the web service.** New +, **Web Service**, pick your repo.

| Setting | Value |
|---|---|
| Language / Runtime | **Docker** |
| Root Directory | blank, or the API folder in your monorepo |
| Dockerfile Path | `./Dockerfile` |
| Region | as close as possible to the database (Neon region, or Render Postgres region) |
| Instance type | Free for the demo (sleeps after 15 minutes idle, then the first request takes about a minute). Starter ($7/mo) does not sleep. |
| Health Check Path | `/up` |
| Auto-Deploy | On (deploys when you push to the branch) |

**Step 3: environment variables** (Environment tab):

| Key | Value | Why |
|---|---|---|
| `APP_NAME` | `OneHealth` | |
| `APP_ENV` | `staging` for the demo, `production` for real | The demo seeder **refuses** to run when this is `production` (it creates known passwords). |
| `APP_DEBUG` | `false` | `true` would show stack traces to users. |
| `APP_KEY` | `base64:...` from section 2 | Encrypts cookies. The container refuses to start without it. |
| `APP_URL` | `https://<your-service>.onrender.com` | |
| `NIN_HASH_KEY` | 64 hex characters from section 2 | The container refuses to start without it. |
| `DB_CONNECTION` | `pgsql` | |
| `DB_URL` | Neon **pooled** string (Render Postgres: its Internal URL) | Laravel reads host, user, password and SSL mode from this one value. |
| `DB_DIRECT_URL` | Neon **direct** string. Leave unset for Render Postgres. | Used only for migrations and seeding at start-up. |
| `LOG_CHANNEL` | `stderr` | Writes logs to Render's **Logs** tab. Files inside a container vanish on restart. |
| `LOG_LEVEL` | `info` | |
| `SESSION_DRIVER` | `database` | |
| `CACHE_STORE` | `database` | Rate limits live here, so they survive restarts. |
| `QUEUE_CONNECTION` | `sync` | Nothing in the app needs a queue worker yet. |
| `SESSION_SECURE_COOKIE` | `true` | Cookies only over HTTPS. |
| `SESSION_SAME_SITE` | `lax` | See section 7 before changing it. |
| `CORS_ALLOWED_ORIGINS` | your frontend origin, no trailing slash | Only needed if the browser calls the API directly (section 7 avoids that). |
| `APPROVAL_LINK_BASE` | `https://<your-frontend>/approve` | The link inside the contact's SMS. |
| `NIN_DRIVER` | `fake` | No real NIN service yet. |
| `SMS_DRIVER` | `log` | SMS goes to the Logs tab. That is how you read the contact code during the demo. |
| `OTP_FIXED_CODE` | `1234` | **Demo only.** Every verification code becomes 1234. Delete before a real person signs up. |
| `ACCESS_PATIENT_WAIT_SECONDS` | `15` | Demo: the contacts are asked after 15 seconds, not 2 minutes. |
| `RUN_MIGRATIONS` | `true` | Creates the tables on start. |
| `SEED_DEMO` | `true` | Creates Tunde, the hospital and the card, once. |
| `RUN_SCHEDULER` | `true` | Background loop that escalates to the contacts. |

**Step 4: deploy** (Manual Deploy, Deploy latest commit if it did not start). Watch the **Logs** tab. A good start ends with lines like `Migrated: ...`, then the Caddy server starting.

---

## 6. After the first deploy: check it works

```
curl https://<your-service>.onrender.com/up
curl https://<your-service>.onrender.com/api/ping
```

Then, in the Render dashboard:

- **Logs**: no red errors.
- **Shell** tab (paid plans only): `php artisan tinker`, then `App\Models\HealthCard::first()->makeVisible('qr_token')->qr_token` gives the QR token for the hospital scan. On the free plan, log in as Tunde and read the token from the `/cards/me` response in the browser's network tab.

The SMS the contact would receive shows up in Logs as the message text with the code and link.

---

## 7. Connecting the frontend (the trap)

The frontend sends the session cookie and reads a second cookie (`XSRF-TOKEN`) from JavaScript to protect against forged requests. **A page on one website cannot read a cookie that belongs to another website.** If the frontend is `onehealth.vercel.app` and the API is `onehealth-api.onrender.com`, those are different sites (both `vercel.app` and `onrender.com` are on the public suffix list, so they never count as siblings), and the browser will:

- refuse to let `document.cookie` see the API's XSRF cookie, so every POST fails with `REQUEST_FORGERY`, and
- block the session cookie as a third-party cookie in several browsers.

**Fix for the demo: make the browser think it is talking to one site.** Have the frontend's server forward `/api/*` to Render. In the Next.js app (`next.config.ts`):

```ts
import type { NextConfig } from "next"

const nextConfig: NextConfig = {
  async rewrites() {
    return [
      // The browser calls /api/... on the frontend's own address; Next forwards it to Render.
      { source: "/api/:path*", destination: `${process.env.API_ORIGIN}/api/:path*` },
    ]
  },
}

export default nextConfig
```

Frontend environment variables (Vercel, or wherever it is hosted):

```
API_ORIGIN=https://<your-service>.onrender.com     # server side only, no NEXT_PUBLIC_
NEXT_PUBLIC_API_URL=/api/v1                        # relative: same address as the page
NEXT_PUBLIC_USE_MOCKS=false
```

Now both cookies belong to the frontend's address, CORS is not involved at all, and `SESSION_SAME_SITE=lax` is correct. `SESSION_DOMAIN` stays unset.

For production, the cleaner version is a custom domain with the app on `app.onehealth.ng` and the API on `api.onehealth.ng` (siblings under one registrable domain), then set `SESSION_DOMAIN=.onehealth.ng` and `CORS_ALLOWED_ORIGINS=https://app.onehealth.ng`. Decide that with your team before launch.

---

## 8. Demo-day gotchas

| What | What to do |
|---|---|
| Free web service is asleep | Open `/up` in a browser 2 minutes before recording. Never record the cold start. |
| Neon wakes from sleep | First query after 5 idle minutes is about a second slower. Send one request before recording. |
| Render Postgres free plan | Deleted after 30 days. Neon's free database is not. |
| Re-seeding | The seeder skips when users exist. To start clean on Neon: in the project, **Branches**, reset the branch to empty, or in the SQL Editor run `DROP SCHEMA public CASCADE; CREATE SCHEMA public;` then redeploy (the start-up script migrates and seeds again). |
| Contact "SMS" | Read it in the web service's Logs tab. Keep that tab open next to the recording. |
| Slow first click after idle | Free plan only. Starter plan removes it. |

---

## 9. Before real patients

- [ ] `APP_ENV=production`, `SEED_DEMO=false`, **`OTP_FIXED_CODE` deleted** (this one is a security hole if left in).
- [ ] A real SMS gateway and NIN verifier (`SMS_DRIVER`, `NIN_DRIVER`), see the backend guide section 8.
- [ ] Neon paid plan (or Render paid Postgres) with point-in-time restore on, same region as the web service.
- [ ] Paid web service (no sleeping) and a Render **Cron Job** running `php artisan schedule:run` every minute, then set `RUN_SCHEDULER=false` so two schedulers do not both run.
- [ ] Custom domain on HTTPS, sibling subdomains, `SESSION_DOMAIN` set.
- [ ] A database user for the app with no UPDATE or DELETE on `audit_logs`.
- [ ] Container runs as root today (simplest with FrankenPHP). Switch to a non-root user before production.
- [ ] Lawyer review under NDPA 2023.

---

## 10. If something fails

| Symptom | Cause | Fix |
|---|---|---|
| Container exits at once: "APP_KEY is not set" | missing env var | Environment tab, add it, redeploy |
| "could not find driver" | Postgres extension missing | rebuild; the Dockerfile installs `pdo_pgsql`, so make sure the old image is not cached (Manual Deploy, Clear build cache) |
| `SQLSTATE connection refused` / timeout | `DB_URL` wrong | check the string was pasted whole, no spaces, `sslmode=require` present. For Render Postgres use the **Internal** URL and the same region. |
| `SSL connection is required` / `no pg_hba.conf entry` | `sslmode=require` missing from a Neon string | add `?sslmode=require` |
| `prepared statement ... does not exist` | pooled string used for migrations | set `DB_DIRECT_URL` to the direct (non-`-pooler`) string |
| `password authentication failed` | stale password after a reset in Neon | copy a fresh string from **Connect** |
| Migration fails on Postgres | a migration only tested on SQLite | send me the error text from Logs; this is the likeliest first-deploy problem because tests ran on SQLite |
| `REQUEST_FORGERY` on every POST | cross-site cookies | section 7 |
| Rate limits trip for everyone | proxy headers not trusted | confirm the `trustProxies` line is in `bootstrap/app.php` and redeployed |
| 502 or "service unavailable" for a minute | free plan waking up | wait, then retry |
| Build fails at `composer install` | package or PHP version problem | open the build log, send me the red lines |
