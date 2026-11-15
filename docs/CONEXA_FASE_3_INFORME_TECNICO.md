# CONEXA — FASE 3: PUBLICACIÓN, VERSIONADO Y ROLLBACK DE FLUJOS V1
## INFORME TÉCNICO DE IMPLEMENTACIÓN, VALIDACIÓN Y CIERRE DEFINITIVO

---

### 1. Resumen Ejecutivo y Veredicto

Se completó formalmente la **Fase 3: Publicación, Versionado, Historial y Rollback de Flujos V1** para la plataforma conversacional Conexa.

El sistema garantiza que los flujos puedan evolucionar sin riesgos operacionales mediante:
1. **Inmutabilidad absoluta del backend:** Las versiones con estado `published` rechazan cualquier intento de edición directa de nodos, aristas o variables (`422 Unprocessable Entity`).
2. **Version Pinning estricto:** Las conversaciones activas iniciadas en una versión determinada permanecen ligadas de forma inmutable a esa versión (`ConversationIngressService` respeta `active_flow_version_id`), mientras que las nuevas conversaciones se dirigen a la versión activa del flujo.
3. **Validación previa a publicación:** Motor de validación estructural con clasificación de `errors` (bloqueantes con `structured_errors`), `warnings` e `infos`.
4. **Publicación y Rollback atómicos e idempotentes:** Operaciones transaccionales mediante locking pesimista (`lockForUpdate()`), garantizando una única versión activa por flujo ante concurrencia o reintentos HTTP.
5. **Auditoría completa:** Registro persistente en la tabla `flow_publications` y bitácora de auditoría de seguridad (`AuditLogger`) para todas las acciones de publicación y reversión.
6. **Comparador Semántico de Versiones (Diff):** Motor semántico en backend (`FlowDiffService`) y visualizador en frontend (`FlowVersionCompareModal`) que analiza adición, eliminación y mutación de nodos, conexiones y variables, diferenciando cambios lógicos de meros desplazamientos en coordenadas.
7. **Rollback sin pérdida de datos:** La reversión actualiza la versión activa de forma transaccional sin destruir borradores ni alterar versiones históricas ni conversaciones en curso.

**Veredicto Final:**
# FASE 3 CERRADA

---

### 2. Arquitectura y Modelo de Datos

#### 2.1. Entidades y Relaciones

- **`Flow`:** Modelo raíz que mantiene `active_version_id` apuntando a la única versión activa en producción.
- **`FlowVersion`:** Representa una versión inmutable (`published`) o editable (`draft`). Incluye metadata, grafo conversacional (`FlowNode`, `FlowEdge`), y relaciones hacia conversaciones y ejecuciones.
- **`FlowPublication`:** Entidad de auditoría que registra cada evento de publicación o reversión:
  - `flow_id` (UUID)
  - `flow_version_id` (UUID)
  - `previous_version_id` (UUID, nullable)
  - `published_by` (UUID/ID del usuario autenticado)
  - `action` (`publish` | `rollback`)
  - `notes` (Texto descriptivo opcional)
  - `created_at` (Timestamp)

#### 2.2. Migración Incremental y Reversibilidad

- Archivo de migración: `backend/database/migrations/2026_10_08_100000_create_flow_publications_table.php`
- Índices para consultas de alta velocidad: `(flow_id, created_at)` y `(flow_version_id)`.
- Restricciones referenciales con `cascadeOnDelete`.
- Verificación de reversibilidad ejecutada:
  - `php artisan migrate:rollback --step=1` ejecutado limpiamente en PostgreSQL (Batch 7 deshecho).
  - `php artisan migrate --force` reaplicado exitosamente sin pérdida de consistencia.

---

### 3. Garantías de Integridad y Runtime

#### 3.1. Inmutabilidad en Backend (`FlowController`)
- En `saveVersionGraph`: Si `version->status === 'published'`, el backend responde inmediatamente con HTTP 422:
  `"No se puede modificar una versión publicada. Las versiones publicadas son inmutables para garantizar el version pinning."`
