# Apps Script · «El cartero» de MAD Oficina

Este script vigila tu carpeta de Meet y hace tres cosas:
- manda cada reunión nueva a la web,
- apunta una fila en la hoja **«Reuniones MAD»**,
- cuando apruebas el acta en la bandeja, la archiva en una rama **privada** dentro de tu carpeta Google Meet (`Reuniones por cliente/<CÓDIGO · Cliente>/`) y comparte la grabación con los contactos del cliente, **sin enviar el email de aviso de Google**. Las reuniones nunca se mueven a la carpeta de documentos del cliente, que ve el equipo.

## Instalación (una vez)

1. Ve a <https://script.google.com>, crea un **proyecto nuevo** («MAD Oficina · Cartero») y pega el contenido de `Code.gs`.
2. En **Configuración del proyecto**, activa «Mostrar el archivo de manifiesto `appsscript.json`» y pega el contenido de `appsscript.json`. Esto activa el servicio avanzado de Drive.
3. En **Configuración del proyecto → Propiedades del script**, añade:

   | Propiedad | Valor |
   |---|---|
   | `MAD_ENDPOINT` | Lo copias de *MAD Oficina → Reuniones → Apps Script y ajustes* |
   | `MAD_SECRET` | Lo copias de la misma pantalla |
   | `ROOT_FOLDER_ID` | `1a9Bw6H8lLV74nzD2DJ60FTqzvvUQ5cBo` (tu carpeta «Google Meet») |
   | `ARCHIVE_FOLDER_ID` | *(opcional)* Si quieres el archivo en otra carpeta privada. Si no la pones, se crea `Reuniones por cliente` dentro de tu carpeta Google Meet. |

4. Ejecuta la función **`install`** y acepta los permisos. Pide Drive, Docs, Calendar (lectura), Hojas y conexiones externas. Crea un disparador cada 10 minutos y la hoja «Reuniones MAD» dentro de tu carpeta de reuniones.

## Facturas: Emitidas, envío y Libro MAD

Desde el bloque 10 el cartero también se encarga de las facturas que apruebas en la bandeja:
- guarda el PDF en la unidad compartida, en `Emitidas/AAAA/Tn/26-024 Cliente.pdf`;
- si elegiste «Emitir y enviar», lo **envía desde tu Gmail** con el PDF adjunto (queda en tus Enviados y las respuestas te llegan a ti);
- apunta o actualiza su fila en la hoja **«Libro MAD»** (pestaña Emitidas), que crea en la unidad compartida la primera vez;
- con «Enviarme una prueba», te manda solo a ti el borrador con la marca BORRADOR.

Nunca envía una factura dos veces, aunque se reintente la orden.

**Para activarlo (una vez):**
1. Pega el `Code.gs` y el `appsscript.json` nuevos.
2. En **Propiedades del script**, añade:

   | Propiedad | Valor |
   |---|---|
   | `ACCOUNTING_DRIVE_ID` | `0AEAF3xbrnHIuUk9PVA` (la unidad compartida de contabilidad) |
   | `EMITIDAS_FOLDER_ID` | *(opcional)* `1Tv8hF7BByRhxb3DJYw8MXWsZQovrr4qX`, la carpeta «Emitidas». Si no la pones, la busca por nombre. |

3. Ejecuta **`install`** otra vez y acepta el permiso nuevo: **enviar correo en tu nombre** (`script.send_mail`).
4. En **MAD Oficina → Facturación → Datos del emisor**, pulsa **«Sincronizar todo el Libro MAD»** para volcar el histórico.

## Facturas de proveedores (Gmail → archivo)

El cartero también lee tu Gmail, **solo para leer**: no cambia, borra ni marca nada. Busca correos con PDF que parezcan facturas: palabras como factura, invoice o recibo, o remitentes de proveedores conocidos.
- Cada PDF nuevo se lo pasa a la web. La Contable lo lee y **solo cuenta las facturas a tu nombre**.
- Todo se archiva en la carpeta elegida en **Finanzas → Gastos → Ajustes → Archivo en Drive** (p. ej. «Facturas para MAD»). Si no eliges ninguna, en `Recibidas/` de la unidad de contabilidad.
- Las facturas a nombre de clientes u otros quedan guardadas aparte, en `No son mías/AAAA/`.
- Las tuyas van a `AAAA/AAAA-Tn/AAAA-MM-DD_Proveedor_Importe.pdf`, y su justificante de pago al lado, con el mismo nombre más `_pago`.
- Los pagos de impuestos (cuota de autónomos, 303, 130) se archivan igual como `AAAA-MM-DD_Impuesto-…` y se apuntan en Finanzas → Impuestos.
- El mismo PDF nunca se manda dos veces.
- Si apruebas el filtro de un proveedor nuevo, crea en Gmail el filtro que pone la etiqueta «Facturas» a sus correos.

**Para activarlo (una vez):**
1. Pega el `Code.gs` y el `appsscript.json` nuevos. Activan el servicio avanzado de **Gmail**.
2. Ejecuta **`install`** y acepta los permisos nuevos de Gmail: leer, etiquetas y filtros.
3. Para repasar **los últimos 12 meses**, ejecuta una vez **`backfillInvoices(365)`**.
   - Cada vuelta procesa lo que le da tiempo (unos 4 minutos) y la siguiente continúa.
   - Con muchas facturas, puede tardar unas horas en ponerse al día.
4. `facturas@imad.pro` entra solo si sus correos llegan a tu buzón, es decir, si es un alias tuyo o un grupo que te reenvía.

