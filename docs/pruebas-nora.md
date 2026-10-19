# Pruebas de Nora (para Claude en el navegador)

Copia todo lo que hay debajo de la línea y pégaselo a Claude en Chrome.

---

Vas a probar a **Nora**, la asistente de la oficina de MAD. Es un bot de **Google Chat** que vive en un plugin de WordPress. Al terminar, entrega un **informe** para el desarrollador. Jessica (la dueña) está contigo y te da acceso.

## Dónde

- **Google Chat** (chat.google.com): el mensaje directo con el bot «MAD Oficina». Ahí le escribes a Nora.
- **El WordPress de MAD** (la sesión de Jessica, ya abierta), para comprobar resultados:
  - MAD Oficina → **Seguimiento** y **Tareas**: lo que Nora crea o cambia en tareas.
  - **Campañas → Bitácora**: lo que apunta Nuria (la de campañas).
  - **Chat → ajustes**: el estado del último mensaje y de la última transcripción de audio.
  - **Registro**: lo que hizo cada agente.

## Reglas

- **No apruebes nada en la Bandeja** ni pulses botones que envíen algo a clientes o a Liz («Avisar a Liz», «Enviar»…).
- Para pruebas que **cambian datos** (crear o cerrar tareas, apuntar en la bitácora), usa solo el cliente de pruebas que te diga Jessica. Si no hay, pídele que cree uno con código **PRB**. Al terminar cada una, pulsa **«Deshacer»** si Nora lo ofrece y anótalo.
- Las preguntas de **solo lectura** («¿qué tareas tiene LCS?», «¿cuál es la última factura de MOM?») puedes hacerlas sobre clientes reales.
- Los **audios** los graba y envía Jessica desde su móvil. Tú anotas qué dijo y qué respondió Nora.
- Espera a que Nora termine antes de escribir lo siguiente. A veces manda un mensaje de «un momento…» y luego lo actualiza con la respuesta.
- No cambies ajustes del plugin ni de Google.

## Qué probar

Para cada prueba anota:
1. lo que enviaste (texto exacto, o «audio de N segundos» y lo que decía);
2. la **respuesta completa** de Nora, copiada tal cual;
3. cuánto tardó, más o menos;
4. si salió «Deshacer» u otros botones;
5. qué cambió en WordPress, si había que comprobarlo;
6. qué está mal: repite, se inventa, tarda, se corta, responde otra cosa…

### A. Repetición y longitud (lo que más preocupa)
1. «Hola Nora, ¿qué tal?»
2. «¿Qué tareas tengo pendientes esta semana?», y después «¿y de esas, cuáles son de LCS?».
3. Corrígela: «no, me refería solo a las de Liz».
4. Pregunta lo mismo con otras palabras: «¿qué me queda por hacer?».
5. En cada respuesta, señala:
   - si **repite** algo que ya dijo en un mensaje anterior;
   - si **rehace** la respuesta entera en vez de dar solo lo nuevo;
   - si es **más larga de lo necesario**. Cuenta las líneas.

### B. Tareas (dicen que «las tareas son largas»)
1. «Apunta una tarea para PRB: enviar el presupuesto de la web antes del viernes.»
2. «Esa tarea es de Liz.»
3. «Marca como hecha la del presupuesto de PRB.»
4. Abre la tarea en WordPress y **copia su título y su descripción tal cual**. ¿El título es largo, repite el cliente o lleva frases de relleno?
5. «¿De dónde salió la tarea X?» (con una tarea real que Jessica te indique).

### C. Audios (Jessica los envía)
1. Uno de **unos 10 segundos** («Nora, ¿qué tengo hoy?»).
2. Uno de **1 minuto y medio a 3 minutos**, contando varias cosas y dejando una **muy concreta al final** (por ejemplo, «y lo último: recuérdame llamar a Tomás el jueves»). ¿Nora responde a lo del final?
3. Tras cada audio, mira **Chat → ajustes** y copia la línea «Última transcripción…».

### D. Campañas y bitácora (con PRB)
1. «En PRB subí el presupuesto de la campaña Rebajas de 20 a 35 € porque convertía bien.» Comprueba en Campañas → Bitácora que aparece.
2. Jessica le manda una **captura** con el texto «esto es lo de Rebajas de PRB». ¿Aparece la imagen en esa tarjeta de la bitácora?
3. «¿Por qué subimos el presupuesto de Rebajas en PRB?»

### E. Otras áreas (solo lectura)
1. «¿Cuál es la última factura de [cliente real]?»
2. «¿Qué acordamos en la última reunión con [cliente real]?»
3. «¿Cuánto gastamos en software este mes?»
4. Anota a quién dice que se lo pregunta («se lo pregunto a Amparo / Valle / …») y si tiene sentido.

### F. Memoria
1. «Recuerda que a Luz Clara la llamo siempre LCS.»
2. Más tarde: «¿Qué te pedí que recordaras?»

## El informe

Entrégalo así:

1. **Resumen**: los 3–5 problemas más importantes, de más a menos grave, con un ejemplo real de cada uno.
2. **Tabla de pruebas**: prueba · lo que enviaste · resultado (bien / regular / mal) · problema en una línea.
3. **Detalle**: por cada prueba regular o mal, la conversación copiada tal cual y lo que esperabas.
4. **Datos técnicos**: las líneas de Chat → ajustes («Último mensaje…», «Última transcripción…») y cualquier error que salga en pantalla.
5. **Lo que dejaste cambiado**: tareas, apuntes o imágenes que no se pudieron deshacer.

No incluyas en el informe datos personales de clientes (teléfonos, emails, importes concretos). Usa el código del cliente y «[importe]».
