# AGENTS_technical_assessment.md — AI Agent Operating Guide (Golf Listing API)

Vendor-neutral context file. Any AI coding agent can read this to install, configure, run, test and modify the project without extra guidance. It describes the system as implemented; where this file disagrees with code, trust the code and report the drift.

- Stack: PHP 8.2, Laravel 12 (streamlined structure, `bootstrap/app.php`), MySQL.
- Architecture: Hexagonal (Ports & Adapters) + light tactical DDD + Event-Driven (async LLM + audit).
- Auth: Laravel Sanctum bearer tokens. No register/login endpoints; tokens are seeded.
- Queue: `database` driver. DLQ via `failed_jobs`.
- Tests: Pest (unit + feature) against a dedicated MySQL database.

---

## 1. Install / Setup

Prerequisites the host must provide:

- PHP 8.2+ with extensions: `pdo_mysql`, `mbstring`, `openssl`, `json`, `bcmath`, `ctype`, `fileinfo`.
- Composer 2.x.
- MySQL 8.x reachable (create databases `golf_api` and `golf_api_testing`).
- Node.js 18+ and npm (only needed for front-end assets / `npm run dev`; the JSON API does not need built assets).
- Optional: [Ollama](https://ollama.com) if you run the real LLM provider (see section 3).

First-time setup (manual, explicit):

```bash
composer install
cp .env.example .env          # On Windows PowerShell: Copy-Item .env.example .env
php artisan key:generate
# Edit .env DB_* credentials and create the database (see section 3), then:
php artisan migrate
php artisan db:seed
```

There is also a Composer convenience script (note: it runs `npm install` + `npm run build`):

```bash
composer setup
```

Create the MySQL databases before migrating:

```sql
CREATE DATABASE IF NOT EXISTS golf_api CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS golf_api_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### Seeded credentials (deterministic, local only)

`database/seeders/TokenSeeder.php` creates two Sanctum tokens. Use them as `Authorization: Bearer <token>`:

| User         | Email            | Bearer token                |
| ------------ | ---------------- | --------------------------- |
| Alice Walker | alice@golf.test  | `1\|golf-seed-token-alice`  |
| Bob Stone    | bob@golf.test    | `2\|golf-seed-token-bob`    |

The seeders also create 7 categories (ids 1-7 in this order: `Drivers, Woods, Hybrids, Driving Irons, Irons, Wedges, Putters`) and 10 listings that cover every public-visibility scenario.

---

## 2. Run

```bash
# 1) API server
php artisan serve                 # http://127.0.0.1:8000

# 2) Queue worker — REQUIRED for moderation, enrichment and audit-logs to materialize
php artisan queue:work            # or: php artisan queue:listen --tries=1 (dev)
```

CRITICAL: `QUEUE_CONNECTION=database`. Moderation, enrichment and audit-log writes are queued jobs/listeners. Without a running worker, `moderation_status`/`ai_enrichment_status` stay `pending` and `GET /api/audit-logs` returns an empty `data` array.

All-in-one dev (server + queue + vite):

```bash
composer dev
```

---

## 3. Environment Variables

Defined in `.env` (copy from `.env.example`). Key entries:

### Core
| Variable          | Example / Default      | Notes                                             |
| ----------------- | ---------------------- | ------------------------------------------------- |
| `APP_KEY`         | (generated)            | Run `php artisan key:generate`.                   |
| `APP_URL`         | `http://localhost:8000`|                                                   |
| `DB_CONNECTION`   | `mysql`                |                                                   |
| `DB_HOST`         | `127.0.0.1`            |                                                   |
| `DB_PORT`         | `3306`                 |                                                   |
| `DB_DATABASE`     | `golf_api`             | Test suite uses `golf_api_testing` (see §5).      |
| `DB_USERNAME`     | `root`                 |                                                   |
| `DB_PASSWORD`     | `admin`                |                                                   |
| `QUEUE_CONNECTION`| `database`             | Async jobs + DLQ (`failed_jobs`).                 |
| `CACHE_STORE`     | `database`             |                                                   |
| `SESSION_DRIVER`  | `database`             |                                                   |
| `LOG_CHANNEL`     | `stack`                | Operational telemetry also goes to `stdout` (JSON Lines), see §7. |

### Business
| Variable                        | Default | Notes                                                       |
| ------------------------------- | ------- | ----------------------------------------------------------- |
| `LISTINGS_DAILY_CREATION_LIMIT` | `10`    | Per-user/day cap on `POST /api/listings` (`config/listings.php`). |

### LLM provider (`config/llm.php`)
| Variable            | Default                     | Notes                                                            |
| ------------------- | --------------------------- | ---------------------------------------------------------------- |
| `LLM_PROVIDER`      | `mock`                      | `mock` (deterministic, in-process) or `ollama` (real, local).   |
| `OLLAMA_BASE_URL`   | `http://localhost:11434`    | Ollama server; adapter calls `POST /api/chat`.                  |
| `OLLAMA_MODEL`      | `qwen2.5-coder:7b`          | Pull it first: `ollama pull qwen2.5-coder:7b`.                  |
| `OLLAMA_TIMEOUT`    | `60`                        | Seconds per HTTP call.                                          |
| `OLLAMA_TEMPERATURE`| `0.1`                       | Low for stable JSON output.                                    |
| `OLLAMA_KEEP_ALIVE` | `5m`                        | Ollama model keep-alive.                                       |

Provider binding lives in `App\Providers\ListingsServiceProvider` (reads `config/llm.php`). To use the real LLM: set `LLM_PROVIDER=ollama`, run `ollama serve`, and `ollama pull qwen2.5-coder:7b`. Keep `LLM_PROVIDER=mock` for tests/CI and offline work.

---

## 4. Day-to-day Commands

```bash
# Tests
php artisan test                          # full suite
php artisan test --compact                # condensed output
php artisan test --filter=SomeTestName    # single test/case
composer test                             # config:clear + artisan test

# Code style (Laravel Pint, PSR-12) — REQUIRED before finalizing PHP changes
vendor/bin/pint --dirty --format agent    # fix only changed files
vendor/bin/pint --format agent            # fix whole project

# Database
php artisan migrate:fresh --seed          # rebuild + reseed (resets ids; tests/Postman rely on seed ids)
php artisan db:seed

# Queue / DLQ
php artisan queue:work
php artisan queue:failed                   # inspect dead-lettered jobs
php artisan queue:retry all

# Introspection
php artisan route:list --path=api
php artisan config:show llm.provider
php artisan tinker
```

Agent conventions for Artisan generators: use `php artisan make:*` with `--no-interaction`, and create factories/seeders for new models.

---

## 5. Testing

- Framework: Pest 3 (`pestphp/pest`), with `pestphp/pest-plugin-laravel`. PHPUnit 11 underneath.
- Database: tests run against MySQL `golf_api_testing` with `RefreshDatabase` (see `phpunit.xml`). Create that database before running the suite.
- Create tests with `php artisan make:test --pest {Name}` (feature) or add `--unit`. Do not include the suite dir in `{Name}`.
- Coverage focus (per `DESIGN.md` §9): happy-path of each endpoint, AuditLog idempotency, moderation fallback, and boundary telemetry (HTTP, jobs, audit listener), including non-leakage of sensitive content.
- Do not delete or weaken existing tests without explicit approval. Prefer factories/states over manual model setup.

Manual API verification: import `postman/golf-api.postman_collection.json` (collection variables already include `base_url` and both bearer tokens). Run `migrate:fresh --seed` + `queue:work` first.

---

## 6. Architecture & Conventions (respect this style)

Directory layout (`app/`):

```text
app/
  Listings/                       # Core bounded context
    Domain/                       # Entities, ValueObjects, Events, Exceptions, Contracts (Ports incl. LlmPort)
    Application/                  # UseCases, Commands, Queries, DTOs, ReadModels, output-port Contracts
    Infrastructure/               # Eloquent models, Repositories, Mappers, LLM adapters, Jobs, Dispatchers
  AuditLog/                       # Event-consumer bounded context (no access to Listings tables/models)
    Domain/ Application/ Infrastructure/
  Http/                           # Controllers, FormRequests, Resources, Middleware (cross-cutting layer)
  Support/                        # Telemetry (structured-log emitter)
  Providers/                      # ServiceProviders (Port -> Adapter bindings)
```

Dependency rules (enforce strictly):

- Allowed: `app/Http -> Application`, `Application -> Infrastructure`, `Infrastructure -> Domain`.
- Forbidden: `Domain -> Laravel/Eloquent`, `Application -> Eloquent/HTTP`, `Domain -> Infrastructure`, and any direct use of concrete repositories from `app/Http`.
- Eloquent models are NOT domain entities; convert via mappers.
- Repositories are interfaces in `Domain/Contracts`, implemented in `Infrastructure`.
- Application output ports (`DomainEventPublisher`, `ListingProcessingDispatcher`) live in `Application/Contracts`, implemented in `Infrastructure`.
- Controllers are thin: receive HTTP -> validate via FormRequest -> map to Command/DTO -> invoke Use Case -> return Resource. No business rules in controllers.
- Cross-context communication only via domain events. `AuditLog` never touches Listings tables/repos/models; it persists only the event payload (no FKs to listings/users).

Code style:

- PHP 8.2: constructor property promotion, explicit return types and param type hints, `declare(strict_types=1)`, curly braces always.
- English code and comments. PHPDoc on public classes/methods; comments only where they add value. TitleCase enum cases.
- Run `vendor/bin/pint` before finishing any PHP change.
- Casts go in a model `casts()` method. Prefer named routes / Eloquent API Resources + versioning conventions already present.

Error envelope (normative) for JSON requests:

```json
{ "error": { "code": "VALIDATION_ERROR", "message": "Validation failed", "details": { "field": ["msg"] } } }
```

Mapping is centralized in `bootstrap/app.php`. Codes: `VALIDATION_ERROR` (422), `UNAUTHENTICATED` (401), `FORBIDDEN` (403), `NOT_FOUND` (404), `RATE_LIMITED` (429).

IMPORTANT for any client/agent calling the API: always send `Accept: application/json`. The exception handlers only emit the JSON envelope when `expectsJson()` is true; otherwise Laravel may redirect (e.g. 401 -> login route) instead of returning JSON.

---

## 7. Async Processing, Failure Handling & Telemetry

- On create/update, two independent parallel jobs are queued on the `database` queue (no ordering guarantee): `ModerationJob` and `EnrichmentJob`. They are thin adapters that call `ModerateListingUseCase` / `EnrichListingUseCase`, which call `LlmPort`.
- Column-scoped persistence: `updateModerationResult(...)` and `updateEnrichment(...)` write only their own dirty columns, so the two jobs never overwrite each other.
- Retry policy: `$tries = 3`, exponential backoff `[5, 15, 30]` seconds.
- Fallback after retries are exhausted (`failed()`):
  - Moderation -> `moderation_status` stays `pending` (listing not publicly visible); error recorded in `moderation_result`.
  - Enrichment -> `ai_enrichment_status = failed`; error recorded in `ai_enrichment`.
  - Job lands in `failed_jobs` (DLQ).
- LLM adapter (`OllamaLlmProvider`): on any transport/contract violation (connection error, HTTP error, invalid JSON) it throws `OllamaException` so the retry/backoff/fallback/DLQ path applies. It never returns a degraded result.
- Audit pipeline: Use Cases publish domain events after commit; `RecordAuditLogListener` (`ShouldQueue`, `database`) writes the audit row with idempotent `firstOrCreate(['event_id' => ...])` (UNIQUE `event_id`). 3 tries; persistent failure -> DLQ.
- Telemetry: structured JSON Lines to `stdout` via `App\Support\Telemetry` (Monolog channel `stdout`), separate from the business `AuditLog`. Events: `http.request`/`http.outcome`, `job.start`/`job.outcome`/`job.failed`, `ollama.request`/`ollama.outcome`. Only identifiers/metadata are logged — never user content (`title`, `description`), credentials, or the `q` query string.

---

## 8. Endpoint Description  

Base path `/api`. Authenticated routes (everything except `GET /api/listings`) apply `auth:sanctum` + `throttle:60,1`. Source: `routes/api.php`, the FormRequests, Use Cases and `EloquentListingQueryRepository`.

### `GET /api/listings` — public list (no auth)
- Query params (`ListListingsRequest`): `min_price` (numeric, min 0), `max_price` (numeric, min 0), `category_id` (integer, `exists:categories,id`), `condition` (`in:New,Used,Refurbished,Like New`), `q` (string, max 255), `show_all` (boolean), `page` (int, min 1), `per_page` (int, min 1, max 100). Invalid -> 422 envelope.
- `q` does a `LIKE %term%` over `title` OR `description`.
- Visibility (`show_all=false`, default): `moderation_status='approved'` AND `cancelled_at IS NULL` AND (`end_date IS NULL` OR `end_date >= today`); ordered `created_at ASC`.
- `show_all=true`: returns ALL listings with `moderation_status='approved' ordered by `price DESC`.
- Response: paginated with `data` + `meta` + `links`. Each item: `id, title, price, condition, description, created_at, user{first_name,last_name}, category{id,name}, ai_enrichment`. The owner name is split from the single `name` column (first token vs remainder). `ai_enrichment` is the raw JSON object or `null`.
- With the seed data, default visible total = 6 (listings 1,2,7,8,9,10). Hidden by default: pending (3), rejected (4), cancelled (5), expired (6).
- Codes: 200, 422.

### `POST /api/listings` — create (auth; owner is the caller)
- Extra limiter: `throttle:listing-creation` = `LISTINGS_DAILY_CREATION_LIMIT` per user per day (429 when exceeded), on top of `throttle:60,1`.
- Validation (`StoreListingRequest`): `title` required, `^[A-Za-z ]+$`, 3-255, trimmed; `price` required numeric `0.01..99999999.99`; `condition` required enum; `description` required 10-1000, `strip_tags`+trim; `end_date` optional `Y-m-d`, `after_or_equal:today`; `category_id` required `exists:categories,id`.
- Behavior: persists with `moderation_status=pending`, `ai_enrichment_status=pending`; publishes `ListingCreated` after commit; queues `ModerationJob` + `EnrichmentJob`.
- Response 201, flat body (no `data` wrapper): `id, title, price, condition, description, end_date, category_id, moderation_status, ai_enrichment_status, ai_enrichment(null), created_at, user{name}`. Header `Location: /api/listings/{id}`.
- Codes: 201, 422, 401, 429.

### `PATCH /api/listings/{id}` — partial update (auth, owner-only)
- Load order: missing OR cancelled listing -> 404 (`NOT_FOUND`); non-owner -> 403 (`FORBIDDEN`). Authorization is resolved in the Use Case (no Laravel Policy).
- Only submitted fields change (`sometimes` rules, same constraints as create; `category_id` editable; `end_date` may be set to null).
- Re-evaluation triggers (only when the value actually changes): `title` or `description` -> `moderation_status=pending` + re-queue `ModerationJob`; `price` or `condition` -> `ai_enrichment_status=pending` + re-queue `EnrichmentJob`; `category_id` alone triggers nothing.
- Publishes `ListingUpdated` after commit. Response 200, same shape as POST (`user{name}`).
- Codes: 200, 403, 404 (missing or cancelled), 422, 401, 429.

### `DELETE /api/listings/{id}` — cancel / soft-delete (auth, owner-only)
- Missing -> 404; non-owner -> 403 (authorization checked BEFORE idempotency). Sets `cancelled_at=now`, publishes `ListingDeleted` after commit.
- Idempotent: deleting an already-cancelled listing returns 204 and does NOT re-persist or re-publish.
- Response 204, no body. Codes: 204, 403, 404, 401, 429.

### `GET /api/audit-logs` — current user's audit log (auth)
- Returns only the authenticated user's entries, `created_at DESC`, paginated 20/page (`data` + `meta` + `links`).
- Item: `id, action(created|updated|deleted), message, metadata(payload snapshot), created_at`.
- Requires a running queue worker for entries to exist (events are processed asynchronously).
- Codes: 200, 401, 429.

### Known v1.0 trade-offs 
- `user` shape differs by endpoint: writes return `{ name }`, the public list returns `{ first_name, last_name }` (split from `name`).
- Owner-only is enforced only in the Use Case (no Policy), to preserve hexagonal dependency rules.

---

## 9. Safety Rules for Agents

- Do not change dependencies (`composer.json` / `package.json`) or the directory structure without approval.
- Do not commit unless explicitly asked. Never commit secrets (`.env`).
- Do not create documentation files unless explicitly requested.
- Always run `vendor/bin/pint` after editing PHP, and run the relevant tests.
- When adding endpoints/models, follow the hexagonal layering and the error-envelope convention above; add factories/seeders/tests.
- Use `search-docs` (Laravel Boost MCP) for version-specific Laravel 12 guidance before non-trivial changes.
