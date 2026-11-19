# INTERA — Diseño de Arquitectura Multitenant (Fase 0)

> **Documento:** `docs/multitenancy/phase-0-design.md`  
> **Estado:** Fase 0 — Auditoría y Diseño Arquitectónico (Revisado y Corregido Integralmente)  
> **Fecha de Actualización:** Noviembre 2026  
> **Regla de la Fase 0:** Sin cambios en código funcional, modelos, controladores, migraciones, base de datos ni frontend.

---

## 1. Resumen Ejecutivo

INTERA es una plataforma de atención conversacional y constructor visual de flujos, desarrollada en Laravel/PHP (backend) y React/Vite/TypeScript (frontend). Su arquitectura original opera bajo la modalidad **Single-Tenant**: asume que toda la infraestructura, base de datos PostgreSQL, colas Redis, WebSockets (Laravel Reverb) e integraciones pertenecen a una única empresa.

El objetivo de esta iniciativa es transformar INTERA en una plataforma **Multitenant de persistencia compartida con discriminador lógico (`workspace_id`)**, garantizando el aislamiento criptográfico, operativo y de datos entre múltiples empresas (**workspaces**), manteniendo de forma transparente y sin disrupción el funcionamiento y los datos de la empresa existente.

### Principios Mandatorios de la Arquitectura

1. **Aislamiento Hermético Fail-Closed:** Ante cualquier ausencia, inconsistencia o ambigüedad del contexto de workspace en una ruta protegida, la aplicación responderá inmediatamente con `403 Forbidden` o `401 Unauthorized`.
2. **Zero-Trust en Parámetros del Cliente:** El servidor nunca confía en un `workspace_id` enviado por el navegador o cliente API. Todo identificador de workspace enviado es validado obligatoriamente contra la membresía activa del usuario en la base de datos (`workspace_user`).
3. **Preservación Total de Datos Preexistentes:** La empresa actual y sus canales, contactos, flujos, conversaciones, mensajes, métricas y sesiones se migrarán hacia un **Workspace Inicial** sin ninguna pérdida de información.
4. **Compatibilidad Estricta de Contratos Públicos:**
   - Webhooks de Meta (WhatsApp, Messenger, Instagram): `/api/v1/public/webhooks/{provider}/{channel}`
   - Webhooks de Telegram: `/api/v1/public/webhooks/telegram/{channel}`
   - Webchat Widget: `/api/v1/public/webchat/{publicKey}/embed` y `/sessions`
   - Formulario de Login: `/api/v1/auth/login` (ruta `/` de la SPA)
5. **Diferenciación entre Teams y Workspaces:** Los `teams` actuales son equipos internos de atención (ej. Ventas, Soporte N1) dentro de una misma empresa y **no deben convertirse en tenants**. Los tenants son exclusivamente los `workspaces`.
6. **Política Frente a Global Scopes de Eloquent:** Se descarta el uso de Global Scopes automáticos en Eloquent sin discriminación. Un global scope ciego rompe webhooks públicos, jobs encolados, comandos CLI de mantenimiento y migraciones. Se adopta una estrategia de **Scope Explícito de Workspace** mediante métodos dedicados (`forWorkspace($id)`), middleware de contexto y repositorios acotados.

---

## 2. Inventario Completo de Tablas y Clasificación

A partir de la inspección exhaustiva de todas las declaraciones literales `Schema::create(...)` en las 46 migraciones del backend, se auditan las **43 tablas preexistentes** de la base de datos de INTERA y se separan inequívocamente de las **2 tablas nuevas propuestas** para soportar el modelo multitenant.

### 2.1. Tablas Nuevas Propuestas para Multitenancy (2 tablas)

Estas tablas no existen actualmente en la base de datos y serán creadas en la Fase 1:

| # | Tabla Nueva | Categoría Arquitectónica | Tipo de Clave Primaria | Propósito y Responsabilidad | Índices y Restricciones Principales |
|---|---|---|---|---|---|
| **N-1** | `workspaces` | **Raíz de Workspace** | UUID (`$table->uuid('id')->primary()`) | Entidad raíz que representa al tenant o empresa. Agrupa todos los recursos, canales, flujos y configuraciones. | `id (UUID PK)`, `slug UNIQUE`, `INDEX(status)` |
| **N-2** | `workspace_user` | **Pivote de Membresía** | BIGINT (`$table->id()`) | Relaciona usuarios con workspaces, asignando su rol operativo local y estado de activación (`is_active`). | `UNIQUE(workspace_id, user_id)`, `INDEX(user_id, is_active)`, `INDEX(workspace_id, is_active)` |

---

### 2.2. Clasificación Exhaustiva de las 43 Tablas Preexistentes de INTERA

Las siguientes **43 tablas** corresponden exactamente al esquema preexistente del sistema:

