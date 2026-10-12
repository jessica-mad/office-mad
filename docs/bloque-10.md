# Bloque 10 · Facturación

Se entrega por partes.

| Parte | Estado |
|---|---|
| 1 · Libro de facturas, histórico importado, clientes de facturación y resumen | **Hecha** |
| 2 · Emitir desde la oficina: borrador → número → PDF con la plantilla MAD → envío (con tu aprobación) y «Libro MAD» en la unidad compartida | **Hecha** |
| 2b · Previsión de ingresos: acuerdos (fees y encargos), presupuestos con probabilidad y vista mes a mes | **Hecha** |
| 3 · Calendario de facturación: borradores automáticos y «¿Envío la factura de X?» por Chat | **Hecha** |

## Parte 1 · Libro de facturas

### Series
- **F · Facturas.** Numeración `AA-NNN`, como la de siempre. La oficina asigna el número de forma correlativa y sin huecos. Dos aprobaciones a la vez no pueden coger el mismo número.
- **R · Rectificativas.** Numeración `R-AA-NN`.
- **AR · Argentina.** Facturas que se emiten en la plataforma argentina. Se guardan solo para tenerlo todo junto: **no se numeran aquí ni cuentan en los libros españoles ni en el paquete de la gestoría.** El resumen las muestra aparte.

### Importar el histórico
1. En «Facturas Autónomo Jessica Murillo», abre la pestaña **facturacion** y ve a **Archivo → Descargar → CSV**.
2. En **MAD Oficina → Facturación → Importar histórico**, sube el archivo. Verás una vista previa antes de importar.

**Qué hace con cada fila:**
- **Facturas con número:** se importan tal cual, con su estado (enviada, cobrada) y la fila original guardada en la ficha.
- **Facturas sin número:** eliges en la vista previa qué clientes van a la **serie Argentina**. Vienen propuestos los que no llevan IVA, IRPF ni NIF.
- **Borradores ya numerados pero sin enviar** (los de octubre): pierden el número que llevaban y **reciben el siguiente libre al emitirlos**. Así se corrige lo de octubre, porque 26-019, 26-020 y 26-021 ya estaban usados.
- **Filas vacías** (0 €) y **separadores de año:** se omiten.
- **NIF repetido con otro cliente** (el de Museum of Mini): se quita del borrador y queda una nota para que lo revises.
- **«Olofane» y «Olofane S.L.»:** son la misma ficha de cliente.

Puedes importar el mismo archivo otra vez sin que se dupliquen las facturas.

**Comprobación con la hoja real:**
- Los totales de 2024 cuadran al céntimo con la pestaña «Facturación mensual».
- En 2025 la diferencia son exactamente los 6.000 € de Luxury Tennessee, que ahora va a la serie Argentina.

### Pantallas
- **Facturas:** filtros por año, estado, serie y texto. Desde la ficha de cada factura puedes marcarla como cobrada o pendiente y añadir notas.
- **Resumen:** bases, IVA, IRPF y total a cobrar por trimestre y por mes, y una tabla por cliente con su porcentaje, como tus pestañas «Facturación mensual» y «Por clientes». La serie Argentina aparece aparte.
- **Clientes:** datos fiscales de cada cliente:
  - NIF, dirección y email de facturas;
  - IVA e IRPF;
  - serie;
  - el cliente de la oficina con el que está vinculado.
- **Datos del emisor:** tus datos para la plantilla nueva (nombre fiscal, NIF, dirección, IBAN y forma de pago).

### Conservación
Las facturas no se borran nunca. Si se borra un cliente de la oficina, sus facturas se quedan y solo se desvinculan.

## El PDF con el diseño de MAD

El diseño de tus facturas actuales («Work invoice» en negro y mostaza, el logo MAD en círculo negro, tus datos a la izquierda, el cliente a la derecha, la tabla de conceptos, la forma de pago y los totales con el total en amarillo) ahora se maqueta **como texto de verdad**, en vez de escribir encima de una imagen de fondo. Eso corrige:
- las etiquetas del cliente que se pisaban con los datos;
- los totales desplazados una fila;
- los importes sin formato (`731.79` → `731,79 €`);
- la segunda página en blanco;
- una sola línea de concepto: ahora caben tantas como haga falta.

