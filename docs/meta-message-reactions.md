# Reacciones de mensajes de Meta

Conexa procesa las reacciones entrantes de Facebook Messenger e Instagram Direct como una actualización del mensaje original. No las convierte en mensajes nuevos ni las pasa al flujo del bot.

## Activación después del despliegue

Cuando este cambio esté desplegado, suscribe el campo **`message_reactions`** en Meta:

- **Facebook Messenger:** Meta for Developers → Messenger → Settings/Webhooks → objeto **Page** → suscribe `message_reactions` para la página conectada.
- **Instagram Direct:** Meta for Developers → Messenger → Instagram settings → Webhooks → suscribe `message_reactions` para la cuenta conectada.

No hace falta cambiar la URL callback ni el verify token si el webhook actual ya está validado. No suscribas este campo antes de desplegar el cambio, para que Conexa ya pueda procesar los eventos cuando Meta empiece a enviarlos.

## Comportamiento

- Una reacción nueva se guarda en los metadatos del mensaje al que apunta Meta.
- Cambiar la reacción reemplaza la anterior; quitarla elimina el indicador.
- La bandeja muestra el emoji debajo del mensaje y se actualiza en tiempo real.
- Los eventos repetidos son idempotentes y nunca reabren conversaciones ni ejecutan flujos.
- Si llega una reacción cuyo mensaje original no está en Conexa, se reconoce el webhook y se registra un diagnóstico sin guardar contenido ni IDs sin procesar.
