# Bloque 6 · Recepción para visitantes

Se entrega por partes. Cada parte se puede desplegar sola.

| Parte | Estado |
|---|---|
| 1 · Leads y brief (también «Nuevo encargo» para clientes) | **Hecha** |
| 2 · Primera reunión con confirmación por email | **Hecha** |
| 3 · Charla con la recepcionista («Qué hacemos») | **Hecha** (apagada hasta que revises la ficha) |
| 4 · Oficina con las salas cerradas (solo un velo oscuro, sin rediseñar) | **Hecha** (adelantada) |

## Parte 1 · Leads y brief

### Qué ve un visitante en `/oficina`
Si no ha iniciado sesión, ve la **recepción abierta**: un saludo de la recepcionista, tres botones y «Ya soy cliente: entrar».
- **Dejar un brief** abre un formulario con estos campos:
  - nombre, email, empresa y web;
  - qué necesita (web, ads, diseño, contenidos, vídeo u otra cosa);
  - el proyecto;
  - plazo y presupuesto orientativo (opcional, en rangos);
  - casilla de consentimiento con el enlace a tu política de privacidad.

El visitante también puede usar la vista oficina, igual que los clientes (en móvil empieza en la vista clásica). Ver la parte 4.

### Flujo
1. El visitante envía el brief y recibe un email: «Confirma tu mensaje a MAD».
2. Al pulsar el enlace, vuelve a `/oficina` con el aviso «¡Confirmado!».
3. El lead aparece en **MAD Oficina → Leads** y te llega un aviso por Chat.

Mientras el visitante no confirma, no te molesta: el lead aparece solo en el filtro «Sin confirmar» y se borra a los 7 días.

**Clientes:** el botón **«Nuevo encargo»** usa el mismo formulario, sin datos de contacto y sin confirmación porque ya está identificado. Te llega al momento.

### Pantalla Leads
- Filtros: nuevo, contactado, cliente, descartado y sin confirmar.
- En cada lead puedes cambiar el estado y escribir notas internas.
- **Convertir en cliente** crea la ficha del cliente con su contacto.
  - Propone un código libre a partir del nombre (por ejemplo, «Taller Azul» → TA).
  - Usa como dominio el del email, salvo que sea de un correo gratuito como Gmail o Hotmail.
- **Borrar (RGPD)** elimina el lead con todos sus datos.

Al borrar un cliente, también se borran sus leads.

### Protección de los formularios públicos
- **Campo trampa (honeypot):** un campo oculto que solo rellenan los robots. Si viene relleno, se les responde «ok» y no se guarda nada.
- **Cloudflare Turnstile**, si pones las claves en Leads → Ajustes.
- **Límites:** 3 briefs al día por IP y por email. La IP no se guarda, solo una huella cifrada.
- **Confirmación por email** antes de que el lead te llegue.
- **Sin nonce en la página pública:** así la caché de la web o de Cloudflare no puede romper el formulario.

## Qué tienes que hacer
1. Despliega la rama y activa el módulo **Visitantes y leads** en Módulos.
2. **Turnstile:**
   1. En Cloudflare, ve a **Turnstile → Add widget**, con el dominio `imad.pro` y el modo «Managed».
   2. Pega la *Site key* y la *Secret key* en **MAD Oficina → Leads → Ajustes**.
3. En **Leads → Ajustes**, pon la URL de tu **política de privacidad**, si no es la de WordPress.
4. Prueba los emails de confirmación:
   - Comprueba que tu web envía emails, porque son los que llegan a los visitantes. Si usas un plugin SMTP, se envían por él.
   - Haz una prueba sin iniciar sesión, por ejemplo en una ventana privada.

## Parte 4 · Oficina con las salas cerradas
- **Lo que ve el visitante:**
  - la oficina completa, con la recepción iluminada y la recepcionista saludando;
  - el resto de salas se oscurecen con un fundido y muestran el cartel «SOLO CLIENTES» en el centro;
  - los personajes siguen trabajando en penumbra.
