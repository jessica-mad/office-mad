# Bloque 10 · Facturación

Se entrega por partes.

| Parte | Estado |
|---|---|
| 1 · Libro de facturas, histórico importado, clientes de facturación y resumen | **Hecha** |
| 2 · Emitir desde la oficina: borrador → número → PDF con la plantilla MAD → envío (con tu aprobación) y «Libro MAD» en la unidad compartida | Siguiente |
| 3 · Calendario de facturación: borradores automáticos y «¿Envío la factura de X?» por Chat | Pendiente |

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
