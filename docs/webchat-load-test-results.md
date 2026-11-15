# Resultados de la prueba local de capacidad de Webchat

Fecha: 11 de noviembre de 2026 (hora de Costa Rica).

## Alcance y entorno

Se probaron mensajes entrantes sintéticos por la ruta pública de Webchat de Conexa. Cada intento debía recibir HTTP 201 y una respuesta del bot del flujo publicado. Se crearon 250 sesiones aisladas por corrida. PostgreSQL, Redis, worker, Reverb y backend corrieron en el proyecto Docker `conexa-loadtest`, con red interna y sin puertos publicados.

El contenedor del backend tenía un límite de 2 CPU y 2 GiB; PostgreSQL, 1 CPU y 1 GiB. El generador PHP cURL multi corrió dentro del mismo contenedor de backend y compartió su límite de CPU. Por ello, estos números son una medición conservadora de este entorno local y no una capacidad aislada ni una recomendación directa de hardware de producción.

## Resultado de capacidad estable

Perfil `capacity`: escalones de 45 segundos a 25, 30, 35 y 40 mensajes/s, seguidos por 15 segundos de rampa descendente. Duración del perfil: 195 segundos; preparación de 250 sesiones: 12.2 segundos.

| Objetivo | Solicitudes exitosas | p95 | p99 | Descartes por concurrencia |
|---:|---:|---:|---:|---:|
| 25/s | 1,125 / 1,125 | 62 ms | 71 ms | 0 |
| 30/s | 1,350 / 1,350 | 66 ms | 100 ms | 0 |
| 35/s | 1,574 / 1,575 | 88 ms | 414 ms | 0 |
| 40/s | 1,800 / 1,800 | 84 ms | 150 ms | 0 |

En total, 6,149 intentos recibieron HTTP 201 y respuesta del bot, sin errores HTTP ni descartes por concurrencia. Una llegada (0.016%) no fue programada a tiempo por el generador local. El perfil pasó los umbrales definidos: error <1%, p95 <1 s, p99 <2.5 s, cero descartes por concurrencia y menos de 0.1% de omisiones del planificador.

## Resultado del barrido de saturación

Perfil `sweep`: escalones de 45 segundos a 10, 25, 50, 75 y 100 mensajes/s, seguidos por 15 segundos de rampa descendente. Objetivo total: 12,450 llegadas durante 240 segundos; preparación de sesiones: 12.3 segundos.

| Objetivo | Solicitudes exitosas | Descartes por concurrencia | Omitidas por planificador | p95 |
|---:|---:|---:|---:|---:|
| 10/s | 450 / 450 | 0 | 0 | 65 ms |
| 25/s | 1,125 / 1,125 | 0 | 0 | 71 ms |
| 50/s | 1,967 | 270 | 13 | 7.01 s |
| 75/s | 1,729 | 1,645 | 1 | 7.37 s |
| 100/s | 1,569 | 1,235 | 1,696 | 7.88 s |
| Rampa descendente | 412 | 302 | 36 | 7.57 s |

De las 12,450 llegadas objetivo, 7,252 terminaron con HTTP 201 y respuesta del bot; se registraron cero errores HTTP. Las otras 5,198 llegadas se contabilizaron como descartes por el límite de 250 solicitudes simultáneas u omisiones del planificador. La latencia global p50 fue 6.29 s, p95 7.49 s y p99 7.89 s. El perfil falló los objetivos de latencia y de carga sin descartar; la saturación comenzó en el escalón de 50/s.

## Recursos observados

Muestreo de `docker stats` cada cinco segundos durante el barrido de saturación (36 muestras):

| Servicio | CPU promedio | CPU máxima | Memoria máxima observada / límite |
|---|---:|---:|---:|
| Backend + generador | 90.5% | 135.1% | 127.6 MiB / 2 GiB |
| PostgreSQL | 73.1% | 103.6% | 216 MiB / 1 GiB |
| Redis | 2.7% | 4.2% | 12.4 MiB / 256 MiB |
| Reverb | 4.2% | 6.8% | 40.7 MiB / 512 MiB |
| Worker | <0.1% | 0.1% | 34.5 MiB / 768 MiB |

Los picos de CPU superiores a 100% reflejan el uso de más de un núcleo; el contenedor de backend tenía un límite de 2 CPU. La memoria no fue el límite visible en este entorno; la cola/latencia y el CPU compartido dominaron al aumentar el objetivo.

## Verificación posterior y límites

Después del barrido se repitió el smoke: una sesión, HTTP 201 y respuesta del bot; el backend y PostgreSQL reportaron estado saludable. Los logs de la corrida no mostraron fallos de aplicación relacionados con las solicitudes medidas.

Las pruebas no llamaron a Meta, WhatsApp, Instagram, Messenger ni servicios de producción. No se tocaron stacks existentes ni datos reales. Webchat tampoco modela el costo de medios ni el comportamiento de otros canales. Para recomendar capacidad de un cliente, hay que repetir el perfil en un staging aislado con hardware y configuración equivalentes, usando un generador independiente del backend y revisando el camino real de la aplicación.
