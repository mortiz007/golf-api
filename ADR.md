# ADR — Golf Listing API

> Architecture Decision Record. Registra las decisiones de arquitectura e implementación tomadas durante el desarrollo. Complementa a `SPECS.md` (comportamiento observable) y `DESIGN.md` (implementación técnica). En caso de conflicto prevalece `SPECS.md`.

- **Stack:** PHP 8.2 · Laravel 12 · MySQL · Hexagonal + EDA
- **Base de datos:** `golf_api`
- **Pruebas:** Pest · **Linter:** Laravel Pint
- **Presupuesto:** 20 h / 1 persona con asistencia de agente IA
- **Estado del documento:** vivo (se actualiza por sesión)

---

## Índice de decisiones

| ADR | Título | Estado |
| --- | --- | --- |
| ADR-001 | Alineación SPECS ↔ DESIGN como fuente de verdad | Aceptada |
| ADR-002 | Esquema de tabla `users` por defecto de Laravel (`name`, `password`) | Aceptada |
| ADR-003 | Despacho de jobs LLM en cola única sin garantía de orden | Aceptada |
| ADR-004 | Estructura de directorios hexagonal por Bounded Context | Aceptada |
| ADR-005 | Estrategia de migraciones MySQL e índices | Aceptada |
| ADR-006 | Estrategia de seeders (categorías, usuarios, tokens, listings) | Aceptada |
| ADR-007 | Tablas de infraestructura nativas (Sanctum, queue, failed_jobs) | Aceptada |

---

## ADR-001 — Alineación SPECS ↔ DESIGN como fuente de verdad

**Contexto.** El proyecto se rige por dos documentos normativos: `SPECS.md` (comportamiento observable) y `DESIGN.md` (implementación técnica).

**Decisión.** Tras cotejo detallado, ambos documentos se consideran sustancialmente alineados. `SPECS.md` prevalece ante cualquier conflicto. Las divergencias menores detectadas se resolvieron explícitamente (ver ADR-002 y ADR-003).

**Consecuencias.** Toda implementación se valida contra SPECS; DESIGN aporta el detalle técnico concreto (p. ej. reintentos 3× con backoff 5s/15s/30s).

**Confianza:** alta (9/10).

---

## ADR-002 — Esquema de tabla `users` por defecto de Laravel

**Contexto.** `SPECS.md §3` define `users` con `first_name`, `last_name` y `password_hash`. El esquema por defecto de Laravel usa `name` y `password`, lo que minimiza la fricción con Sanctum/Auth y evita overrides como `getAuthPassword()`.

**Decisión.** Usar el **esquema por defecto de Laravel** (`name`, `password`).

**Consecuencias.**
- Se evita override de Auth; el `User` model por defecto funciona sin cambios.
- **Divergencia pendiente:** `SPECS §4.4` expone `user: { first_name, last_name }` en el listado. Se resolverá en la capa **Resource** (pendiente) con una de estas opciones:
  - **A)** Split de `name` por espacio en `first_name`/`last_name`.
  - **B)** Cambiar el contrato de respuesta a `{ name }`.
  - **C)** Añadir columnas `first_name`/`last_name` adicionales.

**Estado de la divergencia:** abierta, no bloqueante. A decidir en etapa de Resources.

---

## ADR-003 — Despacho de jobs LLM en cola única sin garantía de orden

**Contexto.** `SPECS §4.1` describe la moderación como "prioritaria" y el enrichment como "no bloqueante"; `DESIGN §V` los describe como dos jobs independientes y paralelos sin mención de prioridad.

**Decisión.** **Dos jobs en la misma cola `database` sin garantía de orden** (interpretación literal de DESIGN). `EnrichmentJob` no depende del resultado de `ModerationJob`.

**Consecuencias.**
- Simplicidad operativa; un único worker procesa ambos jobs.
- Reintentos: 3× con backoff exponencial (5s, 15s, 30s).
- Fallo definitivo → `failed_jobs` (DLQ). Moderación fallida → `moderation_status=pending` (no visible); enrichment fallido → `ai_enrichment_status=failed`.

---

## ADR-004 — Estructura de directorios hexagonal por Bounded Context

**Contexto.** Arquitectura Hexagonal + DDD táctico ligero + EDA (`DESIGN §II`, decisión Q6=B).

