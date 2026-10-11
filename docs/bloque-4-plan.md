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

## 3. Propuesta

- **Para ti, Jessica: bot «MAD Oficina» por DM**, con tarjetas y botones **Aprobar / Editar en la web / Descartar** para cada petición de la bandeja. También responde a mensajes sencillos, como «¿qué tengo pendiente?».
- **Para Liz: el mismo bot por DM** (es interna, así que las tarjetas funcionan). Cada tarea le llega con contexto, enlaces, fecha y un botón **«Hecha»**.
- **Para clientes: la opción A por defecto,** publicar como tú en su espacio o en tu DM con ellos. Es lo más natural, no les obliga a nada y funciona ya en los espacios que tienes.
  - En la ficha de cada cliente eliges el destino por tipo de mensaje (resumen de reunión, recordatorios): espacio compartido, DM con una persona, webhook (opción C) o email.
  - **Nada se envía al cliente sin pasar por la bandeja.** El resumen de reunión sale al aprobar el acta, si marcas «Enviar resumen».

## 4. Cosas a tener en cuenta antes de empezar

1. **Credenciales del bot.** Para que el bot escriba por iniciativa propia (avisarte de una aprobación nueva) necesita una **cuenta de servicio** con clave JSON. En organizaciones de Workspace recientes, Google **bloquea por defecto la creación de claves** con la política `iam.disableServiceAccountKeyCreation`. Si al crearla te sale ese error, hay que desactivar esa política en el proyecto, como administradora de la organización en Google Cloud. Te guiaré.
2. **Cloudflare.** Google Chat enviará los clics de las tarjetas a `/wp-json/mad-oficina/v1/chat/events`. Hará falta una segunda regla, igual que la de `/hooks/`.
3. **Verificación.** Cada evento de Chat llega firmado por Google (un token JWT). El plugin comprueba la firma con las claves públicas de Google y que va dirigido a tu proyecto antes de hacer nada.
4. **Permisos nuevos de tu cuenta** (solo para la opción A): `chat.messages.create` y `chat.spaces.readonly`, este último para que elijas el espacio de cada cliente de una lista. Tendrás que volver a conectar Google en *Conexiones*.

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