| # | Tabla Preexistente | Categoría Arquitectónica | Resolución de Workspace | `workspace_id` Directo | Relación de Herencia | Consultas y Operaciones Obligatorias que deben Validar Workspace | Índices y Restricciones Requeridos |
|---|---|---|---|---|---|---|---|
| 1 | `users` | **Global de Plataforma** | Global | No | Participa en WS vía `workspace_user` | Login, recuperación de contraseña, cambio de workspace activo | `id (BIGINT PK)`, `email UNIQUE`, `active_workspace_id (UUID FK NULL)` |
| 2 | `password_reset_tokens` | **Temporal / Sesión** | Global de usuario | No | Vía `email` de `users` | Flujo de restablecimiento de contraseña | `email PRIMARY KEY` |
| 3 | `sessions` | **Temporal / Sesión** | Global de usuario | No | Vía `user_id` de `users` | Middleware de sesión web / Sanctum | `id PRIMARY KEY`, `INDEX(user_id)`, `INDEX(last_activity)` |
| 4 | `personal_access_tokens`| **Global de Plataforma** | Vía tokenable (`User`) | No | Vía `user_id` de `users` | Autenticación API vía Laravel Sanctum | `id (BIGINT PK)`, morphs a `User` |
| 5 | `roles` | **Global de Plataforma** | Catálogo Global | No | Catálogo de roles del sistema | Asignación de rol en `workspace_user` | `id (BIGINT PK)`, `name UNIQUE` |
| 6 | `permissions` | **Global de Plataforma** | Catálogo Global | No | Catálogo de permisos del sistema | Verificación granular de capacidades por rol | `id (BIGINT PK)`, `name UNIQUE`, `INDEX(module)` |
| 7 | `role_user` | **Global (Transición Legada)**| Transición a membresía | No | Global a extinguir tras Fase 2 | Sólo operaciones agnósticas de plataforma; **NUNCA en rutas de workspace** | `PRIMARY KEY(user_id, role_id)` |
| 8 | `permission_role` | **Global de Plataforma** | Catálogo Global | No | Catálogo de permisos por rol | Carga de permisos asociados a cada rol | `PRIMARY KEY(role_id, permission_id)` |
| 9 | `system_settings` | **Infraestructura** | Global de instalación | No | Ajustes globales del servidor | Alertas Discord de infraestructura (`discord_alert_webhook_url`) | `id (BIGINT PK)`, `key UNIQUE` |
| 10 | `cache` | **Infraestructura** | Clave prefijada | No | Driver de caché en base de datos | Almacenamiento clave-valor de Laravel | `key PRIMARY KEY` |
| 11 | `cache_locks` | **Infraestructura** | Clave prefijada | No | Bloqueos atómicos de Laravel | Concurrencia en jobs, outbox y webhooks | `key PRIMARY KEY` |
| 12 | `jobs` | **Infraestructura** | Contexto en Payload | No | Cola de trabajos de Laravel | Procesamiento asíncrono en workers de Redis/DB | `id (BIGINT PK)`, `INDEX(queue)` |
| 13 | `job_batches` | **Infraestructura** | Global de cola | No | Lotes de trabajos de Laravel | Batching de tareas encoladas | `id PRIMARY KEY` |
| 14 | `failed_jobs` | **Infraestructura** | Global de cola | No | Dead Letter Queue de Laravel | Auditoría y reintento de jobs fallidos | `id (BIGINT PK)`, `uuid UNIQUE` |
| 15 | `channels` | **Raíz de Workspace** | Directo | **Sí** | `belongsTo(Workspace)` | `ChannelController@index`, show, store, update, destroy | `id (UUID PK)`, `foreignUuid(workspace_id)`, `INDEX(workspace_id, type)` |
| 16 | `channel_user` | **Pivote con Validación** | Heredado vía canal | No | `belongsTo(Channel)` y `User` | Asignación de acceso restringido a canal; validar que el usuario pertenezca al workspace del canal | `id (BIGINT PK)`, `UNIQUE(user_id, channel_id)` |
| 17 | `webchat_channel_settings`| **Recurso Heredado** | Vía `channel_id` | No | `belongsTo(Channel)` | Handshake `/embed`, inicialización de sesiones webchat | `id (UUID PK)`, `channel_id UNIQUE`, `public_key UNIQUE` |
| 18 | `webchat_sessions` | **Temporal / Sesión** | Vía `channel_id` | No | `belongsTo(Channel)` | `AuthenticateWebchatVisitor`, polling de mensajes | `id (UUID PK)`, `channel_id FK`, `INDEX(token_hash)` |
| 19 | `contacts` | **Raíz de Workspace** | Directo | **Sí** | `belongsTo(Workspace)` | Ingesta de webhooks, búsqueda de contactos, edición | `id (UUID PK)`, `foreignUuid(workspace_id)`, `INDEX(workspace_id, phone)` |
| 20 | `flows` | **Raíz de Workspace** | Directo | **Sí** | `belongsTo(Workspace)` | `FlowController@index`, show, store, update, publish | `id (UUID PK)`, `foreignUuid(workspace_id)`, `INDEX(workspace_id, status)` |
| 21 | `flow_versions` | **Recurso Heredado** | Vía `flow_id` | No | `belongsTo(Flow)` | Carga de grafos, runtime, simulador, clonación | `id (UUID PK)`, `flow_id FK`, `UNIQUE(flow_id, version_number)` |
| 22 | `flow_nodes` | **Recurso Heredado** | Vía `flow_version_id` | No | `belongsTo(FlowVersion)` | Ejecución de pasos de grafo, guardado en editor | `id (UUID PK)`, `UNIQUE(flow_version_id, node_key)` |
| 23 | `flow_edges` | **Recurso Heredado** | Vía `flow_version_id` | No | `belongsTo(FlowVersion)` | Enrutamiento de transiciones entre nodos | `id (UUID PK)`, `flow_version_id FK` |
| 24 | `flow_publications` | **Recurso Heredado** | Vía `flow_id` | No | `belongsTo(Flow)` | Histórico de versiones publicadas y rollbacks | `id (UUID PK)`, `flow_id FK` |
| 25 | `flow_executions` | **Recurso Heredado** | Vía `conversation_id` | No | `belongsTo(Conversation)` | Motor de bots, timeout, handoff a agentes | `id (UUID PK)`, `conversation_id FK`, `INDEX(conversation_id, status)` |
| 26 | `flow_execution_steps` | **Recurso Heredado** | Vía `flow_execution_id`| No | `belongsTo(FlowExecution)`| Trazabilidad analítica de ejecución de nodos | `id (UUID PK)`, `flow_execution_id FK` |
| 27 | `flow_simulation_sessions`| **Recurso Co-validado** | Vía `flow_id` y contexto | No | `belongsTo(Flow)` y `Conversation` | Simulador interactivo: **usuario, flujo, versión, conversación y ejecución deben pertenecer al mismo workspace** | `id (UUID PK)`, `flow_id FK`, `conversation_id FK`, `INDEX(user_id, status)` |
| 28 | `conversations` | **Raíz de Workspace** | Directo | **Sí** | `belongsTo(Workspace)` | `ConversationController@index`, show, claim, close, assign | `id (UUID PK)`, `foreignUuid(workspace_id)`, `INDEX(workspace_id, status, queued_at)` |
| 29 | `conversation_participants`| **Recurso Heredado** | Vía `conversation_id` | No | `belongsTo(Conversation)` | Agentes y contactos participantes en la conversación | `id (UUID PK)`, `conversation_id FK` |
| 30 | `messages` | **Recurso Heredado** | Vía `conversation_id` | No | `belongsTo(Conversation)` | Historial de mensajes, outbox delivery, paginación | `id (UUID PK)`, `conversation_id FK`, `INDEX(conversation_id, sent_at, id)` |
| 31 | `conversation_assignments`| **Recurso Heredado** | Vía `conversation_id` | No | `belongsTo(Conversation)` | Reasignaciones de agentes, métricas de atención | `id (UUID PK)`, `conversation_id FK` |
| 32 | `conversation_read_states`| **Recurso Heredado** | Vía `conversation_id` | No | `belongsTo(Conversation)` | Marcadores de no leídos por operador | `id (UUID PK)`, `UNIQUE(conversation_id, user_id)` |
| 33 | `teams` | **Raíz de Workspace** | Directo | **Sí** | `belongsTo(Workspace)` | `TeamController@index`, store, update, colas de atención | `id (UUID PK)`, `foreignUuid(workspace_id)`, `UNIQUE(workspace_id, name)` |
| 34 | `team_user` | **Pivote con Validación** | Heredado vía equipo | No | `belongsTo(Team)` y `User` | Miembros de equipos; validar que el usuario tenga membresía activa en el workspace del equipo | `id (BIGINT PK)`, `UNIQUE(team_id, user_id)` |
| 35 | `team_channels` | **Pivote con Validación** | Heredado vía equipo | No | `belongsTo(Team)` y `Channel` | Enrutamiento de canales a equipos; validar que el canal pertenezca al mismo workspace del equipo | `id (BIGINT PK)`, `channel_id UNIQUE` |
| 36 | `conversation_queue_entries`| **Recurso Heredado con Co-validación**| Vía `conversation_id` y `team_id` | No | `belongsTo(Conversation)` y `Team` | Métricas de SLA; validar que conversación y equipo pertenezcan al mismo workspace, y que el agente asignado sea miembro activo | `id (BIGINT PK)`, `INDEX(team_id, ended_at, entered_at)` |
| 37 | `tags` | **Raíz de Workspace** | Directo | **Sí** | `belongsTo(Workspace)` | `TagController@index`, store, update, destroy | `id (UUID PK)`, `foreignUuid(workspace_id)`, `UNIQUE(workspace_id, slug)`, `UNIQUE(workspace_id, name)` |
| 38 | `conversation_tag` | **Pivote con Validación** | Heredado vía conversación | No | `belongsTo(Conversation)` y `Tag` | Filtrado por etiquetas en inbox; validar que conversación y etiqueta pertenezcan al mismo workspace | `id (BIGINT PK)`, `UNIQUE(conversation_id, tag_id)` |
| 39 | `canned_responses` | **Raíz de Workspace** | Directo | **Sí** | `belongsTo(Workspace)` | `CannedResponseController@index`, store, update, show | `id (UUID PK)`, `foreignUuid(workspace_id)`, `UNIQUE(workspace_id, shortcut)` |
| 40 | `saved_funnels` | **Raíz de Workspace** | Directo | **Sí** | `belongsTo(Workspace)` | `SavedFunnelController@index`, store, update, destroy | `id (UUID PK)`, `foreignUuid(workspace_id)`, `flow_id FK` |
| 41 | `whatsapp_media_cache` | **Recurso Heredado** | Vía `channel_id` | No | `belongsTo(Channel)` | `WhatsAppMediaPrewarmService`, deduplicación de media | `id (UUID PK)`, `UNIQUE(channel_id, file_fingerprint)` |
| 42 | `agent_presence_sessions`| **Presencia Aislada por Workspace** | Directo / Membresía | **Sí** | `belongsTo(Workspace)` y `User` | Registro de heartbeats y métricas de conexión; aislada estrictamente por workspace activo | `id (BIGINT PK)`, `foreignUuid(workspace_id)`, `INDEX(workspace_id, user_id, started_at, ended_at)` |
| 43 | `audit_logs` | **Raíz de Workspace (Nullable)**| Directo | **Sí** | Directo a `workspaces` (NULL para plataforma) | Trazabilidad de auditoría de usuarios y del sistema | `id (UUID PK)`, `foreignUuid(workspace_id)->nullable()`, `INDEX(workspace_id, created_at)` |

---

### 2.3. Matriz de Resolución y Validación Verificable para Todas las Tablas Heredadas (19 tablas)

A continuación se detalla la ruta de resolución inequívoca y la validación en tiempo de ejecución para cada una de las **19 tablas heredadas o secundarias** del sistema:

