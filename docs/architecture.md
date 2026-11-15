# Conexa — Arquitectura del Núcleo y Dominio Conversacional (Fase 1)

## 1. Visión General del Producto

**Conexa** es una plataforma conversacional tipo Botmaker orientada a ser distribuida e instalada como software dedicado en la infraestructura privada de cada cliente (**single-tenant por instalación**).

### Principios Fundamentales:
1. **Aislamiento Single-Tenant**: Cada cliente cuenta con su propia instancia dedicada de aplicación y base de datos relacional. No existe lógica de particionamiento multitenant compartida.
2. **Desacoplamiento de Canales**: Los conceptos de canal, contacto, conversación y mensaje son agnósticos respecto al proveedor subyacente. Webchat es el canal inicial de la V1, pero el modelo de datos y las interfaces están preparados para WhatsApp, Facebook Messenger, Instagram, etc.
3. **Inmutabilidad y Versionado de Flujos**: Ningún flujo en producción es alterado destructivamente en caliente. Las conversaciones en curso continúan ejecutando la versión exacta con la que iniciaron.
4. **Seguridad y Control de Acceso Granular (RBAC)**: Autorización basada en permisos atómicos en el backend, no checks superficiales de strings en el frontend.

---

## 2. Modelo de Dominio Conversacional

El ciclo de interacción desacopla al emisor de la plataforma externa mediante la siguiente jerarquía:

```
[Channel] (Webchat / WhatsApp / Messenger / Instagram)
    │
    ▼
[Contact] (Identidad unificada del usuario final)
    │
    ▼
[Conversation] (Sesión viva con estado, asignación y flujo activo)
    │
    ├── [ConversationParticipant] (Auditoría de quién participa: Contacto o Agente)
    ├── [ConversationAssignment] (Historial y trazabilidad de asignación a agentes)
    └── [Message] (Contenido unificado, dirección, emisor y tipo)
```

### 2.1. Abstracción del Canal (`Channel`)
- Representa el punto de contacto por el cual ingresan y egresan las conversaciones.
- Contiene un campo `configuration` tipo JSONB para almacenar parámetros específicos del proveedor (por ejemplo, tokens, IDs de integración, webhooks o paletas de colores de Webchat).
- **¿Por qué es agnóstico del proveedor?**
  - Cada conector externo simplemente normaliza los payloads entrantes a entidades `Contact` y `Message`, y transforma los mensajes salientes a la API del canal correspondiente.
  - El motor conversacional, el evaluador de flujos y la bandeja de atención interactúan exclusivamente con el modelo interno `Conversation` y `Message`, sin conocer detalles de protocolo HTTP, Webhooks de Meta o WebSockets de Webchat.

### 2.2. Contacto (`Contact`)
- Representa al usuario final humano que conversa.
- Dispone de identificadores opcionales (`display_name`, `email`, `phone`, `external_identifier`) y metadatos JSONB extensibles. No asume que todo usuario tiene email o teléfono, adaptándose a identificadores anónimos o IDs de mensajería (ej. `wa_id`, `psid`).

### 2.3. Conversación (`Conversation`)
- Contenedor de la interacción viva entre el Contacto y el Sistema/Agente.
- Estados controlados (`ConversationStatus`):
  - `bot`: Conversación gestionada por el árbol de automatización.
  - `waiting_agent`: Solicitud de escalamiento a humano en cola de espera.
  - `assigned`: Asignada a un agente humano para atención en vivo.
  - `closed`: Conversación resuelta y finalizada.
- Registra `started_at`, `last_message_at` y `closed_at`.

### 2.4. Mensaje (`Message`)
- Entidad central de intercambio de información.
- Atributos clave:
  - `direction`: `inbound` (entrante del contacto) u `outbound` (saliente del bot/agente/sistema).
  - `sender_type`: `contact`, `bot`, `agent`, `system`.
  - `message_type`: `text`, `system` (preparado para `image`, `video`, `audio`, `document`, `location`, `template`).
  - `external_message_id`: Clave indexada para soportar idempotencia contra reintentos de webhooks de proveedores.
  - `metadata`: JSONB para trazas técnicas, payload de botones, o estados de entrega.

### 2.5. Trazabilidad de Asignación (`ConversationAssignment`)
- Permite registrar el ciclo de vida completo de cada atención humana:
  - Momento de asignación (`assigned_at`).
  - Agente asignado (`assigned_user_id`).
  - Usuario supervisor o sistema que ejecutó la asignación (`assigned_by_user_id`).
  - Motivo de asignación (`reason`).
  - Momento de desasignación o reasignación (`unassigned_at`).
- Fundamental para métricas de tiempo de primera respuesta (FRT), tiempo de resolución y SLAs.

---

## 3. Arquitectura del Motor de Flujos

El sistema de flujos se basa en grafos dirigidos inmutables y versionados:

```
[Flow] (Contenedor maestro del flujo)
   │
   ▼
[FlowVersion] (Instancia inmutable: v1, v2, v3... - Estado: draft | published | archived)
   │
   ├── [FlowNode] (Nodos: start, message, question, condition, transfer_to_agent, end)
   │
   └── [FlowEdge] (Conexiones explícitas: source_node_key → target_node_key)
```

