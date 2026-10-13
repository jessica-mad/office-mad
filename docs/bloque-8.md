# Bloque 8 · Proveedores y gastos

**MAD Oficina → Gastos.** Lo lleva la Contable.

## Cómo llegan las facturas
- **De Gmail, con el cartero:**
  - cada 10 minutos busca correos con PDF que parezcan facturas: palabras como factura, invoice o recibo, o remitentes de proveedores conocidos;
  - solo lee: no toca nada en Gmail;
  - el mismo PDF nunca se procesa dos veces.
- **Subidas a mano** (**Subir factura**): para las que se descargan del panel del proveedor.

## Qué hace con cada una
1. **La lee** (Claude, a partir del PDF): proveedor, NIF, número, fecha, base, IVA, IRPF, total, moneda, concepto y destinatario.
   - El PDF y el email son datos, nunca instrucciones.
   - Si no es una factura (presupuesto, proforma, extracto…), la descarta.
2. **Solo cuentan las facturas a tu nombre.** Compara el destinatario con tu nombre fiscal y tu NIE (de Facturación → Datos del emisor) y con los que añadas en **Ajustes**.
   - **A nombre de un cliente o de otra persona:** no cuenta. Se guarda aparte, en **«No son mías»** y en `Recibidas/No son mías/AAAA/`, por si alguna fuera tuya: ábrela y pulsa **«Es mía»**.
   - **Sin destinatario:** si el recibo trae tu email, es tuya. Si no, **te pregunta** en la bandeja y en Chat.
3. **Proveedor conocido:** la registra sola, sin preguntarte, y la archiva en `Recibidas/AAAA/Tn/AAAA-MM-DD_Proveedor_Importe.pdf`.
   - Si es más cara de lo habitual (más de un 10 %), lo anota y sale en el resumen de los lunes.
   - Si la misma factura llega dos veces, la segunda se descarta.
4. **Proveedor nuevo:** tarjeta **«¿Proveedor nuevo?»** con lo que ha leído. Puedes ajustar la categoría, la periodicidad, el día esperado, el importe habitual y si se repercute a un cliente. También puedes unirla a un proveedor que ya tienes.
   - Al aprobarla, da de alta el proveedor (con el email desde el que factura) y registra la factura.
   - Si lo dejas marcado, el cartero crea en Gmail el filtro con la etiqueta «Facturas».
   - **Descartar** = no es una factura tuya de proveedor: se guarda aparte.
5. **Si no puede leerla** tras 3 intentos, la deja en **Por revisar** para que completes los datos a mano.

## Facturas que faltan
Para cada proveedor mensual, trimestral o anual con día esperado: si pasan 5 días (configurable) y no ha llegado, te pregunta en la bandeja y en Chat:
- **Te la reenvío** o **Descárgala del panel**: espera 3 días y vuelve a preguntar;
- **Recuérdamelo en 7 días**;
- **Este mes no hay**.

Cuando llega, la pregunta se cierra sola.

Con las **facturas anuales**, te avisa una semana antes: «esta semana debería llegarte…».

## Gastos repercutibles
Un gasto marcado para un cliente (en el proveedor o en el propio gasto) se suma **solo** a su próximo borrador de factura: «Repercutido: proveedor · concepto (fecha)», por la base y **sin margen**.
- Sale en el Calendario aunque ese mes el cliente no tenga fee.
- La línea queda enlazada al gasto, así nunca se cobra dos veces.

## Pantallas
- **Gastos:** por año y trimestre, con la base, el IVA soportado y el total.
- **Por revisar** y **No son mías**.
- **Proveedores:** ficha de cada uno: emails, categoría, periodicidad, día y mes esperados, importe habitual, cómo llega la factura (email, panel con su URL o «me la pide a mí»), si es repercutible y estado.
- **Subir factura**.
- **Ajustes:**
  - otros nombres, NIF y emails tuyos;
  - días de gracia;
  - modelo de Claude (Opus 5.5 por defecto; Haiku 5.5 es mucho más barato);
  - resumen de los lunes.

## Coste aproximado
Leer una factura de 1–2 páginas con Opus 5.5 cuesta alrededor de 1–2 céntimos. El repaso de 12 meses, con unos cientos de PDF, son unos pocos euros. Con Haiku 5.5, una fracción de eso.

## Archivo en Drive, importación del histórico y justificantes de pago
- **Carpeta del archivo** configurable (Gastos → Ajustes), p. ej. «Facturas para MAD»:
  - `AAAA/AAAA-Tn/` con cada factura y su justificante de pago al lado (`…_pago.pdf`);
  - `No son mías/AAAA/` y `Duplicados/`.
  - «Reorganizar el archivo» mueve allí lo ya archivado.
- **Importación del histórico** desde carpetas de Drive (Gastos → Subir o importar), desde un año:
  - el cartero recorre las carpetas y envía cada PDF o imagen nuevo (`/hooks/expenses` con `source: drive`);
  - las carpetas de origen solo se leen: se copia al archivo;
  - lo que ya está en el archivo se ordena allí mismo; las copias repetidas van a `Duplicados/`;
  - se recuerda qué archivos de Drive se enviaron (`expenses.source_ref`), así no se descargan dos veces.
- **La Contable distingue tres tipos de documento**:
  - factura;
  - **justificante de pago**: recibo de tarjeta, transferencia, Western Union o captura;
  - **pago de impuestos**: autónomos, 303, 130.
- **Justificantes de pago** (estado `payment`):
  - se unen solos a la factura que pagan (`payment_for`), por número de factura o por proveedor, importe y fecha;
  - si la factura llega después, se unen al registrarla;
  - si la factura va en otra moneda, el importe pagado en euros (`paid_eur`) es lo que cuenta en el Resumen;
  - nunca cuentan como gasto.
  - Pestaña **Pagos**: los que aún no tienen factura, para unirlos a mano o registrarlos como gasto si no hay factura.
- **Pagos de impuestos** (estado `tax`): se apuntan en Finanzas → Impuestos (uno por impuesto y periodo; sustituyen a la cuota automática de ese mes) con enlace al documento.
- **Imágenes** (PNG, JPG, WEBP): se leen igual que los PDF.
- **Corregido**: en las subidas a mano ya no se cambia el año de una factura antigua.

## Pendiente (bloque 9 y siguientes)
- Hoja «Gastos MAD» con alertas de suscripciones duplicadas y categorías disparadas.
- Importar la pestaña «Gastos» de 2024.
- Altas de proveedores escribiendo al bot de Chat.