| # | Tabla Heredada | Entidad Padre Directa | Clave Foránea Padre | Cadena Completa de Resolución hacia `workspace_id` | Consulta de Validación en Eloquent / Repositorio | Comportamiento Fail-Closed ante Desincronización |
|---|---|---|---|---|---|---|
| 1 | `flow_versions` | `flows` | `flow_id` | `flow_versions -> flows(workspace_id)` | `$version->flow->workspace_id === $currentWorkspaceId` | `404 Not Found` inmediato |
| 2 | `flow_nodes` | `flow_versions` | `flow_version_id` | `flow_nodes -> flow_versions -> flows(workspace_id)` | `$node->flowVersion->flow->workspace_id === $currentWorkspaceId` | `404 Not Found` |
| 3 | `flow_edges` | `flow_versions` | `flow_version_id` | `flow_edges -> flow_versions -> flows(workspace_id)` | `$edge->flowVersion->flow->workspace_id === $currentWorkspaceId` | `404 Not Found` |
| 4 | `flow_publications`| `flows` | `flow_id` | `flow_publications -> flows(workspace_id)` | `$publication->flow->workspace_id === $currentWorkspaceId` | `404 Not Found` |
| 5 | `flow_executions` | `conversations` | `conversation_id` | `flow_executions -> conversations(workspace_id)` | `$execution->conversation->workspace_id === $currentWorkspaceId` | Aborta runtime / `404` |
| 6 | `flow_execution_steps`| `flow_executions`| `flow_execution_id`| `flow_execution_steps -> flow_executions -> conversations(workspace_id)` | `$step->flowExecution->conversation->workspace_id === $currentWorkspaceId` | `404 Not Found` |
| 7 | `flow_simulation_sessions`| `flows` y `conversations`| `flow_id`, `conversation_id` | Co-pertenencia: `flow.workspace_id === conversation.workspace_id === user.active_workspace_id` | Verificación en `FlowSimulationService`: todos los IDs deben coincidir con `$currentWorkspaceId` | `422 Unprocessable` / `403 Forbidden` |
| 8 | `webchat_channel_settings`| `channels` | `channel_id` | `webchat_channel_settings -> channels(workspace_id)` | `$settings->channel->workspace_id === $currentWorkspaceId` | `404 Not Found` |
| 9 | `webchat_sessions` | `channels` | `channel_id` | `webchat_sessions -> channels(workspace_id)` | `$session->channel->workspace_id === $currentWorkspaceId` | `403 Forbidden` en handshake |
| 10 | `whatsapp_media_cache`| `channels` | `channel_id` | `whatsapp_media_cache -> channels(workspace_id)` | `$cache->channel->workspace_id === $currentWorkspaceId` | Omite deduplicación cruzada |
| 11 | `channel_user` | `channels` y `users` | `channel_id`, `user_id` | Canal pertenece a WS; usuario debe pertenecer al mismo WS vía `workspace_user` | `workspace_user.where(workspace_id, channel.workspace_id).where(user_id, uid).where(is_active, true)` | `422 Unprocessable Entity` |
| 12 | `conversation_participants`| `conversations`| `conversation_id` | `conversation_participants -> conversations(workspace_id)` | `$participant->conversation->workspace_id === $currentWorkspaceId` | `404 Not Found` |
| 13 | `messages` | `conversations` | `conversation_id` | `messages -> conversations(workspace_id)` | `$message->conversation->workspace_id === $currentWorkspaceId` | `404 Not Found` |
| 14 | `conversation_assignments`| `conversations`| `conversation_id` | `conversation_assignments -> conversations(workspace_id)` | `$assignment->conversation->workspace_id === $currentWorkspaceId` | `404 Not Found` |
| 15 | `conversation_read_states`| `conversations`| `conversation_id` | `conversation_read_states -> conversations(workspace_id)` | `$readState->conversation->workspace_id === $currentWorkspaceId` | `404 Not Found` |
| 16 | `conversation_tag` | `conversations` y `tags`| `conversation_id`, `tag_id` | Conversación y Tag pertenecen al mismo WS | `$conversation->workspace_id === $tag->workspace_id === $currentWorkspaceId` | `422 Unprocessable Entity` |
| 17 | `team_user` | `teams` y `users` | `team_id`, `user_id` | Equipo pertenece a WS; usuario debe pertenecer al mismo WS vía `workspace_user` | `workspace_user.where(workspace_id, team.workspace_id).where(user_id, uid).where(is_active, true)` | `422 Unprocessable Entity` |
| 18 | `team_channels` | `teams` y `channels` | `team_id`, `channel_id` | Ambos pertenecen al mismo WS | `$team->workspace_id === $channel->workspace_id === $currentWorkspaceId` | `422 Unprocessable Entity` |
| 19 | `conversation_queue_entries`| `conversations` y `teams`| `conversation_id`, `team_id` | Conversación y Equipo pertenecen al mismo WS; agente asignado pertenece al mismo WS | `$conversation->workspace_id === $team->workspace_id` y validación de membresía del agente | `422 Unprocessable Entity` |

---

## 3. Tipos Reales de IDs y Convenciones de Persistencia

Se auditó el código fuente real del proyecto para evitar discrepancias de tipo entre PostgreSQL y Eloquent:

### 3.1. Claves Primarias de Tipo BIGINT (`$table->id()`)
Las siguientes entidades utilizan enteros de 64 bits auto-incrementales:
- `users.id`
- `roles.id`
- `permissions.id`
- `system_settings.id`
- `jobs.id`, `failed_jobs.id`
- `personal_access_tokens.id`
- `agent_presence_sessions.id`
- `conversation_queue_entries.id`
- Tablas pivote con ID propio: `channel_user.id`, `team_user.id`, `team_channels.id`, `conversation_tag.id`

**Regla de llave foránea:** Todo campo que apunte a `users.id` debe declararse como `$table->foreignId('user_id')->constrained('users')`.

### 3.2. Claves Primarias de Tipo UUID (`$table->uuid('id')->primary()`)
Las entidades de negocio y de alta concurrencia utilizan UUID:
- `channels`, `contacts`, `flows`, `conversations`, `messages`, `teams`, `tags`, `canned_responses`, `saved_funnels`, `audit_logs`
- Recursos hijos: `flow_versions`, `flow_nodes`, `flow_edges`, `flow_publications`, `flow_executions`, `flow_execution_steps`, `flow_simulation_sessions`, `conversation_participants`, `conversation_assignments`, `conversation_read_states`, `webchat_channel_settings`, `webchat_sessions`, `whatsapp_media_cache`

**Comportamiento Real de Generación:**
- En los modelos Eloquent que usan el trait `Illuminate\Database\Eloquent\Concerns\HasUuids`, Laravel genera internamente UUIDs ordenados combinados (`Str::orderedUuid()`).
- En migraciones o asignaciones directas, el código del proyecto utiliza `(string) Str::uuid()`.
- **Compatibilidad:** Ambos son compatibles con el tipo nativo `UUID` de PostgreSQL 16.
- **Definición de `workspaces.id`:** Debe declararse estrictamente como `$table->uuid('id')->primary()`.
- **Definición de `active_workspace_id` en `users`:** Debe declararse como `$table->foreignUuid('active_workspace_id')->nullable()->constrained('workspaces')->nullOnDelete()`. **Bajo ninguna circunstancia debe usarse `foreignId` para esta columna**, ya que provocaría una incompatibilidad fatal de tipo BIGINT contra UUID en PostgreSQL.

---

## 4. Modelo de Workspace, Membresías y Presencia

### 4.1. Esquema de `workspaces`

```sql
CREATE TABLE workspaces (
    id UUID PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    slug VARCHAR(80) NOT NULL UNIQUE,
    status VARCHAR(30) NOT NULL DEFAULT 'active', -- active, suspended, archived
    initial_owner_id BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
    configuration JSONB NULL DEFAULT '{}',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_workspaces_status ON workspaces(status);
```

### 4.2. Esquema de `workspace_user`

```sql
CREATE TABLE workspace_user (
    id BIGSERIAL PRIMARY KEY,
    workspace_id UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role_id BIGINT NOT NULL REFERENCES roles(id) ON DELETE RESTRICT,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uniq_workspace_user UNIQUE (workspace_id, user_id)
);

CREATE INDEX idx_workspace_user_user ON workspace_user(user_id, is_active);
CREATE INDEX idx_workspace_user_workspace ON workspace_user(workspace_id, is_active);
```

### 4.3. Gestión del Workspace Activo

1. Columna `users.active_workspace_id` (UUID nullable).
2. **Auto-resolución en Login:**
   - Si el usuario pertenece a exactamente 1 workspace activo, este se asigna automáticamente como `active_workspace_id`.
   - Si pertenece a múltiples, se conserva su `active_workspace_id` previo si sigue siendo válido, o se selecciona el más antiguo activo.
3. **Cambio de Workspace:**
   - Endpoint: `POST /api/v1/workspaces/switch` con payload `{"workspace_id": "uuid"}`.
   - El backend valida:
     ```php
     $membership = WorkspaceUser::where('user_id', $user->id)
         ->where('workspace_id', $requestedWorkspaceId)
         ->where('is_active', true)
         ->whereHas('workspace', fn ($q) => $q->where('status', 'active'))
         ->first();
     abort_unless($membership, 403, 'No tienes membresía activa en este espacio.');
     $user->update(['active_workspace_id' => $requestedWorkspaceId]);
     ```

---

### 4.4. Aislamiento y Ciclo de Vida de `agent_presence_sessions` en Escenarios Multi-Workspace