**Cómo está hecho:**
- La cabecera es la de siempre, recortada de tu plantilla (`assets/billing/invoice-header.png`).
- La tipografía es **Montserrat**, la misma que usa tu factura.
- Tus datos (nombre, NIE, domicilio, IBAN, teléfono) **no van en ninguna imagen**: salen de **Facturación → Datos del emisor**.
- Si una factura no lleva IRPF, la fila de IRPF no aparece.
- Los borradores llevan la marca de agua «BORRADOR».
- Se genera en tu propia web con dompdf (`lib/dompdf`, licencia LGPL) en unas décimas de segundo.

**Para verla:** en la ficha de cualquier factura, pulsa **«Ver con el diseño MAD»**.

## Surarquia
No es de la serie Argentina: en la vista previa de la importación, desmárcala. Sus dos facturas de 2022 se enviaron sin número, así que se importan como borradores históricos, con una nota.

## Parte 2 · Emitir desde la oficina

### Borradores
- **Nueva factura** desde Facturación o desde la ficha del cliente. Toma su razón social actual, su IVA y su IRPF.
- **Copia los conceptos de su última factura**, porque las cuotas se repiten cada mes. Los cambias si ese mes es distinto.
- Puedes poner **tantos conceptos como quieras** (concepto, cantidad y precio). Los totales se calculan al momento y redondean igual que el servidor (147,105 → 147,11).
- **Fecha:** el día de facturación del cliente. Por defecto es el **28** (fin de mes); si el mes es más corto, el último día. Cada cliente puede tener su propio día (p. ej. DT) en sus datos fiscales.
- **Vencimiento:** fecha + **15 días**, o los días que tenga ese cliente.
- **Enviarme una prueba:** el cartero te manda solo a ti el PDF con la marca BORRADOR y el texto del email.
- Un borrador se puede borrar. Una factura emitida no se toca: se corrige con **Rectificar**, que prepara la rectificativa (serie R) con los conceptos en negativo.

### Emitir
1. **Pedir emisión** deja la tarjeta en la bandeja (y en Chat). Puedes cambiar la fecha, el destinatario, el asunto y el mensaje, y elegir **Emitir y enviar** o **Solo emitir**.
2. **Al aprobar:**
   - se le da el **siguiente número**;
   - se comprueba que la fecha no sea anterior a la última factura emitida, porque las facturas tienen que ir en orden;
   - se guarda una **copia de tus datos de emisor** en la factura, para que si cambias de dirección o de IBAN las antiguas no cambien;
   - se crea la orden para el cartero.
3. **El cartero:**
   - guarda el PDF en `Emitidas/AAAA/Tn/`;
   - si lo elegiste, lo envía **desde tu Gmail** con el PDF adjunto;
   - actualiza el **Libro MAD**;
   - avisa a la web, que marca la factura como **Enviada** y guarda el enlace de Drive.
4. La factura aparece en **«Mis facturas»** de las personas del cliente con ese permiso.

Si descartas una emisión, no se gasta ningún número.

### Cobros y Libro MAD
- **Marcar como cobrada** actualiza su fila del Libro MAD.
- Las facturas sin cobrar que pasan del vencimiento salen como **Vencida**.
- **Sincronizar todo el Libro MAD** (en Datos del emisor) vuelca todo el histórico de una vez.

### Argentina
Los borradores de la serie Argentina no se emiten aquí: se guardan y, desde su ficha, se marcan como **emitidos en Argentina**.

### Email
El texto se edita en **Datos del emisor**. Admite `{numero}`, `{cliente}`, `{total}`, `{mes}` y `{vencimiento}`.

## Parte 2b · Previsión de ingresos

Todo es interno y sin IVA: lo ves solo tú.

### Acuerdos
Lo que paga cada cliente:
- **Fee mensual:** concepto, importe al mes, desde y hasta (vacío = sin fin). Por ejemplo, RF hasta enero.
- **Encargo puntual:** un importe en un mes concreto. Si se cobra en dos veces, son dos encargos.
- **Cambiar importe desde un mes:** el fee anterior termina el mes de antes y empieza uno nuevo; el historial no se pierde.
- **Terminar:** pones el último mes. **Borrar** es solo para lo que se metió por error.
- **Fees que veo en tu histórico:** propone un fee para cada cliente facturado al menos 3 de los últimos 4 meses que aún no tenga uno. El fee arranca cuando empezó su importe actual. Tú revisas el importe y pones la fecha de fin si la sabes.

