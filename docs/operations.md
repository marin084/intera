# Conexa — Operations Manual

Este documento detalla los procedimientos estándar para la administración, supervisión, backup, restore, actualización y mantenimiento del stack productivo de Conexa.

---

## 1. Arquitectura de Servicios y Responsabilidades

Conexa opera mediante contenedores Docker coordinados por Docker Compose:

| Servicio | Imagen / Runtime | Responsabilidad | Exposición |
|---|---|---|---|
| `frontend` | Nginx (Alpine) | Gateway HTTP/TLS inverso, SPA Vite, assets estáticos de Webchat, proxy de `/api/` y `/sanctum/`, proxy WebSocket `/app/` | Puerto host 3000 (o 80/443 tras TLS proxy) |
| `backend` | Nginx + PHP 8.4 FPM (Supervisord) | Servidor HTTP interno Nginx (puerto 8000) pasando FastCGI a PHP-FPM (127.0.0.1:9000). Ejecuta API, lógica de negocio y runtime de flows | Puerto 8000 (red Docker interna / host) |
| `reverb` | PHP 8.4 CLI | Servidor WebSocket Laravel Reverb para broadcasting en tiempo real (puerto 8080) | Red Docker interna |
| `scheduler` | PHP 8.4 CLI | Ejecutor continuo de tareas programadas (`schedule:work`), incluyendo poda periódica de sesiones Webchat | Red Docker interna |
| `postgres` | PostgreSQL 16 Alpine | Base de datos relacional persistente (**única fuente de verdad de datos de negocio**) | Puerto host 5432 (o red interna) |
| `redis` | Redis 7 Alpine | Caché volátil, sesiones HTTP, colas y throttling de rate limiting | Puerto host 6379 (o red interna) |

> [!IMPORTANT]
> **PHP-FPM nunca se expone a internet o al host directamente**. Permanece en el socket interno `127.0.0.1:9000` dentro del contenedor `backend`.
> El servidor de desarrollo `php artisan serve` ha sido completamente eliminado del runtime productivo.

---

## 2. Gestión del Ciclo de Vida del Stack

Todos los comandos deben ejecutarse desde la raíz del proyecto donde reside `docker-compose.yml` y `.env`.

### Iniciar servicios
```bash
docker compose up -d
```

### Detener servicios
```bash
docker compose stop
```

### Detener y remover contenedores (preservando volúmenes)
```bash
docker compose down
```

### Reiniciar un servicio específico
```bash
docker compose restart backend
docker compose restart scheduler
docker compose restart reverb
```

### Estado de los servicios y healthchecks
```bash
docker compose ps
```

---

## 3. Logs y Monitoreo

Por defecto en producción, todos los contenedores emiten sus logs a `stdout` / `stderr` (`LOG_CHANNEL=stderr`). La retención y rotación de logs corresponde al daemon de Docker del host (o agente de logging como Vector, Promtail o FluentBit).

### Visualizar logs en tiempo real
```bash
# Todos los servicios
docker compose logs -f

# Backend (Nginx + PHP-FPM)
docker compose logs -f backend

# Scheduler de tareas programadas
docker compose logs -f scheduler

# Servidor WebSocket Reverb
docker compose logs -f reverb
```

### Configuración recomendada de rotación en Docker (`/etc/docker/daemon.json`)
```json
{
  "log-driver": "json-file",
  "log-opts": {
    "max-size": "50m",
    "max-file": "5"
  }
}
```

---

## 4. Health Checks y Observabilidad

El stack provee tres endpoints de verificación operacional:

### A. Health Liveness (`/health`)
Verifica que el servidor web y PHP-FPM estén vivos. Retorna la versión del aplicativo.
```bash
curl -i http://localhost:8000/health
# o a través del frontend gateway:
curl -i http://localhost:3000/health
```
Respuesta esperada (HTTP 200):
```json
{
  "status": "ok",
  "app": "Conexa",
  "version": "1.0.0",
  "timestamp": "2026-10-04T21:00:00Z"
}
```

### B. Health Readiness (`/health/ready`)
Verifica la conectividad activa con PostgreSQL, Redis y Reverb.
```bash
curl -i http://localhost:3000/health/ready
```
Respuesta esperada (HTTP 200):
```json
{
  "status": "ready",
  "version": "1.0.0",
  "checks": {
    "database": "ok",
    "redis": "ok",
    "realtime": "ok"
  },
  "timestamp": "2026-10-04T21:00:00Z"
}
```

### C. Health Realtime (`/health/realtime`)
Comprueba la disponibilidad del socket de Reverb.
```bash
curl -i http://localhost:3000/api/health/realtime
```

---

## 5. Procedimiento de Backup

PostgreSQL reside en el volumen Docker `postgres_data`. Para generar un backup seguro, íntegro y con checksum:

```bash
./scripts/backup.sh
```