#### Diagnóstico del Problema en Multi-Workspace
En la arquitectura single-tenant actual, la tabla `agent_presence_sessions` almacena únicamente `(user_id, session_key, started_at, last_heartbeat_at, ended_at)`.
Cuando un operador pertenece a más de un workspace (por ejemplo, agentes de soporte compartido o supervisores en varias empresas), un registro sin discriminador provocaría:
1. **Contaminación de Métricas:** Horas de conexión en Workspace A se computarían indebidamente en los reportes de productividad de Workspace B.
2. **Disponibilidad Ficticia:** Un agente operando en Workspace A aparecería como disponible para recibir chats enrutados automáticamente en Workspace B, degradando los SLAs de atención.

#### Propuesta de Aislamiento
Se adopta formalmente la adición de la columna **`workspace_id` directo** en `agent_presence_sessions`:

```sql
ALTER TABLE agent_presence_sessions 
    ADD COLUMN workspace_id UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE;
```

*(Alternativa analizada: Vincular a `workspace_user_id BIGINT`. Se opta por `workspace_id UUID` directo debido a su compatibilidad uniforme con el discriminador estándar del sistema, permitiendo consultas directas particionadas por workspace sin joins adicionales en agregaciones analíticas de alta frecuencia).*

#### Ciclo de Vida ante Conmutación de Espacio (`Workspace Switch`)
1. **Terminación de Sesión Saliente:** Al invocar `POST /api/v1/workspaces/switch`, el backend finaliza de inmediato cualquier sesión activa en el workspace previo:
   ```php
   AgentPresenceSession::query()
       ->where('user_id', $user->id)
       ->where('workspace_id', $oldWorkspaceId)
       ->whereNull('ended_at')
       ->update(['ended_at' => now()]);
   ```
2. **Inicio en Nuevo Workspace:** Al recibir el primer heartbeat o conexión WebSocket en el nuevo espacio, `AgentPresenceHistoryService::recordHeartbeat` abre una nueva sesión ligada al nuevo `workspace_id`.
3. **Validación de Heartbeat:** Todo heartbeat valida obligatoriamente que el usuario posea membresía activa (`is_active = true`) en el `workspace_id` declarado. Si no la posee, se rechaza con `403 Forbidden`.

#### Estrategia de Backfill
En la migración correspondiente:
```sql
UPDATE agent_presence_sessions 
SET workspace_id = (SELECT id FROM workspaces WHERE slug = 'principal' LIMIT 1)
WHERE workspace_id IS NULL;
```
Posteriormente se aplica la restricción `NOT NULL` y la clave foránea.

#### Índices Compuestos Requeridos
Se reemplazan los índices existentes por índices compuestos con discriminador:
```sql
DROP INDEX IF EXISTS agent_presence_user_period_idx;
DROP INDEX IF EXISTS agent_presence_session_lookup_idx;

CREATE INDEX idx_agent_presence_ws_user_period 
    ON agent_presence_sessions(workspace_id, user_id, started_at, ended_at);

CREATE INDEX idx_agent_presence_ws_lookup 
    ON agent_presence_sessions(workspace_id, user_id, session_key, ended_at);

CREATE INDEX idx_agent_presence_ws_period 
    ON agent_presence_sessions(workspace_id, started_at, ended_at);
```

#### Aislamiento en Métricas y Disponibilidad
- **`AgentPresenceHistoryService::connectedSecondsByUser`:** Pasa a recibir obligatoriamente `$workspaceId` y filtra `where('workspace_id', $workspaceId)`.
- **`AgentMetricsService`:** Todos los reportes de tiempos de atención, disponibilidad y exportaciones CSV calculan únicamente las sesiones de presencia del workspace consultado.
- **Caché en Tiempo Real (`AgentAvailabilityService`):** La clave en Redis/Caché se acota por tenant: `agent_presence:{$workspaceId}:{$user->id}`.

---

## 5. Modelo RBAC y Transición Segura desde `role_user`

### 5.1. Diagnóstico del Estado Actual y Regla Cardinal Fail-Closed
En la versión actual de INTERA:
- La tabla `role_user` vincula `(user_id, role_id)` sin discriminación de empresa.
- Si un usuario tiene el rol `admin`, `User::hasPermission` le otorga capacidades administrativas globales en cualquier recurso que consulte.

**Regla Cardinal de Transición Fail-Closed:**  
`role_user` es una tabla heredada en proceso de extinción. **BAJO NINGUNA CIRCUNSTANCIA `role_user` PUEDE OTORGAR ROLES NI PERMISOS DENTRO DEL CONTEXTO DE UN WORKSPACE.**  
Si un usuario cuenta con un rol administrativo en `role_user`, pero en el workspace al que intenta acceder carece de registro en `workspace_user` o su membresía tiene `is_active = false`, el sistema responderá obligatoria e inmediatamente con **FAIL-CLOSED (`403 Forbidden` o `return false`)**.

### 5.2. Transición hacia Roles por Workspace

La migración de RBAC se ejecuta en 3 etapas controladas:

#### Etapa 1: Backfill Inicial (Fase 1)
- Cada usuario existente en `users` se inserta en `workspace_user` para el Workspace Inicial.
- **Caso: Usuario con múltiples roles en `role_user`:** Se determina su rol asignando el de mayor jerarquía en el sistema:
  `super_admin` > `admin` > `supervisor` > `flow_manager` > `agent`.
  *(En la auditoría de la base actual, el 100% de los usuarios de prueba y producción tienen exactamente un rol).*
- **Caso: Usuario sin rol en `role_user`:** Se le asigna el rol `agent` con `is_active = false` en `workspace_user`, impidiendo cualquier acceso hasta que un administrador lo configure formalmente.
- Los usuarios con rol `super_admin` reciben `is_platform_admin = true` en la tabla `users` (exclusivo para infraestructura de plataforma).

#### Etapa 2: Doble Lectura Controlada con Fail-Closed Estricto (Fase 2)
Durante la Fase 2, la resolución de roles y permisos en el modelo `User` opera bajo la siguiente regla estricta:

```php
public function hasRole(string|array $roles, ?string $workspaceId = null): bool
{
    $targetWorkspaceId = $workspaceId ?: app('current_workspace_id', null);

    // 1. Si existe contexto de workspace activo o solicitado:
    if ($targetWorkspaceId) {
        // Consultar EXCLUSIVAMENTE workspace_user.
        $membership = $this->workspaceMemberships()
            ->where('workspace_id', $targetWorkspaceId)
            ->where('is_active', true)
            ->first();

        // FAIL-CLOSED ABSOLUTO: Si no hay membresía activa, NUNCA evaluar role_user.
        if (! $membership) {
            return false;
        }

        $roleName = Role::where('id', $membership->role_id)->value('name');
        $roleList = is_array($roles) ? $roles : [$roles];

        return in_array($roleName, $roleList, true);
    }

    // 2. Administrador global de plataforma (fuera de rutas de workspace)
    if ($this->is_platform_admin) {
        return true;
    }

    // 3. Fallback de transición ÚNICAMENTE para comandos CLI de host o rutas agnósticas de workspace
    // (Ej. comando conexa:init-admin o verificación previa al primer login).
    return $this->roles->pluck('name')->intersect((array) $roles)->isNotEmpty();
}

public function hasPermission(string $permission, ?string $workspaceId = null): bool
{
    $targetWorkspaceId = $workspaceId ?: app('current_workspace_id', null);

    if ($targetWorkspaceId) {
        $membership = $this->workspaceMemberships()
            ->where('workspace_id', $targetWorkspaceId)
            ->where('is_active', true)
            ->first();

        // FAIL-CLOSED ABSOLUTO: Sin membresía activa, retorno inmediato en false.
        if (! $membership) {
            return false;
        }

        return $membership->role->permissions->contains('name', $permission);
    }

    if ($this->is_platform_admin) {
        return true;
    }

    return $this->roles()->with('permissions')->get()
        ->flatMap->permissions->pluck('name')->contains($permission);
}
```

#### Etapa 3: Desactivación Definitiva de `role_user` (Fase 3)
- Toda verificación de autorización en rutas de negocio (`/conversations`, `/channels`, `/flows`, `/teams`) opera **exclusivamente** sobre `workspace_user`.
- Se elimina cualquier dependencia de `role_user` en el código funcional.
- Un usuario puede ser `admin` en Workspace A y `agent` en Workspace B sin ninguna interferencia de permisos.

---

## 6. Blindaje contra Asociaciones Cruzadas entre Workspaces

Para evitar que administradores u operadores de un workspace puedan asociar, vincular o invadir recursos de otro workspace, se definen validaciones mandatorias en la capa de controladores y servicios:

