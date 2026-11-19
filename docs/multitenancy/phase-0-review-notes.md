# Notas de Revisión y Corrección de la Fase 0 (Auditoría Multitenant)

> **Documento:** `docs/multitenancy/phase-0-review-notes.md`  
> **Fecha de Actualización:** Noviembre 2026  
> **Proyecto:** INTERA (`/Users/marin084/Projects/INTERA`)  
> **Rama:** `feat/multitenancy-phase-0`  
> **Estado:** Fase 0 — Revisión Integral, Corrección de Inconsistencias y Blindaje Arquitectónico

---

## 1. Correcciones Realizadas al Documento de Diseño (`phase-0-design.md`)

A continuación se detalla cada una de las correcciones implementadas para resolver las inconsistencias documentales detectadas en la auditoría previa:

### 1.1. Separación Clara del Inventario (43 Tablas Preexistentes vs 2 Tablas Nuevas)
- **Corrección Numérica y Estructural:**  
  La versión previa mezclaba en una única tabla de 45 filas las tablas preexistentes con las propuestas, declarando erróneamente un inventario de 43 pero numerando de 1 a 45.
- **Estructuración Actual:**  
  Se separaron estrictamente en dos secciones independientes:
  - **Sección 2.1:** **2 Tablas Nuevas Propuestas** (`workspaces` y `workspace_user`), identificadas como N-1 y N-2.
  - **Sección 2.2:** **43 Tablas Preexistentes** auditadas directamente de las 46 migraciones (`Schema::create`), numeradas estrictamente del 1 al 43.
  - Se eliminaron contradicciones de clasificación y conteo.

### 1.2. Aislamiento de `agent_presence_sessions` ante Usuarios Multi-Workspace
- **Problema Abordado:** En single-tenant la presencia solo registraba `(user_id, session_key)`. Un usuario con membresía en dos o más empresas contaminaría los cómputos de horas conectadas, tableros de supervisión y SLAs entre tenants.
- **Diseño Incorporado (Sección 4.4):**
  - **Propuesta Principal Adoptada:** Adición de `workspace_id UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE`. Permite particionamiento y consulta directa por tenant sin joins adicionales en agregaciones de alta frecuencia.
  - **Alternativa Documentada:** Membresía histórica explícita (`workspace_user_id BIGINT`). Se descarta como vía principal para mantener consistencia con el discriminador estándar de la base de datos.
  - **Ciclo de Vida en Workspace Switch:** Al invocar `POST /api/v1/workspaces/switch`, el backend finaliza de inmediato la sesión activa en el workspace anterior (`ended_at = now()`). El primer heartbeat en el nuevo workspace inicia una nueva fila vinculada al nuevo `workspace_id`.
  - **Estrategia de Backfill:** Asignación transaccional del Workspace Inicial a todas las filas existentes previo a fijar `NOT NULL`.
  - **Índices Compuestos Específicos:**
    - `idx_agent_presence_ws_user_period`: `(workspace_id, user_id, started_at, ended_at)`
    - `idx_agent_presence_ws_lookup`: `(workspace_id, user_id, session_key, ended_at)`
    - `idx_agent_presence_ws_period`: `(workspace_id, started_at, ended_at)`
  - **Scoping en Analítica y Métricas:** `AgentPresenceHistoryService::connectedSecondsByUser` recibe `$workspaceId` y filtra incondicionalmente por tenant. Clave de caché aislada: `agent_presence:{$workspaceId}:{$user->id}`.

### 1.3. Co-pertenencia Estricta en `flow_simulation_sessions`
- **Problema Abordado:** El simulador de flujos no tenía acotamiento explícito de relaciones en multitenancy, permitiendo teóricamente que un usuario ejecutara un flujo de otra empresa utilizando una conversación sintética ajena.
- **Regla Mandatoria Incorporada (Sección 2.2, 2.3 y 6.5):**
  Se exige formalmente que **las 5 entidades involucradas en la sesión pertenezcan de forma obligatoria al mismo workspace**:
  1. `user_id`: Membresía activa en `workspace_user` para el tenant.
  2. `flow_id`: El flujo debe pertenecer al tenant (`flows.workspace_id === current_workspace_id`).
  3. `flow_version_id`: La versión debe pertenecer al flujo del tenant.
  4. `conversation_id`: La conversación mock de prueba debe crearse dentro del mismo tenant.
  5. `flow_execution_id`: La ejecución debe asociarse exclusivamente a la conversación del tenant.
  Cualquier desalineación aborta inmediatamente con `422 Unprocessable Entity` o `403 Forbidden`.

