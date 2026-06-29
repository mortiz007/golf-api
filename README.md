# README — LLM Choice, Failure Handling & AI Assistance

Short companion notes for the Golf Listing API. For full setup/run/test details see [AGENTS.md](AGENTS.md).

## LLM Provider & Why

The LLM integration sits behind a single domain port, `App\Listings\Domain\Contracts\LlmPort`, with the active adapter selected in `config/llm.php` (`LLM_PROVIDER`). Two adapters ship:

- **Ollama (real provider)** — `OllamaLlmProvider`, talking to a local Ollama server (`POST /api/chat`) running **`qwen2.5-coder:7b`** at `temperature 0.1` with `format: json`.
- **Mock (deterministic)** — `LlmProviderMock`, in-process, used for tests/CI and offline development (default in `.env.example`).

Why this choice:

- **Local and zero-cost.** Ollama runs on the developer's machine with no API keys, no per-token billing, and no data leaving the host — a good fit for a 20h, single-developer budget.
- **Deterministic, low-temperature JSON.** `temperature 0.1` + `format: json` keeps moderation/enrichment output parseable; `qwen2.5-coder:7b` is small enough to run locally yet reliable at structured JSON.
- **Swappable without touching the domain.** Because everything depends on `LlmPort`, the deterministic mock powers fast, hermetic tests, while production can switch to Ollama (or any future provider) by changing one config value — the hexagonal boundary stays intact.

## How Failures Are Handled

Moderation and enrichment run as two independent, parallel queued jobs (`database` queue), each delegating to a Use Case that calls `LlmPort`.

- **Adapter level:** `OllamaLlmProvider` throws `OllamaException` on connection errors, non-2xx HTTP, or non-JSON content. It never returns a degraded/partial result, so the failure always propagates to the job.
- **Retries:** each job has `$tries = 3` with exponential backoff `[5, 15, 30]` seconds.
- **Fallback after retries are exhausted (`failed()`):**
    - Moderation -> `moderation_status` stays `pending`, so the listing remains **not publicly visible** (fail-safe); the error is written to `moderation_result`.
    - Enrichment -> `ai_enrichment_status = failed`; the error is written to `ai_enrichment`.
    - The job is dead-lettered to `failed_jobs` (inspect with `php artisan queue:failed`).
- **Concurrency safety:** the two jobs write only their own columns (`updateModerationResult` / `updateEnrichment`), so parallel execution never causes last-writer-wins.
- **Audit pipeline:** domain events are consumed by a queued listener that inserts idempotently via `firstOrCreate(['event_id' => ...])` (UNIQUE `event_id`), so retries/duplicates produce exactly one row; persistent failures also go to the DLQ.
- **API errors:** validation/authorization/not-found map to a uniform envelope `{ error: { code, message, details } }` in `bootstrap/app.php` (422/403/404, plus 429/401 by HTTP status).

## AI Assistance — Used & Rejected

**Used AI assistance for:**
The AI assistant was used during all development phases but mainly for:

- Migrations and seeders.
- Boilerplate (Value Objects, DTOs, Resources, FormRequests).
- Drafting the Pest test suite (endpoint happy-paths, AuditLog idempotency, moderation fallback, boundary telemetry).
- Generating the Postman collection (`postman/golf-api.postman_collection.json`) with positive/negative cases and automated flows, **derived from seeders.**
- Writing prompts and the JSON response mapping for the Ollama adapter.
- Documentation.

**Rejected:**

- To design the solution.
- To initialize and create the main structure of project.
- In general it was used in shorts verification loop and rejected for large tasks.


For more documentation details read: **DESIGN.md**