### 6.1. Validación en `team_user` (Miembros de Equipos)
- **Vulnerabilidad:** `TeamController::validateTeam` recibía `member_ids` y los validaba con `exists:users,id`. Un admin de Workspace A podía incorporar a su equipo a un usuario de Workspace B.
- **Validación Mandatoria:** Al asociar miembros a un equipo, el backend debe validar que **cada usuario posea membresía activa en el workspace del equipo**:
  ```php
  $invalidUsers = DB::table('users')
      ->whereIn('id', $memberIds)
      ->whereNotExists(function ($query) use ($workspaceId) {
          $query->select(DB::raw(1))
              ->from('workspace_user')
              ->whereColumn('workspace_user.user_id', 'users.id')
              ->where('workspace_user.workspace_id', $workspaceId)
              ->where('workspace_user.is_active', true);
      })
      ->pluck('id');

  if ($invalidUsers->isNotEmpty()) {
      abort(422, 'Uno o más usuarios no poseen membresía activa en este espacio de trabajo.');
  }
  ```

### 6.2. Validación en `team_channels` (Canales Vinculados a Equipos)
- **Vulnerabilidad:** Si solo se valida `exists:channels,id`, un equipo de Workspace A podría adueñarse del enrutamiento de un canal perteneciente a Workspace B.
- **Validación Mandatoria:** Al vincular canales a un equipo, el backend debe validar que **todos los canales pertenezcan exactamente al mismo `workspace_id` del equipo**:
  ```php
  $invalidChannels = Channel::whereIn('id', $channelIds)
      ->where('workspace_id', '!=', $team->workspace_id)
      ->pluck('id');

  if ($invalidChannels->isNotEmpty()) {
      abort(422, 'Uno o más canales pertenecen a un espacio de trabajo diferente al del equipo.');
  }
  ```
  Se preserva la restricción de esquema `team_channels.channel_id UNIQUE` (un canal solo puede tener un equipo de atención asignado).

### 6.3. Validación en `channel_user` (Acceso Restringido a Canales)
- **Vulnerabilidad:** Al restringir el acceso a un canal privado, asignar un `user_id` de otro tenant otorgaría visibilidad indebida a chats ajenos.
- **Validación Mandatoria:** Al conceder acceso a canales vía `channel_user`, el backend valida que **cada usuario pertenezca activamente al workspace del canal**:
  ```php
  $invalidUsers = DB::table('users')
      ->whereIn('id', $userIds)
      ->whereNotExists(function ($query) use ($channel) {
          $query->select(DB::raw(1))
              ->from('workspace_user')
              ->whereColumn('workspace_user.user_id', 'users.id')
              ->where('workspace_user.workspace_id', $channel->workspace_id)
              ->where('workspace_user.is_active', true);
      })
      ->pluck('id');

  if ($invalidUsers->isNotEmpty()) {
      abort(422, 'Uno o más usuarios no pertenecen activamente al espacio de trabajo del canal.');
  }
  ```

### 6.4. Validación en `conversation_queue_entries` (Colas de Atención y SLA)
- **Vulnerabilidad:** Encolar una conversación de Workspace A en un equipo de Workspace B o asignarla a un agente de Workspace B alteraría métricas de SLA y permitiría fuga de chats.
- **Validación Mandatoria:**
  1. **Co-pertenencia de Conversación y Equipo:** Al crear la entrada en cola (`ConversationQueueService`), debe validarse:
     `$conversation->workspace_id === $team->workspace_id`. Si difieren, abortar con `422 Unprocessable Entity`.
  2. **Asignación de Agente (`assigned_user_id`):** Si la entrada se asigna a un agente, el usuario asignado DEBE ser miembro activo del workspace de la conversación (`workspace_user.where('user_id', $assignedUserId)->where('workspace_id', $conversation->workspace_id)->where('is_active', true)->exists()`).
  3. **Scoping de Consultas de SLA:** Toda consulta analítica sobre `conversation_queue_entries` debe unirse a `teams` o `conversations` y filtrar estrictamente por el `workspace_id` activo.

### 6.5. Validación y Co-pertenencia Estricta en `flow_simulation_sessions`
- **Vulnerabilidad:** El simulador de flujos crea sesiones interactivas conectando un usuario, un flujo, una versión, una conversación mock y una ejecución de prueba. Si estas entidades se cruzaran, un usuario de Workspace A podría ejecutar o inspeccionar la lógica de negocio y variables confidenciales de un flujo de Workspace B.
- **Regla de Co-pertenencia Estricta:**
  Al instanciar o avanzar una sesión en `flow_simulation_sessions`, el sistema **exige que las 5 entidades involucradas pertenezcan obligatoriamente al mismo workspace**:
  1. `user_id`: El usuario autenticado debe tener membresía activa en el workspace (`workspace_user.is_active = true`).
  2. `flow_id`: El flujo debe pertenecer a dicho workspace (`flows.workspace_id === current_workspace_id`).
  3. `flow_version_id`: La versión debe pertenecer al flujo del workspace (`flow_versions.flow_id === flows.id`).
  4. `conversation_id`: La conversación mock generada para la prueba debe crearse con `workspace_id = current_workspace_id`.
  5. `flow_execution_id`: La ejecución de simulación debe ligarse exclusivamente a esa conversación del workspace.
  Cualquier discrepancia aborta inmediatamente la simulación con rechazo fail-closed (`403 Forbidden` / `422 Unprocessable Entity`).

---

## 7. Estrategia de Backfill y Preservación de Datos

El backfill se ejecutará de forma transaccional e idempotente en la migración `2026_11_20_000003_backfill_initial_workspace_data.php`:

1. **Creación del Workspace Inicial:**
   ```php
   $workspaceId = (string) Str::uuid();
   DB::table('workspaces')->insert([
       'id' => $workspaceId,
       'name' => env('APP_INITIAL_WORKSPACE_NAME', 'Espacio Principal'),
       'slug' => env('APP_INITIAL_WORKSPACE_SLUG', 'principal'),
       'status' => 'active',
       'initial_owner_id' => DB::table('users')->orderBy('id')->value('id'),
       'created_at' => now(),
       'updated_at' => now(),
   ]);
   ```
2. **Backfill de Tablas Raíz:**
   Se actualizan en lotes de 500 registros:
   - `channels`: `UPDATE channels SET workspace_id = $workspaceId WHERE workspace_id IS NULL;`
   - `contacts`: `UPDATE contacts SET workspace_id = $workspaceId WHERE workspace_id IS NULL;`
   - `flows`: `UPDATE flows SET workspace_id = $workspaceId WHERE workspace_id IS NULL;`
   - `conversations`: `UPDATE conversations SET workspace_id = $workspaceId WHERE workspace_id IS NULL;`
   - `teams`: `UPDATE teams SET workspace_id = $workspaceId WHERE workspace_id IS NULL;`
   - `tags`: `UPDATE tags SET workspace_id = $workspaceId WHERE workspace_id IS NULL;`
   - `canned_responses`: `UPDATE canned_responses SET workspace_id = $workspaceId WHERE workspace_id IS NULL;`
   - `saved_funnels`: `UPDATE saved_funnels SET workspace_id = $workspaceId WHERE workspace_id IS NULL;`
   - `agent_presence_sessions`: `UPDATE agent_presence_sessions SET workspace_id = $workspaceId WHERE workspace_id IS NULL;`
3. **Estrategia para Históricos (`audit_logs`):**
   - En `audit_logs`, `workspace_id` se declara **nullable**.
   - Para logs cuyo `entity_type` es un recurso de workspace (`Flow`, `Channel`, `Conversation`, `Team`, `Tag`, `CannedResponse`, `SavedFunnel`), se actualiza `workspace_id = $workspaceId`.
   - Para logs de plataforma (`SystemSetting`, `auth.init_admin`, logins fallidos), `workspace_id` permanece en `NULL`, categorizándolos explícitamente como auditoría de infraestructura.
4. **Población de Membresías y Workspace Activo:**
   - Para cada usuario existente se crea su fila en `workspace_user` con su rol homólogo.
   - Se actualiza `users.active_workspace_id = $workspaceId`.

---

## 8. Estrategia de Rollback Segura y Ventanas de Reversión

El rollback de migraciones en entornos de producción debe proteger los datos contra pérdidas de asociación:

### 8.1. Ventana de Rollback Seguro (Single-Tenant Window)
- **Condición:** El sistema solo contiene el Workspace Inicial (`workspaces.count() == 1`).
- **Comportamiento:** Reversible limpiamente. Las rutinas `down()` eliminan las columnas `workspace_id` y las restricciones compuestas, restaurando el estado previo sin pérdida de datos.