### 3.1. Estrategia de Versionado
1. **Flujo (`Flow`)**: Define el nombre, aplicabilidad de canales y apunta a la versión activa (`active_version_id`).
2. **Versión de Flujo (`FlowVersion`)**:
   - Cada edición se realiza en una versión con estado `draft`.
   - Una vez validada y publicada, su estado pasa a `published` y no puede modificarse directamente.
   - Las conversaciones que ingresan se asocian a la versión activa del momento. Si una conversación está en curso, continúa su ejecución sobre la versión original asignada.

### 3.2. Tipos de Nodos Base
- `start`: Punto de entrada único del flujo conversacional.
- `message`: Emite un mensaje de texto o multimedia saliente al contacto.
- `question`: Solicita una entrada al usuario y aguarda su respuesta.
- `condition`: Bifurca el flujo según reglas lógicas o respuestas del contacto.
- `transfer_to_agent`: Dispara el handoff bot → humano (cambia el estado de la conversación a `waiting_agent`).
- `end`: Marca la conclusión del árbol automatizado.

### 3.3. Validación Estructural (`FlowValidator`)
Antes de permitir la publicación de una versión, el servicio `FlowValidator` verifica rigurosamente:
- Existencia de exactamente un único nodo `start`.
- Que ningún nodo `start` tenga conexiones entrantes.
- Que ningún nodo `end` tenga conexiones salientes.
- Que todos los `source_node_key` y `target_node_key` existan dentro de la versión.
- Que todos los nodos sean alcanzables desde el nodo inicial mediante un recorrido en anchura (BFS).

---

## 4. Seguridad, Autenticación y RBAC

### 4.1. Autenticación y Sesiones
- **Mecanismo SPA**: **Laravel Sanctum SPA** basado en sesión con cookies seguras `HttpOnly`, protección CSRF (`XSRF-TOKEN`), y requests autenticadas mediante `credentials: 'include'`.
- **Ausencia de Tokens en JS**: El panel administrativo first-party no almacena tokens Bearer en `localStorage` o `sessionStorage`. El estado de identidad reside exclusivamente en memoria React.
- **Personal Access Tokens (PAT)**: La tabla `personal_access_tokens` permanece disponible como soporte subyacente de Sanctum para eventuales integraciones API externas M2M en fases futuras, pero el panel administrativo y los usuarios humanos operan exclusivamente mediante el esquema de sesión SPA seguro.
- **Contraseñas**: Hasheadas mediante **Bcrypt** con costo adecuado; longitud mínima obligatoria de **12 caracteres**. Entrada interactiva segura sin echo y confirmación obligatoria en CLI (`conexa:init-admin`).
- **Rate Limiting y Fuerza Bruta**: Bloqueo progresivo tras 5 intentos fallidos consecutivos por IP y correo.
- **Enumeración de usuarios**: Mensajes de credenciales genéricos ("Credenciales inválidas") tanto si el email no existe como si la contraseña es errónea.
- **Usuarios Inactivos**: Si `is_active` es `false`, la autenticación es rechazada y cualquier sesión activa queda invalidada.

### 4.2. Configuración CORS y Credenciales
- **Rutas cubiertas**: `api/*`, `sanctum/csrf-cookie`, `health*`.
- **Soporte de Credenciales**: `'supports_credentials' => true`, permitiendo intercambio seguro de cookies de sesión y tokens CSRF.
- **Orígenes Permitidos**: Lista explícita (`FRONTEND_URL`, `http://localhost:3000`, `http://localhost:5173`, `127.0.0.1`). No se utiliza comodín `*`, cumpliendo la restricción estricta de la especificación W3C para peticiones con credenciales.
- **Estrategia Producción**: La variable `FRONTEND_URL` en `.env` establece el origen exacto del frontend en la infraestructura dedicada del cliente.

### 4.3. Matriz RBAC
La autorización se evalúa mediante permisos individuales en middleware:

| Permiso | Super Admin | Flow Manager | Supervisor | Agent |
| :--- | :---: | :---: | :---: | :---: |
| `users.view`, `users.manage` | ✅ | ❌ | ❌ | ❌ |
| `roles.view`, `roles.manage` | ✅ | ❌ | ❌ | ❌ |
| `channels.view` | ✅ | ✅ | ❌ | ❌ |
| `channels.manage` | ✅ | ❌ | ❌ | ❌ |
| `flows.view` | ✅ | ✅ | ❌ | ❌ |
| `flows.create`, `flows.edit`, `flows.publish` | ✅ | ✅ | ❌ | ❌ |
| `conversations.view_all` | ✅ | ❌ | ✅ | ❌ |
| `conversations.view_assigned` | ✅ | ❌ | ✅ | ✅ |
| `conversations.reply` | ✅ | ❌ | ✅ | ✅ |
| `conversations.assign` | ✅ | ❌ | ✅ | ❌ |
| `agents.view` | ✅ | ❌ | ✅ | ❌ |
| `audit_logs.view` | ✅ | ❌ | ❌ | ❌ |

