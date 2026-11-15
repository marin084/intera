# Guía de conexión de canales en Conexa

**Versión de la guía:** 1.0  
**Revisada:** 11 de noviembre de 2026  
**Canales cubiertos:** WhatsApp Cloud API, Facebook Messenger, Instagram Direct, Telegram Bot y Webchat.

Esta guía recoge el procedimiento de conexión de Conexa y los detalles que conviene revisar cuando Meta, Telegram o el sitio web no entregan mensajes. Los nombres exactos de algunos menús de Meta pueden cambiar con el tiempo; los nombres de campos de Conexa y sus rutas se contrastaron con el código actual del proyecto.

## Índice

1. Antes de empezar
2. Datos de webhook y seguridad
3. WhatsApp Cloud API
4. Facebook Messenger
5. Instagram Direct
6. Telegram Bot
7. Webchat
8. Vincular y publicar el flujo
9. Prueba de aceptación y resolución de problemas
10. Alertas independientes para Conexa
11. Fuentes oficiales

## 1. Antes de empezar

### 1.1 Requisitos generales

- Acceso de administrador a Conexa y a los activos que se conectarán.
- Para Meta: acceso a Meta for Developers, al portafolio comercial y a la Página, cuenta de WhatsApp o cuenta profesional de Instagram que se usará.
- Para Telegram: acceso a Telegram y a `@BotFather`.
- Para Webchat: acceso al código o al administrador del sitio web donde se instalará el widget.
- Un flujo de Conexa publicado y compatible con el canal. Se puede crear el canal sin flujo, pero el bot no ejecutará la automatización inicial.
- Un dominio público HTTPS para los webhooks. Telegram exige HTTPS para registrar el webhook; Meta también necesita acceder públicamente al callback.

### 1.2 Regla importante para ordenar la configuración

Para cada canal de Meta, conviene crear primero el canal en Conexa. Luego se copian desde Conexa la URL de callback y el Verify Token y se pegan en Meta. Así evitamos inventar el token de verificación o dejarlo distinto en cada plataforma.

En Conexa:

1. Abre **Canales** y elige **Crear Canal**.
2. Selecciona el tipo correcto, escribe un nombre administrativo y completa los campos propios del canal.
3. Si el flujo todavía no aparece en el selector, deja **Flujo Inicial** sin seleccionar y guarda el canal. Se puede volver a editar.
4. En la fila del canal, abre **Webhook Meta**, **Webhook Messenger**, **Webhook Instagram** o **Webhook Bot**, según corresponda.
5. Copia la URL y el token que muestra Conexa. La URL contiene el identificador del canal; no la reconstruyas a mano.

Cada callback de Meta que muestra Conexa lleva el UUID de un canal. No reutilices la URL de otro canal. Si se pretende conectar varias cuentas o páginas detrás de una sola aplicación de Meta, valida primero el enrutamiento: Meta configura callbacks por producto/objeto y el callback de Conexa identifica un canal específico.

## 2. Datos de webhook y seguridad

### 2.1 Qué significa cada dato

| Dato | Para qué sirve | Dónde se obtiene |
|---|---|---|
| **App ID** | Identifica la aplicación de Meta. | Meta for Developers > la aplicación > encabezado o Ajustes de la app > Básica. |
| **App Secret** | Firma/verifica solicitudes de webhook de Meta. No es el App ID ni el Verify Token. | Meta for Developers > Ajustes de la app > Básica > App Secret. Puede requerir volver a autenticarte. |
| **Page ID** | Identifica la Página de Facebook vinculada a Messenger o Instagram. | Configuración/Información de la Página o Meta Business Suite. También puede consultarse con Graph API si el token tiene permisos. |
| **Instagram Account ID** | Identifica la cuenta profesional de Instagram conectada. Es distinto del Page ID. | Consulta la cuenta profesional vinculada a la Página usando Graph API Explorer con el token y permisos adecuados; por ejemplo, el campo `instagram_business_account`. |
| **Phone Number ID** | Identifica el número registrado en WhatsApp Cloud API. No es el número telefónico visible. | Meta App Dashboard > WhatsApp > API Setup / Configuración de la API. |
| **WABA ID** | Identifica la cuenta de WhatsApp Business. | La misma sección de WhatsApp en Meta. En Conexa es opcional en algunos flujos, pero conviene completarlo. |
| **Access Token** | Autoriza las llamadas de envío/consulta a Meta. | Se genera desde el producto o desde Business Settings, según el canal y el modelo de acceso. |
| **Webhook Verify Token** | Se usa una sola vez para que Meta compruebe el callback durante la verificación. | Se genera en Conexa y se copia exactamente a Meta. No es un access token. |
| **Telegram Bot Token** | Autoriza las llamadas de Conexa a la Bot API de Telegram. | `@BotFather`, al crear o consultar el bot. |
| **Webhook Secret Token** | Telegram lo devuelve en una cabecera para autenticar cada actualización. | Se define en Conexa; se recomienda generarlo allí. |

