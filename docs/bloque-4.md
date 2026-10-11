# Bloque 4 · Google Chat (bot «MAD Oficina») e importación de WhatsApp

## Qué hace

| Para | Cómo | Botones |
|---|---|---|
| **Jessica** (DM con el bot) | Tarjeta por cada petición nueva de la bandeja | **Aprobar tal cual** · Revisar en la web · Descartar |
| **Liz** (DM con el bot) | Al aprobar un acta, una tarjeta por cada tarea suya (no internas) | **Hecha** · Abrir la oficina |
| **Clientes** | Al aprobar un acta con «Enviar el resumen», el resumen se publica en su destino: espacio de Chat, webhook o email | Ninguno (los externos no pueden pulsar) |

- **Siempre escribe el bot,** nunca en nombre de Jessica. Google lo marca con la etiqueta «App», y los mensajes a clientes terminan con «Mensaje automático de la oficina de MAD. Jessica lo ha revisado antes de enviarlo».
- **Modo pruebas, activado por defecto:** todo lo que iría a un cliente se publica en tu espacio de pruebas, con el aviso «[PRUEBA · Destino real: …]».
- **Si escribes al bot por DM,** te dice qué tienes pendiente. A Liz le dice sus tareas abiertas.
- **Seguridad:**
  - cada evento llega firmado por Google (un token JWT verificado con sus claves públicas y el número de tu proyecto);
  - solo tu email puede aprobar o descartar;
  - solo la persona asignada, o tú, puede marcar una tarea como hecha;
  - las claves y las URL de webhook se guardan cifradas.

## Puesta en marcha

Los pasos detallados están en **MAD Oficina → Chat**, en el desplegable «Cómo crear el bot en Google Cloud». En resumen:

1. Activa **Google Chat API** en tu proyecto de Google Cloud.
2. Crea una **cuenta de servicio** y su clave JSON, y pégala en la web.
3. Configura la app de Chat:
   - nombre y avatar,
   - URL de eventos,
   - audiencia de autenticación «Número de proyecto»,
   - **sin** la opción «complemento de Workspace»,
   - visibilidad para ti y Liz.
4. Pega el **número de proyecto** en la web.
5. Amplía la **regla de Cloudflare** a `/wp-json/mad-oficina/v1/chat/`.
6. En Chat, escríbele **«hola»** al bot. Crea el espacio **«Pruebas MAD Oficina»**, añade el bot y elígelo en la web.
7. Pulsa **«Enviar mensajes de prueba»**.

## Destinos de cada cliente

En la **ficha del cliente → «Mensajes del bot»** eliges, por tipo de mensaje (resúmenes, recordatorios):
- un **espacio** donde esté el bot, que tienes que crear tú, invitar al cliente y añadir la app;
- un **webhook**;
- **email** a los contactos que reciben resúmenes;
- o «no enviar».

## WhatsApp (importar a mano)

En **Reuniones → Importar → «Una conversación de WhatsApp»**:
1. Sube el `.txt` exportado (Android) o el `.zip` (iPhone).
2. Elige desde qué fecha procesar los mensajes.

La Secretaria saca tareas, temas e ideas a la bandeja. Por defecto, la conversación **no** se muestra en el portal del cliente y **no** se envía resumen.
