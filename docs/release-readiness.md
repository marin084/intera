# INTERA — Checklist de entrega

Esta lista separa las verificaciones que podemos ejecutar localmente de las
que requieren un canal externo real. No se deben probar canales externos con
credenciales o mensajes de clientes desde una rama de desarrollo.

## Antes de revisar una iteración

1. Confirmar que la rama parte de `main` actualizado.
2. Ejecutar la suite backend y las comprobaciones del frontend.
3. Levantar la instancia local con Docker Compose.
4. Verificar `/health`, `/health/ready` y el estado de Reverb.
5. Crear o cargar fixtures E2E desechables únicamente en el entorno local.

## Revisión funcional local

- Iniciar sesión como plataforma y confirmar que puede administrar los
  workspaces.
- Iniciar sesión como administrador de Workspace A y confirmar que no ve
  usuarios, canales, flujos, equipos, conversaciones ni métricas de B.
- Repetir la comprobación desde Workspace B.
- Crear un usuario desde la pantalla global y asignarlo a uno o más workspaces;
  comprobar que no se agregue automáticamente a otro.
- Desactivar una membresía y comprobar que se revocan sus permisos y su sesión
  de trabajo sin afectar al resto del workspace.
- Revisar estados de carga, vacíos, errores, formularios, modales, toasts,
  tablas y navegación en escritorio y móvil.
- Comprobar que los mensajes del webchat y las conversaciones locales llegan a
  la bandeja en tiempo real; la verificación de Telegram, WhatsApp, Messenger e
  Instagram queda pendiente hasta disponer de cuentas externas de prueba.

## Criterios de seguridad

- Todas las rutas de negocio deben resolver el workspace desde el contexto
  autenticado o desde el canal público y rechazar asociaciones cruzadas.
- La autorización debe fallar cerrada ante membresías ausentes o inactivas.
- Nunca se deben incluir tokens, secretos, contraseñas o datos de clientes en
  commits, capturas, logs de CI o documentación.
- Los comandos de fixtures E2E deben estar explícitamente limitados a entornos
  de prueba y no ejecutarse sobre producción.

## Entrega a producción

1. Revisar y aprobar el Pull Request en GitHub.
2. Hacer merge a `main` después de que los checks estén verdes.
3. Generar backup antes del redeploy en Dokploy.
4. Esperar el despliegue y verificar los tres health checks.
5. Ejecutar un smoke test sin canales externos: login, selector de Workspace,
   listado de conversaciones y envío en Webchat de simulación.
6. Registrar cualquier incidencia y detener el rollout si falla un health check,
   aparece una fuga entre workspaces o se rompe el flujo de autenticación.

## Pendiente para el cierre de la fase

- Pruebas E2E con Telegram, WhatsApp/Meta, Messenger e Instagram usando cuentas
  de prueba autorizadas.
- Verificación de webhooks, firma/secretos, reintentos y límites de cada canal.
- Prueba de recuperación ante caída de Reverb y de procesamiento duplicado de
  mensajes en cada proveedor.
