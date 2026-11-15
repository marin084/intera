# Alertas de vencimiento de tokens de Meta por Discord

Conexa consulta a Meta la vigencia de cada token WhatsApp de canal activo cada 15 minutos. Meta devuelve si el token sigue válido, su fecha de vencimiento y, cuando aplica, la fecha de vencimiento del acceso a datos. Conexa no guarda una copia de esas fechas ni escribe tokens/App Secrets en los logs o en Discord.

## Requisitos

- En la configuración de cada canal WhatsApp: **Meta App ID**, **App Secret** y **Access Token** de la misma app de Meta. El App Secret y Access Token existentes se mantienen cifrados.
- En Discord: la URL de un webhook de un canal privado.
- El contenedor `scheduler` de Conexa debe estar corriendo.

El App ID se encuentra en Meta Developers, en la app correcta, bajo **Configuración de la app → Básica**. No se debe confundir con Phone Number ID ni con WABA ID.

## Configuración de Discord desde Conexa

1. En Discord, abre el canal donde deben llegar las alertas y ve a **Editar canal → Integraciones → Webhooks → Nuevo webhook**. Ponle un nombre como `Conexa Alerts`, selecciona ese canal y copia la URL del webhook.
2. En Conexa, abre **Ajustes del sistema → Notificaciones de Discord** y pega la URL. El servidor la cifra en la base de datos con `APP_KEY`. La pantalla solo muestra si está configurada; nunca vuelve a mostrar el secreto.
3. Pulsa **Enviar prueba** y confirma que llegue al canal de Discord.
4. En **Canales → editar canal WhatsApp**, completa Meta App ID y guarda. El ID no es secreto; debe corresponder al App Secret y al token configurados.

La URL completa funciona como una contraseña: no la publiques ni la pegues en chats o logs. Para cambiarla, pega una nueva en Ajustes. Para desconectarla, usa **Quitar webhook**.

## Alertas que se envían

- Aviso cuando un vencimiento de token o de acceso a datos esté a 24 horas o menos.
- Segundo aviso crítico cuando falte una hora o menos, y aviso crítico cuando ya venció.
- Aviso si Meta invalida el token, si el token pertenece a otro App ID o si Meta no permite consultar la vigencia.
- Si Meta confirma que no hay vencimiento automático, se informa una vez para que quede claro que la consulta sí funcionó.

Las alertas se deduplican para no repetir el mismo vencimiento cada 15 minutos. Cuando se cambia el token, la siguiente consulta detecta y usa la nueva fecha.

## Alcance

El monitor depende del scheduler, del acceso de salida a `graph.facebook.com` y de Discord. Si el VPS completo o el scheduler se detiene, Conexa no puede avisar desde ese mismo servidor; para detectar una caída total se necesita además un monitor externo de uptime apuntando a `https://<dominio-de-conexa>/health/ready`.
