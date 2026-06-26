# ADR — Golf Listing API

> Architecture Decision Record. Registra las decisiones de arquitectura e implementación tomadas durante el desarrollo. Complementa a `SPECS.md` (comportamiento observable) y `DESIGN.md` (implementación técnica). En caso de conflicto prevalece `SPECS.md`.

- **Stack:** PHP 8.2 · Laravel 12 · MySQL · Hexagonal + EDA
- **Base de datos:** `golf_api`
- **Pruebas:** Pest · **Linter:** Laravel Pint
- **Presupuesto:** 20 h / 1 persona con asistencia de agente IA
- **Estado del documento:** vivo (se actualiza por sesión)

Este documento tiene dos partes:

1. **Decisiones de arquitectura (ADR-NNN):** decisiones transversales de infraestructura, esquema y estructura.
2. **Registro de ejecución incremental (S1-NN):** bitácora del slice `POST /api/listings`, un paso por turno con gating estricto.

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
| ADR-008 | Base de datos de pruebas MySQL (`golf_api_testing`) por ausencia de `pdo_sqlite` | Aceptada |
| ADR-009 | Cableado de los tres eventos de `Listings` con clases espejo (decisión 2-B) | Aceptada |
| ADR-010 | Lado lectura de `AuditLog`: el repositorio devuelve entidades de dominio | Aceptada |

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
- **Divergencia (RESUELTA en S1-16):** `SPECS §4.4` expone `user: { first_name, last_name }`. Se resolvió en la capa **Resource** con la **opción B** (contrato de respuesta `user: { name }`). Ver S1-16 y OPEN-1.

**Estado de la divergencia:** cerrada (opción 2-B).

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

## ADR-008 — Base de datos de pruebas MySQL (`golf_api_testing`)

**Contexto.** `phpunit.xml` venía configurado con `sqlite :memory:` (default de Laravel). El host de desarrollo solo tiene la extensión `pdo_mysql` habilitada (no `pdo_sqlite`), por lo que la suite no podía arrancar (`could not find driver`).

**Decisión.** Ejecutar la suite contra **MySQL** usando una base de datos dedicada `golf_api_testing`. En `phpunit.xml` se fija `DB_CONNECTION=mysql` y `DB_DATABASE=golf_api_testing`; host, puerto, usuario y contraseña se heredan del `.env`.

**Consecuencias.**
- Aislamiento: las pruebas (`RefreshDatabase`) no tocan `golf_api` de desarrollo.
- Requisito operativo: la base `golf_api_testing` debe existir antes de correr los tests.
- Alternativa descartada: habilitar `pdo_sqlite` en el entorno PHP (cambio de infraestructura fuera del repo).

---

## ADR-009 — Cableado de los tres eventos de `Listings` con clases espejo

