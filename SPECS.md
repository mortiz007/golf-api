# Especificación Funcional y Técnica — Golf Listing API

> Registro las decisiones finales: P20 → A (sin Idempotency-Key; idempotencia solo en consumidores de eventos vía `event_id`) y D1 → A (colas database, DLQ nativa, eventos in-process despachados a queue).

- **Stack:** PHP 8.2 · Laravel 12 · MySQL · Arquitectura Hexagonal + EDA
- **Presupuesto:** 20 h / 1 persona con asistencia de agente IA
- **Versión:** 1.0 (congelada)

## 1. Alcance

API REST para una aplicación móvil de venta de artículos de golf. Permite a usuarios autenticados publicar, actualizar y cancelar listings; expone un listado público; procesa moderación y enriquecimiento vía LLM de forma asíncrona; y registra auditoría en un bounded context independiente que se alimenta exclusivamente de eventos de dominio.

Bounded contexts: Listings (núcleo) · AuditLog (consumidor de eventos, sin acceso a tablas de Listings).

## 2. Decisiones congeladas (resumen normativo)

| #   | Decisión                                                                                                                 |
| --- | ------------------------------------------------------------------------------------------------------------------------ |
| 1   | `moderation_status`: pending / approved / rejected (sin flagged).                                                        |
| 2   | `title`: `^[A-Za-z ]+$`, 3–255, trim, requerido.                                                                         |
| 3   | `end_date`: opcional; si presente ISO 8601 y >= hoy.                                                                     |
| 4   | `show_all=true`: todos, orden price DESC.                                                                                |
| 5   | `show_all=false`: approved + `cancelled_at IS NULL` + `end_date >= hoy`, orden created_at ASC.                           |
| 6   | Sin parámetro sort.                                                                                                      |
| 7   | `ai_enrichment` siempre presente en listado (puede ser null).                                                            |
| 8   | Auth: Laravel Sanctum, `Authorization: Bearer`.                                                                          |
| 9   | Sin register/login; tokens en seed.                                                                                      |
| 10  | Sin `GET /categories`; 7 categorías por seed.                                                                            |
| 11  | Errores de validación: HTTP 422.                                                                                         |
| 12  | `estimated_market_value`: LLM sin fuente externa.                                                                        |
| 13  | Actualización: PATCH parcial.                                                                                            |
| 14  | Fallback moderación: LLM falla → pending (no visible).                                                                   |
| 15  | DELETE sobre cancelado: 204 (idempotente).                                                                               |
| 16  | Re-listar cancelado: no permitido.                                                                                       |
| 17  | Snapshot de eventos: mínimo (sin ai_enrichment/moderation_result).                                                       |
| 18  | AuditLog audita solo Created/Updated/Deleted.                                                                            |
| 19  | Moneda: USD implícito (sin columna).                                                                                     |
| 20  | Sin Idempotency-Key en POST (idempotencia solo en consumidores vía `event_id`).                                          |
| 21  | `description`: requerido, 10–1000, sanitizar.                                                                            |
| 22  | Índices: single-column en price, category_id, condition, created_at, end_date + compuesto (moderation_status, end_date). |
| 23  | Rate limiting: 60 req/min por usuario → 429.                                                                             |
| 24  | Alcance: todo Must.                                                                                                      |
| D1  | Colas: driver database; DLQ vía failed_jobs; eventos in-process → listeners en queue.                                    |

## 3. Modelo de datos (MySQL)

### users

| Columna                 | Tipo            | Notas                   |
| ----------------------- | --------------- | ----------------------- |
| id                      | BIGINT UNSIGNED | PK AI                   |
| first_name              | VARCHAR(100)    | requerido               |
| last_name               | VARCHAR(100)    | requerido               |
| email                   | VARCHAR(255)    | UNIQUE requerido        |
| password_hash           | VARCHAR(255)    | bcrypt; nunca se expone |
| created_at / updated_at | TIMESTAMP       |                         |

### categories

| Columna    | Tipo            | Notas                       |
| ---------- | --------------- | --------------------------- |
| id         | BIGINT UNSIGNED | PK AI                       |
| name       | VARCHAR(50)     | UNIQUE uno de los 7 valores |
| created_at | TIMESTAMP       |                             |

Valores seed: Drivers, Woods, Hybrids, Driving Irons, Irons, Wedges, Putters.

### listings

| Columna                 | Tipo                                        | Notas                                     |
| ----------------------- | ------------------------------------------- | ----------------------------------------- |
| id                      | BIGINT UNSIGNED                             | PK AI                                     |
| user_id                 | BIGINT UNSIGNED                             | FK→users.id                               |
| category_id             | BIGINT UNSIGNED                             | FK→categories.id                          |
| title                   | VARCHAR(255)                                | regex letras+espacios                     |
| price                   | DECIMAL(10,2)                               | >= 0.01, USD implícito                    |
| condition               | ENUM('New','Used','Refurbished','Like New') | requerido                                 |
| description             | TEXT                                        | 10–1000, sanitizado                       |
| end_date                | DATE NULLABLE                               | >= hoy si presente                        |
| moderation_status       | ENUM('pending','approved','rejected')       | default pending                           |
| moderation_result       | JSON NULLABLE                               | labels, scores, explanation               |
| ai_enrichment           | JSON NULLABLE                               | model_evaluation + estimated_market_value |
| ai_enrichment_status    | ENUM('pending','succeeded','failed')        | default pending                           |
| cancelled_at            | TIMESTAMP NULLABLE                          | soft-delete                               |
| created_at / updated_at | TIMESTAMP                                   |                                           |

Índices: (price), (category_id), (condition), (created_at), (end_date), (moderation_status, end_date).

