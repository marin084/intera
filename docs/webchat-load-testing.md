# Prueba aislada de capacidad de Webchat

Este harness mide el recorrido de mensajes de Webchat a través del backend y del flujo conversacional. Usa una base PostgreSQL, Redis, worker y Reverb exclusivos. No usa el Webchat configurado para clientes, credenciales de Meta, datos de producción ni URLs externas.

## Protección del entorno

- El Compose tiene un proyecto fijo `conexa-loadtest`, volúmenes y red propios; la red Docker es `internal` y no tiene salida a Internet.
- No se publica ningún puerto en el host. El generador y el backend se comunican por loopback dentro del contenedor aislado; PostgreSQL, Redis, worker y Reverb tampoco exponen puertos.
- Los recursos de prueba tienen topes de CPU y memoria para reducir la contención del resto del equipo.
- El comando de fixture exige `APP_ENV=loadtest`, PostgreSQL, host `postgres` y base `conexa_loadtest`; rehúsa otros destinos.
- El setup no trunca tablas. Solo crea/actualiza el canal y el flujo con nombres reservados para carga y reconstruye las aristas de esa versión del flujo.
- `down` conserva la base de prueba. `destroy` solo borra el proyecto Compose de carga y pide `CONFIRM_LOADTEST_DATA_DELETION=1`.

## Requisitos

Docker Desktop con Compose y `openssl`.

## Ejecución

Desde la raíz del repositorio:

```sh
scripts/loadtest-webchat.sh init
scripts/loadtest-webchat.sh prepare
scripts/loadtest-webchat.sh smoke
scripts/loadtest-webchat.sh run
```

El smoke test crea una sesión y recorre el flujo completo dentro de la red privada. El perfil `ramp` (predeterminado) crea 250 sesiones aisladas, sube gradualmente a 100 mensajes entrantes por segundo, mantiene ese ritmo durante 2 minutos y baja la carga. El perfil dura 4 minutos y 45 segundos, más el arranque y la preparación de sesiones. Para medir escalones estables, se puede ejecutar `scripts/loadtest-webchat.sh run sweep`: mantiene 10, 25, 50, 75 y 100 mensajes por segundo durante 45 segundos cada uno, y baja la carga al final. `scripts/loadtest-webchat.sh run capacity` afina el rango de 25 a 40 mensajes por segundo con escalones de 5. Cada mensaje recorre la ruta pública de Webchat, persiste en PostgreSQL, entra al runtime publicado y provoca una respuesta del bot. El worker y Reverb están activos para procesar los broadcasts en la cola.

El generador PHP cURL multi corre dentro del contenedor del backend, con un tope de 2 CPU compartido con la aplicación. Se eligió esta alternativa porque Docker Desktop no pudo arrancar contenedores efímeros durante la preparación. El resultado es de extremo a extremo bajo ese límite compartido; no representa una capacidad aislada del backend ni una garantía para producción.

El generador verifica HTTP 201, una respuesta del bot en el mismo ciclo, errores, latencia p50/p95/p99/max por perfil y por escalón, solicitudes descartadas al alcanzar la concurrencia máxima, intervalos de llegada no lanzados por el planificador y códigos HTTP. El resultado del smoke queda en `.loadtest-results/smoke-summary.json`; los resultados de carga y muestreo de Docker quedan en `.loadtest-results/summary.json` y `.loadtest-results/docker-stats.csv`.

```sh
scripts/loadtest-webchat.sh status
scripts/loadtest-webchat.sh logs backend
scripts/loadtest-webchat.sh down
```

Los datos de prueba se mantienen al ejecutar `down` para poder revisar métricas y logs. Para borrarlos:

```sh
CONFIRM_LOADTEST_DATA_DELETION=1 scripts/loadtest-webchat.sh destroy
```

## Interpretación

El resultado mide este camino Webchat en la máquina y con los topes indicados en `docker-compose.loadtest.yml`. No estima directamente las capacidades de Meta, WhatsApp, Messenger o Instagram. Tampoco representa la infraestructura de un cliente hasta ejecutar el mismo perfil en un staging que use recursos equivalentes. El número máximo soportado debe reportarse como el mayor escalón sostenido que cumpla los umbrales, incluyendo hardware, configuración, p95/p99, errores, uso de recursos y duración.

Los perfiles apuntan a p95 < 1 s, p99 < 2.5 s, errores < 1%, cero descartes por alcanzar la concurrencia máxima y menos de 0.1% de llegadas omitidas por demora del planificador local. La tolerancia del planificador separa un pequeño retraso de la máquina que ejecuta el generador de una saturación real; ambos valores se informan por separado. Un incumplimiento se investiga antes de recomendar una cifra de producción.
