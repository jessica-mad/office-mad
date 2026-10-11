# Bloque 4 · Google Chat: investigación y propuesta

> Propuesta para revisar antes de implementar (CLAUDE.md, apartado 5.7).

## 1. Qué permite Google con personas de otros dominios

Según las ayudas oficiales de Workspace [1][2]:

- **Las apps y los webhooks pueden publicar en espacios externos**, y los externos ven sus mensajes.
- **Los externos no pueden interactuar con apps:** no pueden mencionarlas, usar comandos ni **pulsar botones de tarjetas**. Tampoco pueden añadir ni quitar apps o webhooks.
- **El tipo de espacio se fija al crearlo:** un espacio interno no se puede convertir en externo.
- **Los ajustes de administración** de chat externo y espacios externos deben estar activados en tu Workspace, y Chat activado en el del cliente.
- **Mensajes directos entre un bot y un externo:** no he encontrado documentación que lo permita. Un externo no puede instalar apps, así que lo doy por **no disponible**.

## 2. Las tres opciones para escribir a clientes

| Opción | Cómo se ve | Ventajas | Limitaciones |
|---|---|---|---|
| **A. Publicar como Jessica** (OAuth de usuario, `chat.messages.create`) | Mensaje tuyo, con una pequeña atribución «vía MAD Oficina» | Natural para el cliente. Vale en cualquier espacio o DM donde ya estés. No hay que añadir nada al espacio. | **Solo texto** (sin tarjetas ni botones). Depende de que tu cuenta siga conectada. |
| **B. Bot «MAD Oficina»** (app de Chat) | Mensaje del bot, con tarjetas | Imagen de marca y tarjetas bonitas | Los externos **no pueden pulsar** los botones. Hay que añadir el bot a cada espacio. No puede escribir por DM a externos. |
| **C. Webhook entrante** | Mensaje con el nombre y avatar que elijas | Lo más sencillo: una URL por espacio, sin Google Cloud | Solo envía, no recibe respuestas. Hay que crear la URL en cada espacio. Depende de tu edición de Workspace y de la política de administración. |

## 3. Decisión: siempre el bot, nunca en nombre de Jessica

Jessica quiere que se entienda que escribe un bot, no ella. Así que **se descarta la opción A**.

- **Para Jessica y para Liz: bot «MAD Oficina» por mensaje directo,** con tarjetas y botones. Funciona porque son del mismo dominio.
- **Para clientes: el mismo bot dentro de un espacio compartido con el cliente.**
  - Google Chat marca al bot con la etiqueta **«App»** junto a su nombre, así que se ve claramente que no es una persona.
  - Los mensajes a clientes **no llevan botones**, porque no podrían pulsarlos. Llevan un enlace a su oficina en la web.
  - **El espacio lo crea Jessica, desde su Workspace:** «Cliente x MAD», con las personas del cliente. Ella añade el bot con «Añadir apps» y lo elige en la ficha del cliente. Si lo creara el cliente, la política de su organización podría impedir añadir apps de fuera.
  - **Si un espacio no admite apps,** se puede usar un **webhook** (opción C) con el nombre y avatar «MAD Oficina · Asistente», que tampoco parece una persona.
  - **Si el cliente no usa Chat,** se le escribe por **email** desde la web, firmado por «MAD Oficina · Asistente».
- **Nada se envía al cliente sin pasar por la bandeja.** El resumen de reunión sale al aprobar el acta, si marcas «Enviar resumen».
- **Todos los mensajes del bot a clientes terminan con una firma breve:** «Mensaje automático de la oficina de MAD. Jessica lo ha revisado antes de enviarlo».

## 4. Cosas a tener en cuenta antes de empezar

1. **Credenciales del bot.** Para que el bot escriba por iniciativa propia (avisarte de una aprobación nueva) necesita una **cuenta de servicio** con clave JSON. En organizaciones de Workspace recientes, Google **bloquea por defecto la creación de claves** con la política `iam.disableServiceAccountKeyCreation`. Si al crearla te sale ese error, hay que desactivar esa política en el proyecto, como administradora de la organización en Google Cloud. Te guiaré.
2. **Cloudflare.** Google Chat enviará los clics de las tarjetas a `/wp-json/mad-oficina/v1/chat/events`. Hará falta una segunda regla, igual que la de `/hooks/`.
3. **Verificación.** Cada evento de Chat llega firmado por Google (un token JWT). El plugin comprueba la firma con las claves públicas de Google y que va dirigido a tu proyecto antes de hacer nada.
4. **No hacen falta permisos de Chat en tu cuenta:** todo lo publica el bot, con su cuenta de servicio. Los espacios donde el bot está añadido se listan con sus propias credenciales para elegirlos en la ficha del cliente.

## 5. Qué tendrás que hacer en Google Cloud

En el mismo proyecto de la app OAuth:
1. Activar la **Google Chat API**.
2. Configurar la app de Chat: nombre «MAD Oficina», avatar, URL del endpoint y visibilidad solo para tu dominio.
3. Crear la cuenta de servicio y su clave JSON, y pegarla en *Conexiones*.

## 6. Entregables del bloque

- Un módulo `chat` con:
  - el endpoint verificado de eventos,
  - el cliente de Chat (como app y como Jessica),
  - las tarjetas de aprobación,
  - las tareas a Liz,
  - el resumen al cliente con su destino configurable,
  - los tests (firma, permisos, que nada sale sin aprobación).
- **Autonomía por tipo de acción** (siempre preguntar / avisar y hacer / hacer en silencio). Empieza en «siempre preguntar» para todo.

## Fuentes

[1] Google Workspace Admin Help · Chatting with external users & guest accounts: https://support.google.com/a/answer/9296706?hl=en
[2] Google Workspace Admin Help · Control external Chat & spaces chat options: https://knowledge.workspace.google.com/admin/chat/control-external-chat-and-spaces-chat-options?hl=en
[3] Chat API · spaces.messages.create (app auth with `chat.bot`; user auth with `chat.messages.create`, text only): https://developers.google.com/chat/reference/rest/v1/spaces.messages/create
