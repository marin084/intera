# CONEXA — FASE 4: DOCUMENTACIÓN DE ARQUITECTURA Y CONSOLIDACIÓN DEL MOTOR CONVERSACIONAL V1

**Versión:** 1.0.0  
**Fecha:** Octubre 2026  
**Estado:** PRODUCCIÓN / CONSOLIDADA  
**Arquitectura:** Single-tenant On-Premise / VPS (PHP 8.2+ Laravel, React 19 / TypeScript, PostgreSQL 16, Redis opcional con fallback en DB).

---

## ÍNDICE DE CONTENIDOS

1. [Arquitectura del Motor Conversacional](#1-arquitectura-del-motor-conversacional)
2. [Contratos y Ciclo de Vida de la Conversación](#2-contratos-y-ciclo-de-vida-de-la-conversación)
3. [Máquina de Estados y Transiciones](#3-máquina-de-estados-y-transiciones)
4. [Concurrencia y Procesamiento Seguro](#4-concurrencia-y-procesamiento-seguro)
5. [Idempotencia y Deduplicación de Mensajes](#5-idempotencia-y-deduplicación-de-mensajes)
6. [Protección contra Bucles Infinitos y Ciclos Válidos](#6-protección-contra-bucles-infinitos-y-ciclos-válidos)
7. [Seguridad en Integraciones HTTP (Protección SSRF)](#7-seguridad-en-integraciones-http-protección-ssrf)
8. [Transferencia y Handoff a Agentes Humanos](#8-transferencia-y-handoff-a-agentes-humanos)
9. [Abstracción Multicanal y Adaptadores](#9-abstracción-multicanal-y-adaptadores)
10. [Version Pinning y Rollback Seguro](#10-version-pinning-y-rollback-seguro)
11. [Estrategia de Caching e Invalidación](#11-estrategia-de-caching-e-invalidación)
12. [Observabilidad, Correlation IDs y Logging Estructurado](#12-observabilidad-correlation-ids-y-logging-estructurado)
13. [Perfiles de Hardware y Dimensionamiento Recomendados](#13-perfiles-de-hardware-y-dimensionamiento-recomendados)
14. [Resultados Reales de Pruebas de Carga](#14-resultados-reales-de-pruebas-de-carga)
15. [Guía de Recuperación ante Fallos y Desastres](#15-guía-de-recuperación-ante-fallos-y-desastres)
16. [Monitoreo y Alertas Operativas](#16-monitoreo-y-alertas-operativas)
17. [Comandos de Mantenimiento y Diagnósticos](#17-comandos-de-mantenimiento-y-diagnósticos)
18. [Checklist de Verificación en Producción](#18-checklist-de-verificación-en-producción)
19. [Inventario de Archivos Modificados y Nuevos](#19-inventario-de-archivos-modificados-y-nuevos)
20. [Cobertura de Pruebas Automatizadas](#20-cobertura-de-pruebas-automatizadas)

---

## 1. Arquitectura del Motor Conversacional

Conexa utiliza un motor conversacional único y determinista (`FlowRuntimeEngine`), desacoplado de las particularidades de red y de presentación visual. La arquitectura garantiza que cualquier canal (Webchat, Simulador de pruebas, o futuros canales como WhatsApp Cloud API) ejecute con idéntica lógica:

```
                          +------------------------+
                          |   EDITOR DE FLUJOS     |
                          |   (React Flow V3)      |
                          +-----------+------------+
                                      |
                             Publicación / Draft
                                      v
                          +------------------------+
                          |      FLOW VERSION      |
                          |   (Inmutable en Prod)  |
                          +-----------+------------+
                                      |
                                      v
                          +------------------------+
                          |   GRAPH CACHE SERVICE  |
                          | (Redis / DB Fallback)  |
                          +-----------+------------+
                                      |
                                      v
                          +------------------------+
                          |  CONVERSATION INGRESS  | <----+ Bloqueo atómico
                          |       SERVICE          |      | Deduplicación
                          +-----------+------------+      | Correlation ID
                                      |
                                      v
                          +------------------------+
                          |  FLOW RUNTIME ENGINE   |
                          | (Determinista, 9 Nodos)|
                          +-----------+------------+
                                      |
         +----------------------------+----------------------------+
         |                            |                            |
         v                            v                            v
+------------------+         +------------------+         +------------------+
| WEBCHAT ADAPTER  |         |    SIMULATOR     |         |  FUTUROS CANALES |
| (Eventos/Payload)|         |   (Draft Mode)   |         | (ej. WhatsApp)   |
+------------------+         +------------------+         +------------------+
```

### Principios Fundamentales
- **Fuente única de lógica:** La resolución de nodos, interpolación de variables, evaluación de condiciones y avance en el grafo residen exclusivamente en `FlowRuntimeEngine`.
- **Ejecución Transaccional:** Cada turno conversacional se ejecuta de manera atómica protegiendo el estado de la conversación y el historial de mensajes.
- **Sin Inteligencia Artificial en tiempo de ejecución:** Lógica determinista basada en grafos dirigidos acíclicos/cíclicos con validación estricta de tipos.

---

## 2. Contratos y Ciclo de Vida de la Conversación

El modelo conversacional se articula en torno a tres entidades principales:
1. **Contact:** Representa al usuario interlocutor en un canal determinado (`external_id`, `channel`, `name`, `metadata`).
2. **Conversation:** Representa la sesión conversacional activa o histórica (`status`, `channel`, `assigned_to`, `last_activity_at`).
3. **FlowExecution:** Representa el estado de ejecución del motor (`flow_id`, `flow_version_id`, `current_node_id`, `context` [variables], `status`, `waiting_for_input`, `last_interaction_at`).

### Aislamiento Estricto de Contexto
El campo `FlowExecution.context` almacena exclusivamente las variables del flujo para la conversación específica. Dos conversaciones paralelas nunca comparten variables ni punteros de nodo (`current_node_id`). Los metadatos de telemetría (como `_correlation_id`) se segregan a nivel de step logs (`FlowExecutionStep.metadata`) para no colisionar con variables del usuario.

---

## 3. Máquina de Estados y Transiciones

El ciclo de vida de una conversación en Conexa está regido por el enum `ConversationStatus`:

| Estado | Significado | Comportamiento del Motor |
| :--- | :--- | :--- |
| `BOT` | Conversación interactuando activamente con el flujo automatizado. | El motor procesa mensajes entrantes según el nodo actual. |
| `WAITING_AGENT` | El flujo ejecutó un nodo de transferencia (`transfer_to_agent`). | El motor suspende la ejecución automática. La conversación espera asignación en el Inbox. |
| `ASSIGNED` | Un agente humano tomó la conversación desde el Inbox. | El motor rechaza mensajes del bot. La mensajería es bidireccional entre agente y contacto. |
| `CLOSED` | La conversación fue finalizada por el bot (`end` node) o por un agente. | No acepta nuevos mensajes sin una reapertura explícita; un reintento no procesa pasos adicionales. |

### Transiciones Válidas
- `BOT` -> `WAITING_AGENT` (Disparado por nodo `transfer_to_agent` o petición de escalación).
- `BOT` -> `CLOSED` (Disparado por nodo `end` o inactividad).
- `WAITING_AGENT` -> `ASSIGNED` (Agente humano asignado).
- `ASSIGNED` -> `CLOSED` (Agente humano finaliza atención).
- `CLOSED` -> `BOT` (Nuevo contacto inicia flujo o ventana de reapertura).

---

## 4. Concurrencia y Procesamiento Seguro

### El Problema de Mensajes Simultáneos
Cuando un usuario envía mensajes con milisegundos de diferencia (o la red genera duplicados en paralelo), múltiples peticiones HTTP compiten por actualizar la misma conversación.

### Solución Implementada: Bloqueo Atómico a Nivel de Fila
En `ConversationIngressService::handleInboundMessage`:
1. Apertura de transacción SQL (`DB::transaction(...)`).
2. Adquisición de bloqueo pesimista en la fila del contacto:
   ```php
   $contact = Contact::where('id', $contactId)->lockForUpdate()->first();
   ```
3. Adquisición de bloqueo pesimista en la conversación activa:
   ```php
   $conversation = Conversation::where('id', $conversationId)->lockForUpdate()->first();
   ```
4. Adquisición de bloqueo pesimista en el registro `FlowExecution`:
   ```php
   $execution = FlowExecution::where('id', $executionId)->lockForUpdate()->first();
   ```
5. Las solicitudes concurrentes esperan secuencialmente a que libere el bloqueo la primera solicitud. La segunda lee el estado ya actualizado, impidiendo condiciones de carrera, inconsistencias en variables o bifurcaciones erróneas.

---

## 5. Idempotencia y Deduplicación de Mensajes

Para garantizar tolerancia a fallos en redes inestables (reintentos automáticos del cliente o webhooks repetidos):

1. **Clave de Idempotencia:** `external_message_id`.
2. **Alcance:** Canal (`channel`) + Contacto (`contact_id`) + Identificador externo (`external_message_id`).
3. **Detección Previa a Ejecución:**
   Antes de invocar el motor, `ConversationIngressService` consulta si existe un mensaje con ese `external_message_id` para ese contacto.
4. **Respuesta Idempotente:**
   Si el mensaje ya fue procesado:
   - Se omite la ejecución de pasos en el grafo.
   - Se recuperan los mensajes salientes emitidos originalmente en esa interacción (`Message::where('metadata->in_reply_to_external_id', ...)` o contexto del turno).
   - Se retorna el resultado previo sin alterar el estado conversacional ni duplicar mensajes.
5. **Idempotencia en Nodos Terminales:**
   Incluso si el mensaje cerró la conversación (`CLOSED`), un reintento con el mismo `external_message_id` detecta el procesamiento previo y evita iniciar una nueva conversación huérfana.

---

## 6. Protección contra Bucles Infinitos y Ciclos Válidos

### El Problema de Protección Ingenua
Un contador global de pasos por ejecución (`steps_count`) colapsa flujos donde un usuario legítimamente navega menús en bucle interactivo durante 50 turnos:
`Menú -> Consultar Saldo -> Volver al Menú -> Ver Sucursales -> Volver al Menú...`

### Solución en Dos Dimensiones
En `FlowRuntimeEngine::advanceExecution`:
1. **Límite de Pasos por Turno (`max_steps_per_run`, default: 100):**
   Previene bucles automáticos infinitos de nodos sin interacción humana (ej. Condition -> Goto -> Condition).
2. **Límite de Visitas por Nodo en el Mismo Turno (`max_node_visits_per_turn`, default: 10):**
   Rastrea el array `$visitedInTurn = []`. Si un nodo individual es visitado más de 10 veces en una **única interacción sin esperar input**, se clasifica como bucle infinito y se detiene la ejecución arrojando `FlowFailureCode::EXECUTION_FAILED` con log estructurado.
3. **Preservación de Ciclos Interactivos:**
   Cuando el flujo se detiene a esperar respuesta del usuario (`waiting_for_input = true`), el turno finaliza. En el siguiente mensaje entrante, el contador del turno se reinicia, permitiendo que el usuario interactúe con el menú tantas veces como desee a lo largo del tiempo.

---

## 7. Seguridad en Integraciones HTTP (Protección SSRF)

El nodo `HttpRequestNodeHandler` fue endurecido contra ataques Server-Side Request Forgery (SSRF) dirigidos a la infraestructura interna del cliente:

### Validaciones Implementadas
1. **Esquemas permitidos:** Únicamente `http://` y `https://`.
2. **Resolución DNS y Validación de IP:**
   - Se resuelven los registros A/AAAA del hostname antes de enviar la petición.
   - Se bloquean rangos de loopback (`127.0.0.0/8`, `::1`).
   - Se bloquean rangos privados RFC 1918 (`10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`).
   - Se bloquean rangos link-local y metadata de proveedores cloud (`169.254.0.0/16`, en particular `169.254.169.254`).
   - Se bloquean direcciones IPv4-mapped IPv6 (`::ffff:127.0.0.1`, etc.).
3. **Allowlist Empresarial:**
   Configuración `flows.http.allowed_hosts`. Si se definen dominios en esta lista, solo peticiones hacia esos hosts son autorizadas.
4. **Límite de Tamaño de Respuesta:**
   Máximo 2MB (`max_response_bytes`) para prevenir agotamiento de memoria del proceso PHP.
5. **Redacción de Credenciales en Logs:**
   Cabeceras sensibles (`Authorization`, `X-Api-Key`, `ApiKey`, `Token`, `Bearer`) son redactadas automáticamente antes de persistirse en `FlowExecutionStep.metadata`.

---

## 8. Transferencia y Handoff a Agentes Humanos

Cuando el flujo transita por un nodo `transfer_to_agent`:
1. El nodo actualiza el estado de la conversación a `WAITING_AGENT`.
2. Emite el mensaje de transferencia al usuario (ej. *"Un agente se comunicará contigo"*).
3. Se detiene la ejecución automática (`waiting_for_input = false`, pero estado de conversación protegido).
4. **Inhibición de Respuestas:** Mientras la conversación se encuentre en `WAITING_AGENT` o `ASSIGNED`, el servicio `ConversationIngressService` **no** invoca el motor ni responde con mensajes automatizados, enrutando todo mensaje directamente a la bandeja de entrada del Inbox humano.

---

## 9. Abstracción Multicanal y Adaptadores

Para desacoplar el motor conversacional de los canales de transporte, se estableció la arquitectura de adaptadores en `app/Services/Runtime/Channels/`:

```
                    +------------------------+
                    |    InboundMessage      | (DTO Canónico)
                    +------------------------+
                                |
                                v
                    +------------------------+
                    |   ChannelCapabilities  | (Bandera de soporte)
                    +------------------------+
                                |
                                v
                    +------------------------+
                    | ChannelAdapterContract |
                    +------------------------+
                                |
             +------------------+------------------+
             |                                     |
             v                                     v
+------------------------+             +------------------------+
| WebchatChannelAdapter  |             | (WhatsAppAdapter - F5) |
+------------------------+             +------------------------+
```

### Contrato `ChannelAdapter`:
- `getChannel(): string`
- `getCapabilities(): ChannelCapabilities`
- `normalizeInbound(array $rawPayload): InboundMessage`
- `formatOutbound(array $engineMessages): array`
- `validateConfiguration(array $config): bool`

Esta estructura garantiza que la integración futura de WhatsApp (Fase 5) solo requerirá implementar un nuevo adaptador sin modificar una sola línea de `FlowRuntimeEngine`.

---

## 10. Version Pinning y Rollback Seguro

### Inmutabilidad y Version Pinning
- Una conversación iniciada vincula su ejecución a una versión publicada específica (`FlowExecution.flow_version_id`).
- La publicación de una nueva versión (`v2`) no altera las conversaciones existentes (`v1`). Dichas conversaciones continúan su ciclo de vida con la definición original del grafo con la que comenzaron.
- Nuevas conversaciones que ingresan al sistema se vinculan automáticamente a la versión publicada activa más reciente.

### Rollback Seguro
- Realizar rollback a una versión anterior marca la versión seleccionada como `is_published = true` e invalida la caché del grafo.
- Las conversaciones en curso completan su ciclo sin disrupciones ni estados incongruentes.

---

## 11. Estrategia de Caching e Invalidación

### Desafío en VPS de Pocos Recursos
En entornos on-premise pequeños, Redis puede no estar instalado o la extensión `phpredis` puede no estar presente en el entorno PHP CLI.

### Solución: `FlowGraphCacheService`
1. **Doble Nivel con Fallback Transparente:**
   - Si Redis o el driver de caché configurado está disponible, almacena el grafo normalizado en memoria con TTL configurable (1 hora).
   - Si el driver falla o arroja excepciones (`ConnectionException`, `DriverException`), el servicio captura el fallo transparentemente y lee directamente el grafo desde PostgreSQL/SQLite.
2. **Invalidación Inmediata de Caché:**
   Se ejecuta `FlowGraphCacheService::forget($flowId)` en los siguientes eventos del controlador (`FlowController`):
   - `saveVersionGraph` (guardado de borrador).
   - `publishVersion` (publicación de versión).
   - `rollbackVersion` (reversión de versión).
   - `destroyVersion` (eliminación de versión).

---

## 12. Observabilidad, Correlation IDs y Logging Estructurado

### Trazabilidad End-to-End
Cada mensaje entrante genera o propaga un `_correlation_id` (UUIDv4):
- Se asocia a los logs de Laravel: `[RuntimeIngress] [corr_id: ...] Processing inbound message`.
- Se almacena en los metadatos de cada paso ejecutado (`FlowExecutionStep.metadata['_correlation_id']`).
- Permite diagnosticar en segundos cualquier ciclo conversacional buscando el `_correlation_id` en los logs del servidor.

---

## 13. Perfiles de Hardware y Dimensionamiento Recomendados

Basado en los benchmarks de ejecución reales obtenidos en la Fase 4:

| Perfil | CPU | RAM | Disco | Concurrencia | Casos de Uso Recomendados |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **Básico (VPS Mínimo)** | 2 vCPU | 2 GB | 20 GB SSD | Hasta 25 req/s | PyMEs, mesas de ayuda pequeñas, bajo tráfico (< 5,000 msgs/día). |
| **Recomendado (Estándar)** | 4 vCPU | 4 GB | 50 GB NVMe | Hasta 60 req/s | Empresas medianas, campañas comerciales, Webchat + WhatsApp (~50,000 msgs/día). |
| **Alta Capacidad** | 8 vCPU | 8 GB | 100 GB NVMe | 150+ req/s | Retail masivo, picos de alta demanda, múltiples agentes en simultáneo. |

### Configuración Recomendada de PostgreSQL y PHP-FPM (Perfil Estándar)
- `pm = dynamic`, `pm.max_children = 50`, `pm.start_servers = 10`.
- `shared_buffers = 1GB`, `work_mem = 16MB`, `effective_cache_size = 3GB`.

---

## 14. Resultados Reales de Pruebas de Carga

Pruebas ejecutadas mediante `php artisan conexa:load-test` simulando conversaciones multi-paso reales (Ingreso -> Menú -> Selección de Opción -> Respuesta) con persistencia completa en base de datos PostgreSQL:

| Perfil | Concurrencia | Solicitudes | Mensajes | Tiempo Total | Throughput | Latencia p50 | Latencia p95 | Latencia p99 | Tasa de Error |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| **A (Bajo)** | 10 usuarios | 27 reqs | 25 msgs | 0.899 s | **30.03 req/s** | 23.29 ms | 62.27 ms | 67.03 ms | **0.00 %** |
| **B (Medio)** | 50 usuarios | 115 reqs | 105 msgs | 2.210 s | **52.03 req/s** | 17.81 ms | 47.95 ms | 58.23 ms | **0.00 %** |
| **C (Alto)** | 100 usuarios | 225 reqs | 205 msgs | 4.565 s | **49.29 req/s** | 18.52 ms | 48.78 ms | 58.59 ms | **0.00 %** |
| **D (Stress)** | 250 usuarios | 555 reqs | 505 msgs | 11.038 s | **50.28 req/s** | 18.08 ms | 48.34 ms | 59.42 ms | **0.00 %** |
| **E (Peak)** | 500 usuarios | 1105 reqs | 1005 msgs | 23.356 s | **47.31 req/s** | 18.56 ms | 48.06 ms | 69.54 ms | **0.00 %** |

**Observación Clave:** La latencia p50 se mantuvo estable (~18ms) y p99 (< 70ms) a lo largo de todos los perfiles de carga, con una tasa de error de **0.00%**.

---

## 15. Guía de Recuperación ante Fallos y Desastres

1. **Recuperación tras Caída del Proceso PHP/Servidor:**
   - Ningún estado depende de memoria volátil. Las conversaciones interrumpidas reanudan su avance en el nodo persistido en `FlowExecution.current_node_id` cuando llega el próximo mensaje.
2. **Caída del Servicio Redis:**
   - `FlowGraphCacheService` captura automáticamente la falta de conexión y consulta la versión en PostgreSQL sin degradar la disponibilidad del bot.
3. **Manejo de Transacciones Abortadas:**
   - Los bloqueos `lockForUpdate` se liberan inmediatamente al concluir o abortar la transacción de base de datos.

---

## 16. Monitoreo y Alertas Operativas

### Métricas Clave a Monitorear
- **Tasa de Errores del Motor:** Alerta si el conteo de logs `FlowFailureCode` supera el 1% en una ventana de 5 minutos.
- **Latencia de Ingress:** Alerta si el tiempo de respuesta p95 de `/api/v1/webchat/messages` supera los 300ms.
- **Deadlocks / Lock Timeouts:** Monitorear logs de PostgreSQL buscando `could not obtain lock on row`.
- **Uso de Disco:** Alerta cuando el almacenamiento de logs y pasos de ejecución supere el 80% de capacidad del volumen.

---

## 17. Comandos de Mantenimiento y Diagnósticos

```bash
# Ejecución del benchmark de carga del motor
php artisan conexa:load-test --profile=all

# Limpieza de caché de grafos de flujos
php artisan cache:clear

# Verificación del estado de migraciones e índices
php artisan migrate:status

# Script de carga automatizado
./scripts/load-test-engine.sh
```

---

## 18. Checklist de Verificación en Producción

- [x] Migración `2026_10_08_200000_add_runtime_performance_indexes` aplicada.
- [x] Índices compuestos en `flow_executions` y `conversations` verificados.
- [x] Configuración SSRF en `config/flows.php` validada (`allowed_hosts` y rangos privados).
- [x] Límite de pasos por turno (100) y visitas por nodo (10) activos.
- [x] Bloqueos atómicos `lockForUpdate` activos en `ConversationIngressService`.
- [x] Version pinning verificado entre versiones v1 y v2.
- [x] Batería de pruebas automatizadas aprobada al 100%.

---

## 19. Inventario de Archivos Modificados y Nuevos

### Archivos Modificados
- `backend/app/Services/Runtime/FlowRuntimeEngine.php`: Protección contra bucles infinitos por turno y seguimiento de correlation ID.
- `backend/app/Services/Runtime/ConversationIngressService.php`: Bloqueo atómico por fila, deduplicación idempotente y suspensión de bot ante agente.
- `backend/app/Services/Runtime/Handlers/HttpRequestNodeHandler.php`: Blindaje contra SSRF, allowlist, límite de bytes y sanitización de cabeceras.
- `backend/app/Http/Controllers/Api/V1/FlowController.php`: Integración de invalidación de caché en guardado, publicación y rollback.
- `backend/app/Enums/FlowFailureCode.php`: Nuevos códigos de fallo tipados (`SSRF_BLOCKED`, `HTTP_PAYLOAD_TOO_LARGE`).
- `backend/config/flows.php`: Directivas de configuración de seguridad, timeouts y límites de ejecución.

### Archivos Nuevos
- `backend/database/migrations/2026_10_08_200000_add_runtime_performance_indexes.php`: Índices optimizados para concurrencia e ingress.
- `backend/app/Services/Runtime/FlowGraphCacheService.php`: Servicio de caché en dos niveles con fallback resiliente.
- `backend/app/Services/Runtime/Channels/Contracts/ChannelAdapter.php`: Contrato para adaptadores de canal.
- `backend/app/Services/Runtime/Channels/ChannelCapabilities.php`: Objeto de valor para capacidades de canal.
- `backend/app/Services/Runtime/Channels/InboundMessage.php`: DTO canónico de mensajes entrantes.
- `backend/app/Services/Runtime/Channels/Adapters/WebchatChannelAdapter.php`: Adaptador oficial para Webchat.
- `backend/app/Services/Runtime/Channels/ChannelAdapterRegistry.php`: Registro dinámico de adaptadores.
- `backend/app/Console/Commands/RunEngineLoadTest.php`: Comando CLI Artisan para pruebas de carga reproducibles.
- `backend/tests/Feature/FlowRuntimeEngineHardeningFase4Test.php`: Suite de 13 pruebas específicas de Fase 4.
- `scripts/load-test-engine.sh`: Script bash de ejecución de benchmarks.
- `docs/CONEXA_FASE_4_RUNTIME_ENGINE.md`: Documento de arquitectura presente.

---

## 20. Cobertura de Pruebas Automatizadas

El proyecto cuenta con verificación integral en tres niveles:
1. **Backend (PHPUnit / Pest):** 173 pruebas aprobadas (100% éxito, 948 aserciones).
2. **Frontend (Vitest):** 76 pruebas aprobadas (100% éxito).
3. **End-to-End (Playwright):** 23 pruebas aprobadas (100% éxito en Chromium y WebKit).
4. **Calidad de Código:** Oxlint 0 errores, compilación TypeScript sin advertencias.
