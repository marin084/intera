<?php

return [
    /*
    |--------------------------------------------------------------------------
    | WhatsApp Outbox Delivery Timeout (Segundos)
    |--------------------------------------------------------------------------
    |
    | Cantidad de segundos que la cola de salida (outbox) espera el webhook de
    | confirmación 'delivered' de Meta antes de considerar el mensaje en tránsito
    | como no confirmado y desbloquear el envío del siguiente mensaje.
    |
    | NOTA DE ARQUITECTURA (ORDEN vs. BLOQUEO):
    | Desbloquear tras 25 segundos evita que una conversación quede atascada
    | indefinidamente si el dispositivo del destinatario está apagado, sin red,
    | o si Meta demora en procesar y descargar archivos multimedia.
    | Consecuencia documentada: Continuar tras el timeout prioriza la continuidad
    | de la conversación, pero puede comprometer el orden cronológico de llegada
    | en el cliente de WhatsApp si Meta entrega tardíamente el mensaje rezagado.
    |
    */
    'delivery_timeout_seconds' => (int) env('WHATSAPP_DELIVERY_TIMEOUT_SECONDS', 25),

    // Pending outbox messages older than this are surfaced as critical operational alerts.
    'outbox_stuck_after_seconds' => (int) env('WHATSAPP_OUTBOX_STUCK_AFTER_SECONDS', 120),

    /*
    | NOTA DE SEGURIDAD SOBRE REINTENTOS:
    | Meta Graph API no soporta encabezados de idempotencia en /messages.
    | Los reintentos automáticos a ciegas ante excepciones de red pueden causar
    | mensajes duplicados en el teléfono del usuario si Meta recibió el mensaje
    | pero la conexión falló en el retorno. Por ello, solo se permiten reintentos
    | cuando existe evidencia concluyente de que la petición no se envió o fue rechazada
    | explícitamente por Meta (e.g. credenciales faltantes o rechazo de API de Meta),
    | descartando reintentos ante estados ambiguos como excepciones de red (NETWORK_EXCEPTION)
    | o timeouts de entrega (DELIVERY_TIMED_OUT), aunque no tengan WAMID guardado.
    */
];