### 2.2 Reglas de manejo de credenciales

- No pegues tokens, App Secret ni URL de webhook en capturas, tickets públicos o mensajes compartidos.
- Conexa cifra los secretos del canal y los oculta al volver a editarlo. Si el campo aparece como guardado y se deja vacío, el valor existente se conserva. Para rotarlo, pega el nuevo secreto y guarda.
- El **Meta App Secret** pertenece a una aplicación concreta. No mezcles el App ID de una app con el App Secret o token de otra.
- El token breve generado en Graph API Explorer es útil para pruebas, pero puede expirar rápidamente. Para una conexión duradera usa el tipo de token adecuado a la cuenta y al producto; revisa su vigencia al generarlo. El monitor de Conexa puede avisar de la vigencia consultada, pero no renueva automáticamente el token.
- Para WhatsApp, la interfaz de Conexa solicita un token de System User y muestra el permiso `whatsapp_business_messaging`. Revisa también que el usuario del sistema tenga asignados el activo y permisos de la WABA que se conectará.
- Para Messenger, usa un **Page Access Token** de la misma Página que el Page ID del canal y con `pages_messaging`.
- Para Instagram, usa el token de Meta/Página vinculado a la Página que aparece en el canal, con `instagram_manage_messages`.

### 2.3 Rutas de callback de Conexa

Sustituye `TU_DOMINIO` por el dominio público de la instancia y copia el UUID real desde la ventana del webhook en Conexa:

| Canal | Ruta |
|---|---|
| WhatsApp | `https://TU_DOMINIO/api/v1/public/webhooks/whatsapp/UUID_DEL_CANAL` |
| Facebook Messenger | `https://TU_DOMINIO/api/v1/public/webhooks/messenger/UUID_DEL_CANAL` |
| Instagram Direct | `https://TU_DOMINIO/api/v1/public/webhooks/instagram/UUID_DEL_CANAL` |
| Telegram Bot | `https://TU_DOMINIO/api/v1/public/webhooks/telegram/UUID_DEL_CANAL` |

La ventana **Webhook Meta/Messenger/Instagram/Bot** de Conexa presenta la URL exacta y permite copiarla. En Meta, el Verify Token debe coincidir carácter por carácter con el que creó ese canal. Las solicitudes POST de Meta se validan con el App Secret mediante `X-Hub-Signature-256`; Telegram usa `X-Telegram-Bot-Api-Secret-Token`.

## 3. WhatsApp Cloud API

### 3.1 Preparar WhatsApp en Meta