**Contexto.** El contexto `AuditLog` audita `ListingCreated`/`ListingUpdated`/`ListingDeleted` (decisión #18, 2-B), pero solo existía `ListingCreated`; los emisores PATCH/DELETE aún no se construyen.

**Decisión.** Crear clases **espejo** `ListingUpdated` y `ListingDeleted` en `App\Listings\Domain\Events` con el mismo payload normativo y `toArray()` que `ListingCreated`, para suscribir y type-hintear los tres eventos desde ya.

**Consecuencias.**
- El `RecordAuditLogListener` usa un **handler genérico** con type-hint por unión `ListingCreated|ListingUpdated|ListingDeleted` y mapea cada evento a su `AuditAction` con `match`.
- El listener **solo** importa eventos de `Listings` (nunca repos/modelos/servicios), preservando el aislamiento del Bounded Context.
- **Deuda registrada:** `Updated`/`Deleted` quedan cableados pero sin emisor real; su prueba end-to-end es parcial (eventos sintéticos) hasta implementar PATCH/DELETE.

---

## ADR-010 — Lado lectura de `AuditLog`: el repositorio devuelve entidades de dominio

**Contexto.** `GET /api/audit-logs` (`SPECS §4.5`) requiere paginación y orden `created_at DESC`. La regla de dependencia prohíbe `Application → Eloquent`, por lo que el `QueryAuditLogsUseCase` no puede devolver modelos Eloquent.

**Decisión.** `AuditLogRepositoryPort::findByUser(int $userId, int $page)` devuelve un `LengthAwarePaginator` (contrato genérico de Illuminate, no Eloquent) cuyos ítems son **entidades de dominio** `AuditLogEntry` rehidratadas vía `AuditLogEntry::fromState()` y `AuditLogMapper::toDomain()`.

**Detalles.**
- Paginación fija a **20** por página (`EloquentAuditLogRepository::PER_PAGE`).
- Orden `created_at DESC`, con desempate `id DESC` para estabilidad ante timestamps idénticos.
- La entidad incorpora `id`/`createdAt` opcionales (nulos en escritura, presentes en lectura); la firma congelada de `record()` (S2-02) **no** se altera.
- `listing_id` (columna NOT NULL) se deriva del snapshot del evento (`metadata['id']`), respetando la firma de `record()` que no incluye `listing_id`.
- Idempotencia de escritura vía `firstOrCreate(['event_id' => ...])`: duplicado = no-op (sin error, sin fila extra).

**Consecuencias.** `Application` y `Http` operan sobre dominio (no Eloquent); la `AuditLogResource` se construye desde `AuditLogEntry`.

---

# Registro de ejecución incremental — Slice `POST /api/listings`

> Bitácora derivada de la ejecución incremental del Plan de Ejecución (`PLAN.md`), S1-01 → S1-18, con gating estricto (un paso por turno). Fuente de verdad: `SPECS.md` > `DESIGN.md` > este ADR. Consolida decisiones de diseño y verificación de criterios de aceptación por paso. **No incluye código fuente.**

## Índice de pasos

| ID | Paso | Capa |
| --- | --- | --- |
| S1-01 | Domain / Value Objects | Domain |
| S1-02 | Domain / Entity `Listing` | Domain |
| S1-03 | Domain / Event `ListingCreated` | Domain |
| S1-04 | Domain / Exceptions | Domain |
| S1-05 | Domain / Port `ListingRepositoryPort` | Domain |
| S1-06 | Domain / Tests (Pest, first) | Domain (test) |
| S1-07 | Application / DTO `CreateListingCommand` | Application |
| S1-08 | Application / `CreateListingUseCase` | Application |
| S1-09 | Infra / Eloquent `ListingModel` | Infrastructure |
| S1-10 | Infra / `ListingMapper` | Infrastructure |
| S1-11 | Infra / `EloquentListingRepository` | Infrastructure |
| S1-12 | Infra / Jobs stub + `ListingProcessingDispatcher` adapter | Infrastructure |
| S1-13 | Providers / bindings Port→Adapter + `DomainEventPublisher` adapter | Providers/Infra |
| S1-14 | Http / `StoreListingRequest` (FormRequest) | Http |
| S1-15 | Http / `ListingController@store` | Http |
| S1-16 | Http / `ListingResource` | Http |
| S1-17 | Http / Routing (`routes/api.php` + Sanctum/throttle) | Http |
| S1-18 | Feature Test (Pest) — flujo síncrono | Test |

---

## S1-01 — Domain / Value Objects

### Decisiones de diseño
- Implementación de VOs: `Title`, `Price`, `ListingCondition`, `Description`, `EndDate`.
- Cada VO **valida en el constructor**, lanza **excepciones de dominio** y es **inmutable**.
- `Price` almacena el monto en **centavos (cents)** para precisión.
- `EndDate` realiza sus comparaciones en **UTC**.
- Se introdujo un **stub mínimo habilitante** de `ListingDomainException` e `InvalidListingDataException` (Opción A) para satisfacer el requisito de lanzar excepciones, difiriendo la jerarquía completa a S1-04.

### Cumplimiento de criterios y notas técnicas
- ✅ VOs autovalidados, inmutables y desacoplados de Laravel/Eloquent.
- ✅ `Price` en centavos evita errores de coma flotante.
- ✅ `EndDate` normalizado a UTC.
- Nota: el stub de excepciones es deuda controlada, a formalizar en S1-04.
- Nivel de confianza declarado: alto.

---

## S1-02 — Domain / Entity `Listing`

### Decisiones de diseño
- **Constructor privado**; creación vía factories.
- `create()`: factory que fija `moderation_status` y `ai_enrichment_status` en `pending`, e `id = null`.
- `fromState()`: factory de **rehidratación** desde persistencia.
- `withId()`: asigna el ID tras la persistencia **manteniendo la inmutabilidad** (retorna nueva instancia).
- `createdAt` fijado en **UTC**.
- Enums de dominio `ModerationStatus` y `AiEnrichmentStatus` introducidos como value objects.

### Cumplimiento de criterios y notas técnicas
- ✅ La entidad solo acepta value objects e identificadores primitivos.
- ✅ Sin dependencias de Laravel/Eloquent.
- ✅ Inmutabilidad preservada con `withId()`.
- Nivel de confianza declarado: alto.

---

## S1-03 — Domain / Event `ListingCreated`

### Decisiones de diseño
- Payload alineado a la estructura normativa de `DESIGN §IV.2` y `SPECS #17` (**snapshot mínimo**, excluyendo resultados de AI/moderación).
- Creación de un VO `Uuid` para generar **UUID v4** y evitar acoplar el dominio a `Str::uuid()` de Laravel.
- Constructor requiere una entidad `Listing` **ya persistida** (con ID).
- `eventId` y `occurredAt` **inyectables** opcionalmente (testabilidad).
- `price` serializado como **decimal**; fechas en **ISO 8601 / UTC**.
- `EVENT_VERSION = 1`.

### Cumplimiento de criterios y notas técnicas
- ✅ Estructura de payload normativa con snapshot mínimo (9 campos), sin `ai_enrichment`/`moderation_result`.
- ✅ Inyección de `event_id` y generación vía `Uuid::v4()` en el dominio.
- ✅ `toArray()` para serialización.
- Nivel de confianza declarado: alto.

---

## S1-04 — Domain / Exceptions

### Decisiones de diseño
- Formalización de la jerarquía, reemplazando los stubs de S1-01.
- `ListingDomainException`: **base abstracta**.
- `InvalidListingDataException`: soporta **múltiples errores por campo** (`array<string, string[]>`); `forField()` (retrocompatibilidad), `withErrors()` (acumulación) y `errors()` (mapeo a 422).
- `ListingRuleViolationException`: violaciones de regla de negocio.
- Decisión clave: **una sola excepción** para datos inválidos que **acumula errores**, en lugar de una por VO.

### Cumplimiento de criterios y notas técnicas
- ✅ Jerarquía coherente y framework-agnóstica.
- ✅ `errors()` habilita el mapeo directo al error envelope 422.
- Nivel de confianza declarado: alto.

---

## S1-05 — Domain / Port `ListingRepositoryPort`

### Decisiones de diseño
- Interfaz en `app/Listings/Domain/Contracts`.
- Método único: `save(Listing $listing): Listing`.
- Puerto **mínimo**, estrictamente acotado a las necesidades del endpoint `POST /api/listings` (YAGNI).

### Cumplimiento de criterios y notas técnicas
- ✅ Sin dependencias de framework.
- ✅ Superficie mínima alineada al slice.
- Nivel de confianza declarado: alto.

---

## S1-06 — Domain / Tests (Pest, first)

### Decisiones de diseño
- Tests unitarios **puros** con Pest en `tests/Unit/Listings/Domain/`.
- Ejecución **sin base de datos ni contenedor de Laravel**.

### Cumplimiento de criterios y notas técnicas
- ✅ `ValueObjectsTest.php`: casos válidos/ inválidos de `Title`, `Price`, `ListingCondition`, `Description`, `EndDate`, incluyendo aserciones del error bag de `InvalidListingDataException`.
- ✅ `ListingEntityTest.php`: factory `create()` (estados iniciales `pending`, `id=null`), inmutabilidad de `withId()` y exposición de VOs.
- ✅ `ListingCreatedTest.php`: estructura normativa del payload, snapshot mínimo, inyección de `event_id` y generación `Uuid::v4()`.
- Nivel de confianza declarado: alto.

---

## S1-07 — Application / DTO `CreateListingCommand`

### Decisiones de diseño
- DTO de entrada **inmutable** en `app/Listings/Application/Commands`.
- Transporta datos primitivos **ya validados** (`string`, `float`, `int`, `?string`) provenientes del futuro FormRequest (S1-14) más `actorUserId`.
- Factory `fromArray()` por conveniencia.
- Decisión clave: el Command mantiene **primitivos**; los Value Objects se construyen dentro del Use Case (S1-08) → separación de responsabilidades y Command HTTP-agnóstico.

### Cumplimiento de criterios y notas técnicas
- ✅ Inmutable y desacoplado de HTTP.
- ✅ `fromArray()` para construcción conveniente.
- Nivel de confianza declarado: alto.

---

## S1-08 — Application / `CreateListingUseCase`

### Decisiones de diseño
- Use Case en `app/Listings/Application/UseCases`.
- Decisión clave: introducción de **dos puertos de salida** en `app/Listings/Application/Contracts` para preservar la arquitectura hexagonal y la testabilidad:
  - `DomainEventPublisher` → `publishAfterCommit(object $event)`.
  - `ListingProcessingDispatcher` → `dispatchModeration(int $listingId)` y `dispatchEnrichment(int $listingId)`.
- Orquestación: crear entidad `Listing` (revalidando VOs) → persistir vía `ListingRepositoryPort` → publicar `ListingCreated` tras commit vía `DomainEventPublisher` → despachar jobs de moderación y enriquecimiento vía `ListingProcessingDispatcher`.
- Evita dependencias directas de SQL, HTTP, Eloquent o clases Job.

### Cumplimiento de criterios y notas técnicas
- ✅ Orquestación correcta del flujo de creación.
- ✅ Application depende solo de puertos (no de adaptadores concretos).
- Nota: los adaptadores Laravel de los nuevos puertos quedan **pendientes** para S1-12/S1-13.
- Nivel de confianza declarado: alto.

---

## S1-09 — Infra / Eloquent `ListingModel`

### Decisiones de diseño
- `ListingModel` en `app/Listings/Infrastructure/Eloquent`.
- Es un **adaptador de persistencia puro**, **no** una entidad de dominio; la conversión la realiza `ListingMapper` (S1-10).
- `$table = 'listings'`; `$fillable` completo (incluye `moderation_status` y `ai_enrichment_status` iniciales).
- `casts()`: `price` (`decimal:2`), `end_date` (`date:Y-m-d`), `moderation_result` (`array`), `ai_enrichment` (`array`), `cancelled_at` (`datetime`).
- Statuses almacenados como **strings**; los enums de dominio los maneja el mapper.

### Cumplimiento de criterios y notas técnicas
- ✅ Modelo limpio como adaptador de persistencia.
- ✅ Casts coherentes con el esquema MySQL (`SPECS §3`).
- ✅ Separación estricta modelo Eloquent / entidad de dominio.
- Nivel de confianza declarado: alto.

---

## S1-10 — Infra / `ListingMapper`

### Decisiones de diseño
- `ListingMapper` en `app/Listings/Infrastructure/Mappers`.
- **Traductor bidireccional** entre entidad `Listing` y `ListingModel`, usado **exclusivamente** por el repositorio (S1-11).
- `toAttributes(Listing): array` → atributos persistibles (excluye `moderation_result`/`ai_enrichment`, que son `null`/default en creación).
- `toDomain(ListingModel): Listing` → reconstrucción vía `Listing::fromState()`.
- Traduce entre enums de dominio y strings del modelo, y entre `Price` (cents) y la columna decimal; normaliza fechas.

### Cumplimiento de criterios y notas técnicas
- ✅ Conversión bidireccional aislada en infraestructura.
- ✅ Manejo correcto de enums ↔ string y `Price` (cents) ↔ decimal.
- ✅ Normalización de fechas.
- Nivel de confianza declarado: alto.

---

## S1-11 — Infra / `EloquentListingRepository`

### Decisiones de diseño
- Implementa `ListingRepositoryPort` en `app/Listings/Infrastructure/Repositories`.
- `save()`: `ListingModel::create(mapper->toAttributes(...))` → `listing->withId(model->id)`.
- **Opción A**: preserva los VOs originales y solo asigna el ID (no rehidrata desde el modelo).
- **Sin** `DB::transaction` a nivel de repositorio (insert único).
- Inyecta `ListingMapper` por constructor.

### Cumplimiento de criterios y notas técnicas
- ✅ Persistencia vía mapper, retornando la entidad con ID asignado.
- ✅ Preservación de VOs (Opción A) evita reconversión innecesaria.
- Nota: el manejo transaccional se eleva a la capa de orquestación (resuelto en S1-15).
- Nivel de confianza declarado: alto.

---

## S1-12 — Infra / Jobs stub + adaptador `ListingProcessingDispatcher`

### Decisiones de diseño
- Alcance acotado: para `POST /api/listings` solo se requiere **encolar** los jobs; la lógica LLM real (`LlmPort`, `moderate()`/`enrich()`, escritura de resultados) queda **fuera** de este slice.
- `ModerationJob` / `EnrichmentJob`: **stubs** `ShouldQueue` con política de reintentos normativa (`$tries = 3`, `backoff() = [5, 15, 30]`), constructor `(int $listingId)` y `handle()` con `TODO` acotado.
- Jobs **independientes y paralelos** (enrichment no depende de moderation).
- `LaravelListingProcessingDispatcher` en `app/Listings/Infrastructure/Dispatchers`: implementa el puerto despachando ambos jobs; único lugar que referencia las clases Job concretas.

### Cumplimiento de criterios y notas técnicas
- ✅ Jobs stub `ShouldQueue` con firma `(int $listingId)`, sin lógica LLM inventada.
- ✅ Política de reintentos normativa (`DESIGN §V.2`): 3 intentos, backoff exponencial 5/15/30 s.
- ✅ Independientes y paralelos.
- ✅ Adaptador del puerto `ListingProcessingDispatcher` cierra el cabo de S1-08; Application sigue sin tocar clases Job.
- Notas: usan la conexión por defecto (`QUEUE_CONNECTION=database`); se ofreció opción de fijar colas explícitas. Pendiente para S1-13: binding del puerto y adaptador de `DomainEventPublisher`.
- Nivel de confianza declarado: alto.

---

## S1-13 — Providers / bindings Port→Adapter + adaptador `DomainEventPublisher`

### Decisiones de diseño
- `LaravelDomainEventPublisher` en `app/Listings/Infrastructure/Events`: publica eventos in-process con semántica **after-commit** (vía dispatcher de eventos de Laravel).
- `ListingsServiceProvider` dedicado al BC `Listings` (en lugar de ensuciar `AppServiceProvider`), con `$bindings`:
  - `ListingRepositoryPort` → `EloquentListingRepository`.
  - `ListingProcessingDispatcher` → `LaravelListingProcessingDispatcher`.
  - `DomainEventPublisher` → `LaravelDomainEventPublisher`.
- Provider registrado en `bootstrap/providers.php` (Laravel 12).
- Materialización del post-commit (`DESIGN §IV` `dispatchAfterCommit`) sin acoplar Application a `DB`.

### Cumplimiento de criterios y notas técnicas
- ✅ Bindings Port→Adapter de los tres puertos del slice (resolución automática del contenedor).
- ✅ Cierra los cabos de S1-08/S1-12 (entregado el adaptador del publisher).
- ✅ Provider sin lógica de negocio (solo wiring) y registrado.
- Notas: semántica post-commit definitiva — `DB::transaction` en el controller (S1-15) + el adaptador difiere el dispatch con `Connection::afterCommit()` (corregido; ver OPEN-3). `EloquentListingRepository` se autoresuelve por el contenedor (sin binding explícito).
- Nivel de confianza declarado: alto.

---

## S1-14 — Http / `StoreListingRequest` (FormRequest)

### Decisiones de diseño
- FormRequest como **primera barrera de validación** (`SPECS §4.1`), con reglas exactas según decisiones congeladas (#2, #21, #3, #19).
- `prepareForValidation()`: `trim` del título y `strip_tags` + `trim` de la descripción (sanitización #21).
- `failedValidation()` sobrescrito para emitir el **error envelope normativo** (`error.code = VALIDATION_ERROR`, `details`, HTTP 422) en lugar del 422 por defecto.
- Reglas: `title` regex `^[A-Za-z ]+$`, 3–255; `price` requerido, numérico, `min:0.01`, `max:99999999.99` (tope `DECIMAL(10,2)`); `condition` `in:New,Used,Refurbished,Like New`; `description` 10–1000; `end_date` `nullable`, `date_format:Y-m-d`, `after_or_equal:today`; `category_id` `exists:categories,id`.

### Cumplimiento de criterios y notas técnicas
- ✅ Reglas exactas alineadas a `SPECS §4.1` y decisiones congeladas.
- ✅ Sanitización #21 coherente con el VO `Description` (S1-01).
- ✅ Error envelope normativo (`VALIDATION_ERROR`/422), alineado a `DESIGN §VI`.
- ✅ `authorize() = true` (auth Sanctum en middleware de ruta, S1-17).
- Notas: doble validación intencional (FormRequest formato HTTP + VOs en dominio, defensa en profundidad). Posible borde de medianoche entre TZ de la app (`after_or_equal:today`) y UTC (VO `EndDate`); riesgo bajo, opción de alinear TZ pendiente.
- Nivel de confianza declarado: alto.

---

## S1-15 — Http / `ListingController@store`

### Decisiones de diseño
- Controller **delgado** (`DESIGN §II`, sin reglas de negocio): FormRequest validado → arma `CreateListingCommand` → invoca `CreateListingUseCase` → responde `201` con `Location`.
- Transformación a Resource diferida a S1-16; aquí se usa un `JsonResponse` provisional minimalista (marcado para reemplazo).
- **Resolución del post-commit (pendiente de S1-13):** se envuelve la invocación del Use Case en `DB::transaction(...)`, de modo que insert + `publishAfterCommit()` quedan en una transacción y los eventos after-commit se difieren hasta el commit real, materializando `dispatchAfterCommit` de `DESIGN §IV` sin acoplar Application a `DB`.
- `actorUserId` obtenido de `$request->user()` (Sanctum, S1-17).

### Cumplimiento de criterios y notas técnicas
- ✅ Controller delgado, solo orquestación HTTP.
- ✅ Command desde `validated()` + `actorUserId` desde Sanctum.
- ✅ `201 Created` + header `Location: /api/listings/{id}` (`SPECS §4.1`).
- ✅ Post-commit materializado vía `DB::transaction` (resuelve la ambigüedad de S1-13).
- Notas / puntos a confirmar: firma de `CreateListingCommand::fromArray(array $data, int $actorUserId)` (único punto frágil; **resuelto**: la firma real es `(int $actorUserId, array $data)` invocada con argumentos nombrados); body provisional `{ "id": N }` a sustituir por `ListingResource` en S1-16; `extends Controller` asume el base de Laravel 12.
- Nivel de confianza declarado: alto.

---

## S1-16 — Http / `ListingResource`

### Decisiones de diseño
- `ListingResource` (`app/Http/Resources`) se construye **desde la entidad de dominio `Listing`** que retorna el Use Case (no desde Eloquent); lee los VOs vía `$this->resource`.
- **Formato plano**: `public static $wrap = null` (sin envoltura `data`), simétrico inverso al envelope de error `{ error: ... }`.
- **Resolución de OPEN-1**: el propietario se expone como `user: { name }` (opción **2-B** de ADR-002), tomando el `name` del **usuario autenticado** (`$request->user()`), que es el creador.
- Se incluye `category_id` (escalar). **No** se emite el objeto `category: { id, name }`: no existe modelo Eloquent `Category` y la entidad solo porta IDs; el objeto completo se difiere al slice de `GET /api/listings`.
- `ai_enrichment` siempre presente como `null` en la creación (`SPECS #7`).
- No se exponen `password`, `email` ni tokens.
- `ListingController@store` actualizado: reemplaza el body provisional `{ id }` por `ListingResource::make($listing)->response()->setStatusCode(201)->header('Location', ...)`, conservando el `DB::transaction` y el `Location` de S1-15.

### Cumplimiento de criterios y notas técnicas
- ✅ Resource para `201`; expone `{ name }` (2-B); no filtra datos sensibles.
- ✅ Cierra **OPEN-1** a favor de `SPECS.md`/opción 2-B.
- Nota: el objeto `category` completo queda fuera del slice (pendiente para el endpoint de listado).
- Nivel de confianza declarado: alto.

---

## S1-17 — Http / Routing (`routes/api.php` + Sanctum/throttle)

### Decisiones de diseño
- Se crea `routes/api.php` con un grupo `middleware(['auth:sanctum', 'throttle:60,1'])` y la ruta `POST /listings` → `ListingController@store`, nombrada `listings.store`.
- Se registra `api: __DIR__.'/../routes/api.php'` en `withRouting()` de `bootstrap/app.php` (Laravel 12), que aplica el prefijo `/api` y el grupo de middleware `api`.
- **Decisión clave:** **no** ejecutar `php artisan install:api`. Sanctum ya está instalado en el proyecto; `install:api` habría tocado el modelo `User` y re-publicado migraciones. La vía manual es mínima y sin efectos colaterales.

### Cumplimiento de criterios y notas técnicas
- ✅ `route:list` confirma `POST api/listings` (`listings.store`) con middleware `api` + `auth:sanctum` + `throttle:60,1`.
- ✅ `401` sin token (Sanctum) y `429` al exceder 60 req/min por usuario (`#23`).
- Nota: las respuestas `401`/`429` usan el formato por defecto de Laravel, **no** el envelope normativo `UNAUTHENTICATED`/`RATE_LIMITED` de `DESIGN §VI`; la acceptance de S1-17 solo exige los códigos de estado. Shaping del envelope pendiente.
- Nivel de confianza declarado: alto.

---

## S1-18 — Feature Test (Pest) — flujo síncrono

### Decisiones de diseño
- `tests/Feature/Listings/CreateListingTest.php` con `it()` (estilo consistente con los unit tests), `RefreshDatabase` y `Sanctum::actingAs`.
- Cuatro casos:
  - **Happy-path:** `201` + header `Location: /api/listings/{id}`, body plano con `moderation_status=pending`, `ai_enrichment_status=pending`, `ai_enrichment=null`, `user.name`; fila en `listings` vía `assertDatabaseHas`.
  - **Encolado/despacho:** `Queue::fake()` + `Event::fake([ListingCreated::class])` → `assertPushed(ModerationJob/EnrichmentJob)` y `assertDispatched(ListingCreated)`.
  - **`422`:** payload inválido → `assertUnprocessable()` + `error.code = VALIDATION_ERROR` y estructura del envelope.
  - **`401`:** sin token → `assertUnauthorized()`.
- **Decisión de entorno (ver ADR-008):** el host carece de la extensión `pdo_sqlite` (solo `pdo_mysql`). Se cambió `phpunit.xml` de `sqlite :memory:` a **MySQL** (`DB_DATABASE=golf_api_testing`, host/credenciales heredados del `.env`) y se creó esa base de datos para **no** tocar `golf_api` de desarrollo.

### Cumplimiento de criterios y notas técnicas
- ✅ Happy-path + `422` + `401` en verde, con assertions de encolado/despacho.
- ✅ Feature test: 4 passed; **suite completa: 39 passed**. Pint `passed`.
- Notas: 5 falsos positivos de intelephense (`postJson`/`assertDatabaseHas`) por el binding de `$this` de Pest; el cambio de `phpunit.xml` responde a una limitación del entorno (sin `pdo_sqlite`).
- Nivel de confianza declarado: alto.

---

## Decisiones pendientes / abiertas

| ID | Tema | Tipo | Estado |
| --- | --- | --- | --- |
| OPEN-1 | `first_name`/`last_name` vs `name` en la respuesta (ADR-002) | Divergencia | **Resuelta** en S1-16 (opción 2-B: `user: { name }`). |
| OPEN-2 | Firma `CreateListingCommand::fromArray` | Riesgo | **Resuelta**: firma `(int $actorUserId, array $data)` invocada con argumentos nombrados. |
| OPEN-3 | Semántica after-commit en `LaravelDomainEventPublisher` | Defecto | **Resuelto**: el adaptador registra el dispatch vía `Connection::afterCommit()` (inyectando `ConnectionResolverInterface`), corriendo tras el commit real (o inmediato sin transacción). Verificado por el feature test bajo `RefreshDatabase`. |
| OPEN-4 | Provider duplicado en `bootstrap/providers.php` | Defecto | **Resuelto**: `AppServiceProvider` queda listado una sola vez. |
| OPEN-5 | Envelope `401`/`429` (`UNAUTHENTICATED`/`RATE_LIMITED`) | Mejora | **Abierto**: aún en formato por defecto; falta shaping (`DESIGN §VI`). |
| OPEN-6 | Zona horaria: borde de medianoche `after_or_equal:today` (TZ app) vs `EndDate` (UTC) | Riesgo (bajo) | **Abierto**: decidir si alinear TZ. |

**Estado del plan:** S1-01 → S1-18 **completados**.

---

# Registro de ejecución incremental — Slice `AuditLog`

> Bitácora derivada de `Plan_Ejecucion_AuditLog.md`, S2-00 → S2-17, con gating estricto (un paso por turno). Fuente de verdad: `SPECS.md` > `DESIGN.md` > este ADR. Bounded Context `AuditLog` independiente: **todo** dato persistido proviene del payload del evento; nunca consulta tablas/repos/modelos de `Listings`.

## Índice de pasos

| ID | Paso | Capa |
| --- | --- | --- |
| S2-00 | Verificación de tabla/namespace (sin código) | — |
| S2-01 | Domain / Value Objects (`AuditAction`, `AuditMessage`, `EventId`) | Domain |
| S2-02 | Domain / Entity `AuditLogEntry` | Domain |
| S2-03 | Domain / Port `AuditLogRepositoryPort` | Domain |
| S2-04 | Domain / Tests (Pest, VOs + factory + message) | Domain (test) |
| S2-05 | Application / DTO `RecordAuditLogCommand` | Application |
| S2-06 | Application / `RecordAuditLogUseCase` | Application |
| S2-07 | Infra / Eloquent `AuditLogModel` | Infrastructure |
| S2-08 | Infra / `AuditLogMapper` + `EloquentAuditLogRepository` | Infrastructure |
| S2-09 | Domain Events espejo + Infra / `RecordAuditLogListener` | Domain/Infra |
| S2-10 | Providers / binding + suscripción de eventos | Providers |
| S2-11 | Feature Test (consumo + idempotencia) | Test |
| S2-12 | Application / `QueryAuditLogsUseCase` | Application |
| S2-13 | Domain Port (ext.) `findByUser` + impl | Domain/Infra |
| S2-14 | Http / `AuditLogController@index` | Http |
| S2-15 | Http / `AuditLogResource` | Http |
| S2-16 | Http / Routing (`GET /api/audit-logs`) | Http |
| S2-17 | Feature Test (lectura + aislamiento) | Test |

---

## S2-00 — Verificación (sin código)

- Tabla `listing_audit_logs` confirmada alineada con `DESIGN §IV`/`SPECS §3`: `id`, `user_id` y `listing_id` (sin FK), `action` VARCHAR(50), `message` VARCHAR(500), `metadata` JSON, `event_id` CHAR(36) **UNIQUE**, `created_at` nullable (sin `updated_at`).
- Árbol `app/AuditLog` pre-scaffoldeado con `.gitkeep`; no existían `Domain/ValueObjects` ni `Application/Commands` (creados luego). Carpeta de listeners: `Infrastructure/Listeners`.
- Sin seeder para `listing_audit_logs` (se alimenta solo por eventos). Sin discrepancias materiales.

---

## S2-01 — Domain / Value Objects

### Decisiones de diseño
- `AuditAction` (enum `created`/`updated`/`deleted`) con `verb()` para el mensaje legible; `AuditMessage` (no vacío, máx 500, factory `forListing()`); `EventId` (valida UUID v4, normaliza a minúsculas).
- VOs inmutables, validan en constructor, framework-agnósticos. Se usa `InvalidArgumentException` nativa (no se importan excepciones de `Listings`).

### Cumplimiento
- ✅ Sin Eloquent/Laravel; formato `"Created listing 'X' (id: N) by user M"` centralizado en `AuditMessage::forListing()`.

---

## S2-02 — Domain / Entity `AuditLogEntry`

### Decisiones de diseño
- Constructor privado; factory `record(EventId, userId, AuditAction, AuditMessage, array $metadata)` con la firma **congelada**.
- Entidad pura; `metadata` = `array<string,mixed>` (snapshot del payload).
- `listing_id` no se modela como campo propio (la firma no lo incluye); se deriva del snapshot en el mapper (ver ADR-010).

### Cumplimiento
- ✅ Entidad sin acoplar a Eloquent; accessors de solo lectura.

---

## S2-03 — Domain / Port `AuditLogRepositoryPort`

### Decisiones de diseño
- `save(AuditLogEntry): void` con semántica **idempotente** documentada (duplicado por `event_id` = no-op).

### Cumplimiento
- ✅ Contrato en `Domain/Contracts`, sin dependencias de framework.

---

## S2-04 — Domain / Tests

### Cumplimiento
- ✅ Pest unit (`tests/Unit/AuditLog/Domain/AuditLogDomainTest.php`): VOs (válidos/ inválidos), `verb()`, normalización de `EventId`, formato del `message` y factory `record()`. Sin BD.

---

## S2-05 — Application / DTO `RecordAuditLogCommand`

### Decisiones de diseño
- DTO inmutable con **solo** datos del payload: `eventId`, `userId`, `action` (string), `listingId`, `listingTitle`, `snapshot`.
- `action` como primitivo (string); el use case lo convierte a `AuditAction`.

### Cumplimiento
- ✅ No referencia entidades/modelos/repos de `Listings`.

---

## S2-06 — Application / `RecordAuditLogUseCase`

### Decisiones de diseño
- `execute(RecordAuditLogCommand)`: resuelve `AuditAction::from()`, arma `AuditMessage`, construye `AuditLogEntry` y persiste vía el port. La idempotencia recae en el repositorio.

### Cumplimiento
- ✅ Sin HTTP/SQL/Eloquent; test unit con repo fake en memoria (mensaje y mapeo de acciones).

---

## S2-07 — Infra / Eloquent `AuditLogModel`

### Decisiones de diseño
- `$table = 'listing_audit_logs'`; cast `metadata`→`array`; `const UPDATED_AT = null` (solo `created_at`); adaptador de persistencia puro (sin FK ni lógica).

### Cumplimiento
- ✅ Coherente con el esquema; separación modelo Eloquent / entidad de dominio.

---

## S2-08 — Infra / `AuditLogMapper` + `EloquentAuditLogRepository`

### Decisiones de diseño
- `AuditLogMapper::toAttributes()` (escritura) deriva `listing_id` de `metadata['id']`.
- `EloquentAuditLogRepository::save()` usa `firstOrCreate(['event_id' => ...])` → insert idempotente (duplicado no lanza error ni duplica fila).

### Cumplimiento
- ✅ Único componente del BC que toca la BD; nunca consulta `Listings`.

---

## S2-09 — Domain Events espejo + Infra / `RecordAuditLogListener`

### Decisiones de diseño
- Creados `ListingUpdated`/`ListingDeleted` espejando `ListingCreated` (ver **ADR-009**).
- `RecordAuditLogListener implements ShouldQueue`: `connection='database'`, `tries=3`, `backoff()=[5,15,30]`. Handler genérico con type-hint por unión; mapea evento→`AuditAction`; construye el Command solo con datos del payload e invoca el use case.

### Cumplimiento
- ✅ Reintentos normativos (`DESIGN §V.2`); fallo persistente → `failed_jobs` (DLQ). Importa solo eventos de `Listings`.

---

## S2-10 — Providers / binding + suscripción

### Decisiones de diseño
- `AuditLogServiceProvider`: `$bindings` (`AuditLogRepositoryPort → EloquentAuditLogRepository`) + `Event::listen` de los tres eventos al listener en `boot()`. Registrado en `bootstrap/providers.php`.

### Cumplimiento
- ✅ `php artisan event:list` confirma los tres eventos suscritos a `RecordAuditLogListener (ShouldQueue)`.

---

## S2-11 — Feature Test (consumo + idempotencia)

### Decisiones de diseño
- `tests/Feature/AuditLog/RecordAuditLogTest.php` con `RefreshDatabase`; se fuerza `queue.connections.database` a driver `sync` para ejecutar el listener inline.

### Cumplimiento
- ✅ `ListingCreated` real → 1 fila (action/message/metadata correctos); re-despacho del mismo `event_id` → sigue 1 fila; `Updated`/`Deleted` sintéticos. Sin tocar tablas de `Listings`.

---

## S2-12 — Application / `QueryAuditLogsUseCase`

### Decisiones de diseño
- `execute(int $userId, int $page): LengthAwarePaginator` delega en `repository->findByUser()`. Retorna contrato genérico de paginación (no Eloquent).

### Cumplimiento
- ✅ Solo lectura del usuario autenticado; sin acceso a `Listings`.

---

## S2-13 — Domain Port (ext.) `findByUser` + impl

### Decisiones de diseño
- `findByUser(int $userId, int $page): LengthAwarePaginator<int, AuditLogEntry>` (ver **ADR-010**): `where(user_id)->orderByDesc(created_at)->orderByDesc(id)->paginate(20)->through(toDomain)`.
- Entidad ampliada con `fromState()`/`id()`/`createdAt()`; `AuditLogMapper::toDomain()` rehidrata desde el modelo.

### Cumplimiento
- ✅ Repo devuelve entidades de dominio; `Application` no depende de Eloquent. Firma de `record()` intacta.

---

## S2-14 — Http / `AuditLogController@index`

### Decisiones de diseño
- Controlador delgado en `app/Http/Controllers` (transversal, Q12=A): resuelve `page` y `Auth` id → use case → `AuditLogResource::collection()`. Sin reglas de negocio ni repos de `Listings`.

### Cumplimiento
- ✅ Orquestación HTTP mínima.

---

## S2-15 — Http / `AuditLogResource`

### Decisiones de diseño
- Construida desde `AuditLogEntry`; expone `id, action, message, metadata, created_at`. Wrapping por defecto (`data`) para conservar metadatos de paginación.

### Cumplimiento
- ✅ No filtra datos de otros usuarios (scoping en el query); forma estable.

---

## S2-16 — Http / Routing

### Decisiones de diseño
- `GET /api/audit-logs` → `AuditLogController@index` (nombre `audit-logs.index`) dentro del grupo `auth:sanctum` + `throttle:60,1`.

### Cumplimiento
- ✅ `route:list` confirma la ruta; 401 sin token (validado en S2-17).

---

## S2-17 — Feature Test (lectura + aislamiento)

### Decisiones de diseño
- `tests/Feature/AuditLog/ListAuditLogsTest.php` con `RefreshDatabase` y `Sanctum::actingAs`; fixtures sembrados por inserción directa.

### Cumplimiento
- ✅ Usuario A ve solo SUS logs (no los de B); orden `created_at DESC`; paginación 20 (página 1 y 2); contrato de campos; 401 sin token.
- ✅ **Suite completa: 66 passed (161 assertions)**; Pint passed.

---

## Decisiones pendientes / abiertas (AuditLog)

| ID | Tema | Tipo | Estado |
| --- | --- | --- | --- |
| OPEN-7 | Cobertura end-to-end de `ListingUpdated`/`ListingDeleted` | Deuda (2-B) | **Abierto**: cableados y probados con eventos sintéticos; falta emisor real (PATCH/DELETE). |
| OPEN-8 | Envelope `401`/`429` en `GET /api/audit-logs` | Mejora | **Abierto**: hereda el formato por defecto de Laravel (mismo `OPEN-5`). |

**Estado del plan:** S2-00 → S2-17 **completados**.

---

_Última actualización: cierre del slice `AuditLog` (S2-00 → S2-17); alta de ADR-009 (eventos espejo 2-B) y ADR-010 (lectura devuelve entidades de dominio)._