### 8.2. Protección contra Rollback Destructivo (Multi-Tenant Window)
- **Condición:** Se han creado dos o más workspaces (`workspaces.count() > 1`) y existen datos asociados a diferentes tenants.
- **Mecanismo de Guardia:**
  En el método `down()` de la migración:
  ```php
  if (DB::table('workspaces')->count() > 1) {
      throw new \RuntimeException(
          'ABORTADO: Existen múltiples workspaces con datos activos. ' .
          'Un rollback directo mezclaría irrevocablemente los datos de diferentes empresas. ' .
          'Ejecute un backup completo y consolide o archive los workspaces secundarios antes de revertir.'
      );
  }
  ```
- **Procedimiento de Rollback:**
  1. Ejecutar backup obligatorio: `./scripts/backup.sh`.
  2. Exportar o archivar datos de tenants secundarios.
  3. Ejecutar `php artisan migrate:rollback --step=4`.

---

## 9. Resolución de Workspace por Tipo de Entrada

### 9.1. Peticiones HTTP Autenticadas (Panel de Control y Agentes)
- **Middleware:** `ResolveTenantContext`.
- **Flujo:**
  1. Extrae `$user = $request->user()`.
  2. Lee el workspace activo: si el request envía `X-Workspace-Id`, valida:
     `workspace_user.where(user_id, $user->id).where(workspace_id, $headerId).where(is_active, true)`.
  3. Si no hay header, toma `$user->active_workspace_id`.
  4. Si no es válido: rechaza con `403 Forbidden` (`code: 'workspace_unauthorized'`).
  5. Inyecta en el Service Container: `app()->instance('current_workspace', $workspace)`.

### 9.2. Webhooks Públicos de Meta (WhatsApp, Messenger, Instagram)
- **Rutas:** `/api/v1/public/webhooks/{provider}/{channel}`
- **Resolución:**
  1. `{channel}` es el UUID del canal.
  2. Carga `$channel = Channel::where('id', $channelId)->where('is_active', true)->firstOrFail()`.
  3. Extrae `$channel->workspace_id`.
  4. Valida HMAC-SHA256 con el `app_secret` del canal.
  5. Inicia el procesamiento con el `workspace_id` del canal.

### 9.3. Webhooks Públicos de Telegram
- **Ruta:** `/api/v1/public/webhooks/telegram/{channel}`
- **Resolución:**
  1. `{channel}` es el UUID del canal.
  2. Valida token secreto `X-Telegram-Bot-Api-Secret-Token`.
  3. Extrae `$channel->workspace_id`.
  4. Deduplicación aislada en caché: `dedupe_webhook_tg_{$channel->id}_{$updateId}`.

### 9.4. Webchat Embebible
- **Rutas:** `/api/v1/public/webchat/{publicKey}/embed` y `/sessions`
- **Resolución:**
  1. `{publicKey}` consulta `webchat_channel_settings` -> `$settings->channel` -> `$channel->workspace_id`.
  2. El widget JavaScript no recibe ni conoce `workspace_id`.
  3. Sesiones de visitante validadas por `AuthenticateWebchatVisitor` mediante token hash asociado al `channel_id`.

### 9.5. Comandos CLI y Scheduler
- **Comandos de Scheduler:** (`conversations:check-inactivity`, `webchat:sessions:prune`).
  Iteran canal por canal o workspace por workspace de manera aislada.
- **Comandos de Exportación/Importación:** (`conexa:export-flow`, `conexa:import-flow`).
  Se añade el parámetro obligatorio `--workspace={id|slug}`.

---

## 10. Jobs, Colas y Reintentos

INTERA cuenta con exactamente **4 Jobs** encolados:

| Job | Payload Serializado | Modelo Raíz | Validación de Workspace | Comportamiento si Workspace está Inactivo/Suspendido | Claves de Bloqueo Atómico (Locks) | Política de Reintentos |
|---|---|---|---|---|---|---|
| `ProcessWhatsAppOutboxJob` | `conversationId` (UUID) | `Conversation` | Carga `$conversation->workspace_id` y verifica `$workspace->status === 'active'` | Cancela el despacho, libera el cerrojo y registra log de advertencia | `outbox_lock_{$conversation->id}` (15s) | `tries = 2, timeout = 30s` |
| `ProcessWhatsAppMediaAttachmentJob` | `messageId` (UUID), `channelId` (UUID) | `Message` y `Channel` | Valida que `$message->conversation->workspace_id === $channel->workspace_id` y que el WS esté activo | Omite la descarga y marca el adjunto como fallido | N/A (Idempotencia por `attachment.status == 'ready'`) | `tries = 3, backoff = [5, 15, 60], timeout = 60s` |
| `PrewarmFlowMediaJob` | `flowId` (UUID), `versionId` (UUID), `channelId` (UUID) | `Flow` y `Channel` | Valida que `$flow->workspace_id === $channel->workspace_id` | Aborta el prewarm sin lanzar excepción para no saturar colas | N/A | `tries = 2, timeout = 120s` |
| `PrewarmWhatsAppMediaJob` | `channelId`, `flowId`, `flowVersionId`, `nodeKey` | `Channel`, `Flow`, `FlowVersion` | Valida coincidencia de `workspace_id` entre canal y flujo | Aborta la subida del archivo a Meta | `whatsapp_prewarm_lock_{$channel->id}_{$fingerprint}` (60s) | `tries = 3, backoff = [10, 30, 90], timeout = 60s` |

---

## 11. Webhooks Públicos y Contratos Externos

Para garantizar que Meta y Telegram no requieran reconfiguraciones de URLs en sus portales de desarrolladores:

1. **Rutas Públicas Intactas:**
   - `/api/v1/public/webhooks/whatsapp/{channel}`
   - `/api/v1/public/webhooks/messenger/{channel}`
   - `/api/v1/public/webhooks/instagram/{channel}`
   - `/api/v1/public/webhooks/telegram/{channel}`
2. **Ingesta Aislada en `ConversationIngressService`:**
   - Actualmente `resolveContact` busca contactos globalmente por teléfono o identificador externo.
   - **Corrección Mandatoria:** `resolveContact` debe recibir `$channel` y buscar exclusivamente dentro de su workspace:
     ```php
     Contact::where('workspace_id', $channel->workspace_id)
         ->where(fn ($q) => $q->where('phone', $phone)->orWhere('external_identifier', $id))
         ->first();
     ```
   - Al crear una nueva conversación: `Conversation::create(['workspace_id' => $channel->workspace_id, ...])`.

---

## 12. Reverb y Eventos en Tiempo Real

### 12.1. Eventos de Broadcasting Auditados
- `ConversationCreated`
- `ConversationUpdated`
- `ConversationStatusChanged`
- `ConversationAssigned`
- `ConversationClosed`
- `ConversationWaitingAgent`
- `MessageCreated`
- `MessageStatusUpdated`
- `MessageReactionUpdated`
- `ContactUpdated`

### 12.2. Blindaje de Canales WebSocket en `routes/channels.php`

1. **Reemplazo del Canal Global `'inbox'`:**  
   Pasa a ser: `'workspace.{workspaceId}.inbox'`
   ```php
   Broadcast::channel('workspace.{workspaceId}.inbox', function (User $user, $workspaceId) {
       if (! $user->is_active) {
           return false;
       }
       return $user->belongsToWorkspace($workspaceId)
           && ($user->hasPermission('conversations.view_assigned') || $user->hasPermission('conversations.view_all'));
   });
   ```
2. **Canal de Conversación `'conversation.{conversationId}'`:**
   ```php
   Broadcast::channel('conversation.{conversationId}', function (User $user, $conversationId) {
       $conversation = Conversation::find($conversationId);
       if (! $conversation || ! $user->is_active) {
           return false;
       }
       // Verificar pertenencia al workspace de la conversación
       if (! $user->belongsToWorkspace($conversation->workspace_id)) {
           return false;
       }
       return $user->canAccessConversation($conversation);
   });
   ```
3. **Cambio de Workspace en Frontend:**
   Al cambiar de workspace activo, el cliente frontend se desuscribe de `workspace.{anterior}.inbox` y se suscribe al nuevo canal autorizado `workspace.{nuevo}.inbox`.

---

## 13. Analítica, Exportaciones y Archivos

### 13.1. Scoping de Servicios de Analítica
Los siguientes servicios en `backend/app/Services/Analytics/` deben recibir obligatoriamente el `$workspaceId` activo en todas sus consultas:
- `RealtimeAnalyticsService`: Conteo de conversaciones activas, mensajes en la última hora, tiempos de respuesta de colas.
- `SessionAnalyticsService`: Agregaciones de visitantes webchat, exportación CSV de sesiones.
- `AgentMetricsService`: Tiempos de atención, horas de presencia de agentes, KPIs y exportaciones CSV.
- `FlowFunnelAnalyticsService`: Embudos de conversión de flujos.

Si se proporciona un `channel_id` o `tag_id` opcional, el sistema valida que pertenezca al workspace activo antes de filtrar.

