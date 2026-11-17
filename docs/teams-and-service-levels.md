# Equipos, colas y tiempos de atención

## Cómo se decide el equipo

- Al pedir atención humana, la conversación entra a la cola asignada al canal.
- El nodo **Transferir a agente** puede elegir un equipo concreto. Si no lo hace, conserva la ruta configurada para el canal.
- Si el equipo indicado en el flujo ya no está activo, se usa la ruta del canal y, cuando el canal no tiene equipo, **Equipo General**.
- Una persona solo puede recibir la conversación si está activa, pertenece al equipo, tiene acceso al canal y conserva capacidad disponible. Estas condiciones se verifican otra vez dentro de la transacción que asigna el chat.
- La cola mantiene el orden de entrada. Prioridad es una señal visual para el equipo; no adelanta conversaciones ni cambia FIFO.

## Qué miden los relojes

- **Espera en cola:** desde que el flujo solicita atención humana hasta que se asigna un agente.
- **Primera respuesta humana:** desde esa misma solicitud hasta el primer mensaje público o externo enviado por una persona agente. Las notas internas no cuentan. El reloj incluye la espera antes de la asignación.
- **Respuestas posteriores:** desde el mensaje más reciente de la persona cliente en cada turno hasta la siguiente respuesta pública de un agente.
- **Resolución:** desde la primera solicitud de atención humana de la conversación hasta su cierre.

Los relojes usan tiempo corrido; todavía no descuentan horarios fuera de oficina ni pausas por espera de respuesta del cliente. El objetivo de espera y el de primera respuesta se configuran por equipo. Si quedan vacíos, aplican los valores generales configurables (`TEAM_DEFAULT_QUEUE_WAIT_SECONDS`, predeterminado 120 s; `TEAM_DEFAULT_FIRST_RESPONSE_SECONDS`, predeterminado 300 s).

## Datos existentes

Al instalar el sistema de equipos, las conversaciones que ya estaban esperando reciben como hora de entrada la fecha de esa migración, porque no existía un registro de cola anterior. Los tiempos históricos previos a esa fecha no se pueden reconstruir; los nuevos registros sí quedan medidos desde la solicitud humana.

La suite SQLite comprueba las reglas de cola y acceso, pero SQLite no ejecuta bloqueos de filas reales. Para probar el límite de cupo bajo concurrencia, usa una base PostgreSQL desechable y el perfil `phpunit.postgres.xml`; la prueba inicia dos procesos a la vez y comprueba que solo uno recibe el último espacio:

```sh
DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=intera_test DB_USERNAME=postgres DB_PASSWORD='' \
  ./vendor/bin/phpunit -c phpunit.postgres.xml --filter TeamAssignmentConcurrencyPostgresTest
```

La base configurada debe estar dedicada a pruebas: `DatabaseMigrations` crea y revierte tablas durante la ejecución. La prueba requiere PostgreSQL y la extensión `pcntl` de PHP.
