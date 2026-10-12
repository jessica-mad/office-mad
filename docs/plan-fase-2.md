# Plan · Fase 2 · Contabilidad

> **Actualizado tras revisar el sistema actual** (hoja de facturas desde 2021, plantilla de Docs, carpetas del Drive antiguo y la unidad compartida nueva). Los datos de clientes y proveedores no se copian a este repositorio.

## Decisión: sistema nuevo en la unidad compartida

En vez de seguir escribiendo en la hoja antigua, el plugin pasa a ser **la fuente de verdad** (como dice el proyecto). Google Drive queda como **reflejo ordenado** para ti y para la gestoría, todo en la unidad compartida de contabilidad:

```
Unidad compartida «Contabilidad MAD»
├── Libro MAD (Google Sheets)            ← reflejo del plugin, solo lectura para humanos
│     Emitidas · Recibidas · Clientes · Proveedores · Resumen (mes/trimestre/año) · Por cliente
├── Emitidas/AAAA/Tn/                    ← PDF de cada factura emitida
├── Recibidas/AAAA/Tn/                   ← PDF de cada factura de proveedor
├── Presupuestos/AAAA/
├── Gestoría/AAAA-Tn/                    ← paquete de cierre
└── Plantillas/                          ← plantilla de factura y de presupuesto (Docs, estilo MAD)
```

- **Numeración:** la asigna el plugin, de forma atómica y correlativa, con el formato que ya usas (`AA-NNN`, por ejemplo 26-024), continuando la serie del año. Las rectificativas van en serie aparte (`R-AA-NN`).
- **PDF:** lo genera el Apps Script «cartero», que ya tienes instalado y con permiso sobre Drive. Copia la plantilla de Docs de MAD, la rellena (con **varias líneas de concepto**), exporta el PDF y lo guarda en `Emitidas/AAAA/Tn/`. La plantilla la puedes retocar tú en Docs.
- **Corte:** desde una fecha que elijas (propuesta: 1 de noviembre), las facturas solo se emiten desde el plugin. Antes se **importa el histórico 2025–2026** de la hoja antigua, para la numeración, los clientes y las estadísticas. Lo anterior queda como archivo.
- **VeriFactu:** el emisor sigue detrás de `InvoiceIssuerInterface`. Un sistema propio como este vale hasta que la ley exija software homologado; según el calendario vigente, julio de 2027 para autónomos (hay que confirmarlo con la gestoría). Llegado ese momento se cambia el emisor por uno homologado sin tocar el resto.

Propuesta para revisar. No se implementa nada hasta tu OK.

## Bloques

| Bloque | Qué incluye | Necesita de ti |
|---|---|---|
| **8 · Proveedores y gastos** | Registro de proveedores; lectura de facturas en Gmail; extracción con Claude; archivo en Drive; altas desde Chat; facturas que faltan; avisos | Permiso de Gmail (reconectar Google) y decidir cómo será `facturas@imad.pro` |
| **9 · Informe de gastos** | Hoja «Gastos MAD» en `Contabilidad/` con categorías, totales por mes y trimestre, y alertas (subidas, duplicados, categorías disparadas) | — |
| **10 · Facturación a clientes** | Calendario de facturación; borradores 3 días antes; «¿Envío la factura…?» por Chat; emisión desde el plugin (`MadIssuer`: número, PDF con el cartero, «Libro MAD»); archivo en `Emitidas/`; envío; estado de cobro; importación del histórico 2025–2026 | Fecha de corte y datos fiscales del emisor |
| **11 · Presupuestos** | Plantilla A4 de MAD; envío tras aprobación; aceptado o rechazado; conversión a factura | Logo `mad-logo.png` (si no, sello provisional) |
| **12 · Paquete para la gestoría** | Aviso de lo que falta antes del cierre; carpeta `Contabilidad/Gestoría/AAAA-Tn/` con PDF y hoja resumen; envío tras aprobación | Periodicidad y email de la gestoría |
| **13 · Revisor** | Comprobación mensual (emitidas frente a cobradas, gastos sin factura, duplicados, importes raros, repercutibles sin facturar) e informe en la bandeja | — |

**Orden propuesto: 8 → 9 → 10 → 11 → 12 → 13.** El bloque 8 no depende de tu hoja de facturación. Mientras lo hago, me la pasas y la analizo para el bloque 10.

---

## Bloque 8 · Proveedores y gastos (detalle)

### Tablas nuevas
- **`suppliers`**:
  - nombre, NIF, emails desde los que factura, servicio, categoría;
  - periodicidad (mensual, trimestral, anual o puntual), día o mes esperado e importe habitual;
  - cómo llega la factura (email, panel con URL o «me la pide a mí»);
  - si se puede cambiar el email de facturación;
  - si es repercutible, a qué cliente y con qué margen;
  - estado (activo o cancelado) y notas.
- **`expenses`**:
  - proveedor, número, fecha, base, IVA (tipo e importe), total, moneda;
  - archivo en Drive, categoría y trimestre;
  - repercutible a cliente y «facturado en» (se rellena en el bloque 10);
  - origen (Gmail, subida a mano o Chat), el `message_id` de Gmail para no repetir y el estado (pendiente de revisar o registrado).