Los PDF de más de 4 MB no se envían: súbelos a mano en **Gastos → Subir o importar**.

## Importar tu archivo de Drive (histórico)

Para meter de una vez lo que ya tienes guardado en Drive (facturas, justificantes de pago y recibos de impuestos):
1. Pega el `Code.gs` nuevo (no hace falta volver a ejecutar `install`).
2. En **Gastos → Ajustes**, elige la carpeta del archivo y pulsa **Reorganizar el archivo**: lo que ya estaba en `Recibidas/` pasa allí.
3. En **Gastos → Subir o importar**, pega el enlace de tus carpetas antiguas, elige desde qué año y pulsa **Empezar la importación**.

Qué hace el cartero en cada pasada (cada 10 minutos, unos 4 minutos de trabajo):
- Recorre primero la carpeta del archivo y luego las tuyas, por año y trimestre. Se salta las carpetas de años anteriores.
- Manda cada PDF o imagen nuevo. Lo ya enviado no se vuelve a mandar.
- **Tus carpetas antiguas solo se leen**: lo tuyo se copia, ordenado y renombrado, al archivo.
- Lo que ya estaba en la carpeta del archivo se mueve y renombra allí mismo. Si es una copia de algo ya registrado, va a `Duplicados/`. **No se borra nada.**
- Cuando una pasada no encuentra nada nuevo, la importación se da por terminada sola.

Si tenías otro script que copiaba facturas a esa carpeta, desactívalo para que no se mezclen los dos sistemas.

## Qué reuniones envía

Google Meet guarda cada reunión en la carpeta **«Google Meet»**:
- una **subcarpeta por reunión**: `RF x MAD - 2026/10/07 17:57 CEST`;
- una **carpeta fija por cada reunión periódica**: `Divano Toscano x MAD (recurring)`, donde Meet va dejando cada sesión.

Cómo trabaja el script:
- **La unidad es la nota de Gemini:** cada nota es una reunión. La grabación se empareja por fecha y hora (±20 min).
- **Grabaciones sin nota** (porque no activaste Gemini): espera 3 horas y la envía sola. En la web aparecerá como «Sin transcripción».
- **Al archivar una reunión aprobada:**
  - si es una reunión única, mueve su subcarpeta entera a `Google Meet/Reuniones por cliente/<CÓDIGO · Cliente>/AAAA-MM-DD – Título`;
  - si es periódica, **no toca la carpeta de Meet**: crea la carpeta de esa sesión en el archivo y mueve allí solo la nota y la grabación de ese día;
  - el archivo sigue siendo privado, porque hereda los permisos de tu carpeta Google Meet. El cliente solo recibe acceso a los archivos concretos que compartes (la grabación y, si lo marcas, las notas). Nadie del equipo ve las reuniones.
- **No toca la carpeta antigua** `Meet Recordings`.
- **Solo envía las reuniones desde el día de la instalación.** Para mandar también las de los últimos N días, ejecuta `backfill(14)`.

De cada nota de Gemini lee **todas las pestañas**, también la de la transcripción, además de los invitados (enlaces mailto) y el enlace al evento de Calendar.

## Seguridad

- **Firma de cada petición:** HMAC-SHA256 con el secreto compartido. La web rechaza las peticiones de más de 5 minutos de antigüedad y las repetidas.
- **Solo puede entregar y cumplir:** el script entrega datos y ejecuta las órdenes que la web genera **después de tu aprobación**. No puede aprobar nada.
- **Si cambias el secreto en la web,** tienes que pegarlo de nuevo en las propiedades del script.

## Error 403 de Cloudflare

Si el registro dice **«Cloudflare ha bloqueado la petición»**, o ves una página HTML que empieza por `<!--[if lt IE 7]> <html class="no-js ie6 oldie"`, es que Cloudflare está frenando a Google antes de que llegue a WordPress. Las peticiones del script salen de servidores de Google con el agente `Google-Apps-Script`, y Cloudflare las toma por bots.

Cómo solucionarlo en Cloudflare (dominio imad.pro):
1. Ve a **Seguridad → WAF → Reglas personalizadas → Crear regla**.
2. Nombre: `MAD Oficina hooks`.
3. Expresión (con «Editar expresión»):
   ```
   (starts_with(http.request.uri.path, "/wp-json/mad-oficina/v1/hooks/"))
   ```
4. En **Acción**, elige **Omitir (Skip)** y marca todo lo que ofrezca:
   - todas las reglas personalizadas restantes,
   - limitación de velocidad,
   - reglas administradas,
   - Super Bot Fight Mode (si aparece),
   - y en «Más componentes»: **Browser Integrity Check**, **Nivel de seguridad** y **User Agent Blocking**.
5. Guarda la regla y **arrástrala la primera** de la lista.
6. Si tienes activado **Bot Fight Mode**, el del plan gratuito (en **Seguridad → Bots**), tienes que desactivarlo: ese modo no se puede omitir con reglas.

¿Es seguro? Sí: esa ruta solo acepta peticiones firmadas con tu secreto, y la firma la comprueba WordPress. Más adelante, el bot de Google Chat necesitará otra regla igual para `/wp-json/mad-oficina/v1/chat/`.

## Si no tienes los enlaces permanentes activados

`MAD_ENDPOINT` tiene que ser del tipo `https://tu-web/wp-json/mad-oficina/v1`. La web debe tener activados los enlaces permanentes (Ajustes → Enlaces permanentes, cualquier opción distinta de «Simple»).
