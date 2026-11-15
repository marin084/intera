# Conexa — Disaster Recovery Runbook

Este documento establece las acciones inmediatas y protocolos de respuesta ante incidentes graves, fallos de infraestructura o pérdida de componentes del stack productivo.

---

## Escenario 1: Volumen de PostgreSQL Corrupto o Destruido

### Impacto:
Pérdida de la base de datos relacional. Los contenedores no podrán iniciar o responderán con errores 500/503.

### Procedimiento de Recuperación:
1. **Detener el stack y aislar el volumen:**
   ```bash
   docker compose down
   ```
2. **Crear o recrear el volumen limpio:**
   ```bash
   docker volume rm conexa_postgres_data || true
   docker volume create conexa_postgres_data
   ```
3. **Iniciar únicamente la base de datos limpia:**
   ```bash
   docker compose up -d postgres
   ```
4. **Esperar a que PostgreSQL esté listo:**
   ```bash
   docker compose exec -T postgres pg_isready -U conexa -d conexa
   ```
5. **Localizar el backup más reciente y verificar su integridad:**
   ```bash
   LATEST_BACKUP=$(ls -t backups/*.dump | head -n 1)
   echo "Restaurando desde: ${LATEST_BACKUP}"
   ```
6. **Ejecutar el script de restore:**
   ```bash
   ./scripts/restore.sh "${LATEST_BACKUP}"
   ```
7. **Iniciar el resto de los servicios:**
   ```bash
   docker compose up -d
   ```
8. **Validar la salud integral:**
   ```bash
   curl -s http://localhost:3000/health/ready
   ```

---

## Escenario 2: Despliegue Fallido o Release Inestable

### Impacto:
La nueva versión de la aplicación presenta errores de ejecución, incompatibilidades o caídas de contenedores post-upgrade.

### Procedimiento de Recuperación:
1. **Evaluar el tipo de release:**
   - **Caso Compatible hacia Atrás:** No hubo migraciones destructivas.
     ```bash
     git checkout tags/<version_anterior_estable>
     docker compose build
     docker compose up -d
     ```
   - **Caso No Compatible hacia Atrás:** Hubo modificaciones de schema irreversibles.
     1. Regresar código al tag anterior:
        ```bash
        git checkout tags/<version_anterior_estable>
        ```
     2. Restaurar el backup de base de datos generado inmediatamente antes del despliegue:
        ```bash
        ./scripts/restore.sh ./backups/conexa_conexa_<timestamp_pre_upgrade>.dump
        ```
     3. Reconstruir e iniciar contenedores:
        ```bash
        docker compose build
        docker compose up -d
        ```
2. **Validar funcionamiento del sistema:**
   ```bash
   curl -s http://localhost:3000/health/ready
   ```

---

## Escenario 3: Caída del Servidor WebSocket Reverb

### Impacto:
Los clientes Webchat y los agentes de la Bandeja de Entrada dejan de recibir eventos en tiempo real inmediatamente.
**Comportamiento de Resiliencia:**
- Las APIs REST continúan funcionando con normalidad.
- Los mensajes enviados por visitantes y agentes **se guardan exitosamente** en la base de datos.
- El Webchat y el panel de Inbox recargan el historial completo al refrescar la página.

### Procedimiento de Recuperación:
1. **Reiniciar el contenedor de Reverb:**
   ```bash
   docker compose restart reverb
   ```
2. **Comprobar logs de Reverb:**
   ```bash
   docker compose logs --tail=50 reverb
   ```
3. **Verificar el endpoint de salud de realtime:**
   ```bash
   curl -s http://localhost:3000/api/health/realtime
   ```
4. **Reconexión Automática:** Laravel Echo en los navegadores de clientes y agentes se reconectará de forma transparente tras la recuperación del servicio.

---

## Escenario 4: Pérdida o Reinicio del Contenedor Redis

### Impacto Real en la Operación:
- **Datos de Negocio:** **0% de pérdida**. Usuarios, canales, flujos, contactos, conversaciones, mensajes y estados de lectura residen exclusivamente en PostgreSQL.
- **Sesiones HTTP:** Los usuarios administrativos deberán iniciar sesión nuevamente (las sesiones en Redis se invalidan).
- **Caché y Throttling:** Se resetean contadores de rate limiting.
- **Colas:** Trabajos en cola que no hayan sido completados se perderán si Redis no tenía persistencia AOF configurada.

### Procedimiento de Recuperación:
1. Si Redis se detuvo, reiniciarlo:
   ```bash
   docker compose up -d redis
   ```
2. Reiniciar el backend para restablecer el pool de conexiones:
   ```bash
   docker compose restart backend scheduler
   ```
3. Comprobar salud:
   ```bash
   curl -s http://localhost:3000/health/ready
   ```
   (El check `"redis": "ok"` confirmará el restablecimiento).