1. Abre [Meta for Developers](https://developers.facebook.com/) y selecciona la aplicación correcta.
2. En **WhatsApp > API Setup / Configuración de la API**, confirma que está seleccionada la cuenta de WhatsApp Business correcta.
3. Copia el **Phone Number ID** y el **WABA ID**. No uses el número telefónico como Phone Number ID.
4. Para una conexión estable, configura un usuario del sistema en Business Settings, asígnale la aplicación y los activos de WhatsApp necesarios, y genera el token con los permisos requeridos. Evita basar una integración continua en el token temporal que entrega la pantalla de prueba.
5. Copia el **App ID** desde la app y el **App Secret** desde **Ajustes de la app > Básica**.
6. Comprueba que el número de prueba o de negocio se encuentre agregado y habilitado en la app de Meta. Para pruebas, agrega el teléfono destinatario como número permitido si Meta lo solicita.

### 3.2 Crear el canal de WhatsApp en Conexa

1. En **Canales > Crear Canal**, selecciona **WhatsApp**.
2. Completa:
   - **Nombre administrativo:** por ejemplo, `WhatsApp Atención` o `WABA Test`.
   - **Flujo Inicial:** selecciona un flujo publicado y compatible, o déjalo vacío para conectarlo después.
   - **Phone Number ID:** el ID numérico de Meta.
   - **WABA ID / Business Account ID:** el ID de la WABA.
   - **Meta App ID:** el ID numérico de la misma app. Conexa lo usa también para consultar la vigencia del token.
   - **Permanent Access Token (System User):** pega el token del usuario del sistema.
   - **Webhook Verify Token:** pulsa **Generar token seguro** y conserva el valor para el siguiente paso.
   - **Meta App Secret:** el secreto de esa misma app.
   - **Versión Graph API:** conserva la versión propuesta por Conexa, salvo que la app esté configurada para una versión compatible distinta.
3. Guarda el canal.

### 3.3 Registrar el webhook en Meta

1. En Conexa, abre la acción **Webhook Meta** del canal recién creado y copia **Callback URL** y **Verify Token**.
2. En Meta App Dashboard, entra a **WhatsApp > Configuration / Configuración > Webhook** y elige **Edit**.
3. Pega la URL y Verify Token; selecciona **Verify and save**.
4. En los campos del webhook de WhatsApp, activa **`messages`**. Ese campo entrega mensajes entrantes y actualizaciones de estado de mensajes.
5. Confirma que el webhook quedó verificado y que la suscripción aparece activa.

### 3.4 Comprobar envío y recepción

1. Desde un teléfono permitido, envía un mensaje al número de prueba o al número conectado.
2. Confirma que el mensaje aparece en **Conexa > Conversaciones** y que se activa el flujo esperado.
3. Responde desde el bot y desde un agente y revisa los estados de entrega en el chat.
4. Prueba una imagen y un PDF si el flujo usa adjuntos. La precarga de archivos estáticos del flujo reduce la latencia, pero cada tipo de archivo y conexión externa debe verificarse.
5. Envía un mensaje entrante nuevo para actualizar la ventana de atención. Fuera de las 24 horas desde el último mensaje del usuario, WhatsApp exige usar una plantilla aprobada para iniciar contacto; el mensaje libre del agente o bot no reabre esa ventana.
6. Revisa **Dashboards > Consumo** si necesitas contar mensajes; el costo reportado depende de los datos disponibles en Meta y de la configuración/token de la WABA.

## 4. Facebook Messenger

### 4.1 Requisitos

- Una Página de Facebook que administres.
- Una aplicación de Meta con el producto Messenger.
- La Página agregada a esa aplicación y un Page Access Token de esa Página.
- El permiso `pages_messaging`. Para interactuar con personas fuera de roles de desarrollo/prueba, puede requerirse App Review y el acceso correspondiente; el modo Development limita la audiencia.

### 4.2 Obtener el token de Página

1. En Meta Developers, abre tu aplicación y entra en **Messenger > Messenger API Settings** (la ruta puede aparecer como **Messenger > Settings**).
2. En **Generate access tokens**, agrega o selecciona la Página correcta.
3. En **Webhook Subscription / Add Subscriptions**, selecciona `messages` y `messaging_postbacks` para recibir mensajes y respuestas/clics de interacción.
4. Si se necesita registrar reacciones, añade `message_reactions` cuando esté disponible en la configuración de la Página.
5. Genera el token y cópialo en el momento: Meta puede mostrarlo una sola vez. No lo confundas con el App Secret ni con el Verify Token.

### 4.3 Crear el canal en Conexa y configurar Meta

1. En **Canales > Crear Canal**, elige **Messenger**.
2. Completa el nombre, **Facebook Page ID**, **Meta App ID** (opcional en el formulario), **Page Access Token**, **Webhook Verify Token** y **Meta App Secret**. El token debe corresponder al Page ID indicado.
3. Selecciona el flujo publicado compatible o guarda el canal sin flujo y asígnalo después.
4. Guarda y abre **Webhook Messenger** en la fila del canal. Copia la URL y el Verify Token.
5. En Meta Developers entra a **Messenger > Messenger API Settings > Configure webhooks**. Pega la URL y el token y selecciona **Verify and save**.
6. Verifica las suscripciones de Página `messages` y `messaging_postbacks`; añade `message_reactions` solo si quieres recibir esas reacciones.
7. Comprueba que la Página utilizada en la suscripción es la misma de la que generaste el Page Access Token.

### 4.4 Prueba

1. En modo Development, prueba con una cuenta que tenga un rol permitido en la app (administrador, desarrollador o tester).
2. Envía un DM a la Página desde Messenger y confirma que llega a Conexa.
3. Prueba una respuesta de flujo, un postback/botón y, si se habilitó el campo, una reacción.
4. Responde desde un agente y revisa el estado. La ventana de atención de Meta es de 24 horas desde el mensaje del usuario; después, no se debe enviar una respuesta libre fuera de las opciones autorizadas por Meta.

## 5. Instagram Direct

Esta conexión cubre **mensajes directos**. No conecta la bandeja de comentarios de publicaciones ni convierte comentarios/mentions en conversaciones.

### 5.1 Preparar la cuenta

1. La cuenta de Instagram debe ser **Profesional: Empresa o Creador**.
2. Vincula la cuenta profesional a la Página de Facebook que administrarás en Meta Business Suite.
3. En la app móvil de Instagram, habilita el acceso a mensajes para las herramientas conectadas. El nombre exacto del menú puede variar; busca **Configuración y privacidad > Mensajes y respuestas a historias / Controles de mensajes > Permitir acceso a los mensajes**.
4. Usa la aplicación de Meta y la Página enlazadas. No mezcles la opción nueva **Instagram Business Login** con el flujo de token de Página descrito aquí.

### 5.2 Obtener los IDs y el token

1. Copia el **Facebook Page ID** de la Página vinculada.
2. Obtén el **Instagram Business Account ID** de la cuenta profesional vinculada. En Graph API Explorer se puede consultar la Página con el campo `instagram_business_account`; usa un token de Página con los permisos adecuados. El ID devuelto es el Instagram Account ID y no es igual al Page ID.
3. En Meta Developers, usa **Messenger > Instagram settings** (o **Instagram settings**, según la versión de la interfaz) para configurar la integración de mensajería y generar el token de la Página/cuenta vinculada.
4. Asegúrate de que el token tenga el permiso `instagram_manage_messages` y corresponde a la Página correcta. Los permisos de acceso avanzado y App Review pueden ser necesarios para atender usuarios fuera de los roles de la aplicación.
5. Copia el App ID y el App Secret de la aplicación Meta que se usa en esta integración.

### 5.3 Crear el canal y configurar webhooks

1. En **Canales > Crear Canal**, selecciona **Instagram**.
2. Completa el nombre, **Instagram Business Account ID**, **Facebook Page ID Vinculada**, **Instagram / Meta Access Token**, **Webhook Verify Token** y **Meta App Secret**. El Meta App ID es opcional en el formulario.
3. Selecciona un flujo publicado compatible o guarda el canal y asígnalo luego.
4. Guarda el canal y abre **Webhook Instagram**. Copia Callback URL y Verify Token.
5. En la configuración de Meta Developers utilizada para Instagram Direct, pega esos datos en el webhook del objeto Instagram y verifica/guarda.
6. En las suscripciones de la Página/cuenta, activa `messages` y `messaging_postbacks`. Para procesar reacciones, activa también `message_reactions` si aparece en la configuración de este producto.
7. Mantén desactivados los campos de comentarios, menciones y publicaciones si solo se desea mensajería directa.
8. Si Meta muestra un aviso de que los webhooks de Instagram Business Login solo se configuran dentro del producto, verifica que sigues usando **Messenger > Instagram settings** y el flujo de token de Página. No cambies de modalidad durante la conexión.

### 5.4 Prueba

1. Envía un DM desde una cuenta que pueda probar la aplicación.
2. Confirma que Conexa crea la conversación bajo el canal Instagram y ejecuta el flujo.
3. Prueba botones/postbacks y, opcionalmente, una reacción.
4. Confirma que comentarios de publicaciones no aparecen como DMs.
5. Recuerda que Meta limita respuestas libres a la ventana de atención de 24 horas desde el mensaje del usuario; después el usuario debe volver a escribir antes de continuar el intercambio normal.

## 6. Telegram Bot

### 6.1 Crear el bot en Telegram

1. En Telegram abre el bot oficial **`@BotFather`**.
2. Envía `/newbot` y sigue las indicaciones para definir nombre y username. El username debe terminar en `bot`.
3. Copia el **HTTP API Token** que devuelve BotFather y guárdalo como secreto. Si se expone, revócalo/renuévalo en BotFather.

### 6.2 Crear el canal y registrar el webhook

1. En Conexa entra a **Canales > Crear Canal > Telegram**.
2. Completa el nombre del canal, el **Bot Token**, el username sin `@` (opcional) y pulsa **Generar secret** para crear un **Webhook Secret Token** (recomendado).
3. Selecciona el flujo inicial si ya está publicado y compatible.
4. Guarda el canal.
5. En la fila del canal pulsa **Webhook Bot** y luego **Registrar en Telegram**. Conexa registra el callback HTTPS y el secreto ante la Bot API.
6. Comprueba que la respuesta indique registro correcto. Si hace falta, consulta el estado del webhook desde la misma ventana de Conexa.

### 6.3 Prueba

1. Abre el bot usando su username y pulsa **Start** o envía `/start`. Un bot no puede iniciar por sí solo una conversación con un usuario que nunca lo contactó.
2. Envía un texto y confirma que aparece en Conexa.
3. Prueba las opciones interactivas y una respuesta del agente.
4. Telegram no usa la ventana de atención de 24 horas de Meta. Aplican los límites y reglas propios de Telegram.

## 7. Webchat

### 7.1 Crear el canal

1. En Conexa entra a **Canales > Crear Canal > Webchat**.
2. Completa nombre administrativo, nombre visible del widget y los **Orígenes / Dominios Permitidos**. Escribe un origen por línea, con protocolo y puerto exactos cuando aplique. Ejemplos:
   - `https://empresa.example`
   - `https://www.empresa.example`
   - `http://localhost:3000` (solo desarrollo local)
3. No agregues ruta de página, query string ni barra final: `https://empresa.example/contacto` no es un origen válido.
4. Ajusta el color, la ubicación del botón y el mensaje de bienvenida si corresponde.
5. Selecciona el flujo inicial compatible y guarda.

### 7.2 Instalar el widget

1. En la fila del canal usa **Código** para copiar el snippet generado por Conexa. Tiene esta forma:

```html
<script
  src="https://TU_DOMINIO/widget/conexa.js"
  data-channel="TU_CLAVE_PUBLICA"
  async>
</script>
```

2. Sustituye los marcadores con los valores exactos que muestra Conexa; normalmente el botón **Código** ya entrega el snippet listo.
3. Pega el script antes de `</body>` o en la sección de HTML personalizado del constructor del sitio.
4. Guarda/publica el sitio y ábrelo desde el dominio que agregaste a la lista de orígenes.
5. Prueba una conversación real. Si regeneras la clave pública, reemplaza el snippet instalado: la clave anterior deja de conectar.

No se requiere Meta App ID, token ni webhook de Meta para Webchat.

## 8. Vincular y publicar el flujo

Los flujos se filtran por compatibilidad de canal. Los tipos que reconoce Conexa son `whatsapp`, `facebook_messenger`, `instagram`, `telegram` y `webchat`.

1. Abre **Flujos** y edita el flujo que quieres usar.
2. En la configuración del flujo, revisa **Canales compatibles / Aplicabilidad del canal** y activa el tipo exacto del canal. Un flujo creado con la configuración predeterminada puede estar limitado a `webchat`.
3. Guarda y publica una nueva versión del flujo.
4. Regresa a **Canales**, edita el canal y selecciona el flujo publicado en **Flujo Inicial (Entry Flow)**.
5. Guarda y envía un mensaje de prueba desde el canal. Verifica la versión de flujo activa y el nodo de inicio.

Si Conexa muestra **“No hay flujos publicados compatibles con canales de tipo …”**, no es un error del token: revisa compatibilidad del flujo y que tenga una versión publicada.

La apariencia de botones, listas, adjuntos y reacciones puede variar por plataforma. Un flujo compatible por tipo no garantiza que cada nodo tenga una presentación idéntica en todos los canales; prueba cada interacción en la plataforma real.

## 9. Prueba de aceptación y resolución de problemas

### 9.1 Lista de verificación por canal

| Comprobación | Resultado esperado |
|---|---|
| El canal se guardó y está activo | Aparece en Canales sin error de configuración. |
| IDs pertenecen a los activos conectados | Page ID, Instagram ID, Phone Number ID y WABA ID no se intercambiaron. |
| Token coincide con app/cuenta | Token, App ID, App Secret y activos son de la misma configuración de Meta. |
| Webhook verificado | Meta/TG aceptó la URL y token exactos de Conexa. |
| Campos requeridos suscritos | Llegan mensajes y postbacks; reacciones solo si se activaron. |
| Flujo inicial compatible y publicado | Se ejecuta al iniciar/reabrir según las reglas del canal. |
| Entrada | Un mensaje nuevo aparece una sola vez en Conexa. |
| Salida | Bot/agente envía y el estado de entrega se actualiza. |
| Restricciones del canal | Ventana, roles de prueba, permisos y limitaciones de adjuntos están entendidos. |

### 9.2 Errores frecuentes

| Síntoma | Qué revisar primero |
|---|---|
| Meta no valida Callback URL / Verify Token | Copia ambos valores de la ventana del webhook del mismo canal; comprueba que no se perdió ningún carácter, que el dominio responde por HTTPS y que no usaste el UUID de otro canal. |
| Entran mensajes, pero no responde el flujo | El canal puede estar sin Entry Flow; comprueba que el flujo está publicado y que su aplicabilidad incluye ese tipo de canal. |
| `Authentication Error` al enviar | Token vencido/revocado, token generado para otra app/Página/WABA, permiso faltante o App ID/App Secret mezclados. Regenera el token correcto y actualiza el canal; no compartas el valor por chat. |
| Mensajes de Meta no llegan en Development | Prueba con una cuenta que tenga rol de app; para usuarios generales se necesita modo Live y los accesos/revisiones que Meta pida. Confirma además las suscripciones del webhook. |
| Instagram muestra la cuenta pero no llegan DMs | Confirma cuenta profesional, Página vinculada, acceso a mensajes habilitado en Instagram móvil, `messages` suscrito, token con `instagram_manage_messages` y uso del producto Messenger > Instagram settings. |
| Canal incorrecto o activos cruzados | Compara IDs numéricos. Instagram Business Account ID y Facebook Page ID son distintos; Phone Number ID no es el teléfono visible. |
| Telegram no entrega updates | Confirma token, que el usuario haya iniciado el bot, webhook registrado, HTTPS público, secret token sin espacios y resultado de **Webhook Bot > Registrar en Telegram**. |
| Webchat muestra error de origen/CORS | Agrega el origen exacto (esquema, host y puerto) en Allowed Origins. No uses ruta ni barra final. |
| Adjuntos fallan o tardan | Prueba tipo y tamaño por separado; confirma que la URL del archivo sea pública desde el proveedor. Los límites de medios cambian por canal y no todos los adaptadores tienen el mismo soporte. |
| Recibo duplicados | No reenvíes repetidamente para probar. Revisa IDs externos, webhooks activos duplicados y estado del mensaje en Conexa. Conexa deduplica eventos de Meta/Telegram, pero una configuración repetida puede crear rutas confusas. |

## 10. Alertas independientes para Conexa

### 10.1 Herramienta recomendada

Para detectar que el dominio/servidor dejó de responder incluso si Conexa no puede mandar una alerta, recomendamos **UptimeRobot conectado directamente a un webhook de Discord**. Es un monitor alojado fuera del VPS; no depende de la base de datos, Redis ni el proceso de Conexa para enviar una alerta de caída. UptimeRobot documenta la integración nativa con Discord y señala que está disponible en su plan gratuito actual. Ese plan indica 50 monitores y comprobaciones cada cinco minutos; revisa los límites del plan al configurarlo porque el proveedor puede cambiarlos.

Usa un canal de Discord separado, por ejemplo `#conexa-uptime`, y no reutilices el webhook de notificaciones internas de Conexa.

### 10.2 Crear webhook en Discord

1. Crea un canal privado `#conexa-uptime` en el servidor de Discord donde el equipo recibe incidentes.
2. Abre **Editar canal > Integraciones > Webhooks > Nuevo webhook**.
3. Ponle un nombre claro, selecciona ese canal y copia la URL de webhook.
4. Mantén la URL privada. Quien la posea puede publicar mensajes en ese canal.

### 10.3 Conectar UptimeRobot

1. Crea o abre la cuenta de UptimeRobot.
2. En **Integrations & API**, añade la integración **Discord**.
3. Pega la URL del webhook de `#conexa-uptime`, elige los eventos de caída y recuperación y guarda.
4. Crea un monitor HTTP(s) con nombre `Conexa - Ready` y URL:

   `https://TU_DOMINIO/api/health/ready`

   Este endpoint marca como no disponible el servicio si falla la base de datos o Redis. Reverb y la accesibilidad de media se incluyen como indicadores informativos.

5. Crea otro monitor HTTP(s), `Conexa - Realtime`, para:

   `https://TU_DOMINIO/api/health/realtime`

   Este endpoint responde con error HTTP si Conexa no logra conectarse a Reverb.

6. Opcionalmente añade un monitor de la página pública para detectar errores de DNS, TLS, proxy o frontend que los endpoints de salud no cubran.
7. Asocia la integración de Discord con cada monitor y elige las alertas de caída y recuperación.
8. Usa el botón de prueba de la integración. No apagues el VPS ni detengas Conexa para validar el aviso.

### 10.4 Qué detecta cada alerta

- **UptimeRobot:** dominio o endpoint inaccesible, fallo de readiness (DB/Redis) y fallo de Reverb, de acuerdo con los endpoints elegidos.
- **Alertas de Conexa a Discord:** incidentes que la aplicación puede observar, como vigencia/validez de tokens de Meta, webhook u outbox atascado.
- **Límite:** un monitor de uptime no determina por sí mismo si Meta está teniendo una caída regional ni si una cuenta/número perdió permisos. Para eso se mantienen las alertas de aplicación y las páginas de estado de los proveedores.
- **Intervalo:** con comprobaciones de cinco minutos, el aviso no es instantáneo y puede tardar más por reintentos y reglas anti-falsos-positivos. Ajusta el intervalo al nivel de urgencia y al plan disponible.

## 11. Fuentes oficiales

Los pasos de credenciales y los nombres de campos se contrastaron con la interfaz y el código de Conexa. Las pantallas de Meta cambian; consulta también sus guías oficiales vigentes:

- [WhatsApp Cloud API - Get Started](https://developers.facebook.com/docs/whatsapp/cloud-api/get-started/)
- [Messenger Platform - Quick Start](https://developers.facebook.com/docs/messenger-platform/quickstart)
- [Instagram Messaging](https://developers.facebook.com/docs/messenger-platform/instagram/)
- [Graph API Webhooks - Getting Started](https://developers.facebook.com/docs/graph-api/webhooks/getting-started/)
- [Telegram Bot API - Webhooks](https://core.telegram.org/bots/webhooks)
- [UptimeRobot - Discord Integration](https://uptimerobot.com/integrations/discord-integration/)
- [UptimeRobot Help Center - Integrations](https://help.uptimerobot.com/en/articles/11361285-uptimerobot-integrations-basic-information-overview)
- [UptimeRobot - Free Plan](https://help.uptimerobot.com/en/articles/11604710-who-should-use-uptimerobot-s-free-plan)

### Nota de vigencia

Las rutas dentro de Meta Business Suite/Developers, los permisos, las aprobaciones y las condiciones comerciales pueden cambiar. Esta guía sirve para completar la configuración de Conexa con los campos actuales; antes de abrir el canal a usuarios externos, confirma en Meta que la aplicación está en el modo apropiado, que obtuvo los permisos requeridos y que la cuenta/número están habilitados.