- **Al pulsar una sala cerrada o a su personaje,** sale el panel «Solo para clientes» con dos botones: «Ya soy cliente: entrar» y «Dejar un brief». La recepción y la recepcionista abren el menú de recepción.
- **Sin dibujos nuevos:** el velo es un rectángulo que se dibuja por código encima de cada sala del mapa. Seguirá funcionando igual después del rediseño de la oficina.

## Parte 2 · Primera reunión
### Qué ve el visitante
En recepción, **«Pedir una primera reunión»** abre:
- **6 huecos reales de 30 minutos** de tu agenda. Se calculan con las mismas reglas y calendarios de MAD Oficina → Agenda, en días distintos.
- Sus datos: nombre, email, empresa (opcional), de qué quiere hablar (opcional) y el consentimiento.

Si la agenda no está conectada o no hay huecos, se le ofrece dejar un brief.

### Flujo
1. El visitante elige un hueco y envía el formulario. Le llega el email «Confirma tu petición de reunión con MAD».
2. Al confirmar, la petición llega a **tu bandeja y a tu Chat**: «Primera reunión: nombre (empresa) · día y hora».
   - Ese hueco deja de ofrecerse a otros visitantes y clientes.
   - Hasta que confirma el email, el hueco no se reserva.
3. **Al aprobar:**
   - vuelvo a mirar tu calendario;
   - creo el evento «Empresa x MAD · Primera reunión» con Meet;
   - Google le envía la invitación;
   - el lead pasa a «Contactado».

   Antes de aprobar puedes cambiar la hora, la duración, los invitados y el tema.
4. **Si descartas,** no le llega nada automático. Escríbele tú desde el lead.

### Seguridad
- **Las primeras reuniones siempre te preguntan.** Aunque des autonomía a las reuniones de clientes, esa autonomía no se aplica a desconocidos.
- **Protecciones del formulario:** el mismo campo trampa, Turnstile y confirmación por email que el brief.
- **Límites:** 2 peticiones al día por IP y por email.
- **La consulta de huecos públicos** tiene un límite de 60 por hora por IP.
- **Pequeña caché de 1 minuto** en los huecos que ve el visitante, para que nadie pueda saturar tu cuenta de Google. Al enviar el formulario y al aprobar, el hueco se comprueba siempre en directo.

## Parte 3 · «Qué hacemos»: charla con la recepcionista
### Qué hace
- El visitante charla con la recepcionista sobre lo que hace MAD y cómo trabaja.
- Cuando muestra interés, aparecen los botones **«Pedir una primera reunión»** y **«Dejar un brief»**.
- La conversación se mantiene mientras tenga la pestaña abierta.

### Lo que sabe y lo que no
- **Solo sabe lo que pone la ficha de MAD,** que editas en **Leads → Recepcionista**.
- **No tiene herramientas:** no ve clientes, ni la agenda, ni nada de la base de datos.
- **No da precios ni plazos,** no promete resultados y no pide datos personales. Para dejar datos están los formularios, con su consentimiento y su confirmación.
- **Lo que escribe el visitante son datos, no órdenes.** Si intenta cambiarle el papel, sigue siendo la recepcionista.

### Límites y coste
- **Haiku por defecto.** Se puede cambiar en la misma pestaña.
- **Por charla y por visitante:** 15 mensajes por charla y 3 charlas al día.
- **Turnstile** al empezar cada charla. Después, un pase firmado que solo vale para esa conexión durante 6 horas.
- **Tope de gasto diario:** 1 $ para todos los visitantes juntos, configurable. Al llegar al tope, la charla se sustituye por los botones de reunión y brief.
- **Las charlas se borran a los 30 días.** Mientras tanto puedes leerlas en **Leads → Recepcionista → Últimas charlas**, para ver qué pregunta la gente.

### Qué tienes que hacer
1. Ve a **MAD Oficina → Leads → Recepcionista**.
2. Revisa la **ficha de MAD**. Es un borrador escrito sin poder leer imad.pro, porque la red de este entorno no llega a tu web. Corrígelo: servicios, forma de trabajar, casos que se puedan contar y preguntas frecuentes.
3. Quita la primera línea `[BORRADOR…]`. Mientras esté, la charla no se puede activar.
4. Marca **«Activar la charla para visitantes»** y guarda.
5. Pruébala en una ventana privada.
