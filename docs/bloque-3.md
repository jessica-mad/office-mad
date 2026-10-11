# Bloque 3 · Secretaria de actas

## Flujo

```
Carpeta de reuniones (Drive)
  └ «RF x MAD - 2026/10/07 17:57 CEST»/      ← una subcarpeta por reunión
       ├ RF x MAD: … - Notas de Gemini        (notas + pestaña de transcripción)
       └ grabación .mp4
        │  Apps Script cada 10 min (apps-script/Code.gs)
        ▼
POST firmado /hooks/meetings  → reunión «Recibida» → cola en segundo plano
        ▼
Secretaria (Sonnet 5.5, salida JSON con esquema)
  · identifica al cliente: código del título → emails de contactos → dominio y nombres
  · extrae: resumen para el cliente y nota interna, acuerdos, tareas (quién, fecha,
    prioridad, visibilidad), temas pendientes, ideas con fecha para retomarlas,
    próxima reunión y notas solo para ti
        ▼
Bandeja de gerencia: revisas, editas y apruebas por partes
        ▼
Tareas, ideas y temas creados · reunión visible en el portal (si lo marcas)
· orden para el Apps Script: mover la carpeta a «Cliente/Reuniones/AAAA-MM-DD – Título»,
  compartir la grabación sin email de Google y actualizar la hoja «Reuniones MAD»
```

## Puesta en marcha

1. Despliega la rama y activa el módulo **Reuniones** (necesita que **Clientes** esté activo).
2. Crea los clientes reales con su **código**: el que va delante de «x MAD» en Calendar, por ejemplo `RF`. Añade también sus **dominios** y sus **contactos** con todos sus emails.
3. **Prueba primero a mano.** En *Reuniones → Importar*:
   1. Pega el texto de una nota de Gemini. En Google Docs: Archivo → Descargar → Texto sin formato, en cada pestaña. O copia y pega las dos pestañas.
   2. Marca «Procesarla ahora».
   3. Revisa el borrador en la **Bandeja**.
4. Cuando te convenza la extracción, instala el Apps Script siguiendo `apps-script/README.md`.
5. Ajusta la Secretaria en *Reuniones → Apps Script y ajustes → Indicaciones adicionales*. Por ejemplo: «En RF, Candy es Candela Rodríguez, del equipo del cliente».

## Bandeja

- **Qué hay en ella:** todo lo que los agentes quieren hacer, con un contador en el menú.
- **Qué puedes hacer con el acta:**
  - cambiar el cliente;
  - guardar invitados desconocidos como contactos;
  - editar los textos;
  - desmarcar tareas, ideas o temas;
  - elegir para cada elemento quién lo ve: cliente, equipo o interno;
  - decidir si se comparte la grabación (por defecto sí) y las notas o la transcripción (por defecto no).
- **Garantías:** cada aprobación se ejecuta una sola vez. Si falla, vuelve a quedar pendiente. Si reprocesas una reunión, el borrador nuevo sustituye al anterior.
- **En la oficina:** mientras hay un acta pendiente, la Secretaria camina a tu despacho. Mientras procesa una reunión, aparece trabajando.

## Seguridad

- **El Apps Script:** firma cada petición con HMAC (secreto compartido), y la web rechaza las peticiones de más de 5 minutos y las repetidas. Solo entrega datos y cumple órdenes que nacen de una aprobación tuya.
- **La Secretaria no tiene herramientas.** Lo que se diga en una reunión («envía…», «borra…») como mucho acaba como texto del borrador, nunca como una acción.
- **Lo que llega del formulario** se limpia: los valores desconocidos (responsable, prioridad, visibilidad) pasan a valores seguros, que en la visibilidad es «interno». Solo se pueden guardar como contactos los emails que el borrador listaba como desconocidos.
- **Rechazos de los filtros de seguridad de Claude:** con Sonnet 5.5 se reintenta automáticamente con el modelo alternativo que recomienda Anthropic. Si aun así rechaza, la reunión queda en «Error» con el motivo.

## Coste aproximado

Una reunión de una hora con notas y transcripción tiene unos 25.000 tokens. Con Sonnet 5.5 sale por unos 0,05–0,10 $. Cada procesado queda apuntado en `agent_log`.