---

## 5. Auditoría y Trazabilidad (`AuditLog`)
Todas las operaciones sensibles disparan un evento persistente en la tabla `audit_logs`:
- Inicios de sesión, intentos bloqueados y cierres de sesión.
- Altas, modificaciones y cambios de estado de usuarios.
- Asignación de roles y permisos.
- Creación y publicación de flujos.
- Creación de canales y cambios de estado.
- Asignación y respuestas de conversaciones.

**Sanitización obligatoria**: El servicio `AuditLogger` sanitiza los metadatos antes de persistir, redactando cualquier ocurrencia de contraseñas, tokens, credenciales o secretos.

---

## 6. Motor de Ejecución de Flujos en Runtime (Fase 2)

El runtime de Conexa desacopla la recepción de mensajes del procesamiento del flujo:

```
[Inbound Message]
       │
       ▼
[ConversationIngressService] ───(Redis Advisory Lock)
       │
       ├── Busca conversación abierta o la crea
       ├── Asocia FlowVersion activa del Channel (Version Pinning)
       │
       ▼
[FlowRuntimeEngine]
       │
       ├── Recupera FlowExecution y Contexto de Variables
       ├── Resuelve nodo actual vía [NodeHandlerRegistry]
       │       ├── StartNodeHandler
       │       ├── MessageNodeHandler
       │       ├── QuestionNodeHandler
       │       ├── ConditionNodeHandler
       │       ├── TransferToAgentNodeHandler
       │       └── EndNodeHandler
       │
       └── Avanza recursivamente hasta nodo de espera (Question) o terminal (Transfer / End)
```

### Principios del Runtime:
1. **Version Pinning**: Las conversaciones conservan `active_flow_version_id` inmutable a lo largo de su ciclo de vida. Publicar una nueva versión no desestabiliza conversaciones en curso.
2. **Idempotencia y Concurrencia**: Control estricto contra duplicados vía `external_message_id` y locks distribuidos en Redis que impiden avances paralelos con condiciones de carrera.
3. **Protección contra Bucles Infinitos**: Límite de 50 pasos por turno para prevenir ciclos cerrados entre nodos sin interacción humana.
4. **Interpolación Segura**: `VariableInterpolator` soporta sintaxis `{{variable}}` resolviendo claves de contexto sin riesgo de inyección de código.

---

## 7. Constructor Visual de Flujos (Fase 3)

El Constructor Visual permite crear, inspeccionar, editar y conectar los mismos grafos dirigidos ejecutados por el runtime.

### 7.1. Principio de No Divergencia
```
Lo que el editor construye == Lo que FlowValidator valida == Lo que FlowRuntimeEngine ejecuta
```

### 7.2. Capa Adaptadora (`adapter.ts`)
Aísla la librería visual (`@xyflow/react`) del modelo de dominio:
- Convierte `FlowNode` y `FlowEdge` relacionales en `Node` y `Edge` de React Flow.
- Deserializa y serializa `position_x` y `position_y` de forma limpia sin contaminar el objeto funcional `config`.
- Mapea las ramas booleanas de `ConditionNode` a salidas visuales con handles identificados como `"true"` y `"false"`.

### 7.3. Catálogo de Nodos del Constructor
- **`start`**: Raíz obligatoria, 1 salida, sin entrada. No puede eliminarse ni duplicarse.
- **`message`**: Emisión de texto con interpolación y selector visual de variables detectadas.
- **`question`**: Pregunta con captura de respuesta en variable con formato `[a-zA-Z_][a-zA-Z0-9_.]*`, selector de tipo (`text`, `number`, `email`, `choice`) y mensaje de error personalizado.
- **`condition`**: Constructor declarativo con operadores seguros (`equals`, `not_equals`, `contains`, `not_contains`, `starts_with`, `ends_with`, `gt`, `gte`, `lt`, `lte`, `exists`, `not_exists`). Dispone de 2 handles de conexión claramente identificados (verde para `true`, rojo para `false`).
- **`transfer_to_agent`**: Terminal de entrega a agente humano (estatus `waiting_agent`).
- **`end`**: Terminal de cierre de flujo y conversación.

### 7.4. Concurrencia Optimista
Cada petición de guardado (`POST /api/v1/flows/{flow}/versions/{version}/graph`) envía el campo `expected_updated_at`. Si otro usuario modificó la versión concurrentemente, el backend responde con código HTTP 409 (`Conflict`) impidiendo pérdidas de trabajo no deseadas.

### 7.5. Validación y Navegación de Errores
El endpoint `POST /api/v1/flows/{flow}/versions/{version}/validate` ejecuta `FlowValidator` y devuelve:
- `valid`: Booleano de estado general.
- `errors`: Lista de mensajes de error legibles.
- `structured_errors`: Array de objetos con `{ code, node_key, message }`, permitiendo al editor enfocar, centrar y resaltar el nodo defectuoso con un solo clic.
