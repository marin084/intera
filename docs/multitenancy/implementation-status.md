# INTERA — Estado de implementación multitenant

## Estado actual

- El aislamiento de datos y permisos se resuelve por `workspace_id` y por la
  membresía activa del usuario.
- Los usuarios pueden pertenecer a varios Workspaces y tener un rol distinto
  en cada uno.
- El `super_admin` opera a nivel de plataforma; los administradores de
  Workspace solo ven y administran los recursos de sus Workspaces asignados.
- Las relaciones de equipos, canales, colas, simulaciones, presencia y
  conversaciones rechazan asociaciones entre Workspaces.
- La suite E2E local valida aislamiento entre dos Workspaces y el acceso global
  del administrador de plataforma.

## Qué queda fuera por ahora

Las pruebas reales de Telegram, WhatsApp/Meta, Messenger e Instagram se
ejecutarán al final, cuando existan cuentas externas de prueba y sus webhooks
puedan verificarse de forma controlada. Esta exclusión no reduce la cobertura
del aislamiento local ni de los canales de simulación.

## Criterio de cierre

La fase se considera lista cuando:

1. las suites backend, frontend y E2E local están verdes;
2. no existen accesos cruzados entre Workspaces;
3. la UI comunica claramente el Workspace activo y el alcance de cada acción;
4. los cambios pasan revisión en PR antes de llegar a `main`;
5. las pruebas externas pendientes tienen cuentas, secretos y un plan de
   rollback aprobados.
