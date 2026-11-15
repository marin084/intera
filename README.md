# Intera — Plataforma Conversacional y Constructor Visual de Flujos

Plataforma conversacional empresarial para despliegue dedicado en servidores e infraestructura privada de clientes (**Single-Tenant por instalación**).

Incluye:
- **Core Platform**: Autenticación segura Sanctum SPA HttpOnly, RBAC granular, canales, contactos, flujos y auditoría.
- **Motor de Ejecución en Runtime**: `FlowRuntimeEngine`, ejecuciones de flujos, idempotencia, version pinning, variables e interpolación, branching condicional y concurrencia.
- **Visual Flow Builder**: Editor interactivo basado en `@xyflow/react`, capa adaptadora agnóstica, validación y sincronización bidireccional.
- **Webchat Embebible**: Widget ligero desacoplado, tokenización segura de visitantes, historial paginado y eventos en tiempo real.
- **Inbox Operable**: Cola `waiting_agent`, claim atómico con bloqueo de concurrencia, reasignación, cierre manual, tracking de no leídos por usuario y paginación con cursor estable `(sent_at, id)` sobre UUIDv7 monotónicos.
- **Infraestructura Productiva**: Runtime desacoplado con Nginx + PHP-FPM (eliminación total de `php artisan serve`), WebSocket Reverb, Scheduler para poda de sesiones, scripts de Backup/Restore con checksum SHA-256 y simulación de disaster recovery.

---

## 🚀 Arquitectura y Runtime Productivo

- **Frontend Gateway**: Nginx sirviendo la SPA React y los assets estáticos del Webchat, enrutando `/api/`, `/sanctum/`, `/health` y WebSocket `/app/`.
- **Backend API & Runtime**: Nginx interno escuchando en puerto 8000 comunicándose vía FastCGI con un pool de workers PHP-FPM (`127.0.0.1:9000`). Supervisord orquesta ambos procesos. **PHP-FPM nunca se expone a internet o al host**.
- **Realtime Broadcasting**: Laravel Reverb en puerto 8080 (proxy WSS a `/app/`).
- **Scheduler**: Servicio dedicado ejecutando `php artisan schedule:work` para mantenimiento y poda periódica de sesiones.
- **Persistencia**: PostgreSQL 16 Alpine en volumen Docker persistente (`postgres_data`). **Única fuente de verdad de datos de negocio**.
- **Caché y Colas**: Redis 7 Alpine.

---

## 💻 Compatibilidad de Navegadores

| Navegador / Plataforma | Nivel de Soporte | Verificación Automatizada |
|---|---|---|
| **Chromium / Google Chrome** | Soporte Oficial | Verificado con suite Playwright completa (14 E2E) + Smoke |
| **Mozilla Firefox** | Soporte Oficial | Verificado con Playwright Cross-Browser Smoke (Embed + Realtime + Historial) |
| **WebKit / Apple Safari** | Soporte Oficial | Verificado con Playwright Cross-Browser Smoke (Embed + Realtime + Historial) |
| **Microsoft Edge** | Soporte Oficial | Compatible mediante motor Chromium |

---

## ⚡ Instalación Productiva Limpia

### Requisitos Mínimos
- Sistema operativo Linux o macOS con Docker y Docker Compose v2.
- Puertos disponibles en host: 3000 (Frontend) y 8000 (Backend API).

> [!NOTE]
> **Production sizing must be benchmarked for the expected workload.**
> Para un entorno base de arranque se recomienda un mínimo de 2 vCPU, 4 GB de RAM y 20 GB de almacenamiento SSD.

### Pasos de Instalación

1. **Obtener el release o clonar el repositorio:**
   ```bash
   git clone <repo_url> conexa
   cd conexa
   ```

2. **Ejecutar el instalador automatizado e idempotente:**
   ```bash
   ./scripts/install.sh
   ```
   El instalador:
   - Valida Docker y Docker Compose.
   - Crea el archivo `.env` a partir de `.env.example` si no existe.
   - Genera una clave criptográfica `APP_KEY` persistente (nunca se sobreescribe en ejecuciones posteriores).
   - Genera contraseñas aleatorias seguras para PostgreSQL y Reverb si se usan los valores por defecto.
   - Construye las imágenes y levanta los servicios en modo desacoplado (`docker compose up -d`).
   - Aplica migraciones y seeds de roles/permisos.
   - Solicita interactivamente las credenciales para el primer Super Administrador (sin exponerlas en logs o argumentos).
   - Valida el estado de salud de todos los servicios.

3. **Puntos de Acceso:**
   - **Panel Administrativo**: [http://localhost:3000](http://localhost:3000)
   - **API REST**: [http://localhost:8000/api/v1](http://localhost:8000/api/v1)
   - **Health Liveness**: [http://localhost:3000/health](http://localhost:3000/health)
   - **Health Readiness**: [http://localhost:3000/health/ready](http://localhost:3000/health/ready)

---

## 🛡️ Operación, Backup, Restore y Mantenimiento

Consulte la documentación técnica completa:
- [Manual de Operaciones (`docs/operations.md`)](docs/operations.md): Inicio, detención, logs, actualización (upgrade), rollback y scheduler.
- [Guía de Despliegue en Producción (`docs/production-deployment.md`)](docs/production-deployment.md): Configuración de Reverse Proxy TLS/HTTPS/WSS, headers obligatorios, seguridad de cookies `Secure`, Sanctum y benchmark.
- [Plan de Recuperación ante Desastres (`docs/disaster-recovery.md`)](docs/disaster-recovery.md): Protocolos para pérdida del volumen de PostgreSQL, rollback de despliegues, caída de Reverb y fallo de Redis.

### Comandos Rápidos

```bash
# Backup de base de datos con formato custom y checksum SHA-256
./scripts/backup.sh

# Restauración con validación de checksum y guardia destructiva
./scripts/restore.sh ./backups/<archivo>.dump

# Prueba automatizada de Backup y Restore en base de datos aislada
./scripts/test-backup-restore.sh

# Simulación de Upgrade sin pérdida de datos
./scripts/test-upgrade-smoke.sh

# Verificación de concurrencia HTTP en PHP-FPM
./scripts/test-concurrency.sh
```

---

## 🧪 Verificación y Tests

```bash
# Tests Backend (PHPUnit)
docker compose exec -T backend php artisan test

# Formato y estilo Backend (Pint)
docker compose exec -T backend ./vendor/bin/pint --test

# Tests Frontend (Vitest)
npm --prefix frontend test

# Linter Frontend (Oxlint)
npm --prefix frontend run lint

# Build Frontend
npm --prefix frontend run build

# Tests End-to-End (Playwright Chromium)
npm --prefix frontend run test:e2e

# Tests Cross-Browser Smoke (Chromium, Firefox, WebKit)
npm --prefix frontend run test:e2e:smoke
```