### Lectura de Gmail
- **Scope:**
  - `gmail.readonly`;
  - `gmail.labels` y `gmail.settings.basic`, solo para crear la etiqueta «Facturas» y los filtros que apruebes.

  Como la app OAuth es **Interna**, Google no exige verificación.
- **Qué se busca:** cada hora, emails con PDF de los remitentes conocidos y los que tengan la etiqueta «Facturas». Para remitentes desconocidos, solo los que parezcan factura (asunto o adjunto con «factura», «invoice» o «recibo»).
- **Lectura del PDF:** Claude Sonnet lee el PDF directamente y devuelve JSON validado con proveedor, NIF, fecha, número, base, IVA y total.
  - **El contenido del email y del PDF son datos, nunca instrucciones.** Una factura no puede hacer que un agente envíe nada.
  - Si el total no cuadra con la base más el IVA, el gasto queda «por revisar».
- **Archivo:** nombre `AAAA-MM-DD_Proveedor_Importe.pdf` en `Recibidas/AAAA/Tn/` de la unidad compartida. Lo mueve el cartero, que ya tiene acceso a Drive, así que no hace falta otro permiso.
- **Sustituye al flujo de Workspace Studio** «Etiquetar facturas y guardar adjuntos en Drive». Ese flujo guarda también boletines, notas de Gemini y correos de clientes. El agente distingue lo que es factura y lo que no.
- **Proveedor nuevo:** te llega a la bandeja y al Chat con los datos detectados. Te pregunta lo que no puede deducir (periodicidad, categoría, si es para un cliente) y te propone el **filtro de Gmail**, que se crea solo si lo apruebas.

### Altas por Chat
Le escribes al bot «he contratado Canva Pro, 12 €/mes». Claude lo convierte en una ficha de proveedor y te la deja para aprobar con un botón.

### Facturas que faltan
- Si pasa la fecha esperada más N días (configurable, por defecto 5) y no hay factura:
  1. Busca otra vez en Gmail.
  2. Si no la encuentra, te pregunta por Chat con botones: **Te la reenvío** / **Descárgala de [URL]** / **Llegará más tarde (recuérdamelo el día X)** / **Este mes no hay**.
- **Aviso anticipado:** «Esta semana debería llegarte la factura anual de X».
- También puedes **subir un PDF a mano** desde el panel o reenviarlo a `facturas@`.

### Gastos repercutibles
Un gasto marcado para un cliente queda «pendiente de facturar», con un margen opcional. En el bloque 10 se suma a la próxima factura de ese cliente.

### Pantallas
**MAD Oficina → Proveedores y gastos**, con cuatro pestañas:
- proveedores;
- gastos, con filtros por mes, trimestre, categoría y estado;
- pendientes de revisar;
- ajustes: categorías, días de margen y buzones.

### Personaje
La **Contable** de la sala de Administración. Cuando procesa facturas aparece «trabajando», y cuando te pregunta algo camina a tu despacho.

---

## Limitaciones a tener en cuenta

1. **El buzón `facturas@imad.pro`.** El plugin lee el Gmail de la cuenta que conectaste (la tuya). Hay tres formas de crear la dirección:
   - **Alias de tu usuario** (recomendado): gratis. Los emails llegan a tu buzón y el plugin los ve directamente.
   - **Grupo de Google que te reenvía a ti:** también funciona, porque llegan a tu buzón.
   - **Usuario propio de Workspace:** cuesta una licencia y habría que conectarlo aparte. No compensa.
2. **Facturas que no llegan por email** (las de descargar de un panel): el plugin no entra en paneles de terceros con tus contraseñas, porque sería inseguro y frágil. Te avisa con el enlace y tú subes el PDF o lo reenvías.
3. **Gmail con permiso de lectura** es un permiso sensible. Con la app Interna no hace falta verificación, pero solo funciona con cuentas de tu Workspace (imad.pro).
4. **Lo fiscal:** el plugin prepara datos; **no calcula liquidaciones como definitivas ni presenta nada**. Eso lo revisa y presenta la gestoría.
5. **VeriFactu y facturación electrónica:** la emisión irá por el adaptador `InvoiceIssuerInterface`. Cuando sea obligatorio, se cambia `GoogleSheetsIssuer` por un software homologado sin tocar el resto del flujo.

---

## Lo que necesito de ti

1. **Tu hoja de facturación de Google Sheets** (para el bloque 10). Compártela conmigo con un enlace de **solo lectura** y, si puedes, añade un PDF de una factura emitida. No cambiaré su estructura sin preguntarte.
2. **`facturas@imad.pro`:** ¿alias de tu usuario (recomendado), grupo o aún no existe?
3. **Gestoría:** ¿mensual o trimestral? ¿A qué email se manda el paquete?
4. **Categorías de gasto.** Propuesta inicial:
   - software y suscripciones;
   - hosting y dominios;
   - publicidad (saldo de Ads);
   - formación;
   - material y equipos;
   - colaboradores;
   - gestoría y bancos;
   - otros.
5. **Arranque:** ¿quieres que en el primer escaneo repase **los últimos 12 meses** de Gmail y te proponga la lista de proveedores de golpe? Lo recomiendo.
