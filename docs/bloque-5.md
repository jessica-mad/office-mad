# Bloque 5 · Agenda y seguimiento

## Qué hace

### Agenda (módulo «Agenda»)
- En el portal, **«Pedir una reunión»** muestra 5 huecos libres de tu calendario, repartidos en días distintos y alternando mañana y tarde.
- Los huecos se calculan **en el momento** con la consulta de disponibilidad de Google Calendar. No se guardan en caché.
- Si ningún hueco le va bien, el cliente puede escribir, por ejemplo, «el jueves por la tarde».
  - Claude Haiku solo traduce esa frase a fechas.
  - La disponibilidad sale siempre de tu calendario.
  - Cada cliente tiene un máximo de 20 consultas al día.
- La petición llega a tu **bandeja**, y a tu Chat con el botón «Aprobar tal cual».
  - Puedes cambiar la hora, la duración, los invitados y el tema.
  - Puedes invitar también a los contactos del cliente.
- **Al aprobar**:
  1. vuelvo a mirar tu calendario;
  2. si el hueco se ha ocupado, te aviso y no creo nada;
  3. si sigue libre, creo el evento «CÓDIGO x MAD» en tu calendario principal, con Meet;
  4. Google envía las invitaciones.
- El cliente ve en el portal si su petición está **pendiente**, **confirmada** (con el enlace de Meet) o **no pudo ser**.
- Cada cliente puede tener una sola petición pendiente a la vez. Desde gerencia solo ves la vista previa.

**Reglas por defecto**, que puedes cambiar en **MAD Oficina → Agenda**:

| Regla | Valor |
|---|---|
| Horario | De lunes a viernes, de 10:00 a 14:00 y de 16:00 a 18:00 |
| Duración | 45 min |
| Margen entre reuniones | 15 min |
| Antelación mínima | 24 h |
| Máximo de reuniones al día | 3 (cuenta todo lo ocupado en tu horario) |
| Días que se ofrecen | Los próximos 14 |
| Días bloqueados | Ninguno |
| Calendarios que cuentan como ocupado | El principal (puedes añadir más) |

### Autonomía (en la Bandeja, al final)
Hay dos acciones con tres niveles cada una: **preguntarme siempre** (por defecto), **hacerlo y avisarme** (te llega un mensaje por Chat) o **hacerlo en silencio**.
- Crear las reuniones que piden los clientes.
- Enviar recordatorios de tareas a clientes.

Los resúmenes de reunión, los mensajes a Liz y, más adelante, las facturas **siempre** te preguntan. Si una acción autónoma falla (por ejemplo, porque Calendar no responde), vuelve a tu bandeja como pendiente.

### Seguimiento (módulo «Seguimiento» · Coordinadora)

| Qué | Cuándo | A quién |
|---|---|---|
| Tareas que vencen en 2 días o están vencidas | Cada mañana, desde las 9:00 | A ti (las tuyas y las de MAD) y a Liz (las suyas), por Chat |
| Recordatorio al cliente de sus tareas | Cada mañana, como mucho uno por cliente cada 7 días | Propuesta en la bandeja; al aprobarla, sale por el destino «Recordatorios» del cliente |
| Ideas que vuelven | Cuando llega su fecha | Tarjeta en tu Chat con tres botones: **Convertir en tarea**, **Dentro de un mes**, **Descartar** |
| Ficha de reunión | En las 24 h previas a cada evento que identifico como de un cliente (por su código, sus contactos o su dominio) | A ti, una vez por reunión |
| Resumen semanal | Los lunes desde las 8:45 | A ti: reuniones con invitados, entregas por persona, ideas de la semana y bandeja |

Más detalles:
- **Ficha de reunión**: lleva las tareas abiertas por responsable, los temas pendientes de confirmar, las ideas que toca retomar y la última reunión.
- **Resumen semanal**: las facturas se añadirán en la fase de contabilidad.
- **Recordatorios a clientes**:
  - solo incluyen tareas con visibilidad «cliente»;
  - si los mandas tú, llevan la firma «Jessica lo ha revisado»;
  - si salen solos por autonomía, la firma dice solo «Mensaje automático de la oficina de MAD».
- **Banco de ideas**: también puedes resolver las ideas desde la web, en **MAD Oficina → Seguimiento**.
- **Probar ahora**: en esa misma página hay botones para lanzar cada aviso al momento.

**Cuándo se revisa**: cada hora con WP-Cron y, sobre todo, cada 10 minutos con el aviso del Apps Script, que ya tienes instalado. Así no depende de las visitas a la web. Cada aviso diario o semanal se envía una sola vez.

## Qué tienes que hacer

1. **Despliega la rama.**
2. En **MAD Oficina → Módulos**, activa **Agenda** y **Seguimiento**.
3. En **Google Cloud Console** (el mismo proyecto de la app OAuth), ve a «APIs y servicios → Biblioteca» y activa **Google Calendar API**.
4. Ve a **MAD Oficina → Conexiones** y pulsa **«Volver a conectar»**. Google te pedirá tres permisos nuevos de Calendar:
   - ver tu disponibilidad,
   - crear eventos,
   - ver la lista de tus calendarios.

   Hasta que lo hagas, el portal dirá que la agenda no está conectada.
5. Ve a **MAD Oficina → Agenda**:
   - revisa el horario;
   - marca los calendarios que cuentan como ocupado (por ejemplo, el personal);
   - comprueba en «Lo que vería un cliente ahora» que los huecos tienen sentido.
6. En la ficha de cada cliente, elige su destino de **«Recordatorios»** en «Mensajes del bot». Sin destino, el recordatorio no sale y te avisa.
7. Prueba como cliente: con el usuario de demo, pide una reunión y apruébala. **Ojo, crea un evento real** en tu calendario y Google envía la invitación al email del usuario de demo. Bórralo después.
8. En **Seguimiento**, pulsa los botones de «Probar ahora» para ver los avisos en tu Chat.

## Permisos de Google que se añaden

- `calendar.freebusy`: solo ver si estás libre u ocupada, sin títulos.
- `calendar.events`: crear las reuniones y leer los eventos de las próximas 24 h para las fichas.
- `calendar.calendarlist.readonly`: para que elijas qué calendarios cuentan.

## Seguridad

- **El cliente sale siempre de la sesión.** Un cliente no puede pedir reunión en nombre de otro aunque cambie la petición. Hay un test que lo comprueba.
- **El texto libre del cliente es solo un dato.** Haiku únicamente devuelve franjas de fecha en un formato fijo. No puede crear nada ni ver tu agenda.
- **Nada externo sin tu permiso.** Solo se crea un evento o se envía un recordatorio a un cliente con tu aprobación, o con autonomía si tú la activas.
- **Los botones de ideas en Chat** solo funcionan con tu email.

## Tests

Hay 96 tests, todos en verde. Comprueban:
- el motor de huecos: horario, antelación, margen, días llenos y bloqueados, y reparto entre días;
- el aislamiento por cliente y los permisos de gerencia;
- la segunda comprobación al aprobar;
- la autonomía;
- los recordatorios (una vez al día y nunca con tareas internas);
- las ideas (solo tú decides);
- la ficha de reunión (una por evento) y el resumen semanal.