### 1.4. Modelo RBAC Fail-Closed durante Toda la Transición (`role_user`)
- **Problema Abordado:** La versión previa dejaba abierta la posibilidad de que un usuario con rol `admin` en la tabla legada `role_user` obtuviera privilegios dentro de un workspace donde no tuviera membresía o estuviera inactivo.
- **Especificación Fail-Closed Incorporada (Sección 5):**
  - **Principio Cardinal:** `role_user` NUNCA puede otorgar roles ni permisos dentro de un workspace si la membresía correspondiente en `workspace_user` está ausente o inactiva (`is_active = false`).
  - **Resolución en `hasRole` y `hasPermission`:** Ante cualquier petición con contexto de workspace (activo o solicitado), se consulta **única y exclusivamente `workspace_user`**. Si el registro no existe o `is_active` es `false`, se retorna `false` inmediatamente sin consultar jamás `role_user`.
  - **Aislamiento del Fallback:** La tabla legada `role_user` se reserva exclusivamente para comandos de consola de infraestructura (ej. inicialización de host) o rutas agnósticas de workspace, con cero injerencia en endpoints de negocio.

### 1.5. Validaciones de Pertenencia y Protección contra Asociaciones Cruzadas
- **Sección 6 Dedicada:** Se diseñaron las validaciones requeridas en controladores y servicios para evitar invasiones o enlaces inter-tenant:
  - **`team_user`:** Al asociar miembros a un equipo en `TeamController`, se valida que cada `user_id` posea membresía activa en el workspace del equipo.
  - **`team_channels`:** Al asociar canales a un equipo en `TeamController`, se valida que cada `channel_id` pertenezca al mismo workspace del equipo, respetando adicionalmente `team_channels.channel_id UNIQUE`.
  - **`channel_user`:** Al restringir el acceso a un canal en `ChannelController`, se valida que cada `user_id` pertenezca activamente al workspace del canal.
  - **`conversation_queue_entries`:** Al encolar conversaciones (`ConversationQueueService`), se valida que `conversation.workspace_id === team.workspace_id`; y si se asigna un `assigned_user_id`, se valida que el operador pertenezca activamente a dicho workspace.

### 1.6. Ruta de Resolución y Validación Verificable para las 19 Tablas Heredadas
- **Sección 2.3 Incorporada:** Se construyó una matriz exhaustiva para cada una de las 19 tablas secundarias/hijas del sistema:
  - Identificación de la entidad padre directa y su clave foránea.
  - Cadena completa de resolución hacia `workspaces(id)`.
  - Expresión o método de validación verificable en Eloquent / Repositorios.
  - Comportamiento fail-closed ante inconsistencias (`404 Not Found` o `422 Unprocessable Entity`).

### 1.7. Matriz de Riesgos y Pruebas Ampliada
- **Riesgos Adicionales Documentados (Sección 14):** Se añadieron R-11 (simulación cruzada de flujos), R-12 (contaminación de presencia), R-13 (asociación cruzada de miembros a equipos), R-14 (desvío de colas entre empresas), R-15 (acceso a canal privado por usuario ajeno) y R-16 (fuga de permisos por fallback indebido).
- **Escenarios de Prueba Incorporados (Sección 15):** Pruebas de aislamiento específicas para simulación de flujos, presencia multi-workspace, adición de miembros/canales ajenos a equipos y colas inter-workspace.

### 1.8. Definición Formal de Criterios de Aprobación de la Fase 0
- **Sección 18 Incorporada:** Se establecieron los 6 criterios de aprobación exigidos:
  1. Inventario consistente;
  2. Estrategia de presencia definida;
  3. RBAC fail-closed;
  4. Relaciones cruzadas protegidas;
  5. Pruebas y fallos documentados;
  6. Decisiones pendientes enumeradas.

### 1.9. Corrección de la Conclusión General
- Se eliminó la afirmación previa "No hay bloqueos técnicos de diseño".
- Se estableció la conclusión exacta y precisa requerida:
  > **"La implementación puede comenzar únicamente después de aprobar las decisiones pendientes y resolver estas inconsistencias documentales."**

---

## 2. Inventario de Tablas: Comparativa y Resolución

