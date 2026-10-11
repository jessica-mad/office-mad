# Apps Script · «El cartero» de MAD Oficina

Este script vigila tu carpeta de Meet y hace tres cosas:
- manda cada reunión nueva a la web,
- apunta una fila en la hoja **«Reuniones MAD»**,
- cuando apruebas el acta en la bandeja, archiva la reunión en la carpeta del cliente y comparte la grabación con sus contactos, **sin enviar el email de aviso de Google**.

## Instalación (una vez)

1. Ve a <https://script.google.com>, crea un **proyecto nuevo** («MAD Oficina · Cartero») y pega el contenido de `Code.gs`.
2. En **Configuración del proyecto**, activa «Mostrar el archivo de manifiesto `appsscript.json`» y pega el contenido de `appsscript.json`. Esto activa el servicio avanzado de Drive.
3. En **Configuración del proyecto → Propiedades del script**, añade:

   | Propiedad | Valor |
   |---|---|
   | `MAD_ENDPOINT` | Lo copias de *MAD Oficina → Reuniones → Apps Script y ajustes* |
   | `MAD_SECRET` | Lo copias de la misma pantalla |
   | `ROOT_FOLDER_ID` | `1a9Bw6H8lLV74nzD2DJ60FTqzvvUQ5cBo` (tu carpeta «Google Meet») |
   | `CLIENTS_FOLDER_ID` | *(opcional)* La carpeta donde viven las carpetas de tus clientes |

4. Ejecuta la función **`install`** y acepta los permisos. Pide Drive, Docs, Calendar (lectura), Hojas y conexiones externas. Crea un disparador cada 10 minutos y la hoja «Reuniones MAD» dentro de tu carpeta de reuniones.

## Qué reuniones envía

Google Meet guarda cada reunión en la carpeta **«Google Meet»**:
- una **subcarpeta por reunión**: `RF x MAD - 2026/10/07 17:57 CEST`;
- una **carpeta fija por cada reunión periódica**: `Divano Toscano x MAD (recurring)`, donde Meet va dejando cada sesión.

Cómo trabaja el script:
- **La unidad es la nota de Gemini:** cada nota es una reunión. La grabación se empareja por fecha y hora (±20 min).
- **Grabaciones sin nota** (porque no activaste Gemini): espera 3 horas y la envía sola. En la web aparecerá como «Sin transcripción».
- **Al archivar una reunión aprobada:**
  - si es una reunión única, mueve su subcarpeta entera a `Cliente/Reuniones/AAAA-MM-DD – Título`;
  - si es periódica, **no toca la carpeta de Meet**: crea la carpeta de esa sesión en el cliente y mueve allí solo la nota y la grabación de ese día.
- **No toca la carpeta antigua** `Meet Recordings`.
- **Solo envía las reuniones desde el día de la instalación.** Para mandar también las de los últimos N días, ejecuta `backfill(14)`.

De cada nota de Gemini lee **todas las pestañas**, también la de la transcripción, además de los invitados (enlaces mailto) y el enlace al evento de Calendar.

## Seguridad

- **Firma de cada petición:** HMAC-SHA256 con el secreto compartido. La web rechaza las peticiones de más de 5 minutos de antigüedad y las repetidas.
- **Solo puede entregar y cumplir:** el script entrega datos y ejecuta las órdenes que la web genera **después de tu aprobación**. No puede aprobar nada.
- **Si cambias el secreto en la web,** tienes que pegarlo de nuevo en las propiedades del script.

## Si no tienes los enlaces permanentes activados

`MAD_ENDPOINT` tiene que ser del tipo `https://tu-web/wp-json/mad-oficina/v1`. La web debe tener activados los enlaces permanentes (Ajustes → Enlaces permanentes, cualquier opción distinta de «Simple»).
