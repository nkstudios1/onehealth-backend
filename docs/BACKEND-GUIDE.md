# OneHealth API: Laravel 13 backend (patient auth)

The backend for the patient app. It covers the full auth flow that the frontend already has: sign-up, login, session, and password reset. It also holds the **standard layer** that every future module plugs into: one response shape, one error system, one way to log security events.

> **Stack:** Laravel 13, PHP 8.3+, SQLite for development (PostgreSQL later), cookie sessions (no tokens yet).
> **Backend note:** earlier guides and the README say Django. That is replaced by Laravel 13. Update them.

---

## 1. Run it (Windows)

You need PHP 8.3 or newer and Composer. Easiest on Windows: install **Laravel Herd** (https://herd.laravel.com) which gives you both. Then, in PowerShell inside the `onehealth-api` folder:

```powershell
composer install
copy .env.example .env
php artisan key:generate
```

Open `.env` and set `NIN_HASH_KEY` to a long random secret. Generate one:

```powershell
php -r "echo bin2hex(random_bytes(32));"
```

> **Never change `NIN_HASH_KEY` after real patients exist.** NINs are stored only as a keyed hash of this key. Change the key and every stored NIN stops matching.

```powershell
php artisan migrate --seed
php artisan serve
```

The API is now at `http://localhost:8000/api`. Check it: open `http://localhost:8000/api/ping`.

`--seed` creates one test patient: phone `08000000001`, personal ID `4829175036`, password `Password1`. (Same magic phone the frontend mock uses for "already registered".)

Run the tests any time:

```powershell
php artisan test
```

### Development shortcuts (never in production)

| `.env` value | What it does |
|---|---|
| `OTP_FIXED_CODE=1234` | Every one-time code is `1234`. The app **refuses to issue codes** if this is set in production. |
| `SMS_DRIVER=log` | Text messages are written to `storage/logs/laravel.log` instead of sent. **Refuses to boot** in production. |
| `NIN_DRIVER=fake` | Fake NIN lookup. NIN `00000000000` = not found. Anything else returns a made-up person chosen by the last digit. **Refuses to boot** in production. |

---

## 2. The standard (read this before writing any module)

### 2.1 Every response has the same envelope

Success:

```json
{ "success": true, "message": "Logged in.", "data": { "...": "..." }, "meta": { "requestId": "9f1c2b0e-..." } }
```

Error:

```json
{
  "success": false,
  "message": "That code isn't correct. Check it and try again.",
  "code": "OTP_INVALID",
  "errors": null,
  "meta": { "requestId": "9f1c2b0e-...", "retryAfter": 120 }
}
```

- `errors` is `null`, or for form problems `{ "phone": ["Enter a valid 11-digit phone number."] }`.
- `meta.retryAfter` (seconds) appears on rate-limit errors, with a `Retry-After` header.
- The same `requestId` is also in the `X-Request-Id` header and in every log line for that request. **When a user reports a problem, ask for this id** and you can find exactly what happened.
- Keys are **camelCase**. Clients switch on `code`, never on `message`.

This is guaranteed for every route, including 404, 405, 401, 419, 422, 429 and crashes. `tests/Feature/Api/EnvelopeTest.php` proves it. A crash returns a generic 500 and **never** a stack trace (details only appear when `APP_DEBUG=true`).

### 2.2 Writing a controller

Extend `ApiController`. It gives you `ok()`, `created()`, `paginated()` and `fail()`:

```php
class RecordController extends ApiController
{
    public function show(Record $record): JsonResponse
    {
        return $this->ok(new RecordResource($record));
    }
}
```

Never call `response()->json()` directly. It skips the envelope.

Controllers stay thin: **validate with a Form Request, call a service, return a response.** Business rules go in `app/Services/<Module>`.

### 2.3 Failing on purpose

Throw `ApiException` from anywhere (service or controller). The handler turns it into the standard error:

```php
throw new ApiException(ErrorCode::OtpInvalid);
throw ApiException::of(ErrorCode::PhoneAlreadyRegistered, errors: ['phone' => ['Already used']]);
throw ApiException::tooManyAttempts(retryAfter: 120);
```

Do **not** throw `ApiException` for bugs. Let those bubble up. They become a safe generic 500 and are logged with the request id.

### 2.4 Adding an error code

Add a case to `app/Enums/ErrorCode.php` and fill in `status()` and `message()`. `tests/Unit/SupportTest.php` fails if you forget either, or reuse a value. The string value is a contract with the frontend: add it to the frontend's `ApiErrorCode` type too.

All current codes:

| Code | HTTP | Default message |
|---|---|---|
| `BAD_REQUEST` | 400 | We couldn't understand that request. |
| `VALIDATION_FAILED` | 422 | Some of the details you entered are not valid. |
| `UNAUTHENTICATED` | 401 | Please log in to continue. |
| `FORBIDDEN` | 403 | You don't have permission to do that. |
| `NOT_FOUND` | 404 | We couldn't find what you were looking for. |
| `METHOD_NOT_ALLOWED` | 405 | That action isn't supported here. |
| `REQUEST_FORGERY` | 419 | Your session expired. Refresh the page and try again. |
| `TOO_MANY_ATTEMPTS` | 429 | Too many attempts. Try again in a few minutes. |
| `SERVER_ERROR` | 500 | Something went wrong on our side. Please try again. |
| `SERVICE_UNAVAILABLE` | 503 | The service is temporarily unavailable. Please try again shortly. |
| `NIN_NOT_FOUND` | 404 | We couldn't find this NIN. Check the number and try again. |
| `NIN_ALREADY_REGISTERED` | 409 | An account already exists for this NIN. Try logging in. |
| `NIN_SERVICE_UNAVAILABLE` | 503 | We couldn't verify your NIN right now. Please try again shortly. |
| `PHONE_ALREADY_REGISTERED` | 409 | This phone number is already registered. Try logging in. |
| `OTP_INVALID` | 422 | That code isn't correct. Check it and try again. |
| `OTP_EXPIRED` | 422 | That code has expired. Request a new one. |
| `OTP_DELIVERY_FAILED` | 503 | We couldn't send the code. Please try again. |
| `REGISTRATION_NOT_FOUND` | 404 | Your sign-up session expired. Please start again. |
| `REGISTRATION_STEP_INVALID` | 409 | Please complete the previous step first. |
| `REGISTRATION_FAILED` | 500 | We couldn't complete your registration. |
| `INVALID_CREDENTIALS` | 401 | Your personal ID, email or password is incorrect. |
| `ACCOUNT_SUSPENDED` | 403 | This account is not active. Please contact support. |
| `RESET_NOT_FOUND` | 404 | Your reset session expired. Please start again. |
| `CARD_NOT_FOUND` | 404 | We couldn't find that card. Check the number and try again. |
| `CARD_NOT_ACTIVE` | 409 | This card is not active. |
| `ORGANISATION_NOT_VERIFIED` | 403 | Your organisation has not been verified yet. |
| `ACCESS_REQUEST_NOT_FOUND` | 404 | We couldn't find that access request. |
| `ACCESS_REQUEST_CLOSED` | 409 | This request has already been answered or has expired. |
| `APPROVAL_CODE_INVALID` | 422 | That code isn't correct. Check it and try again. |
| `SESSION_NOT_FOUND` | 404 | We couldn't find that session. |
| `SESSION_CLOSED` | 409 | This session has ended. |
| `RECORD_NOT_FOUND` | 404 | We couldn't find that record. |

### 2.5 Audit log

Security-relevant events go to the `audit_logs` table through one call:

```php
Audit::record(AuditEvent::LoginFailed, $user?->id, ['reason' => 'bad_credentials']);
```

- Add a case to `AuditEvent` for each new event type.
- **Never** put a password, code, token or full NIN in the metadata.
- The table is append-only: the model throws if you update or delete a row. In production, also revoke `UPDATE` and `DELETE` on that table from the app's database user. That is the real guarantee.
- A failed audit write is logged but never breaks the request that caused it.

---

## 3. Adding a module

Example: records. Five steps, nothing to register anywhere:

1. **Routes**: create `routes/api/v1/records.php`. Every file in that folder is loaded automatically under `/api/v1`, inside the `api` middleware group (JSON only, session, forgery protection).
2. **Protect them**: `Route::middleware(['auth:web', 'active'])->group(...)`. `active` signs out accounts suspended while logged in.
3. **Controller**: `app/Http/Controllers/Api/V1/Records/...`, extending `ApiController`.
4. **Form Requests**: `app/Http/Requests/Api/V1/Records/...` for validation.
5. **Service**: `app/Services/Records/...` for the rules. Throw `ApiException` for expected failures, call `Audit::record()` for sensitive actions.

Also: return data through a `JsonResource` (see `UserResource`). Never return a model directly, because a column added later would leak. Give every new endpoint a rate limit (`throttle:name`, defined in `AppServiceProvider::configureRateLimiting()`).

Folder map:

```
app/
  Contracts/            interfaces for swappable services (NinVerifier, SmsGateway)
  Enums/                ErrorCode, AuditEvent, AccountStatus, UserType, ...
  Exceptions/           ApiException + the renderer that builds every error response
  Http/
    Controllers/        ApiController (base) + Api/V1/<Module>/
    Middleware/         request id, JSON, security headers, active account
    Requests/           Form Requests (validation)
    Resources/          the only shapes data may leave in
    Responses/          ApiResponse trait + ApiResponder (the envelope)
  Models/
  Services/             business rules, one folder per module
  Support/              small helpers: Phone, Mask, Hmac, Identifier, Audit, RequestId
config/onehealth.php    every tunable number (OTP length, limits, TTLs)
routes/api/v1/          one file per module
```

---

## 4. Endpoints

Base URL: `http://localhost:8000/api/v1`. All requests are JSON. All POSTs need the CSRF header (section 6).

### Sign-up (matches the frontend's `AuthApi`)

| Step | Request | Success `data` |
|---|---|---|
| Proceed | `POST /auth/register/start` `{phone, nin, consent}` | `{registrationId, identity: {fullName, dateOfBirth, gender, photoUrl}}` |
| Yes, this is me | `POST /auth/register/confirm-identity` `{registrationId}` | `{resendAfterSeconds}` (sends the SMS code) |
| Resend code | `POST /auth/register/resend-otp` `{registrationId}` | `{resendAfterSeconds}` |
| Verify | `POST /auth/register/verify-otp` `{registrationId, code}` | `null` |
| Next | `POST /auth/register/emergency-contacts` `{registrationId, contacts: [{name, relationship, phone, email?}]}` (1 to 3) | `null` |
| Continue | `POST /auth/register/complete` `{registrationId, password}` | `{username, personalId}` (201). **No auto-login.** |

The server enforces the order. Skipping a step returns `REGISTRATION_STEP_INVALID`. A sign-up expires after 60 minutes of inactivity (every step extends it). Abandoned sign-ups hold personal data, so `php artisan onehealth:prune` deletes them. It is scheduled daily; you need one cron entry on the server: `* * * * * php /path/to/artisan schedule:run`.

### Login and session

| Request | Notes |
|---|---|
| `POST /auth/login` `{identifier, password}` | `identifier` = email, 11-digit phone, or 10-digit personal ID (not starting with 0). Returns `{user: {id, fullName, personalId, username}}` and sets the session cookie. |
| `GET /auth/me` | Current user, or `UNAUTHENTICATED`. Use it on app start to know if someone is logged in. |
| `POST /auth/logout` | Ends the session. |
| `GET /auth/csrf` | Sets the `XSRF-TOKEN` cookie. Call once before the first POST. |

### Password reset

| Step | Request | Success `data` |
|---|---|---|
| Send code | `POST /auth/password-reset/request` `{identifier}` | `{resetId, channel, destination, resendAfterSeconds}` |
| Resend | `POST /auth/password-reset/resend-otp` `{resetId}` | `{resendAfterSeconds}` |
| Verify | `POST /auth/password-reset/verify-otp` `{resetId, code}` | `{resetToken}` |
| Set password | `POST /auth/password-reset/confirm` `{resetToken, password}` | `null` |

`request` answers **identically whether or not the account exists** (see 5.4). Email identifiers get the code by email, everything else by SMS to the phone on file.

---

## 5. Security: what is protected and how

Sensitive system, so each choice has a reason. Tests exist for all of these.

1. **NIN is never stored.** Only an HMAC-SHA256 hash (keyed with `NIN_HASH_KEY`) and the last 4 digits. An 11-digit NIN has too few possibilities for a plain hash. The key makes guessing impossible without it. It is also never logged and is not flashed into error responses.
2. **One-time codes are hashed** (HMAC), tied to their sign-up or reset, single use, valid 10 minutes, and **die after 5 wrong guesses**. Without that cap, all 10,000 four-digit codes could be tried. A new code kills the old one. Resend cooldown is enforced on the server (the app's timer is cosmetic). Each phone number can be sent at most 5 codes per hour across everything, which stops someone using us to spam a stranger.
3. **Login is slow to guess.** 5 wrong passwords per identifier per IP, and 20 per identifier from any IP, then a 15 minute lock (`TOO_MANY_ATTEMPTS` with `retryAfter`). A locked account stays locked even with the right password, otherwise the lock is useless. Unknown accounts are throttled the same way and cost the same time (a dummy hash is checked), and get the same error and message as a wrong password.
4. **No account enumeration.** Login says only "incorrect". Password-reset `request` gives a normal-looking answer for unknown accounts (a made-up masked destination, stable for the same input), sends the SMS or email **after** the response so timing does not differ, and a fake reset says "wrong code" when verified. `ACCOUNT_SUSPENDED` is only revealed after the correct password.
5. **Sessions.** The session id is replaced at login (stops session fixation). Cookies are `HttpOnly` and encrypted. Resetting a password signs the account out everywhere (all session rows deleted) and texts the owner a notice. Suspending an account kicks the user on their next request (`active` middleware).
6. **Forgery protection.** Cookie sessions need CSRF protection. Laravel 13's `PreventRequestForgery` runs on the API group. A POST must come from the same origin or carry the `X-XSRF-TOKEN` header. A POST without it gets `REQUEST_FORGERY` (419). CORS allows only the origins in `CORS_ALLOWED_ORIGINS`, never `*`.
7. **Headers on every response:** `Cache-Control: no-store` (health data must not sit in a shared cache or browser history), `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, a CSP of `default-src 'none'`, and HSTS in production over HTTPS.
8. **Sign-up integrity.** Account creation happens in one database transaction with a row lock, so double-clicks and races cannot create two accounts. The sign-up row (name, date of birth) is deleted when the account is created. Consent (time and version of the terms) is stored with the patient.
9. **Dev-only switches fail closed.** `NIN_DRIVER=fake`, `SMS_DRIVER=log` and `OTP_FIXED_CODE` all make the app refuse in production instead of silently running insecurely.
10. **Mistakes are loud in development**: writing to a column that is not fillable, or reading one that was not loaded, throws. Destructive commands (`migrate:fresh`) are blocked in production.

---

## 6. Connecting the patient app

The frontend currently talks to a mock. Only `lib/api/*` needs to change: screens do not.

### 6.1 `apps/patient/.env.local`

```
NEXT_PUBLIC_USE_MOCKS=false
NEXT_PUBLIC_API_URL=http://localhost:8000/api/v1
```

### 6.2 `lib/api/client.ts`: replace with this

Four changes from the placeholder: unwrap the envelope's `data`, send cookies (`credentials: "include"`), send the XSRF header, and support GET.

```ts
import { env } from "@/lib/env"
import { ApiError, type ApiErrorCode } from "./errors"

type Envelope<T> =
  | { success: true; message: string; data: T; meta: { requestId: string } }
  | {
      success: false
      message: string
      code: ApiErrorCode
      errors: Record<string, string[]> | null
      meta: { requestId: string; retryAfter?: number }
    }

/** Laravel puts a CSRF token in the XSRF-TOKEN cookie; we send it back in a header. */
function readXsrfCookie(): string | null {
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)
  return match ? decodeURIComponent(match[1]) : null
}

async function ensureCsrfCookie() {
  if (readXsrfCookie()) return
  await fetch(`${env.apiUrl}/auth/csrf`, { credentials: "include" }).catch(() => null)
}

export async function request<T>(
  path: string,
  body?: unknown,
  method: "GET" | "POST" = "POST"
): Promise<T> {
  let response: Response
  try {
    if (method !== "GET") await ensureCsrfCookie()
    const xsrf = readXsrfCookie()

    response = await fetch(`${env.apiUrl}${path}`, {
      method,
      credentials: "include",
      headers: {
        Accept: "application/json",
        ...(method !== "GET" ? { "Content-Type": "application/json" } : {}),
        ...(xsrf ? { "X-XSRF-TOKEN": xsrf } : {}),
      },
      body: method === "GET" || body === undefined ? undefined : JSON.stringify(body),
    })
  } catch {
    throw new ApiError("NETWORK", "We couldn't reach the server. Check your connection and try again.")
  }

  const json = (await response.json().catch(() => null)) as Envelope<T> | null

  if (!response.ok || !json || json.success === false) {
    const err = json && json.success === false ? json : null
    throw new ApiError(
      err?.code ?? "UNKNOWN",
      err?.message ?? "Something went wrong. Please try again.",
      response.status
    )
  }
  return json.data
}
```

For `GET /auth/me`, call `request("/auth/me", undefined, "GET")`.

> If a request comes back `REQUEST_FORGERY`, the XSRF cookie was missing or stale. Delete it and retry once (the app can do this automatically later).

### 6.3 `lib/api/errors.ts`: extend the type

```ts
export type ApiErrorCode =
  | "NETWORK" | "UNKNOWN"
  | "NIN_NOT_FOUND" | "PHONE_ALREADY_REGISTERED"
  | "OTP_INVALID" | "OTP_EXPIRED"
  | "REGISTRATION_NOT_FOUND" | "REGISTRATION_FAILED"
  // already in the login / reset guides:
  | "INVALID_CREDENTIALS" | "RESET_NOT_FOUND"
  // NEW with this backend:
  | "NIN_ALREADY_REGISTERED" | "NIN_SERVICE_UNAVAILABLE"
  | "REGISTRATION_STEP_INVALID" | "OTP_DELIVERY_FAILED"
  | "ACCOUNT_SUSPENDED" | "TOO_MANY_ATTEMPTS" | "REQUEST_FORGERY"
  | "VALIDATION_FAILED" | "UNAUTHENTICATED" | "SERVER_ERROR"
```

Screens that show `error.message` already work for the new codes, because the server writes a user-safe message for each.

### 6.4 `auth.http.ts`: paths

The paths in the frontend guides (`/auth/register/start` and so on) already match. They only gain the `/api/v1` prefix, which comes from `NEXT_PUBLIC_API_URL`. Nothing to rename. Remove `loginWithGoogle` from the http adapter (see 7).

### 6.5 Differences to know about

- **OTP code in dev is `1234`**, not the mock's `0000`/`9999` magic values.
- The resend delay comes from the server (`resendAfterSeconds`, default 300). The server enforces it, and replies `TOO_MANY_ATTEMPTS` with `meta.retryAfter` if the app asks early.
- `completeRegistration` does not log the person in. Same as the current flow: they go to the login screen.

---

## 7. Not built, on purpose

- **Google login.** Creating an account requires a NIN, so no one can have a Google account linked yet. Hide the Google button until a "link Google in settings" flow exists. The `google_sub` column is already on `users`.
- **Tokens / Sanctum.** Cookie sessions fit a web app. Add Sanctum later for a mobile app or the portal. The portal will need its own session cookie name and guard.
- **A real NIN verifier and SMS gateway.** Both are interfaces (`NinVerifier`, `SmsGateway`) with development fakes. See 8.
- **Hospital registration and verification (CAC and NHFR).** Hospitals are created by the seeder for now. `provider_phones` exists, but the one-time-code check of a hospital's numbers is not built.
- **Record versions.** `records` is a simplified single table. The schema's `record` plus append-only `record_version` comes later.
- **Push notifications and email to contacts.** In-app approval works by polling; contacts are told by SMS (code and link). Web push and email are later.
- **Break-glass override and contact opt-in.** Open items in the schema.

---

## 8. Plugging in the real services

**SMS:** write `app/Services/Sms/<Vendor>SmsGateway.php` implementing `SmsGateway::send(string $phone, string $message)`. Throw any exception on failure (the app hides it from the user and logs it). Add a line to the `match` in `AppServiceProvider`, set `SMS_DRIVER=<vendor>`. Use `App\Support\Phone` if the vendor wants `+234`.

**NIN:** same pattern with `NinVerifier::lookup(string $nin): NinRecord`. Throw `ApiException(ErrorCode::NinNotFound)` when the number does not exist, and `ApiException(ErrorCode::NinServiceUnavailable)` when the vendor is down. Never log the raw NIN. The vendor is not decided yet.

---

## 9. Before production

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, a new `APP_KEY` and `NIN_HASH_KEY` stored in a secrets manager and backed up (losing `NIN_HASH_KEY` means re-verifying every patient).
- [ ] Real SMS gateway and NIN verifier bound. `OTP_FIXED_CODE` empty.
- [ ] PostgreSQL, not SQLite. A cache store shared between servers (Redis) so rate limits work across them.
- [ ] HTTPS only. `SESSION_SECURE_COOKIE=true`. Decide the domain layout (app and API on sibling subdomains need `SESSION_DOMAIN` set, and `CORS_ALLOWED_ORIGINS` to the exact app origin).
- [ ] Database user for the app has no `UPDATE`/`DELETE` on `audit_logs`.
- [ ] Trusted proxies configured if behind a load balancer, so `$request->ip()` is the real client (rate limits depend on it).
- [ ] `PASSWORD_CHECK_BREACHES=true` (rejects passwords found in known breaches).
- [ ] Cron running `schedule:run`. Logs shipped somewhere with the request id searchable.
- [ ] Lawyer review under NDPA 2023: consent wording and the retention of sign-up data.

---

## 10. Tests

88 tests. Run `php artisan test`.

| File | Covers |
|---|---|
| `Feature/Api/EnvelopeTest` | success/404/405/401/422 shape, request id rules, no internals leaked |
| `Feature/Auth/RegistrationTest` | full flow, consent, duplicates, step skipping, OTP lockout, resend cooldown, SMS failure, replay |
| `Feature/Auth/LoginTest` | three identifier types, enumeration safety, lockout, suspended, audit rows |
| `Feature/Auth/PasswordResetTest` | sms/email/personal-id, unknown accounts, token single use and expiry, sessions revoked |
| `Feature/Access/AccessRequestTest` | scan, patient answer, wait then contacts, code, link (GET never approves), first approval wins, expiry, rate limits, wrong hospital/patient |
| `Feature/Access/SessionAndRecordsTest` | public vs private visibility, check-out, revoke, auto-close, hospital notes, cards |
| `Unit/SupportTest` | phone, mask, identifier, display name, every `ErrorCode` complete |

The tests do not exercise the CSRF check (Laravel skips it under test). That path was checked by hand against a running server: a POST without the header returns 419 `REQUEST_FORGERY`, with it returns 200.


---

## 11. Cards, access requests and sessions

The flow: **scan, then request, then answer, then session.** A scan opens nothing. It creates a request that waits.

```
Hospital scans card ──> AccessRequest (pending, stage=patient)
                          │ SMS + in-app to the patient
        patient approves ─┤
                          │ no answer within 2 minutes
                          ▼
                    stage=contacts: every emergency contact is asked at once
                       SMS (6-digit code + link), plus the app if they have an account
                          │
   contact taps Approve (link or app)  OR  contact reads the code to the hospital, who types it in
                          ▼
                    CareSession (limited, until check-out)
```

Settings (all in `config/onehealth.php`, `.env`): patient wait 120 s, request valid 30 min, 6-digit code, 5 wrong codes kills the request, 5 scans per card per hour, session auto-closes after 3 days.

### Patient (logged in, `type:patient`)

| Request | Notes |
|---|---|
| `GET /cards/me` | `{card: {last4, status, expiresAt, qrToken}}`. The app draws the QR from `qrToken`. |
| `POST /cards/activate` `{cardNumber}` | 16 digits, spaces allowed. A used or unknown number answers `CARD_NOT_FOUND` (cannot be probed). A new card replaces the old. |
| `GET /access/requests/pending` | Poll every few seconds while the app is open. `{requests: [{requestId, organisationName, frontDeskNumbers, patientName, requestedAt, expiresAt, stage}]}` |
| `POST /access/requests/{id}/approve` · `/deny` | Approve returns the new session. |
| `GET /access/sessions?status=active` | Who has access now. |
| `POST /access/sessions/{id}/revoke` | End access any time. |
| `GET /records?category=` · `POST /records` · `PATCH /records/{id}/visibility` | Own records. **Private by default.** Free text everywhere medical. `category` is one of `clinical_report`, `vitals`, `medical_history`. |

### Emergency contact

| Request | Notes |
|---|---|
| `GET /access/contact-requests` · `POST /access/contact-requests/{id}/approve` | Logged-in contacts (matched by phone number). Only shows requests already passed on to contacts. |
| `GET /access/approvals/{token}` | The SMS link page. **Only describes** the request (hospital, front desk numbers, patient name). It must never approve. Link scanners open every link. |
| `POST /access/approvals/{token}/approve` | The Approve button on that page. Single use. |

The page for the link lives in the patient app (`/approve/[token]`): on load call the GET, show the hospital name and the front-desk number, and show one Approve button that calls the POST. No login needed.

### Hospital (logged in, `type:org_admin,org_staff`, organisation must be verified)

| Request | Notes |
|---|---|
| `POST /provider/access-requests` `{qrToken}` or `{cardNumber, dateOfBirth}` | The scan. Typing a number needs the patient's date of birth. Scanning twice while waiting returns the same request. |
| `GET /provider/access-requests/{id}` | Poll every 3 to 5 seconds. `status`: `pending`, `approved` (has `sessionId`), `denied`, `expired`, `cancelled`. Also moves the request on (to the contacts, or expiry). |
| `POST /provider/access-requests/{id}/code` `{code}` | The code a contact read out. `attemptsLeft` is in the status response. |
| `GET /provider/sessions` | Open sessions of this organisation. |
| `GET /provider/sessions/{id}/essentials` | Patient header, **public** records, and `privateRecordCount` (a number, never content). |
| `POST /provider/sessions/{id}/records` | Hospital writes a note into the patient's record. Always saved **private**: a hospital cannot make anything public. |
| `POST /provider/sessions/{id}/checkout` | Ends the session. |

### Rules to remember

- Only the patient changes who sees a record. No route lets a hospital do it (a test proves it).
- A session reads **public** records only (`access_level = limited`), whoever approved it.
- `CARD_NOT_ACTIVE` is shown to a hospital that scans a deactivated or expired card (it does not reveal anything about the owner).
- Everything is in the audit log: requests, escalations, approvals (with how), failed codes, sessions opened and closed, data viewed.
- The scheduler must run (`php artisan schedule:run` every minute). Without it requests still advance when someone reads them, but sessions are not auto-closed.

### Demo data (`php artisan migrate:fresh --seed`)

| Who | Login | Notes |
|---|---|---|
| Patient Tunde | `08000000001` or personal ID `4829175036`, password `Password1` | Card activated (number and QR token printed by the seeder), public: blood group O positive, genotype AA, allergy Penicillin, medication None. Private: Asthma. |
| Contact Ada | `08000000002`, password `Password1` | **Has an account**: sees requests in the app. |
| Contact Mama Tunde | phone `08000000003` | **No account**: SMS link and code (read it in `storage/logs/laravel.log`). |
| Hospital staff | `front@perfectmedicals.test`, password `Password1` | Perfect Medicals Inc., verified, front desk `08031112222`. |

To make a request move on quickly while demoing, set `ACCESS_PATIENT_WAIT_SECONDS=10` in `.env`.

New error codes the frontend needs: `CARD_NOT_FOUND`, `CARD_NOT_ACTIVE`, `ORGANISATION_NOT_VERIFIED`, `ACCESS_REQUEST_NOT_FOUND`, `ACCESS_REQUEST_CLOSED`, `APPROVAL_CODE_INVALID`, `SESSION_NOT_FOUND`, `SESSION_CLOSED`, `RECORD_NOT_FOUND`, `FORBIDDEN`.