- En `destroyVersion`: Si la versión es `published`, está activa, o cuenta con conversaciones o ejecuciones asociadas, se rechaza la eliminación con HTTP 422.

#### 3.2. Version Pinning en `ConversationIngressService`
En `ConversationIngressService::handleInboundMessage`:
```php
$flowVersion = null;
if ($conversation->active_flow_version_id) {
    $flowVersion = FlowVersion::find($conversation->active_flow_version_id);
}
if (!$flowVersion) {
    $flow = $channel->entryFlow;
    $flowVersion = $flow?->activeVersion;
}
```
Esto asegura que las conversaciones que ya están en curso mantienen su contexto y versión original, incluso si el administrador publica una nueva versión o realiza un rollback.

#### 3.3. Publicación y Rollback Atómicos y Concurrencia
- `publishVersion`: Ejecuta `DB::transaction()` con `$flow->lockForUpdate()`. Si la versión ya está publicada y activa, retorna HTTP 200 de forma idempotente sin duplicar registros. Valida la estructura mediante `FlowValidator` antes de publicar.
- `rollbackVersion`: Valida que la versión destino tenga `status === 'published'`. No se permite hacer rollback a un borrador (`422`). Ejecuta `DB::transaction()` con `$flow->lockForUpdate()`. Idempotente ante reintentos concurrentes.
- Registra fila en `flow_publications` con `action = 'publish'` o `'rollback'` y emite auditoría mediante `AuditLogger`.

#### 3.4. Control de Acceso RBAC
Los endpoints de publicación y rollback están estrictamente protegidos en backend mediante el permiso `flows.publish`. Solicitudes sin dicho permiso son rechazadas con HTTP 403.

---

### 4. Comparador Semántico de Versiones (`FlowDiffService`)

El servicio backend `backend/app/Services/FlowDiffService.php` compara dos versiones de un flujo (`source` vs `target`):
- **Nodos:**
  - `added`: Nodos presentes en destino y ausentes en origen.
  - `removed`: Nodos presentes en origen y ausentes en destino.
  - `modified`: Nodos con cambios en nombre, tipo o configuración (e.g. texto del mensaje, opciones del menú).
  - `moved`: Nodos donde solo cambiaron `position_x` o `position_y` en el lienzo, distinguiéndolos de modificaciones lógicas.
- **Conexiones (`edges`):**
  - Conexiones añadidas o eliminadas entre claves de nodos.
- **Variables:**
  - Variables añadidas, eliminadas o con cambios de tipo/ámbito.
- **KPIs y Highlights:**
  - Métricas numéricas (`nodes_added`, `nodes_removed`, `nodes_modified`, `edges_added`, etc.) y viñetas explicativas en lenguaje natural.

---

### 5. Componentes Frontend Implementados

1. **`FlowToolbar.tsx`:**
   - Visualización del estado dual: versión en edición (`Editando: v...`) y versión en producción (`Producción: v...` o `Sin publicar`).
   - Botón `Historial` para abrir el drawer lateral.
   - Botón `Comparar` para acceder al comparador semántico.
   - Botón contextual `Restaurar versión` para versiones publicadas históricas.
2. **`FlowVersionHistoryDrawer.tsx`:**
   - Panel lateral con listado de versiones ordenadas descendentemente.
   - Badges de estado: `Activa en Producción`, `Histórica`, `Borrador`, `En editor`.
   - Pestaña de `Auditoría` con bitácora de publicaciones y reversiones con autor y notas.
   - Acciones por versión: Ver/Editar, Clonar en nuevo borrador, Diff vs Activa, Publicar, y Rollback.
3. **`FlowVersionCompareModal.tsx`:**
   - Selector visual de versión origen y destino.
   - Tarjetas KPI con diferencias (+/-).
   - Resumen en lenguaje natural y lista detallada de bloques agregados, modificados y eliminados.
4. **`FlowRollbackModal.tsx`:**
   - Modal de confirmación con advertencia explícita sobre el version pinning.
   - Campo para documentar el motivo o notas del rollback.
5. **`FlowsView.tsx`:**
   - Enriquecido con badges de estado (`Activa`, `Histórica`, `Borrador`) y acción de rollback directa desde la vista de gestión.

