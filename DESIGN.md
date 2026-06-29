# DESIGN — Golf Listing API · Fuente Única de Verdad

> **Stack:** PHP 8.2 · Laravel 12 · MySQL
> **Arquitectura:** Hexagonal (Ports & Adapters) + DDD táctico ligero + EDA (Event-Driven)
> **Versión:** 1.1 consolidada · sincronizada con el código

> [!IMPORTANT]
> Documento normativo. Describe el sistema **tal y como está implementado**. En caso de conflicto con el código, prevalece este documento; cuando se detecte una desviación, debe corregirse el código o actualizarse aquí.

---

## Resumen ejecutivo (at a glance)

| Aspecto              | Decisión                                                                   |
| -------------------- | -------------------------------------------------------------------------- |
| **Dominio**          | Marketplace móvil de artículos de golf (compra-venta entre usuarios)       |
| **Bounded contexts** | `Listings` (núcleo) · `AuditLog` (consumidor de eventos)                   |
| **Autenticación**    | Laravel Sanctum (`Authorization: Bearer`); tokens por seed                 |
| **Procesamiento IA** | Moderación + enriquecimiento **asíncronos** vía cola `database`            |
| **Eventos**          | `ListingCreated` · `ListingUpdated` · `ListingDeleted` (`EVENT_VERSION=1`) |
| **Moneda**           | USD implícito (sin columna de moneda)                                      |
| **Observabilidad**   | Telemetría JSON Lines a `stdout`, separada de la auditoría de negocio      |

**Endpoints públicos del API** (base `/api/v1`):

| Método   | Ruta             | Auth | Descripción                       |
| -------- | ---------------- | ---- | --------------------------------- |
| `GET`    | `/listings`      | No   | Listado público con filtros       |
| `POST`   | `/listings`      | Sí   | Crear listing                     |
| `PATCH`  | `/listings/{id}` | Sí   | Actualizar parcial (owner-only)   |
| `DELETE` | `/listings/{id}` | Sí   | Cancelar / soft-delete (owner)    |
| `GET`    | `/audit-logs`    | Sí   | Auditoría del usuario autenticado |

---

## Índice

**Parte I — Especificaciones Funcionales**