**Decisión.** Materializar bajo `app/` dos Bounded Contexts (`Listings`, `AuditLog`), cada uno con capas `Domain` / `Application` / `Infrastructure`, más la capa transversal Laravel `app/Http`.

```text
app/
  Listings/{Domain,Application,Infrastructure}/...
  AuditLog/{Domain,Application,Infrastructure}/...
  Http/{Controllers,Requests,Resources,Middleware}
```

**Consecuencias.**
- `AuditLogController` reside en `app/Http/Controllers` (transversal, Q12=A), **no** en Infrastructure.
- Namespaces `App\Listings\...` / `App\AuditLog\...` cubiertos por el PSR-4 por defecto (`"App\\": "app/"`); no se modifica `composer.json`.
- Restricciones de dependencia: `Http → Application → Domain`; `Infrastructure → Domain`. Prohibido `Domain → Laravel/Eloquent`.

---

## ADR-005 — Estrategia de migraciones MySQL e índices

**Contexto.** Modelo de datos definido en `SPECS §3` y `DESIGN §II-bis`.

**Decisión.** Crear migraciones para `users` (esquema por defecto), `categories`, `listings` y `listing_audit_logs`.

**Detalles.**
- `listings`: ENUM `condition` (`New`/`Used`/`Refurbished`/`Like New`), `moderation_status`, `ai_enrichment_status`; columnas JSON `moderation_result`/`ai_enrichment`; `cancelled_at` para soft-delete.
- Índices: single-column en `price`, `category_id`, `condition`, `created_at`, `end_date` + compuesto `(moderation_status, end_date)` para el listado público.
- `listing_audit_logs`: **sin FK** hacia `listings`/`users` (aislamiento de Bounded Context); `event_id` CHAR(36) **UNIQUE** para idempotencia.
- FKs: `listings.user_id → users.id` (cascade), `listings.category_id → categories.id` (restrict).

**Nota.** El índice explícito en `category_id` es redundante con el de la FK en MySQL; se mantiene por trazabilidad con SPECS.

---

## ADR-006 — Estrategia de seeders

**Contexto.** Sin endpoints register/login; tokens en seed (decisiones #9, #10).

**Decisión.** Seeders en este orden de dependencia: `CategorySeeder → UserSeeder → ListingSeeder → TokenSeeder`.

**Detalles.**
- `CategorySeeder`: 7 categorías fijas (Drivers, Woods, Hybrids, Driving Irons, Irons, Wedges, Putters) vía `updateOrInsert` (idempotente).
- `UserSeeder`: 2 usuarios (`name`, `email`, `password` bcrypt).
- `TokenSeeder`: tokens Sanctum pre-sembrados; el plain-text se imprime una vez para pruebas locales.
- `ListingSeeder`: **5 registros** que cubren los escenarios de visibilidad de `SPECS #5`:

| # | moderation | enrichment | end_date | cancelled | ¿visible (`show_all=false`)? |
| --- | --- | --- | --- | --- | --- |
| 1 | approved | succeeded | futuro | no | ✅ |
| 2 | approved | succeeded | null | no | ✅ |
| 3 | pending | pending | futuro | no | ❌ |
| 4 | rejected | failed | futuro | no | ❌ |
| 5 | approved | succeeded | futuro | sí | ❌ |

---

## ADR-007 — Tablas de infraestructura nativas

**Contexto.** Transporte asíncrono con colas `database` y DLQ (decisión D1).

**Decisión.** Usar las tablas nativas de Laravel sin redefinirlas:
- `personal_access_tokens` (Sanctum, vía `vendor:publish`).
- `jobs` (`php artisan queue:table`).
- `failed_jobs` (`php artisan queue:failed-table`) como DLQ.

**Configuración `.env`:** `DB_CONNECTION=mysql`, `DB_DATABASE=golf_api`, `QUEUE_CONNECTION=database`.

---

## Decisiones pendientes / abiertas

| ID | Tema | Bloqueante | Etapa de resolución |
| --- | --- | --- | --- |
| OPEN-1 | `first_name`/`last_name` vs `name` en respuesta del listado (ADR-002) | No | Capa Resources |

---

_Última actualización: sesión de migraciones, seeders y estructura de directorios._