### listing_audit_logs

(esquema/contexto AuditLog — sin FK a listings/users)

| Columna    | Tipo            | Notas                      |
| ---------- | --------------- | -------------------------- |
| id         | BIGINT UNSIGNED | PK AI                      |
| user_id    | BIGINT UNSIGNED | desde payload, sin FK      |
| listing_id | BIGINT UNSIGNED | desde payload, sin FK      |
| action     | VARCHAR(50)     | created/updated/deleted    |
| message    | VARCHAR(500)    | legible                    |
| metadata   | JSON            | snapshot del payload       |
| event_id   | CHAR(36)        | UNIQUE UUID, deduplicación |
| created_at | TIMESTAMP       |                            |

`failed_jobs`: tabla nativa Laravel (DLQ).

## 4. Endpoints REST

Base: `/api`. Errores uniformes:

```json
{
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "Validation failed",
    "details": { "field": ["msg"] }
  }
}
```

Throttle global autenticado: `throttle:60,1` → 429.

### 4.1 POST /api/listings — Crear (protegido)

Request:

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

Validaciones: title requerido `^[A-Za-z ]+$` 3–255 trim; price requerido numérico >=0.01; condition requerido (1 de 4); description requerido 10–1000 sanitizado; end_date opcional ISO >=hoy; category_id requerido y existente.

Comportamiento: persistir `moderation_status=pending`, `ai_enrichment_status=pending` → publicar ListingCreated → encolar moderation (prioritaria) + enrichment (no bloqueante).

Respuestas: 201 (recurso + header `Location: /api/listings/{id}`), 422, 401, 429.

### 4.2 PATCH /api/listings/{id} — Actualizar parcial (protegido, solo propietario)

Solo se modifican campos enviados; mismas reglas de validación por campo presente. Reglas: si cambian title o description → `moderation_status=pending` + re-encolar moderación; si cambian price o condition → `ai_enrichment_status=pending` + re-encolar enrichment. Publicar ListingUpdated. Respuestas: 200, 403, 404 (inexistente o cancelado), 422, 401.

### 4.3 DELETE /api/listings/{id} — Cancelar (protegido, solo propietario)

Soft-delete (`cancelled_at = now`). Sobre ya cancelado → 204 (idempotente). Publicar ListingDeleted. Respuestas: 204, 403, 404 (inexistente), 401. Re-listado no permitido.

### 4.4 GET /api/listings — Listado público

Query: min_price, max_price, category_id, condition, q (title+description), show_all (default false), page, per_page (default 20, max 100). Visibilidad:

- `show_all=false`: approved + `cancelled_at IS NULL` + `end_date >= hoy` (o null); orden created_at ASC.
- `show_all=true`: todos; orden price DESC.

Item:

```json
{
  "id": 123,
  "title": "...",
  "price": 123.45,
  "condition": "Used",
  "description": "...",
  "created_at": "ISO8601",
  "user": { "first_name": "...", "last_name": "..." },
  "category": { "id": 1, "name": "Drivers" },
  "ai_enrichment": null
}
```

Respuestas: 200 (paginado), 422.

### 4.5 GET /api/audit-logs — Auditoría (protegido)

Logs del usuario autenticado, orden created_at DESC, paginado. Campos: action, message, metadata, created_at. Respuestas: 200, 401.

## 5. Eventos de dominio

Payload común: `event_id` (UUID), `event_version` (int), `occurred_at` (ISO), `user_id`, `listing_id`, `listing_snapshot`. `listing_snapshot`: id, title, price, condition, description, category_id, moderation_status, created_at, end_date (sin ai_enrichment/moderation_result).

Eventos: ListingCreated, ListingUpdated, ListingDeleted.

Despacho: in-process (Laravel events) → listener encolado (ShouldQueue, driver database). Consumidor AuditLog idempotente por `event_id` (insert con UNIQUE; duplicado se ignora). Reintentos con backoff exponencial; fallo persistente → failed_jobs (DLQ). Mensaje ejemplo: `"Created listing 'Driver X' (id: 123) by user 45"`.

## 6. Integración LLM (adapter desacoplado)

Interfaz única `LlmPort` (métodos `moderate()` y `enrich()`) en dominio; adaptadores en infraestructura.

- **Moderación** → retorna `moderation_result` y resuelve `moderation_status` (`approved` | `rejected` | `pending`).
- **Enriquecimiento** → retorna `ai_enrichment` (`model_evaluation` + `estimated_market_value`) y resuelve `ai_enrichment_status`.
- **Mock intercambiable** (`LlmProviderMock`) implementa el mismo puerto para local/testing, conmutable vía ServiceProvider sin tocar el dominio.

> Bloques JSON de `moderation_result` y `ai_enrichment` sin cambios respecto a la versión previa.

## 7. Estructura hexagonal (pragmática)

Capa de entrada Laravel transversal (fuera de los bounded contexts):

```text
app/Http/ (Controllers, FormRequests, Resources, Middlewares)
```

Bounded context **Listings**:

```text
app/Listings/
├── Domain/         (Entities, ValueObjects, Events, Exceptions, Contracts/Ports —incl. LlmPort—)
├── Application/    (UseCases, Commands, Queries, DTOs)
└── Infrastructure/ (Eloquent repos, LLM adapters, Jobs, Mappers)
```

Bounded context **AuditLog**:

```text
app/AuditLog/
├── Domain/         (AuditLogEntry, Contracts)
├── Application/    (RecordAuditLog use case)
└── Infrastructure/ (Listener/Consumer, Eloquent repo)
```

> El controlador de `GET /api/audit-logs` reside en `app/Http/Controllers` (capa transversal), **no** en `Infrastructure`.
