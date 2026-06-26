# Plan de Ejecución — Contexto `AuditLog`

## Decisiones congeladas

- **1-A** — El evento vive en `App\Listings\Domain\Events`; `AuditLog` lo consume con **type-hint** (acoplamiento de namespace mínimo aceptado, pragmático para 20 h).
- **2-B** — Se cablean los **tres eventos** (`ListingCreated` / `ListingUpdated` / `ListingDeleted`) con un **handler genérico**, aunque `Updated`/`Deleted` aún no se emitan.
- **3-A** — Orden: **primero escritura** (slice por evento) y **luego lectura** (slice por endpoint).

### Reglas innegociables del contexto

- Bounded context **independiente**: `app/AuditLog/{Domain,Application,Infrastructure}` + `app/Http`.
- **NUNCA** consultar tablas/repos/modelos de `Listings`. **TODO** dato proviene del **payload del evento**.
- El Listener **puede** type-hintear `App\Listings\Domain\Events\*`, pero **NO** importar repos/modelos/servicios de `Listings`.
- **Idempotencia** por `event_id` UNIQUE: insert duplicado se **ignora** (no error, no fila extra).
- Listener `ShouldQueue`: cola `database`, `tries=3`, backoff `[5,15,30]`, fallo → `failed_jobs` (DLQ).
- `GET /api/audit-logs`: solo logs del usuario autenticado, `created_at DESC`, paginado, `auth:sanctum` + `throttle:60,1`.

**Deuda registrada:** cobertura de `Updated`/`Deleted` parcial hasta que existan los emisores (PATCH/DELETE).

---

## Sub-slice A — Escritura (slice por evento / `RecordAuditLog`)

| ID | Módulo | Objetivo | Criterio de aceptación | Peso |
| --- | --- | --- | --- | --- |
| **S2-00** | Verificación | Inspeccionar SOLO `app/AuditLog` (si existe), `database/migrations` (tabla `listing_audit_logs`) y `database/seeders`; confirmar columnas (`event_id` UNIQUE, `user_id`, `action`, `message`, `metadata` JSON, `created_at`). Excluir vendor/etc. No código | Reporte del estado real de la tabla y del namespace; discrepancias vs `DESIGN §IV`; sin código | S |
| **S2-01** | Domain / VOs | `AuditAction` (enum: created/updated/deleted), `AuditMessage` (string legible no vacío), `EventId` (UUID v4) | VOs inmutables, validan en ctor, sin Eloquent/Laravel | S |
| **S2-02** | Domain / Entity | `AuditLogEntry` + factory `record(EventId, userId, AuditAction, AuditMessage, array $metadata)` | Entidad pura; no conoce Eloquent; metadata = array | M |
| **S2-03** | Domain / Port | `AuditLogRepositoryPort::save(AuditLogEntry): void` con semántica **idempotente** (duplicado por `event_id` se ignora) | Interfaz en `AuditLog/Domain/Contracts`; contrato documenta idempotencia | S |
| **S2-04** | Domain / Tests | Pest unit: VOs + factory + construcción del `message` legible esperado | Tests verdes, sin BD; cubre formato `"Created listing 'X' (id: N) by user M"` | M |
| **S2-05** | Application / DTO | `RecordAuditLogCommand` (event_id, user_id, action, listing_id, listing_title, snapshot) — **solo datos del payload** | DTO inmutable; no referencia entidades de `Listings` | S |
| **S2-06** | Application / UseCase | `RecordAuditLogUseCase::execute(Command)` → construye `AuditLogEntry` (arma `message`) → `repo->save()` | Idempotente; sin queries a `Listings`; sin HTTP; test unit con repo mock | M |
| **S2-07** | Infra / Eloquent | `AuditLogModel` (tabla `listing_audit_logs`, cast `metadata`→array, `$timestamps` solo created_at) | Mapea tabla nativa; sin FK a `listings` | S |
| **S2-08** | Infra / Mapper + Repo | `AuditLogMapper` (Entity↔Eloquent) + `EloquentAuditLogRepository implements AuditLogRepositoryPort`; insert idempotente (catch UNIQUE / `firstOrCreate` por `event_id`) | Duplicado no lanza error ni duplica fila | M |
| **S2-09** | Infra / Listener | `RecordAuditLogListener implements ShouldQueue` (cola `database`, `tries=3`, backoff `[5,15,30]`). **Handler genérico** que acepta `ListingCreated`/`ListingUpdated`/`ListingDeleted` (type-hint a las clases de `App\Listings\Domain\Events`), extrae payload → `RecordAuditLogCommand` → UseCase | Mapea los 3 eventos a su `AuditAction`; falla → `failed_jobs` (DLQ); SOLO usa datos del payload | L |
| **S2-10** | Providers | Registrar binding `AuditLogRepositoryPort → EloquentAuditLogRepository` y **suscripción** del Listener a los 3 eventos (`EventServiceProvider`/`Event::listen`) | Binding y listeners registrados; `event:list` (o test) confirma suscripción | S |
| **S2-11** | Feature test (consumo) | Pest: despachar `ListingCreated` real → asegurar **1 fila** en `listing_audit_logs` con `message`/`metadata`/`action=created` correctos; re-despachar mismo `event_id` → **sigue 1 fila** (idempotencia). `Updated`/`Deleted` con eventos sintéticos | Verdes; idempotencia probada; aislamiento (no toca tablas `Listings`) | L |

---

## Sub-slice B — Lectura (slice por endpoint / `GET /api/audit-logs`)

| ID | Módulo | Objetivo | Criterio de aceptación | Peso |
| --- | --- | --- | --- | --- |
| **S2-12** | Application / Query | `QueryAuditLogsUseCase::execute(userId, page)` → `repo->findByUser(userId, page)` (orden `created_at DESC`, paginado) | Solo logs del usuario autenticado; sin acceso a `Listings` | M |
| **S2-13** | Domain / Port (ext.) | Añadir `findByUser(int $userId, int $page): paginado` a `AuditLogRepositoryPort` + impl en `EloquentAuditLogRepository` | `SELECT ... WHERE user_id=? ORDER BY created_at DESC`, paginado | S |
| **S2-14** | Http / Controller | `AuditLogController::index()` (resuelve `Auth::id()` → UseCase → Resource) | Sin reglas de negocio; sin repos de `Listings` | S |
| **S2-15** | Http / Resource | `AuditLogResource` (expone `id, action, message, metadata, created_at`) + colección paginada | No filtra datos de otros usuarios; forma estable | S |
| **S2-16** | Http / Routing | `GET /api/audit-logs` con `auth:sanctum` + `throttle:60,1` | Ruta protegida; 401 sin token | S |
| **S2-17** | Feature test (lectura) | Pest: usuario A ve solo SUS logs; orden `created_at DESC`; paginación; 401 sin token; **usuario A NO ve logs de B** (aislamiento por usuario) | Verdes; cubre orden, paginación, 401 y fuga de datos entre usuarios | L |

---

## Leyenda de pesos

| Peso | Significado |
| --- | --- |
| **S** | Small — trivial / mecánico |
| **M** | Medium — esfuerzo moderado |
| **L** | Large — mayor esfuerzo / lógica o pruebas amplias |

---

## Notas de cierre

- **Gating:** un solo ID por turno; el agente se detiene y espera aprobación explícita.
- **Riesgo conocido (2-B):** `Updated`/`Deleted` quedan cableados pero su prueba end-to-end es parcial hasta construir PATCH/DELETE.
- **Aislamiento (1-A):** acoplamiento de namespace aceptado; el dato persistido SIEMPRE proviene del payload del evento.