### Características del Backup:
- Formato: Snapshot binario comprimido de PostgreSQL (`pg_dump -Fc`).
- Destino: `./backups/conexa_<database>_<timestamp>.dump` (configurable vía `BACKUP_DIR`).
- Permisos: `600` (lectura/escritura exclusiva para el usuario).
- Checksum: Archivo `.sha256` generado automáticamente.
- Metadatos: Archivo `.meta` con timestamp UTC, versión de la app, tamaño y checksum.
- No imprime contraseñas ni cadenas de conexión en pantalla o logs.

---

## 6. Procedimiento de Restore

> [!CAUTION]
> El restore reemplaza todos los datos existentes en la base de datos de destino.

### Ejecución Manual Interactiva:
```bash
./scripts/restore.sh ./backups/conexa_conexa_20261004_120000Z.dump
```
El script solicitará confirmación explícita escribiendo `RESTORE` en mayúsculas antes de proceder.

### Pasos automáticos ejecutados por el restore:
1. Valida que el archivo exista y no esté vacío.
2. Valida la integridad del archivo mediante el checksum SHA-256 (si existe el archivo `.sha256`).
3. Solicita confirmación explícita (o `--confirm-restore` en automatizaciones).
4. Restaura las tablas y datos usando `pg_restore --clean --if-exists`.
5. Ejecuta migraciones pendientes posteriores (`php artisan migrate --force`).
6. Ejecuta comprobación de salud post-restore.

---

## 7. Procedimiento de Actualización (Upgrade)

Las actualizaciones de producción deben ejecutarse a partir de un **release/tag formal**, nunca sobre branches de desarrollo inestables.

### Pasos:
1. **Verificar versión actual y estado del stack:**
   ```bash
   docker compose ps
   curl -s http://localhost:3000/health
   ```
2. **Generar un backup previo obligatorio:**
   ```bash
   ./scripts/backup.sh
   ```
3. **Descargar la nueva versión/release:**
   ```bash
   git fetch --tags
   git checkout tags/v1.0.1
   ```
4. **Construir las nuevas imágenes:**
   ```bash
   docker compose build
   ```
5. **Aplicar la nueva versión:**
   ```bash
   docker compose up -d
   ```
6. **Ejecutar migraciones de base de datos:**
   ```bash
   docker compose exec -T backend php artisan migrate --force --no-interaction
   ```
7. **Verificar healthchecks y smoke tests:**
   ```bash
   curl -s http://localhost:3000/health/ready
   ```

---

## 8. Procedimiento de Rollback

Si una actualización presenta anomalías:

### Caso A: Migración Compatible hacia Atrás (Backward-compatible)
Si la nueva release no introdujo migraciones destructivas (eliminación de columnas o tablas activas):
1. Regresar al tag anterior del código:
   ```bash
   git checkout tags/v1.0.0
   ```
2. Reconstruir o levantar las imágenes anteriores:
   ```bash
   docker compose build
   docker compose up -d
   ```
3. Verificar operatividad:
   ```bash
   curl -s http://localhost:3000/health/ready
   ```

### Caso B: Migración No Compatible hacia Atrás
Si la release fallida modificó irreversiblemente la estructura de datos:
1. Regresar el código al tag anterior (`git checkout tags/v1.0.0`).
2. Restaurar el backup de base de datos generado inmediatamente antes del upgrade:
   ```bash
   ./scripts/restore.sh ./backups/conexa_conexa_<timestamp_previo>.dump
   ```
3. Reconstruir e iniciar servicios (`docker compose build && docker compose up -d`).
4. Verificar salud del aplicativo.

---

## 9. Scheduler y Mantenimiento Periódico

El contenedor `conexa_scheduler` ejecuta continuamente `php artisan schedule:work`.

Tareas programadas configuradas:
- `webchat:sessions:prune`: Ejecución diaria. Elimina sesiones de Webchat expiradas según el umbral `WEBCHAT_SESSION_TTL_DAYS` (por defecto 30 días) para evitar crecimiento innecesario de la tabla `webchat_sessions`.

Para verificar la ejecución manual del prune:
```bash
docker compose exec -T backend php artisan webchat:sessions:prune
```

---

## 10. Modificación Operacional de Configuración (.env)

Cuando un operador modifique variables en `.env` (por ejemplo, cambios de dominio en `APP_URL`, dominios permitidos en `SANCTUM_STATEFUL_DOMAINS`, credenciales o puertos):

Para compilar y aplicar los cambios sin dejar configuraciones obsoletas en caché:

```bash
# 1. Aplicar cambios de variables a los contenedores
docker compose up -d

# 2. Purgar y recompilar caché de configuración de Laravel
docker compose exec -T backend php artisan config:clear
docker compose exec -T backend php artisan config:cache
docker compose exec -T backend php artisan route:cache
docker compose exec -T backend php artisan view:cache

# 3. Reiniciar workers y servicios dependientes
docker compose restart backend reverb scheduler
```

> [!NOTE]
> `scripts/install.sh` ejecuta este ciclo de caché automáticamente al finalizar la instalación. Este procedimiento manual solo es requerido si se edita el archivo `.env` con posterioridad a la instalación inicial.