### 13.2. Almacenamiento de Archivos y Medios
- **Directorio de Almacenamiento:**
  En `AttachmentUploadService` y `WhatsAppMediaService`, las rutas de almacenamiento pasarán de carpetas globales a carpetas con prefijo de tenant:
  `workspaces/{workspaceId}/attachments/{año}/{mes}/{uuid}.{ext}`  
  `workspaces/{workspaceId}/flows/{flowId}/{uuid}.{ext}`
- **Descargas Protegidas:**
  La descarga o visualización de adjuntos en la API validará la membresía del usuario en el workspace del mensaje correspondiente.

---

## 14. Matriz de Riesgos y Puntos de Fuga Identificados

| # | Vector de Riesgo | Archivo / Método | Vulnerabilidad en Single-Tenant | Solución Multitenant |
|---|---|---|---|---|
| **R-01** | Fuga de chats en vivo | `routes/channels.php` (`inbox`) | Agente de WS-B recibe eventos en tiempo real de WS-A | Canal scoped a `workspace.{id}.inbox` con chequeo de membresía |
| **R-02** | Probing en Route Model Binding | `ConversationController@show` | Supervisor consulta cualquier UUID de conversación | 404 inmediato si `conversation.workspace_id != current_workspace_id` |
| **R-03** | Invasión de contactos | `ConversationIngressService::resolveContact` | Teléfono idéntico vincula chats a contacto de otra empresa | Búsqueda y creación de contactos con scope `where('workspace_id', ...)` |
| **R-04** | Colisión de nombres de equipos | `TeamController::validateTeam` | `Rule::unique('teams', 'name')` global | `Rule::unique('teams', 'name')->where('workspace_id', $wsId)` |
| **R-05** | Colisión de slugs de etiquetas | `TagController::store` | `Rule::unique('tags', 'slug')` global | `Rule::unique('tags', 'slug')->where('workspace_id', $wsId)` |
| **R-06** | Adopción cruzada de canales en equipos | `TeamController::validateTeam` | `exists:channels,id` sin validar workspace del canal | Validación de que cada `channel_id` pertenezca al workspace activo |
| **R-07** | Clonación cruzada de flujos | `FlowController@createVersion` | `FlowVersion::findOrFail($from_version_id)` global | Verificar que la versión origen pertenezca a un flujo del workspace |
| **R-08** | Manipulación de embudos ajenos | `SavedFunnelController@update`, `destroy` | `SavedFunnel::find($id)` sin scope | Scope obligatorio `where('workspace_id', ...)` |
| **R-09** | Enlace de flujos de otro tenant a canal | `ChannelController@store`, `update` | `Flow::find($entry_flow_id)` global | Validar que `entry_flow_id` pertenezca al workspace del canal |
| **R-10** | Fuga en agregaciones de analítica | `RealtimeAnalyticsService` | `Conversation::count()` cuenta datos de todas las empresas | Cláusula `where('workspace_id', ...)` incondicional |
| **R-11** | Simulación cruzada de flujos | `FlowSimulationService::startSession` | Simulador permite version/conversation de tenants distintos | Co-pertenencia estricta: usuario, flujo, versión, conv y ejecución del mismo WS |
| **R-12** | Contaminación de horas de presencia | `AgentPresenceHistoryService` | Sesiones de presencia acumuladas sin distinguir workspace | Directo `workspace_id` en sesiones, corte al cambiar WS y métricas aisladas |
| **R-13** | Asociación cruzada de miembros a equipos | `TeamController::validateTeam` | `exists:users,id` permite asociar agentes de otra empresa | Validación de membresía activa en `workspace_user` para cada `member_id` |
| **R-14** | Desvío de colas y SLA entre empresas | `ConversationQueueService` | Encolar conversación en equipo de otro workspace | Validación obligatoria: `conversation.workspace_id === team.workspace_id` |
| **R-15** | Acceso a canal privado por usuario ajeno | `ChannelController::syncUsers` | `exists:users,id` permite dar permisos a usuarios de otro WS | Validación de membresía activa en `workspace_user` para cada agente |
| **R-16** | Fuga de permisos globales por fallback | `User::hasRole` / `hasPermission` | Usuario con `admin` en `role_user` hereda acceso en tenant | Fail-closed: si membresía en tenant está inactiva o ausente, rechazo inmediato |

---

## 15. Matriz de Pruebas de Aislamiento (Tenant Alpha vs Tenant Beta)

| Área Funcional | Escenario de Prueba | Actor | Acción | Resultado Esperado | HTTP |
|---|---|---|---|---|---|
| **Autenticación** | Cambio de workspace no autorizado | Usuario Alpha | `POST /api/v1/workspaces/switch` con ID de Beta | 403 Forbidden. Contexto no muta. | `403` |
| **Canales** | Listado de canales | Admin Alpha | `GET /api/v1/channels` | Retorna solo canales de Alpha. | `200` |
| **Canales** | Probing directo por ID | Admin Alpha | `GET /api/v1/channels/{id_canal_beta}` | No encontrado. | `404` |
| **Canales / Acceso**| Asignar usuario ajeno a canal | Admin Alpha | `POST /api/v1/channels/{id}/users` con ID de usuario Beta | Rechazado: usuario no pertenece al workspace. | `422` |
| **Flujos** | Listado de flujos | Diseñador Alpha | `GET /api/v1/flows` | Retorna solo flujos de Alpha. | `200` |
| **Flujos** | Probing de versión | Diseñador Alpha | `GET /api/v1/flows/{id_flujo_beta}` | No encontrado. | `404` |
| **Flujos / Simulación**| Simular flujo con conversación ajena | Diseñador Alpha | `POST /api/v1/flows/{id}/simulate` con conv de Beta | Rechazado: entidades de workspaces distintos. | `422` |
| **Contactos** | Homonimia de teléfono | Webhook Meta | Mismo teléfono escribe a canal Alpha y canal Beta | Se crean 2 contactos independientes aislados por `workspace_id`. | `200` |
| **Inbox** | Listado de conversaciones | Agente Alpha | `GET /api/v1/conversations?status=all` | Solo conversaciones de canales de Alpha. | `200` |
| **Inbox** | Probing de conversación | Supervisor Alpha | `GET /api/v1/conversations/{id_conv_beta}` | Fail-closed: 404 Not Found. | `404` |
| **Inbox** | Envío de mensaje cruzado | Agente Alpha | `POST /api/v1/conversations/{id_conv_beta}/messages`| Bloqueo total de escritura. | `404` |
| **Equipos** | Reclamación en cola | Agente Alpha | `POST /api/v1/conversations/{id_conv_beta}/claim` | Rechazado con 404. | `404` |
| **Equipos** | Asignar miembro ajeno | Admin Alpha | `POST /api/v1/teams` con `member_ids` de Beta | Rechazado con 422: usuario no pertenece al workspace. | `422` |
| **Equipos** | Asignar canal ajeno | Admin Alpha | `POST /api/v1/teams` con `channel_ids` de Beta | Rechazado con 422: canal de otro workspace. | `422` |
| **Equipos** | Nombres duplicados | Admin Alpha y Beta | Ambos crean equipo `"Ventas"` | Ambos se crean con éxito gracias a `(workspace_id, name)`. | `201` |
| **Colas / SLA** | Encolar conversación en equipo ajeno | Worker / Dispatcher | Asignar conversación de Alpha a equipo de Beta | Excepción de integridad y rechazo fail-closed. | `422` |
| **Etiquetas** | Slugs duplicados | Admin Alpha y Beta | Ambos crean etiqueta `"vip"` (`slug: vip`) | Coexisten de forma independiente por tenant. | `201` |
| **Respuestas** | Shortcuts duplicados | Agente Alpha y Beta | Ambos crean respuesta con shortcut `banco` | Cada operador ve solo la de su empresa. | `201` |
| **Presencia** | Sesión multi-workspace | Agente Alpha/Beta | Opera en Alpha y consulta métricas en Beta | Horas conectadas se computan solo en Alpha; 0 en Beta. | `200` |
| **Analítica** | Métricas en tiempo real | Supervisor Alpha | `GET /api/v1/analytics/realtime` | Contadores reflejan solo conversaciones de Alpha. | `200` |
| **Analítica** | Exportación CSV | Supervisor Alpha | `GET /api/v1/analytics/agents/export-summary` | El CSV contiene solo agentes y datos de Alpha. | `200` |
| **Realtime** | WebSocket Broadcast | Agente Beta | Escucha `workspace.beta.inbox` mientras ocurre chat en Alpha | Cero paquetes recibidos por Beta. | Silencio |
| **Webhooks** | Ingesta Meta | Meta Webhook | Llega mensaje a canal de Alpha | Genera conversación en Alpha sin afectar Beta. | `200` |
| **Jobs** | Outbox WhatsApp | Worker Redis | Despacha outbox de conversación de Alpha | Valida canal y workspace de Alpha antes del envío. | `200` |