### Presupuestos
De momento se apuntan para la previsión; el documento A4 y el envío llegan con el bloque 11.
- Pueden ir a un cliente con datos fiscales, a un *lead* o a un nombre libre.
- Cada línea es **al mes** (desde un mes, N meses o sin fin) o **una vez** (en un mes).
- **Probabilidad:** 10, 25, 50, 75 o 90 %.
- **Estados:** borrador → enviado → aceptado / rechazado / caducado. Solo los enviados cuentan como «posible».
- **¡Aceptado!:** eliges a quién se factura y en qué mes empieza; si arranca más tarde, todo el presupuesto se mueve. Sus líneas pasan a Acuerdos.

### Previsión
- **Tres resúmenes:**
  - este mes;
  - lo confirmado de los próximos 3 meses;
  - los presupuestos en el aire.
- **Gráfica mes a mes**, de los 3 meses pasados a los 9 siguientes:
  - **negro**, lo facturado;
  - **mostaza**, lo confirmado;
  - **rayado**, lo posible.

  Puedes ver lo posible ponderado por su probabilidad o al 100 %.
- **Tabla por cliente**, con «falta X» donde lo acordado no se llegó a facturar.
- **Avisos:**
  - fees que terminan en los próximos 3 meses, y cuánto baja el mes siguiente;
  - lo acordado y no facturado en los últimos 3 meses;
  - el primer mes que va más de un 25 % por debajo de tu media de los últimos 6;
  - presupuestos enviados que han caducado.

La Parte 3 usará estos acuerdos para preparar cada mes los borradores de factura.

## Parte 3 · Calendario de facturación

**Facturación → Calendario.**

### Activarlo
1. Marca **«Preparar los borradores…»**.
2. Elige **Empezar en**: por defecto, el mes que viene. Los meses anteriores no se tocan, así no se duplica lo que ya hiciste con la hoja antigua.
3. Elige **cuántos días antes** se prepara el borrador (3 por defecto) y **desde qué hora** te pregunta (9:00).

### Qué hace cada mes
- **Unos días antes del día de cada cliente** (el 28, o el suyo, como DT), prepara el borrador con sus fees y encargos de ese mes:
  - «Mantenimiento web · noviembre 2026»;
  - «Landing Black Friday».
- **Cada línea recuerda qué acuerdo y qué mes cobra.** Si editas el borrador, el enlace se mantiene. Nada se factura dos veces ni se queda sin facturar.
- **Si ese mes ya hay otra factura del cliente hecha a mano**, el borrador lleva una nota para que revises que no se cobre dos veces.
- **Si surge un encargo después de emitir la del mes**, prepara otro borrador solo con lo nuevo.
- **El día que toca, te pregunta** en la bandeja y en Chat: **Enviar · Editar · Posponer 7 días · Este mes no**.
  - **Posponer:** mueve la fecha (y el vencimiento) y te vuelve a preguntar ese día.
  - **Este mes no:** quita el borrador y lo apunta, así no lo vuelve a preparar ni cuenta en la previsión.
- **Argentina:** prepara el borrador y te avisa por Chat de que toca hacerla en la plataforma argentina.

### La vista del mes
Para cada cliente muestra:
- el día;
- qué se factura (con el número de factura si ya salió);
- la base;
- el estado: por preparar / borrador listo / esperando tu OK / emitida / enviada / este mes no.

Desde aquí puedes **preparar ahora**, abrir, posponer o marcar «este mes no».

## Finanzas (menú propio)

- Menú **Finanzas**: Resumen · Facturas · Calendario · Previsión · Acuerdos · Presupuestos · Clientes de facturación · Gastos · Proveedores · Impuestos · Ajustes.
- **Resumen** (`Finance::year`): ingresos sin IVA (España y Argentina por separado), gastos registrados (en euros, o lo pagado en euros si la factura va en otra moneda: `expenses.paid_eur`), cuotas e impuestos que son coste (autónomos, otros) y lo que queda. IVA por trimestre cobrado vs soportado, solo orientativo.
- **Impuestos** (`Taxes`, tabla `taxes`, DB 13): apuntes a mano (303, 130, otros) y la cuota de autónomos se apunta sola cada mes desde el mes indicado. El IVA no cuenta como gasto: es dinero de los clientes que pasa por ti.
- Siguiente: justificante de pago (Western Union) junto a la factura del gasto.