1. [Alcance del Sistema](#1-alcance-del-sistema)
2. [Modelo de Datos (MySQL)](#2-modelo-de-datos-mysql)
3. [Contratos de API (Endpoints)](#3-contratos-de-api-endpoints)
4. [Eventos de Dominio](#4-eventos-de-dominio)

**Parte II — Diseño Técnico y Arquitectura**

5. [Stack Tecnológico](#5-stack-tecnológico)
6. [Arquitectura Hexagonal y Bounded Contexts](#6-arquitectura-hexagonal-y-bounded-contexts)
7. [Autorización y Seguridad](#7-autorización-y-seguridad)
8. [Integración LLM (Asíncrona)](#8-integración-llm-asíncrona)
9. [Estándares de Código y Calidad](#9-estándares-de-código-y-calidad)

---

# PARTE I — Especificaciones Funcionales

## 1. Alcance del Sistema

API REST para una aplicación móvil de venta de artículos de golf. Permite a usuarios autenticados publicar, actualizar y cancelar listings; expone un listado público con filtros y paginación; procesa moderación y enriquecimiento vía LLM de forma asíncrona; y registra auditoría en un bounded context independiente alimentado **exclusivamente** por eventos de dominio.

```mermaid
flowchart LR
    Client["App móvil / Cliente API"]

    subgraph listingsCtx ["Bounded Context: Listings (núcleo)"]
        api["API REST /api/v1"]
        lifecycle["Ciclo de vida del listing"]
        llm["Procesamiento LLM asíncrono"]
    end

    subgraph auditCtx ["Bounded Context: AuditLog (consumidor)"]
        audit["Persistencia de auditoría"]
    end

    Client -->|HTTP + Bearer| api
    api --> lifecycle
    lifecycle -->|encola jobs| llm
    lifecycle -.->|"eventos de dominio"| audit
```

**Bounded contexts:**

- **Listings** (núcleo): ciclo de vida del listing y procesamiento LLM.
- **AuditLog** (consumidor de eventos): persiste auditoría sin acceso a las tablas de Listings.

> [!NOTE]
> Moneda: **USD implícito** (sin columna de moneda). Autenticación: **Laravel Sanctum** (`Authorization: Bearer`), sin endpoints de register/login; los tokens se entregan por seed.

---

## 2. Modelo de Datos (MySQL)

Base de datos: `golf_api`. Base de pruebas: `golf_api_testing`.

```mermaid
erDiagram
    users ||--o{ listings : "publica"
    categories ||--o{ listings : "clasifica"
    users ||--o{ personal_access_tokens : "tokenable"
    listings }o..o{ listing_audit_logs : "sin FK (solo payload)"

    users {
        bigint id PK
        varchar name
        varchar email UK
        varchar password
    }
    categories {
        bigint id PK
        varchar name UK
    }
    listings {
        bigint id PK
        bigint user_id FK
        bigint category_id FK
        varchar title
        decimal price
        enum condition
        text description
        date end_date
        enum moderation_status
        json moderation_result
        json ai_enrichment
        enum ai_enrichment_status
        timestamp cancelled_at
    }
    listing_audit_logs {
        bigint id PK
        bigint user_id "sin FK"
        bigint listing_id "sin FK"
        varchar action
        varchar message
        json metadata
        char event_id UK
    }
```

> [!NOTE]
> La relación entre `listings` y `listing_audit_logs` es **lógica, no referencial**: `AuditLog` persiste únicamente el payload recibido por evento y no declara claves foráneas hacia `Listings`/`users` (aislamiento de bounded context).

### 2.1 `users`

| Columna           | Tipo            | Restricciones / Notas   |
| ----------------- | --------------- | ----------------------- |
| id                | BIGINT UNSIGNED | PK, AUTO_INCREMENT      |
| name              | VARCHAR(255)    | Requerido               |
| email             | VARCHAR(255)    | UNIQUE, requerido       |
| email_verified_at | TIMESTAMP       | NULLABLE                |
| password          | VARCHAR(255)    | bcrypt; nunca se expone |
| remember_token    | VARCHAR(100)    | NULLABLE                |
| created_at        | TIMESTAMP       |                         |
| updated_at        | TIMESTAMP       |                         |

### 2.2 `categories`

| Columna    | Tipo            | Restricciones / Notas        |
| ---------- | --------------- | ---------------------------- |
| id         | BIGINT UNSIGNED | PK, AUTO_INCREMENT           |
| name       | VARCHAR(50)     | UNIQUE; uno de los 7 valores |
| created_at | TIMESTAMP       | NULLABLE (sin `updated_at`)  |

Valores: `Drivers`, `Woods`, `Hybrids`, `Driving Irons`, `Irons`, `Wedges`, `Putters`.

### 2.3 `listings`

| Columna              | Tipo                                        | Restricciones / Notas                       |
| -------------------- | ------------------------------------------- | ------------------------------------------- |
| id                   | BIGINT UNSIGNED                             | PK, AUTO_INCREMENT                          |
| user_id              | BIGINT UNSIGNED                             | FK → `users.id` (cascade on delete)         |
| category_id          | BIGINT UNSIGNED                             | FK → `categories.id` (restrict on delete)   |
| title                | VARCHAR(255)                                | Regex letras+espacios                       |
| price                | DECIMAL(10,2)                               | >= 0.01; USD implícito                      |
| condition            | ENUM('New','Used','Refurbished','Like New') | Requerido                                   |
| description          | TEXT                                        | 10–1000, sanitizado                         |
| end_date             | DATE                                        | NULLABLE; >= hoy si presente                |
| moderation_status    | ENUM('pending','approved','rejected')       | DEFAULT 'pending'                           |
| moderation_result    | JSON                                        | NULLABLE; labels, scores, explanation       |
| ai_enrichment        | JSON                                        | NULLABLE; model_evaluation + valor estimado |
| ai_enrichment_status | ENUM('pending','succeeded','failed')        | DEFAULT 'pending'                           |
| cancelled_at         | TIMESTAMP                                   | NULLABLE; soft-delete                       |
| created_at           | TIMESTAMP                                   |                                             |
| updated_at           | TIMESTAMP                                   |                                             |

**Índices:**

| Columnas                        | Tipo                        |
| ------------------------------- | --------------------------- |
| `id`                            | PRIMARY                     |
| `user_id`                       | FOREIGN KEY                 |
| `category_id`                   | FOREIGN KEY + single-column |
| `price`                         | single-column               |
| `condition`                     | single-column               |
| `created_at`                    | single-column               |
| `end_date`                      | single-column               |
| `(moderation_status, end_date)` | compuesto (listado público) |

> [!NOTE]
> El Value Object `Price` opera internamente en **céntimos enteros** (mín. 1 céntimo = $0.01; máx. 9.999.999.999 céntimos) aunque la columna física sea `DECIMAL(10,2)`. La conversión es responsabilidad del VO/mapper.

### 2.4 `listing_audit_logs`

| Columna    | Tipo            | Restricciones / Notas             |
| ---------- | --------------- | --------------------------------- |
| id         | BIGINT UNSIGNED | PK, AUTO_INCREMENT                |
| user_id    | BIGINT UNSIGNED | Desde payload; **sin FK**         |
| listing_id | BIGINT UNSIGNED | Desde payload; **sin FK**         |
| action     | VARCHAR(50)     | `created` / `updated` / `deleted` |
| message    | VARCHAR(500)    | Legible                           |
| metadata   | JSON            | Snapshot del payload              |
| event_id   | CHAR(36)        | UNIQUE; UUID v4; deduplicación    |
| created_at | TIMESTAMP       | NULLABLE (sin `updated_at`)       |

La tabla **no** declara claves foráneas hacia `listings`/`users`: persiste únicamente el payload recibido, sin acoplarse al esquema del emisor.

### 2.5 Tablas de infraestructura nativas

- `personal_access_tokens` — tokens de Sanctum (`token` hasheado, UNIQUE).
- `jobs` — cola de trabajos asíncronos (driver `database`).
- `failed_jobs` — Dead Letter Queue (DLQ), driver `database-uuids`.
- `job_batches` — soporte de lotes (creada por la migración nativa; no usada funcionalmente).
- `password_reset_tokens`, `sessions` — creadas por la migración nativa de usuarios; no usadas por esta API solo-token.

---

## 3. Contratos de API (Endpoints)

Base: `/api/v1` (configurado en `bootstrap/app.php` vía `apiPrefix: 'api/v1'`). Las rutas autenticadas aplican `auth:sanctum` y `throttle:60,1`. El middleware terminable `LogHttpTelemetry` se aplica a todo el grupo `api`.

### 3.1 Error Envelope normativo

Todas las respuestas de error usan el formato uniforme:

```json
{
    "error": {
        "code": "VALIDATION_ERROR",
        "message": "Validation failed",
        "details": { "field": ["msg"] }
    }
}
```

**Catálogo de errores:**

| Code               | HTTP | Cuándo                                                      |
| ------------------ | ---- | ----------------------------------------------------------- |
| `VALIDATION_ERROR` | 422  | FormRequest o regla de dominio falla.                       |
| `UNAUTHENTICATED`  | 401  | Token ausente o inválido.                                   |
| `FORBIDDEN`        | 403  | No es propietario del listing.                              |
| `NOT_FOUND`        | 404  | Listing inexistente o cancelado (excepción de dominio).     |
| `RATE_LIMITED`     | 429  | Excede 60 req/min, o el cupo diario de creación (§3.2, §7). |
| `INTERNAL_ERROR`   | 500  | `Throwable` no controlado (catch-all).                      |

> [!NOTE]
> El campo `details` es un **objeto vacío `{}`** (`new stdClass`) para todos los códigos salvo `VALIDATION_ERROR`, donde es un **array indexado por campo** con los mensajes de validación. El `message` de validación es fijo: `"Validation failed"`.

**Flujo de mapeo (`bootstrap/app.php`):**

```mermaid
flowchart TD
    err["Throwable lanzado"] --> json{"expectsJson() o is('api/*')?"}
    json -->|No| passthrough["Respuesta por defecto de Laravel"]
    json -->|Sí| kind{"Tipo de excepción"}

    kind -->|ListingNotFoundException| e404["NOT_FOUND · 404"]
    kind -->|ListingAccessDeniedException| e403["FORBIDDEN · 403"]
    kind -->|InvalidListingDataException| e422d["VALIDATION_ERROR · 422 (details = errors)"]
    kind -->|AuthenticationException| e401["UNAUTHENTICATED · 401"]
    kind -->|ThrottleRequestsException| e429["RATE_LIMITED · 429 (conserva headers)"]
    kind -->|"HttpResponseException / HttpExceptionInterface"| keep["Conserva su status propio (FormRequest 422, ruta 404/405)"]
    kind -->|Otro Throwable| e500["INTERNAL_ERROR · 500 (catch-all, sin filtrar trace)"]
```

- Cada excepción esperada (dominio, autenticación, throttling) se mapea con un `render` callback al envelope. Un `render` catch-all final convierte cualquier `Throwable` restante en `INTERNAL_ERROR` 500, **excluyendo** `HttpResponseException` y `HttpExceptionInterface` (FormRequest 422, 404/405 de ruta) para que conserven su propio status. El catch-all nunca filtra el mensaje ni el trace interno.
- **Detección JSON por path:** los handlers responden con el envelope cuando `expectsJson()` **o** `is('api/*')`, de modo que un cliente que omite `Accept: application/json` igual recibe JSON. Complementado en el middleware con `redirectGuestsTo(null)` (app solo-API: nunca redirige a una ruta `login` inexistente; un 401 sin `Accept` devuelve el envelope `UNAUTHENTICATED` en vez de un 500 latente).
- **Reporte:** las excepciones de dominio (`ListingDomainException` y subclases) se excluyen del log vía `dontReport()` por ser flujo de control esperado. Un `report` callback emite además `error.unhandled` al pipeline `stdout` (§9.2) para los `Throwable` que sí se reportan (500 HTTP y fallos definitivos de jobs).

### 3.2 `POST /api/v1/listings` — Crear (protegido)

> [!IMPORTANT]
> Además del throttle global `throttle:60,1`, esta ruta aplica un limitador con nombre **`listing-creation`**: un cupo **diario por usuario** (`Limit::perDay`, keyed por `user->id` o IP), configurable en `config/listings.php` vía `LISTINGS_DAILY_CREATION_LIMIT` (default **10/día**). Al excederlo → `429 RATE_LIMITED`. Protege la API y acota el coste de procesamiento LLM.

**Request:**

```json
{
    "title": "string",
    "price": 0.0,
    "condition": "New|Used|Refurbished|Like New",
    "description": "string",
    "end_date": "YYYY-MM-DD (opcional)",
    "category_id": 1
}
```

**Reglas de validación:**

| Campo       | Reglas                                                 |
| ----------- | ------------------------------------------------------ |
| title       | requerido; `^[A-Za-z ]+$`; 3–255; `trim` previo        |
| price       | requerido; numérico; `min:0.01`; `max:99999999.99`     |
| condition   | requerido; `in:New,Used,Refurbished,Like New`          |
| description | requerido; 10–1000; sanitizado (`strip_tags` + `trim`) |
| end_date    | opcional; `date_format:Y-m-d`; `after_or_equal:today`  |
| category_id | requerido; `integer`; `exists:categories,id`           |

**Comportamiento:** persiste con `moderation_status=pending` y `ai_enrichment_status=pending`; publica `ListingCreated` tras commit; encola `ModerationJob` y `EnrichmentJob`.

```mermaid
sequenceDiagram
    autonumber
    participant C as Cliente
    participant Ctrl as ListingController
    participant FR as StoreListingRequest
    participant UC as CreateListingUseCase
    participant Repo as ListingRepositoryPort
    participant Pub as DomainEventPublisher
    participant Q as Cola (database)

    C->>Ctrl: POST /api/v1/listings (Bearer)
    Ctrl->>FR: validar + sanitizar
    FR-->>Ctrl: datos validados
    Ctrl->>UC: execute(CreateListingCommand) [DB::transaction]
    UC->>Repo: save(Listing) status pending/pending
    Repo-->>UC: Listing (con id)
    UC->>Pub: publishAfterCommit(ListingCreated)
    UC->>Q: dispatchModeration(id)
    UC->>Q: dispatchEnrichment(id)
    UC-->>Ctrl: Listing
    Note over Pub: el evento se despacha tras el commit
    Ctrl-->>C: 201 + Location: /api/v1/listings/{id}
```

**Response 201:**

<details>
<summary>Ver cuerpo de respuesta 201</summary>

```json
{
    "id": 123,
    "title": "Driver X",
    "price": 199.99,
    "condition": "Used",
    "description": "...",
    "end_date": null,
    "category_id": 1,
    "moderation_status": "pending",
    "ai_enrichment_status": "pending",
    "ai_enrichment": null,
    "created_at": "ISO8601",
    "user": { "name": "Jane Doe" }
}
```

</details>

Header `Location: /api/v1/listings/{id}`. Cuerpo **plano** (sin envoltura `data`; `$wrap = null`). `ai_enrichment` siempre presente (hardcoded `null` en creación/actualización). El usuario se expone como `{ "name": "..." }`.

**Códigos:** `201`, `422`, `401`, `429`.

### 3.3 `PATCH /api/v1/listings/{id}` — Actualizar parcial (protegido, solo propietario)

Actualización parcial: solo se modifican los campos enviados, aplicando las mismas reglas por campo presente (reglas `sometimes`). `category_id` es editable.

**Re-evaluación condicional** (solo si el valor realmente cambia, comparado vía `equals()`):

| Cambio de campo            | Efecto                                                         |
| -------------------------- | -------------------------------------------------------------- |
| `title` o `description`    | `moderation_status=pending` + re-encola `ModerationJob`        |
| `price` o `condition`      | `ai_enrichment_status=pending` + re-encola `EnrichmentJob`     |
| `end_date` o `category_id` | Se registra en `changes`; **sin** reset de estado ni re-encola |

> [!NOTE]
> El repositorio **siempre** persiste (`update()`), aunque `changes` quede vacío. Sin embargo, `ListingUpdated` **no se publica** si `changes` está vacío (ningún campo enviado por el usuario cambió). Publica tras commit.

**Response 200** con el recurso (forma idéntica a `POST`, `user: { name }`).

**Códigos:** `200`, `403`, `404` (inexistente o cancelado), `422`, `401`, `429`.

### 3.4 `DELETE /api/v1/listings/{id}` — Cancelar (protegido, solo propietario)

Soft-delete (`cancelled_at = now`). **Idempotente:** un DELETE sobre un listing ya cancelado responde `204` sin re-persistir ni re-publicar.

> [!IMPORTANT]
> El orden de comprobación es: `404` si no existe → `403` si el actor no es propietario → no-op si ya está cancelado → cancelación real. La autorización (403) se evalúa **antes** que la idempotencia.

Publica `ListingDeleted` tras commit, únicamente en la cancelación real. Re-listado no permitido.

**Códigos:** `204`, `403`, `404` (inexistente), `401`, `429`. Sin cuerpo de respuesta.

### 3.5 `GET /api/v1/listings` — Listado público (sin auth)

**Query params:**

| Param       | Reglas                                                          | Default |
| ----------- | --------------------------------------------------------------- | ------- |
| min_price   | nullable; `numeric`; `min:0`                                    | —       |
| max_price   | nullable; `numeric`; `min:0`                                    | —       |
| category_id | nullable; `integer`; `exists:categories,id`                     | —       |
| condition   | nullable; `in:New,Used,Refurbished,Like New`                    | —       |
| q           | nullable; `string`; `max:255`; busca en `title` + `description` | —       |
| show_all    | nullable; `boolean`                                             | `false` |
| page        | nullable; `integer`; `min:1`                                    | `1`     |
| per_page    | nullable; `integer`; `min:1`; `max:100` (422)                   | `20`    |

> [!NOTE]
> Los defaults (`show_all`, `page`, `per_page`) se aplican en `ListListingsQuery::fromValidated`, no en el FormRequest. **No** existe regla cruzada `min_price <= max_price`.

**Visibilidad y orden:**

- `show_all=false`: `moderation_status='approved'` + `cancelled_at IS NULL` + (`end_date >= hoy` OR `end_date IS NULL`); orden `created_at ASC`.
- `show_all=true`: todos los listings; orden `price DESC`.

**Item de respuesta:**

<details>
<summary>Ver item del listado</summary>

```json
{
    "id": 123,
    "title": "Driver X",
    "price": 199.99,
    "condition": "Used",
    "description": "...",
    "created_at": "ISO8601",
    "user": { "first_name": "Jane", "last_name": "Doe" },
    "category": { "id": 1, "name": "Drivers" },
    "ai_enrichment": null
}
```

</details>

> [!WARNING]
> La forma del usuario **difiere por endpoint**: en este listado se expone como `{ first_name, last_name }` (derivado del único campo `name`: primer token vs. resto), mientras que en `POST`/`PATCH` se expone como `{ name }`. `ai_enrichment` se expone tal cual (null o el objeto JSON completo). La respuesta es paginada con envoltura `data` + `meta`/`links`.

**Códigos:** `200`, `422`.

### 3.6 `GET /api/v1/audit-logs` — Auditoría (protegido)

Devuelve los logs **del usuario autenticado**, orden `created_at DESC` (desempate `id DESC`), paginado a **20 por página** (fijo en el repositorio; único query param aceptado: `page`, coaccionado con `max(1, ...)`). Envoltura `data` + `meta`/`links`.

**Item de respuesta:**

```json
{
    "id": 1,
    "action": "created",
    "message": "Created listing 'Driver X' (id: 123) by user 45",
    "metadata": { "...": "snapshot del payload" },
    "created_at": "ISO8601"
}
```

> [!NOTE]
> El recurso **no** expone `user_id`, `listing_id` ni `event_id`.

**Códigos:** `200`, `401`, `429`.

---

## 4. Eventos de Dominio

Eventos: `ListingCreated`, `ListingUpdated`, `ListingDeleted`. `EVENT_VERSION = 1`.

### 4.1 Payload normativo

IDs de negocio (`user_id`, `listing_id`) son **BIGINT**; `event_id` es **UUID v4** (identificador del evento para idempotencia). Los tres eventos comparten el mismo envelope (`event_id`, `event_version`, `occurred_at`, `user_id`, `listing_id`, `listing_snapshot`); el `id` del listing nunca se duplica dentro de `listing_snapshot` y siempre se excluyen `ai_enrichment` y `moderation_result`. El contenido de `listing_snapshot` cambia según el tipo de evento.

<details>
<summary><strong>ListingCreated</strong> — snapshot completo del estado inicial (sin <code>id</code>)</summary>

```json
{
    "event_id": "550e8400-e29b-41d4-a716-446655440000",
    "event_version": 1,
    "occurred_at": "2026-06-23T23:00:00Z",
    "user_id": 45,
    "listing_id": 123,
    "listing_snapshot": {
        "title": "Driver X",
        "price": 199.99,
        "condition": "Used",
        "description": "Descripción...",
        "category_id": 1,
        "moderation_status": "pending",
        "created_at": "2026-06-23T22:59:59Z",
        "end_date": null
    }
}
```

Incluye: `title`, `price`, `condition`, `description`, `category_id`, `moderation_status`, `created_at`, `end_date`. Excluye `ai_enrichment`, `moderation_result` y `ai_enrichment_status`.

</details>

<details>
<summary><strong>ListingUpdated</strong> — <code>title</code> actual + <code>changes</code></summary>

`changes` contiene **solo** los campos enviados por el usuario que cambiaron, cada uno con `old`/`new`. No incluye side-effects del sistema (`moderation_status`, `ai_enrichment_status`). El evento **no se publica** si `changes` queda vacío.

```json
"listing_snapshot": {
  "title": "New Title",
  "changes": {
    "title": { "old": "Old Title", "new": "New Title" },
    "price": { "old": 199.99, "new": 250.00 }
  }
}
```

</details>

<details>
<summary><strong>ListingDeleted</strong> — snapshot mínimo (solo <code>title</code>)</summary>

El momento de la cancelación lo cubre `occurred_at`.

```json
"listing_snapshot": {
  "title": "Driver X"
}
```

</details>

Fechas en ISO 8601 / UTC; `price` serializado como decimal.

### 4.2 Flujo de auditoría idempotente

```mermaid
sequenceDiagram
    autonumber
    participant UC as Use Case (Listings)
    participant Pub as DomainEventPublisher
    participant L as RecordAuditLogListener (ShouldQueue)
    participant Repo as EloquentAuditLogRepository
    participant DB as listing_audit_logs
    participant DLQ as failed_jobs

    UC->>Pub: publishAfterCommit(evento)
    Pub-->>L: dispatch in-process (tras commit)
    L->>L: encolar en driver database
    L->>Repo: save(AuditLogEntry)
    Repo->>DB: firstOrCreate(event_id)
    alt event_id nuevo
        DB-->>Repo: INSERT (1 fila)
    else event_id duplicado
        DB-->>Repo: no-op (UNIQUE garantiza 1 fila)
    end
    Note over L,DLQ: fallo persistente tras reintentos -> DLQ
```

1. El Use Case persiste el listing y publica el evento de dominio **in-process** con semántica after-commit.
2. Un **Listener `ShouldQueue`** del contexto `AuditLog` se encola en el driver `database` (3 reintentos, backoff `[5, 15, 30]`).
3. El consumidor verifica `event_id`:
    - **Duplicado** → no-op (insert idempotente vía `firstOrCreate(['event_id' => ...])`; el UNIQUE garantiza una sola fila).
    - **Nuevo** → INSERT con `user_id`, `listing_id`, `action`, `message`, `metadata`, `event_id`.
4. Fallo persistente → `failed_jobs` (DLQ).

`message` legible, ej.: `"Created listing 'Driver X' (id: 123) by user 45"`; en updates se listan los campos cambiados, ej.: `"Updated listing 'Driver X' (id: 123): title, price by user 45"`. `metadata` = `listing_snapshot` del payload. Solo se auditan `ListingCreated`/`ListingUpdated`/`ListingDeleted`; **no** se auditan resultados de moderación/enriquecimiento.

> [!NOTE]
> La auditoría es **asíncrona y eventualmente consistente**: la escritura del listing es síncrona, pero la fila de auditoría se persiste tras el procesamiento del listener encolado.

---

# PARTE II — Diseño Técnico y Arquitectura

## 5. Stack Tecnológico

| Componente            | Tecnología                                                            |
| --------------------- | --------------------------------------------------------------------- |
| Lenguaje              | PHP 8.2                                                               |
| Framework             | Laravel 12 (estructura streamlined; `bootstrap/app.php`)              |
| Base de datos         | MySQL (`golf_api`; pruebas: `golf_api_testing`)                       |
| Autenticación         | Laravel Sanctum (token Bearer)                                        |
| Cola asíncrona        | Driver `database`; DLQ vía `failed_jobs` (`database-uuids`)           |
| Eventos               | Eventos in-process de Laravel → listener encolado                     |
| Validación de entrada | FormRequest                                                           |
| Autorización          | Use Cases (owner-only) + `throttle:60,1` + cupo diario de creación    |
| Configuración propia  | `config/listings.php` (cupo diario), `config/llm.php` (proveedor LLM) |
| Testing               | Pest (unit + feature)                                                 |
| Linter / estilo       | Laravel Pint (PSR-12)                                                 |
| Telemetría            | Logs estructurados JSON Lines a `stdout` (canal Monolog dedicado)     |

---

## 6. Arquitectura Hexagonal y Bounded Contexts

```mermaid
flowchart LR
    subgraph listingsBC ["Listings (núcleo)"]
        direction TB
        lDom["Domain: entidades, VOs, eventos, puertos"]
        lApp["Application: use cases, DTOs, read models"]
        lInfra["Infrastructure: Eloquent, repos, LLM, jobs"]
    end

    subgraph auditBC ["AuditLog (consumidor)"]
        direction TB
        aDom["Domain: AuditLogEntry, puertos"]
        aApp["Application: record / query"]
        aInfra["Infrastructure: listener, repo, mapper"]
    end

    listingsBC ==>|"ListingCreated / Updated / Deleted"| auditBC
    auditBC -. "sin acceso a tablas/repos/modelos de Listings" .-> listingsBC
```

> [!IMPORTANT]
> El cruce entre bounded contexts se realiza **solo por contrato explícito (eventos)**. `AuditLog` nunca toca tablas, repositorios ni modelos de `Listings`.

### 6.1 Estructura de directorios

```text
app/
  Listings/
    Domain/          # Entities, ValueObjects, Events, Exceptions, Contracts (Ports, incl. LlmPort)
    Application/     # UseCases, Commands, Queries, DTOs, ReadModels, Contracts (puertos de salida)
    Infrastructure/  # Eloquent models, Repositories, Mappers, LLM adapters, Jobs, Dispatchers, Events
  AuditLog/
    Domain/          # AuditLogEntry (Entity/VO), Contracts (AuditLogRepositoryPort)
    Application/     # RecordAuditLog (escritura), QueryAuditLogs (lectura), Commands
    Infrastructure/  # Listener (ShouldQueue), Eloquent repo, Mapper
  Http/              # Controllers, Requests (FormRequests), Resources, Middlewares (capa transversal)
  Support/           # Utilidades transversales (p.ej. Telemetry: emisor de logs estructurados)
  Providers/         # ServiceProviders (bindings Port→Adapter)
database/
  migrations/
  seeders/
config/
  listings.php       # cupo diario de creación
  llm.php            # proveedor LLM (mock | ollama)
routes/
  api.php
```

Namespaces `App\Listings\...` y `App\AuditLog\...` cubiertos por el PSR-4 por defecto (`"App\\": "app/"`).

### 6.2 Reglas de flujo de dependencias

**Permitido:**

- `app/Http → Application`
- `Application → Domain`
- `Infrastructure → Domain`

**Prohibido:**

- `Domain → Laravel/Eloquent`
- `Application → Eloquent/HTTP`
- `Domain → Infrastructure`
- Acceso directo a repositorios concretos desde `app/Http`.

**Reglas adicionales:**

- Los modelos Eloquent **no** son entidades de dominio; la conversión se realiza vía mappers.
- Los repositorios se definen como interfaces en `Domain/Contracts` y se implementan en `Infrastructure`.
- Los puertos de salida de Application (`DomainEventPublisher`, `ListingProcessingDispatcher`) se definen en `Application/Contracts` y se implementan en `Infrastructure`.
- Los controladores reciben HTTP, validan vía FormRequest, transforman a Command/DTO, invocan el Use Case y devuelven Resource. **Sin reglas de negocio.**
- No se usan Observers de Eloquent para auditoría; los eventos de persistencia no sustituyen a los eventos de dominio.
- `AuditLogController` reside en `app/Http/Controllers` (capa transversal), no en `Infrastructure`.

### 6.3 Diagrama de capas

```mermaid
graph TD
    subgraph Laravel["Capa de entrada Laravel (app/Http, app/Providers)"]
        C[Controllers]
        FR[FormRequests]
        JB["Jobs / Workers"]
        SP[ServiceProviders]
    end

    subgraph BC["Bounded Context (app/Contexto/)"]
        subgraph INFRA["Infrastructure (Adaptadores)"]
            E["Modelos Eloquent"]
            R["Repositorios concretos"]
            ALLM["Adaptador LLM"]
            MAP[Mappers]
        end
        subgraph APP["Application (Casos de uso)"]
            UC["Use Cases"]
            DTO["DTOs / Commands / ReadModels"]
        end
        subgraph DOM["Domain (Núcleo)"]
            ENT[Entities]
            VO["Value Objects"]
            PORT["Contracts / Ports"]
            EVT["Domain Events"]
        end
    end

    C -->|invoca| UC
    JB -->|invoca| UC
    UC -->|usa| ENT
    UC -->|depende de| PORT
    R -.->|implementa| PORT
    ALLM -.->|implementa| PORT
    R -->|persiste con| E
    R --> MAP
    SP -.->|"bind Port a Adapter"| R
    E -.->|NO son| ENT
```

### 6.4 Modelo de lectura (CQRS-lite) del listado

`GET /api/v1/listings` usa un lado de lectura separado del de escritura:

- `ListingListItem` (read model con nombres unidos y `ai_enrichment`).
- `ListListingsQuery` (DTO de filtros con defaults).
- `ListingQueryPort::search(): LengthAwarePaginator` (contrato genérico de Illuminate, no Eloquent).
- `EloquentListingQueryRepository` aplica filtros, visibilidad/orden y eager-loading `with(['user:id,name','category:id,name'])` para evitar N+1.

`AuditLog` expone su lectura vía `AuditLogRepositoryPort::findByUser(int $userId, int $page): LengthAwarePaginator`, cuyos ítems son entidades de dominio `AuditLogEntry` rehidratadas por el mapper.

---

## 7. Autorización y Seguridad

```mermaid
flowchart TD
    req["Petición a ruta protegida"] --> auth{"Token Sanctum válido?"}
    auth -->|No| u401["401 UNAUTHENTICATED"]
    auth -->|Sí| rate{"Dentro de límites?<br/>60/min + cupo diario (POST)"}
    rate -->|No| u429["429 RATE_LIMITED"]
    rate -->|Sí| load{"Listing existe y no cancelado?"}
    load -->|No| u404["404 NOT_FOUND"]
    load -->|Sí| owner{"Actor es propietario?"}
    owner -->|No| u403["403 FORBIDDEN"]
    owner -->|Sí| ok["Ejecuta Use Case"]
```

- **Autenticación:** Sanctum, header `Authorization: Bearer <token>`. Sin endpoints register/login; tokens entregados por seed. El plain-text del token se imprime una vez al sembrar para pruebas locales.
- **Owner-only:** la autorización se resuelve en el Use Case. El caso de uso carga el listing vía `ListingRepositoryPort::findById`; si es null o está cancelado lanza `ListingNotFoundException` (404), y si el actor no es el propietario lanza `ListingAccessDeniedException` (403). Las excepciones de dominio se mapean al error envelope en `bootstrap/app.php` solo para peticiones JSON.
- **Rate limiting:** middleware `throttle:60,1` en rutas autenticadas → `429` al exceder 60 req/min por usuario. `POST /listings` añade el limitador con nombre `listing-creation` (cupo diario, default 10/día; `config/listings.php`).
- **Ruta pública:** `GET /api/v1/listings` se registra fuera del grupo `auth:sanctum`/`throttle` (sin rate limit).
- **Datos sensibles:** `password`, `email` y tokens nunca se exponen en respuestas.

---

## 8. Integración LLM (Asíncrona)

### 8.1 Puerto único

Un solo puerto en el dominio, con adaptadores en infraestructura:

```php
namespace App\Listings\Domain\Contracts;

interface LlmPort
{
    public function moderate(ModerationInput $input): ModerationResult;

    public function enrich(EnrichmentInput $input): EnrichmentResult;
}
```

Los DTOs (`ModerationInput`/`ModerationResult`/`EnrichmentInput`/`EnrichmentResult`) son inmutables, viven en `App\Listings\Domain\Llm` y exponen `toArray()`.

- `ModerationResult` → `{ status: approved|rejected, labels[], scores{}, explanation, model, timestamp }`
- `EnrichmentResult` → `{ model_evaluation{summary,features[],confidence}, estimated_market_value{value,currency:"USD",confidence_interval[],confidence,basis}, model, generated_at }`

### 8.2 Jobs paralelos

Al crear o actualizar se encolan **dos jobs independientes y paralelos** en la misma cola `database`, sin garantía de orden. `EnrichmentJob` no depende del resultado de `ModerationJob`. Los jobs son adaptadores delgados que invocan casos de uso de Application (`ModerateListingUseCase`, `EnrichListingUseCase`); nunca llaman al puerto directamente.

- **ModerationJob:** clasifica el contenido → escribe `moderation_result` y resuelve `moderation_status` (`approved` / `rejected`).
- **EnrichmentJob:** genera `model_evaluation` + `estimated_market_value` → escribe `ai_enrichment` y resuelve `ai_enrichment_status`.

**Ciclo de vida del listing** (estados derivados de creación, jobs LLM y cancelación):

```mermaid
stateDiagram-v2
    direction LR

    state moderationStatus {
        modPending: pending
        approved: approved
        rejected: rejected
        [*] --> modPending: POST
        modPending --> approved: ModerationJob ok
        modPending --> rejected: ModerationJob ok
        modPending --> modPending: fallo -> fallback (sigue oculto)
        approved --> modPending: PATCH title/description
        rejected --> modPending: PATCH title/description
    }

    state aiEnrichmentStatus {
        enrPending: pending
        succeeded: succeeded
        failed: failed
        [*] --> enrPending: POST
        enrPending --> succeeded: EnrichmentJob ok
        enrPending --> failed: EnrichmentJob fallo definitivo
        succeeded --> enrPending: PATCH price/condition
        failed --> enrPending: PATCH price/condition
    }

    state cancellation {
        active: activo
        cancelled: cancelled_at = now
        [*] --> active: POST
        active --> cancelled: DELETE
        cancelled --> cancelled: DELETE idempotente (no-op)
    }
```

> [!NOTE]
> Solo los listings con `moderation_status=approved`, `cancelled_at IS NULL` y `end_date` vigente (o nulo) son visibles en el listado público por defecto (§3.5).

> [!NOTE]
> **Persistencia acotada por columna:** `ListingRepositoryPort` expone `updateModerationResult(...)` y `updateEnrichment(...)` que cargan el modelo y persisten únicamente las columnas sucias, preservando la columna del otro proceso. Si el listing ya no existe al ejecutar el job, el use case retorna `false` y el job emite `job.skipped` (no-op).

### 8.3 Política de reintentos y fallback

```mermaid
flowchart TD
    start["Job ejecuta handle()"] --> call["Invoca Use Case -> LlmPort"]
    call --> result{"Resultado"}
    result -->|Éxito| persistOk["Persiste resultado y estado"]
    result -->|OllamaException| retryable{"isRetryable()?"}

    retryable -->|"No (4xx, JSON/schema inválido)"| failNow["this->fail() -> DLQ inmediato"]
    retryable -->|"Sí (conexión, 5xx)"| rethrow["throw -> reintento"]
    rethrow --> tries{"attempts < 3?"}
    tries -->|Sí| backoff["Backoff 5s / 15s / 30s"]
    backoff --> start
    tries -->|No| failNow

    failNow --> fb["failed(): fallback por job"]
    fb --> fbMod["Moderación -> moderation_status=pending (oculto)"]
    fb --> fbEnr["Enrichment -> ai_enrichment_status=failed"]
    fb --> dlq["failed_jobs (DLQ)"]
    fb -.->|"si el fallback falla"| ffail["emite job.fallback_failed"]
```

- `QUEUE_CONNECTION=database`.
- **3 reintentos** con backoff exponencial: **5s, 15s, 30s** (`$tries = 3`, `backoff() = [5, 15, 30]`).
- **Clasificación de fallos del LLM:** `OllamaException` expone `isRetryable()`. Los fallos **transitorios** (error de conexión, HTTP 5xx) se relanzan y consumen el ciclo de reintentos. Los fallos **permanentes** (HTTP 4xx, JSON inválido, schema inválido) no pueden tener éxito al reintentar: el job llama `$this->fail($e)` de inmediato y salta directo a la DLQ, evitando el backoff inútil.
- **Fallback tras fallo definitivo** (`failed()` del job):
    - Moderación fallida → `moderation_status=pending` (no visible públicamente); error en `moderation_result`.
    - Enrichment fallido → `ai_enrichment_status=failed`; error en `ai_enrichment`.
    - El job va a `failed_jobs` (DLQ).
    - La persistencia del fallback se envuelve en try/catch; si ella misma falla, se emite `job.fallback_failed` (§9.2) para que la pérdida no quede silenciosa.

### 8.4 Mock LLM determinista

`LlmProviderMock` (`Infrastructure\Llm`) implementa `LlmPort` y se enlaza vía `ListingsServiceProvider` leyendo `config/llm.php` (`LLM_PROVIDER`, default **`mock`**). Modelo reportado: `mock-llm-v1`; sin llamadas HTTP externas ni telemetría.

- **Moderación:** `approved` por defecto; `rejected` si el contenido (`title + description`, en minúsculas) contiene `"scam"` o coincide con una URL sospechosa (`#https?://|www\.#i`).
- **Enrichment:** texto simple + `estimated_market_value = price * factor_by_condition`, intervalo de confianza ±10% (`confidence` 0.7, currency `USD`). Factores por condición:

| Condición       | Factor         |
| --------------- | -------------- |
| New             | 1.0            |
| Like New        | 0.9            |
| Refurbished     | 0.8            |
| Used            | 0.65           |
| _(desconocida)_ | 0.5 (fallback) |

El adaptador real `OllamaLlmProvider` realiza POST a `{baseUrl}/api/chat` (`format: json`, `stream: false`), valida la respuesta vía `OllamaResponseMapper` (que fuerza `currency: USD`) y nunca devuelve resultados degradados: cualquier fallo lanza `OllamaException`.

---

## 9. Estándares de Código y Calidad

- **Idioma:** código y comentarios en inglés; nombres auto-descriptivos.
- **Documentación:** DocStrings en clases/métodos públicos; comentarios solo donde aporten valor.
- **Estilo:** PSR-12 + Laravel Pint.
- **Telemetría:** logs estructurados en las fronteras del sistema (controladores, jobs, adaptador LLM). Detalle en §9.2.
- **Testing:** Pest para unit y feature. Pruebas de dominio aisladas con mocks de puertos. La suite cubre happy-path de cada endpoint, idempotencia de `AuditLog`, fallback de moderación y la telemetría de fronteras (HTTP, jobs, listener de auditoría), incluyendo la no fuga de contenido sensible. La suite se ejecuta contra MySQL en la base dedicada `golf_api_testing` con `RefreshDatabase`.

### 9.1 Seeders

Orden de dependencia: `CategorySeeder → UserSeeder → ListingSeeder → TokenSeeder` (con `WithoutModelEvents`).

- `CategorySeeder`: 7 categorías fijas (idempotente vía `updateOrInsert`).
- `UserSeeder`: usuarios `alice@golf.test` (Alice Walker) y `bob@golf.test` (Bob Stone); password `password` (bcrypt).
- `ListingSeeder`: 10 listings que cubren los escenarios de visibilidad del listado público (aprobado/visible, sin `end_date`, pendiente, rechazado, cancelado, expirado, premium, demo de búsqueda).
- `TokenSeeder`: tokens Sanctum deterministas — `golf-seed-token-alice`, `golf-seed-token-bob` (abilities `['*']`); imprime el bearer `{id}|{plainText}` en consola.

### 9.2 Telemetría operacional (logs estructurados)

> [!IMPORTANT]
> Telemetría operacional para observabilidad, **independiente del bounded context `AuditLog`**: no se persiste en base de datos ni se mezcla con la auditoría de negocio (que sigue alimentándose solo por eventos de dominio, §4). Es telemetría per-layer: cada frontera registra sus eventos de forma autónoma, sin id de correlación cruzado request→job.

**Canal y formato.** Canal Monolog dedicado `stdout` (`config/logging.php`): `JsonFormatter` → `php://stdout` con `appendNewline` (una línea JSON por evento, _JSON Lines_) y `name => 'stdout'`. No altera el canal por defecto (`stack`/`single`), de modo que el log de archivo de la aplicación queda intacto.

**Emisor central.** `App\Support\Telemetry` se registra como singleton (`AppServiceProvider`) enlazado a `Log::channel('stdout')` y expone `event(string $event, array $context = [], string $level = 'info')`, garantizando una forma uniforme. El nombre de evento es el `message` del registro y los campos estructurados viajan en `context`.

**Convención de nombres.** `<area>.<evento>`.

**Fronteras instrumentadas y eventos:**

| Frontera                      | Implementación                                         | Eventos                                                                        | Campos de `context`                                                                                        |
| ----------------------------- | ------------------------------------------------------ | ------------------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------- |
| HTTP (controladores)          | Middleware terminable `LogHttpTelemetry` (grupo `api`) | `http.request`, `http.outcome`                                                 | `method`, `path`, `status`, `duration_ms`, `user_id`                                                       |
| Excepciones no controladas    | `report` callback en `bootstrap/app.php`               | `error.unhandled`                                                              | `exception` (clase), `message`                                                                             |
| Jobs LLM                      | `ModerationJob`, `EnrichmentJob`                       | `job.start`, `job.outcome`, `job.skipped`, `job.failed`, `job.fallback_failed` | `job`, `listing_id`, `attempt`, `outcome`, `duration_ms`, `exception`, `reason`                            |
| Listener de auditoría (async) | `RecordAuditLogListener`                               | `job.start`, `job.outcome`, `job.failed`                                       | `job` (=`audit_log`), `action`, `listing_id`, `event_id`, `attempt`, `outcome`, `duration_ms`, `exception` |
| Adaptador LLM                 | `OllamaLlmProvider`, `OllamaResponseMapper`            | `ollama.request`, `ollama.outcome`                                             | `operation`, `endpoint`, `model`, `outcome`, `status`, `reason`                                            |

`http.outcome` se emite en `terminate()` para capturar el status final incluso de respuestas de error renderizadas en `bootstrap/app.php`. Los `job.outcome` distinguen `success`/`error`; `job.failed` (nivel `warning`, incluye la clase de la `exception`) se emite al agotar reintentos o al fallar de forma definitiva (DLQ) sin alterar el fallback de negocio existente. `job.skipped` registra el no-op cuando el listing ya no existe entre el dispatch y la ejecución; `job.fallback_failed` (nivel `error`) señala que la persistencia del fallback también falló. `error.unhandled` (nivel `error`) lleva al pipeline `stdout` cualquier `Throwable` reportable (500 HTTP y fallos de jobs), de forma aditiva al log de archivo por defecto.

> [!WARNING]
> **Privacidad.** Solo se registran identificadores y metadatos. Nunca contenido de usuario (`title`, `description`) ni datos sensibles (`email`, `password`, token), ni la query string (el middleware registra `path` sin querystring para no filtrar el parámetro `q`).
