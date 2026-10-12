# Bloque 6 · Recepción para visitantes

Se entrega por partes. Cada parte se puede desplegar sola.

| Parte | Estado |
|---|---|
| 1 · Leads y brief (también «Nuevo encargo» para clientes) | **Hecha** |
| 2 · Primera reunión con confirmación por email | Pendiente |
| 3 · Charla con la recepcionista («Qué hacemos») | Pendiente |
| 4 · Oficina con las salas cerradas (solo un velo oscuro, sin rediseñar) | Pendiente |

## Parte 1 · Leads y brief

### Qué ve un visitante en `/oficina`
Si no ha iniciado sesión, ve la **recepción abierta**: un saludo de la recepcionista, tres botones y «Ya soy cliente: entrar».
- **Qué hacemos** y **Pedir una primera reunión** ponen «Pronto» hasta las partes 2 y 3.
- **Dejar un brief** abre un formulario con estos campos:
  - nombre, email, empresa y web;
  - qué necesita (web, ads, diseño, contenidos, vídeo u otra cosa);
  - el proyecto;
  - plazo y presupuesto orientativo (opcional, en rangos);
  - casilla de consentimiento con el enlace a tu política de privacidad.

Por ahora el visitante solo tiene la vista clásica. La oficina con las salas cerradas llega en la parte 4.

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
