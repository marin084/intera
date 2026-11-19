# Multitenancy — Phase 2: contexto de workspace

Esta fase activa el primer límite de aislamiento en tiempo de ejecución sin
cambiar todavía la administración completa de organizaciones.

## Qué queda activo

- Cada usuario conserva un `active_workspace_id` elegido al iniciar sesión.
- `GET /api/v1/workspaces` devuelve sus membresías activas.
- `POST /api/v1/workspaces/switch` cambia el workspace después de validar la
  membresía y finaliza las sesiones de presencia del workspace anterior.
- Las rutas autenticadas de negocio resuelven el workspace mediante
  `X-Workspace-Id` o, si no se envía, mediante `active_workspace_id`.
- Si el workspace no existe, está inactivo o el usuario no pertenece a él, la
  API responde `403` con `code: workspace_unauthorized`.
- Los modelos de negocio aplican una consulta global por workspace mientras el
  contexto está activo y fuerzan ese workspace al crear registros desde una
  petición.
- Los permisos consultan el rol de la membresía activa; no reutilizan un rol
  de otro workspace cuando existe contexto.

## Contrato para el frontend

El login y `/auth/me` incluyen `workspace` y `workspaces`. El layout muestra un
selector solo cuando hay más de un workspace disponible; al cambiar, refresca
la sesión y las capacidades antes de volver a cargar la vista.

## Fuera de esta fase

Los webhooks y endpoints públicos siguen resolviendo el tenant a partir del
canal, la clave pública o el token del visitante; no reciben el middleware de
workspace de usuarios autenticados. La administración de organizaciones,
invitaciones y facturación se implementará en fases posteriores.