| Concepto | Versión Previa | Versión Corregida |
|---|---|---|
| **Conteo Total Declarado** | "43 tablas reales" | **43 tablas preexistentes** auditadas + **2 tablas nuevas** propuestas |
| **Numeración en Tabla** | Filas 1 a 45 mezcladas | Sección 2.1 (N-1, N-2) y Sección 2.2 (1 a 43) claramente separadas |
| **`agent_presence_sessions`** | Clasificada como recurso heredado sin resolver multi-workspace | Clasificada con `workspace_id` directo, ciclo de switch, backfill e índices dedicados |
| **`flow_simulation_sessions`** | "Vía flow_id" sin co-validación | Co-pertenencia obligatoria de las 5 entidades al mismo workspace |
| **Tablas Pivote (`team_user`, etc.)** | Registradas sin reglas de validación en controladores | Reglas formales de validación de membresía y pertenencia en Sección 6 |
| **Tablas Heredadas (19 tablas)** | Sin matriz de trazabilidad | Matriz detallada en Sección 2.3 con validación verificable y fail-closed |

---

## 3. Decisiones Arquitectónicas que Requieren Ratificación Humana

Antes de iniciar la Fase 1, las siguientes **6 decisiones** deben ser ratificadas formalmente:

1. **Denominación y Slug del Workspace Inicial:**  
   Confirmar `name: "Espacio Principal"`, `slug: "principal"` (configurable mediante `.env`: `APP_INITIAL_WORKSPACE_NAME`, `APP_INITIAL_WORKSPACE_SLUG`).
2. **Estrategia de Persistencia de `messages.workspace_id`:**  
   Ratificar la decisión de mantener `messages` heredando el workspace vía `conversation_id`, aprovechando el índice monotónico `(conversation_id, sent_at, id)` y evitando mutaciones redundantes masivas.
3. **Estrategia de Aislamiento para `agent_presence_sessions`:**  
   Ratificar la adición directa de la columna `workspace_id UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE` y el cierre de sesión activa al conmutar de tenant.
4. **Flujo de Usuario con Múltiples Workspaces en Login:**  
   Ratificar la auto-selección del último `active_workspace_id` con selector visible en la interfaz para alternar entre empresas.
5. **Aislamiento Total de Clientes Finales (`contacts`):**  
   Confirmar que un mismo número de teléfono en dos empresas distintas crea dos contactos completamente aislados e independientes.
6. **Alcance y Límites de `is_platform_admin`:**  
   Confirmar que los administradores de infraestructura de plataforma no poseen acceso automático a los datos de negocio de ningún workspace a menos que cuenten con membresía explícita o modo "impersonate" auditado.

---

## 4. Estado de Verificación de Suites de Prueba y Entorno

Se reitera el diagnóstico real de las suites de prueba en el repositorio para evitar falsos bloqueos:

- **PHPUnit / Artisan Test:** 409 tests, 2 fallos preexistentes en `origin/main` en `InboxOperabilityTest` y `WhatsAppWindowTest` derivados del commit `4b3f24f` (closed-chat follow-ups). No bloquean el diseño ni las migraciones de base de datos de la Fase 1.
- **Laravel Pint:** 56 archivos con discrepancias menores de estilo preexistentes en `origin/main`.
- **Frontend Vitest:** Advertencia de JSDOM/localStorage con Node 26.
- **Frontend Build & Lint:** `npm run build` exitoso (código de salida 0), `npm run lint` con 0 errores (código de salida 0).
- **Docker Daemon:** Inactivo en host local Mac; irrelevante para migraciones SQLite locales.

---

## 5. Criterios de Aprobación de la Fase 0 y Conclusión

### Resumen de Cumplimiento de Criterios de la Fase 0:
1. **Inventario Consistente:** Cumplido. 43 tablas actuales separadas de 2 tablas nuevas propuestas.
2. **Estrategia de Presencia Definida:** Cumplido. Aislamiento formal de `agent_presence_sessions` con `workspace_id`, switch, backfill, índices y métricas.
3. **RBAC Fail-Closed:** Cumplido. Regla absoluta: `role_user` nunca otorga permisos en workspace si la membresía está ausente o inactiva.
4. **Relaciones Cruzadas Protegidas:** Cumplido. Validaciones mandatorias documentadas para `team_user`, `team_channels`, `channel_user`, `conversation_queue_entries` y `flow_simulation_sessions`.
5. **Pruebas y Fallos Documentados:** Cumplido. Diagnóstico riguroso de suites y fallos preexistentes registrado.
6. **Decisiones Pendientes Enumeradas:** Cumplido. 6 decisiones formalmente estructuradas para ratificación.

### Conclusión Precisa:
> **La implementación puede comenzar únicamente después de aprobar las decisiones pendientes y resolver estas inconsistencias documentales.**