---

### 6. Matriz de Resultados y Verificación de Pruebas

#### 6.1. Backend (`php artisan test`)
- **Total de pruebas ejecutadas:** 160
- **Total de pruebas aprobadas:** 160 (100%)
- **Total de aserciones:** 893
- **Pruebas fallidas:** 0
- **Suites principales de Fase 3:**
  - `FlowPublicationVersioningTest.php` (10 pruebas): inmutabilidad, unicidad, transaccionalidad, diff semántico, RBAC, auditoría.
  - `FlowVersioningConversationLifecycleTest.php` (1 prueba integral): ciclo de vida completo de 17 pasos con pinning entre V1, V2 y rollback.
  - `FlowValidatorTest.php`, `FlowControllerTest.php`, `FlowSimulationControllerTest.php`.

#### 6.2. Frontend Unitario (`vitest`)
- **Archivos de prueba ejecutados:** 11
- **Pruebas ejecutadas:** 76
- **Pruebas aprobadas:** 76 (100%)
- **Pruebas fallidas:** 0
- **Suites principales:**
  - `FlowVersioningFeatures.test.tsx` (5 pruebas): drawer de historial, pestañas de versiones y auditoría, modal de rollback y comparador semántico.
  - `FlowSimulatorDrawer.test.tsx` (11 pruebas): compatibilidad total con el simulador conversacional de la Fase 2.
  - `FlowEditor.test.tsx` (6 pruebas), `FlowEditorV2.test.tsx` (5 pruebas).

#### 6.3. Playwright E2E (`npx playwright test`)
- **Navegadores validados:** Chromium y WebKit (Safari).
- **Pruebas ejecutadas:** 23
- **Pruebas aprobadas:** 23 (100%)
- **Pruebas fallidas:** 0
- **Pruebas omitidas:** 0
- **Tiempo total de ejecución:** 1.1m
- **Detalle de suites E2E:**
  - `e2e/flow-versioning-publication.spec.ts` (Chromium y WebKit):
    - Ciclo completo: Creación de flujo, edición de borrador, guardado, validación prepublicación, publicación activa, consulta de historial y bitácora de auditoría, comparación semántica de versiones y apertura del simulador de flujos.
  - `e2e/flow-simulator.spec.ts` (Chromium y WebKit):
    - Ejecución de simulación, inspector de variables, historial de eventos, resaltado visual de nodos y aristas sin regresiones.
  - `e2e/smoke.spec.ts`, `e2e/auth.spec.ts`, `e2e/conversations.spec.ts`, `e2e/webchat-messaging.spec.ts`, `e2e/flow-editor-v3.spec.ts`.

#### 6.4. Verificación de Calidad de Código
- **Linter (`npm run lint` / `oxlint`):** 0 errores (75 archivos examinados con 116 reglas).
- **TypeScript / Vite (`npm run build`):** Compilación limpia sin errores en cliente y widget loader (`dist/` generado correctamente).

---

### 7. Integración y Estado de Git

1. **Ramas integradas:**
   - Rama de desarrollo: `feature/flow-publication-versioning-v1`
   - Rama destino: `main`
2. **Estrategia de integración:**
   - Merge formal sin avance rápido (`--no-ff`): commit `a48ae83`
3. **Sincronización remota:**
   - `git push origin main` completado con éxito hacia `https://github.com/marin084/conexa.git`.
   - Rama `main` local sincronizada exactamente con `origin/main` (`Your branch is up to date with 'origin/main'`).
4. **Estado del árbol de trabajo:**
   - Limpio (`working tree clean`), sin archivos sin seguimiento ni secretos expuestos.

---

### 8. Problemas Pendientes y Próximos Pasos

- **Incidencias P0 / P1:** 0 (Ninguna).
- **Deuda técnica:** Ninguna identificada en el alcance de la Fase 3.
- **Preparación para Fase 4:**
  - La plataforma queda lista para la siguiente fase planificada (e.g. Canales / WhatsApp Cloud API / Inbox unificado).
