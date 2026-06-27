# ADR Único Consolidado — Compromisos Arquitectónicos v1.0

**Estado:** Aceptado

**Stack:** PHP 8.2 · Laravel 12 · MySQL · Hexagonal + DDD táctico ligero + EDA
**Presupuesto:** 20 h / 1 persona con asistencia de agente IA · **Versión:** 1.0 (congelada)

> Este documento reemplaza la bitácora anterior de ADRs dispersos (ADR-001…014 y los registros incrementales S1–S6). Consolida, de forma compacta, las decisiones clave y la deuda técnica **aceptada conscientemente** para cerrar la v1.0. Fuente de verdad: `SPECS.md` > `DESIGN.md` > este ADR.

---

## Contexto

Se adoptó Arquitectura Hexagonal con DDD táctico ligero (entidades, value objects y eventos de dominio; sin agregados complejos ni event sourcing) y EDA para dos bounded contexts: `Listings` (núcleo) y `AuditLog` (consumidor de eventos). Con un presupuesto de 20 h, la prioridad fue entregar los seis slices funcionales (`POST`/`PATCH`/`DELETE`/`GET /api/listings`, integración LLM y `GET /api/audit-logs`) con la suite en verde, aceptando deliberadamente algunas concesiones sobre la pureza del diseño. Lo que sigue documenta dónde está esa "grasa" y por qué se deja para la v2.0.

---

## Decisiones y Trade-offs (la "grasa" consciente)

### 1. Esquema `users` por defecto de Laravel → forma de `user` inconsistente en la API

**Deuda/inconsistencia.** `SPECS §3` define `first_name`, `last_name`, `password_hash`. Se usó el esquema nativo (`name`, `password`). De ahí derivó una inconsistencia real en el contrato: escritura (`POST`/`PATCH`) responde `user: { name }`, mientras el listado (`GET /api/listings`) responde `user: { first_name, last_name }` derivando ambos campos por *split* del único `name`.

**Justificación.** Cambiar el esquema obligaba a override de Auth (`getAuthPassword()`), fricción con Sanctum y re-publicación de migraciones; coste real frente a cero valor de negocio (no hay register/login, los tokens van en seed). El *split* de `name` cubre la letra de `SPECS §4.4` sin tocar el modelo. Es una inconsistencia cosmética, contenida en la capa `Resource` (no en el dominio), trivial de unificar cuando exista un cliente que lo exija. No compensa pagarla ahora.

### 2. Owner-only resuelto solo en el Use Case (sin Policy de Laravel)

**Deuda/inconsistencia.** `DESIGN §III` prescribe una "doble barrera": Policy nativa en `app/Http` (403 rápido) + revalidación defensiva en el Use Case. Se implementó **solo** la segunda: el caso de uso carga el listing, lanza `ListingAccessDeniedException` (403) / `ListingNotFoundException` (404) y se mapean al envelope en `bootstrap/app.php`. Se rompió la letra del diseño.

**Justificación.** Una Policy de Laravel necesita leer el `listing` desde `app/Http`, lo que acopla la capa HTTP a Eloquent/repositorios y viola la regla de dependencia más estricta del proyecto (`Http → Application → Domain`). Entre cumplir la letra de `DESIGN §III` o preservar la pureza hexagonal, se priorizó esta última: la autorización es de negocio y vive en el Use Case. El resultado es **más** correcto arquitectónicamente, no menos; el único costo es perder el "403 antes del Use Case", irrelevante a esta escala. Riesgo controlado.

### 3. Concurrencia de jobs LLM resuelta con escritura acotada por columna (no transaccional)

**Deuda/inconsistencia.** `ModerationJob` y `EnrichmentJob` corren en paralelo en la misma cola `database` **sin garantía de orden** (interpretación literal de `DESIGN §V`). Un `save()` de fila completa provocaría *last-writer-wins* entre ambos. La mitigación es pragmática: métodos dirigidos `updateModerationResult()` / `updateEnrichment()` que cargan el modelo y persisten **solo columnas sucias**, en lugar de locking optimista, versionado de fila o serialización de la cola.

**Justificación.** El locking/versionado real es esfuerzo y complejidad operativa desproporcionados para dos columnas JSON disjuntas que nunca se pisan entre sí: moderación escribe `moderation_result`/`moderation_status`, enrichment escribe `ai_enrichment`/`ai_enrichment_status`. El *dirty-save* por columna elimina el conflicto observable sin introducir transacciones distribuidas ni cambiar el modelo de colas. Reabrir esto solo tendría sentido si dos procesos llegaran a compartir columna; hoy no ocurre. Deuda contenida y barata de saldar si cambia el supuesto.

### 4. Envelope `401`/`429` fuera del catálogo de errores normativo

**Deuda/inconsistencia.** `DESIGN §VI` define un envelope uniforme `{ error: { code, message, details } }` con códigos `UNAUTHENTICATED` (401) y `RATE_LIMITED` (429). Hoy 422/403/404 emiten el envelope normativo, pero **401 y 429 heredan el formato por defecto de Laravel** (Sanctum / `throttle:60,1`). La API es internamente inconsistente en sus errores.

**Justificación.** Dar forma a 401/429 exige interceptar el handler de Sanctum y el del rate limiter (puntos fuera del flujo de los casos de uso), con su propio testing de regresión. El cliente móvil ya distingue estos casos por **status HTTP**, que sí es correcto y estable; el `code` textual es conveniencia, no requisito funcional. El valor entregado por unificar el cuerpo no justifica el tiempo dentro del presupuesto. Shaping diferido a v2.0; riesgo nulo para el consumidor que enruta por status.

---

## Consecuencias

- **Contrato de error parcialmente heterogéneo:** los clientes de v1.0 deben enrutar 401/429 por *status* HTTP, no por `error.code`. Documentado para evitar suposiciones.
- **Contrato de `user` no uniforme** entre escritura (`{ name }`) y listado (`{ first_name, last_name }`): aceptable mientras no haya un cliente que consuma ambos y exija simetría.
- **Garantía de consistencia de los jobs LLM acotada por diseño:** válida *solo* mientras moderación y enrichment escriban columnas disjuntas. Cualquier feature que comparta columna entre procesos asíncronos reabre la decisión #3.
- **Autorización centralizada en Application:** la pureza hexagonal se mantiene a costa de divergir de la "doble barrera" de `DESIGN §III`. Si en v2.0 se requiere bloqueo temprano por performance, se añade la Policy como capa adicional, sin remover la revalidación del Use Case.
- **Sin cambios de dependencias ni de esquema pendientes:** las cuatro concesiones son saldables de forma incremental en v2.0 sin migraciones destructivas ni refactor del dominio.

_Riesgos de borde conocidos y aceptados como menores: posible discrepancia de medianoche entre la TZ de la app (`after_or_equal:today`) y el VO `EndDate` en UTC; suite de pruebas contra MySQL (`golf_api_testing`) por ausencia de `pdo_sqlite` en el host. Ninguno bloquea la v1.0._
