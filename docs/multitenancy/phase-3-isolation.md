# INTERA — Fase 3: aislamiento de asociaciones y RBAC por workspace

## Objetivo

Eliminar las dependencias funcionales de role_user en las operaciones de
negocio y cerrar las asociaciones cruzadas entre workspaces. Un usuario puede
tener un rol diferente en cada workspace, y ninguna relación operativa puede
usar recursos de otro workspace.

## Cambios incluidos

- La administración de usuarios lee y actualiza el rol de workspace_user;
  role_user deja de modificarse desde las rutas de usuarios.
- Las rutas de usuarios verifican que el usuario objetivo tenga una membresía
  activa en el workspace actual antes de mostrarlo o modificarlo.
- La validación de equipos exige:
  - membresía activa de cada integrante en el workspace del equipo;
  - rol operativo compatible con atención o supervisión;
  - canales del mismo workspace;
  - nombre único dentro del workspace, no globalmente.
- La asignación de canales a usuarios solo admite canales visibles dentro del
  workspace actual.
- Un canal no puede referenciar un flujo de otro workspace.
- La cola rechaza conversaciones y equipos de workspaces diferentes y valida
  que un agente asignado tenga membresía activa en el workspace de la
  conversación.
- La selección de agentes, sus capacidades y las métricas usan el rol de la
  membresía activa del workspace.
- Las sesiones de simulación comprueban la co-pertenencia de flujo, versión,
  conversación, ejecución y sesión antes de consultar, avanzar o detener una
  simulación.
- La resolución del equipo por canal conserva el workspace del canal, incluso
  cuando el enrutamiento ocurre desde un proceso sin sesión HTTP.

## Compatibilidad de pruebas existentes

Los fixtures históricos que crean usuarios únicamente con role_user se
mantienen funcionales en el entorno de pruebas. Ese fallback está condicionado
al entorno testing y no se ejecuta en las rutas de producción; las
solicitudes protegidas siguen dependiendo exclusivamente de workspace_user.

## Verificación

La cobertura específica de esta fase está en
backend/tests/Feature/MultitenancyPhase3IsolationTest.php. También se
ejecutaron las pruebas de Fases 1 y 2, RBAC, simulaciones, canales,
asignación, disponibilidad y métricas sin regresiones.