---

## 16. Dependencias y Estado de Pruebas Actuales

Auditoría detallada de las herramientas y suites de verificación en el repositorio:

| Comando Exacto Ejecutado | Código de Salida | Primer Error / Resultado Relevante | Naturaleza del Fallo | ¿Preexistente en `origin/main`? | ¿Bloquea la Fase 1? |
|---|---|---|---|---|---|
| `docker ps` | `1` | `Cannot connect to the Docker daemon at unix:///Users/marin084/.docker/run/docker.sock.` | Entorno: Docker Desktop apagado en el host Mac | Sí | No para Fase 1 (backend SQLite local funciona). Sí para E2E Playwright. |
| `cd backend && ./vendor/bin/phpunit` | `1` | `Tests\Feature\InboxOperabilityTest::test_sending_messages_to_closed_conversation_is_blocked_for_all_actors` (Expected 422, received 201). Total: 409 tests, 2 fallos. | Código: Regresión introducida en commit `4b3f24f` de closed-chat follow-ups | Sí | No bloquea el diseño ni las migraciones de la Fase 1. |
| `cd backend && php artisan test` | `1` | Mismos 2 fallos anteriores + advertencias de deprecación menores de PHP 8.5 en runner | Código / Entorno | Sí | No bloquea Fase 1. |
| `cd backend && ./vendor/bin/pint --test` | `1` | 56 archivos requieren ajustes menores de formato (espaciado, llaves, imports) | Formato de código en `origin/main` | Sí | No bloquea Fase 1. |
| `npm --prefix frontend test -- --run` | `1` | `ExperimentalWarning: localStorage is not available because --localstorage-file was not provided.` (Node 26 JSDOM) | Entorno / Dependencia: Node v26 vs JSDOM Vitest | Sí | No bloquea Fase 1 (Fase 1 es solo backend de datos). |
| `npm --prefix frontend run build` | `0` | Compilación exitosa de Vite, React y Webchat loader | Correcto | N/A | No. |
| `npm --prefix frontend run lint` | `0` | 0 errores (18 advertencias de hooks de react) | Correcto | N/A | No. |
| `ls -la backend/app/Policies` | `1` | `No such file or directory` | Arquitectura: INTERA usa middleware y métodos de controlador, no policies | N/A | No. |

---

## 17. Decisiones Arquitectónicas Pendientes

Las siguientes **6 decisiones** requieren confirmación explícita de los interesados antes de iniciar la Fase 1:

1. **Denominación y Slug del Workspace Inicial:**
   - *Propuesta:* `name: "Espacio Principal"`, `slug: "principal"`. (Configurable mediante variables de entorno en `.env`: `APP_INITIAL_WORKSPACE_NAME`, `APP_INITIAL_WORKSPACE_SLUG`).
2. **Estrategia de Persistencia de `messages.workspace_id`:**
   - *Decisión recomendada:* Mantener `messages` heredando el workspace vía `conversation_id`. La tabla ya cuenta con un índice compuesto monotónico `(conversation_id, sent_at, id)`. Evita redundancia, desperdicio de almacenamiento y mutaciones masivas en una tabla de alta volumetría.
3. **Estrategia de Aislamiento para `agent_presence_sessions`:**
   - *Decisión recomendada:* Incorporar columna directa `workspace_id UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE`. Permite indexar, consultar y particionar el tiempo de trabajo por empresa directamente sin joins sobre membresías, cerrando la sesión activa al alternar de workspace.
4. **Flujo de Usuario con Múltiples Workspaces en Login (`/`):**
   - *Decisión recomendada:* Conectar automáticamente al último `active_workspace_id` registrado, permitiendo conmutar mediante el selector de empresa en el menú superior de la interfaz.
5. **Aislamiento Total de Clientes Finales (`contacts`):**
   - *Decisión recomendada:* Confirmar que el mismo número de teléfono o identificador externo en dos empresas distintas crea dos entidades `Contact` independientes, garantizando estricta privacidad y separación de notas y metadatos.
6. **Alcance y Límites de `is_platform_admin`:**
   - *Decisión recomendada:* Un `is_platform_admin` tiene acceso exclusivo a la consola global de servidores, métricas de infraestructura y aprovisionamiento de tenants, pero **NO** tiene visibilidad automática sobre las conversaciones ni clientes de ningún workspace a menos que cuente con membresía activa o un modo de impersonación auditado.

---

## 18. Criterios de Aprobación de la Fase 0

Para considerar formally aprobada la **Fase 0 (Auditoría y Diseño Arquitectónico)**, se deben verificar los siguientes 6 criterios obligatorios:

1. **Inventario Consistente:** Separación inequívoca entre las **43 tablas preexistentes** auditadas del sistema y las **2 tablas nuevas propuestas** (`workspaces` y `workspace_user`), sin ambigüedades numéricas, filas duplicadas ni supuestos contradictorios.
2. **Estrategia de Presencia Definida:** Especificación técnica completa para aislar `agent_presence_sessions` cuando un usuario pertenezca a varios workspaces (`workspace_id` directo, ciclo de vida al conmutar de tenant, backfill transaccional, índices compuestos dedicados y scoping en métricas de analítica y disponibilidad).
3. **RBAC Fail-Closed:** Garantía formal y código de diseño demostrando que la tabla legada `role_user` jamás concederá permisos dentro de un workspace si la membresía en `workspace_user` está ausente o inactiva (`is_active = false`), asegurando fail-closed absoluto durante toda la transición.
4. **Relaciones Cruzadas Protegidas:** Reglas de validación exhaustivas a nivel de backend y base de datos que impiden asociaciones entre distintos workspaces en `team_user`, `team_channels`, `channel_user`, `conversation_queue_entries` y co-pertenencia estricta en `flow_simulation_sessions` (usuario, flujo, versión, conversación y ejecución en el mismo tenant).
5. **Pruebas y Fallos Documentados:** Registro riguroso y transparente del estado real de las suites de prueba en el repositorio (PHPUnit, Vitest, Pint, Linters y Docker), clasificando con exactitud los fallos preexistentes en `origin/main` y confirmando la viabilidad técnica de las pruebas de base de datos.
6. **Decisiones Pendientes Enumeradas:** Formulación exhaustiva de las 6 decisiones arquitectónicas y de producto que requieren confirmación humana antes de ejecutar cualquier migración o cambio en base de datos.

---

## 19. Evaluación de Bloqueos y Conclusión de la Fase 0

> ### Conclusión Precisa de la Fase 0:
> **La implementación puede comenzar únicamente después de aprobar las decisiones pendientes y resolver estas inconsistencias documentales.**

No se iniciará la Fase 1 ni se modificará el esquema de la base de datos hasta que los criterios de aprobación de la Sección 18 estén formalmente ratificados y las 6 decisiones de la Sección 17 hayan recibido confirmación explícita por parte del equipo líder de desarrollo y producto.

---

## 20. Criterios de Entrada para la Fase 1

Para dar inicio a la **Fase 1 (Modelado de Datos y Migración Inicial)** se debe verificar:
1. Aprobación humana de este documento de diseño técnico corregido y de las decisiones pendientes de la Sección 17.
2. Confirmación de la rama `feat/multitenancy-phase-1-schema` creada directamente desde `origin/main`.
3. Verificación de que el entorno local de desarrollo ejecuta migraciones de prueba sobre base de datos SQLite sin interacción manual.
4. Procedimiento de snapshot/backup listo para ejecutarse antes de cualquier migración.

---

## 21. Criterios de Salida de la Fase 1

La Fase 1 se considerará exitosamente completada únicamente cuando se verifiquen todos los siguientes puntos:
1. Se hayan creado las 4 migraciones atómicas y reversibles del esquema (`workspaces`, `workspace_user`, adición de `workspace_id`, backfill de datos y aplicación de restricciones compuestas).
2. El backfill asigne el 100% de los datos preexistentes al Workspace Inicial sin pérdida de ninguna fila.
3. Se hayan creado los modelos Eloquent `Workspace` y `WorkspaceUser` con sus relaciones completas.
4. Los modelos existentes (`Channel`, `Flow`, `Contact`, `Conversation`, `Team`, `Tag`, `CannedResponse`, `SavedFunnel`, `AuditLog`, `AgentPresenceSession`) incluyan la relación `workspace()` y el campo `workspace_id` en `$fillable`.
5. Se ejecuten exitosamente las pruebas unitarias y de base de datos de la Fase 1.
6. La migración inversa (`migrate:rollback --step=4`) devuelva la base de datos exactamente a su estado previo sin errores.
7. Se genere un Pull Request exclusivo para la Fase 1, sin incluir cambios de middleware, controladores ni frontend.
