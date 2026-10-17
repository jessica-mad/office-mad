# Plan · Fase 3 · Campañas

> Propuesta para revisar. No se implementa nada hasta tu OK.

## Lo que pides

1. **Conectar las campañas** de Meta y de Google Ads y leer algunos datos (solo lectura).
2. **Un registro de cambios con su porqué**: qué se cambió, cuándo, quién lo cambió y **por qué se decidió**. Con tantos clientes y tantos cambios, al cabo de unas semanas ya no recuerdas el motivo de cada uno.

Lo segundo es lo importante. Ninguna de las dos plataformas guarda el porqué: solo registran *qué* cambió. Así que el plugin hará dos cosas:

- **Detectar cada día los cambios** en las cuentas de cada cliente.
- **Preguntarte el motivo** por Chat (con texto o audio), en un único mensaje al día y agrupado por cliente.

La que se encarga es **Nuria, la analista de campañas**: el personaje de la «Sala de campañas» que ya existe en la oficina.

## Cómo funcionará el porqué

```
Cada mañana (sincronización, solo lectura)
  Google Ads: historial de cambios (change_event) + métricas del día anterior
  Meta: historial de actividad de la cuenta (/activities) + insights
        │
        ▼
  Tabla ad_changes: cliente · campaña · qué cambió (antes → después) · quién · cuándo · desde dónde
        │
        ├── ¿Ya tiene motivo? (se lo contaste a Nora antes, o salió en un acta) → se une solo
        │
        └── Sin motivo → un mensaje de Nuria a tu Chat:
              «Ayer en LCS: presupuesto de "Rebajas" 20 → 35 €/día; puja de "Marca" a CPA objetivo 8 €.
               ¿Por qué?»
              Le respondes de forma natural (texto o audio): «el de rebajas porque convertía a 6 €
              y quedaba presupuesto; lo de marca, prueba de 2 semanas»
              → Nuria reparte el motivo entre los cambios y te deja «Deshacer»
```

- **Antes o después, como prefieras.** Si le dices a Nora «en LCS voy a subir el presupuesto de rebajas porque convierte bien», el motivo queda guardado y se une al cambio cuando aparezca al día siguiente. Lo mismo con las decisiones de un acta de reunión («subir presupuesto de rebajas»): Nuria propone unirlas.
- **Ruido fuera.** Las recomendaciones que aplica Google automáticamente, los cambios de sistema y las pausas automáticas no te los pregunta: se registran como «automático». Los cambios del mismo día en la misma campaña se agrupan en una sola línea.
- **Antes / después.** Cada cambio guarda las métricas de los 7 días anteriores y, una semana más tarde, las de los 7 posteriores: CPA, CTR, conversiones y gasto. En el informe ves «tras subir el presupuesto, el CPA pasó de 9 a 11 €». Es una comparación de periodos, **no una prueba de causa**, y el informe lo dirá así.
- **Interno por defecto.** Los motivos llevan visibilidad `internal`. El cliente no los ve, salvo que tú marques alguno para su informe.

## Qué verás

- **Nuevo menú «Campañas»** (gerencia):
  - **Por cliente:** cuentas vinculadas, campañas (estado, objetivo, presupuesto, fechas), métricas de los últimos 30 días y tus notas de «cómo se creó la campaña».
  - **Bitácora de cambios:** línea de tiempo por cliente y campaña, con filtros (sin motivo, por plataforma, por fechas). Cada línea muestra qué cambió, quién lo cambió, el porqué y el antes/después. El motivo se puede editar desde la propia lista, sin recargar.
  - **Informe:** por cliente y periodo, «qué cambiamos y por qué» con el resultado. Se puede exportar a la hoja de seguimiento del cliente y a un PDF. La versión para el cliente lleva solo lo que tú marques y sale **tras tu aprobación**.
- **En Chat con Nora:** «¿por qué bajamos la puja de LCS en septiembre?», «¿qué cambios hicimos este mes en Lanas?». Nuria responde con los datos, de forma objetiva, como el resto de agentes.
- **Informe previo a la reunión** (ya existe): se añade una sección de campañas con los cambios desde la última reunión y sus motivos, más las novedades.
- **En la oficina:** Nuria pasa a «trabajando» mientras sincroniza y camina a tu despacho cuando tiene cambios sin motivo.

## Datos (tablas nuevas)

| Tabla | Para qué |
|---|---|
| `ad_accounts` | Cliente, plataforma (`google`/`meta`), ID externo, nombre, moneda, estado, última sincronización |
| `ad_campaigns` | Cuenta, ID externo, nombre, estado, objetivo, presupuesto, fechas, notas de cómo se creó |
| `ad_metrics_daily` | Campaña y día: impresiones, clics, gasto, conversiones y valor |
| `ad_changes` | Cambio: plataforma, campaña u objeto, campo, antes → después, quién, cuándo y desde dónde (web, Editor, API, automático); motivo, de dónde salió el motivo (Chat, acta, manual), quién lo dio, visibilidad, métricas antes/después |

Ya hay un campo `ads_accounts` en la ficha de cliente; se sustituye por `ad_accounts`.

## Limitaciones (antes de implementar)

**Google Ads**

- Necesitas un **developer token** de tu cuenta de administrador (MCC), que se pide en *Herramientas → Centro de API*. Los tokens nuevos empiezan en «acceso de prueba», que **no lee cuentas reales**. Hay que pedir el **acceso básico**: un formulario, con respuesta en unos días; a veces piden una breve descripción del uso, que te preparo yo. El acceso básico permite 15.000 operaciones al día; nos sobra.
- El permiso de OAuth (`adwords`) es **de lectura y escritura**, porque Google no tiene uno de solo lectura. El plugin solo usará consultas de lectura. Como tu app de Google es Interna, no hace falta verificación: basta con reconectar Google una vez.
- El historial de cambios de la API cubre **solo los últimos 30 días**. Por eso se sincroniza a diario y el plugin guarda su propia copia. Lo anterior a la conexión no se puede recuperar más allá de esos 30 días.
- Las versiones de la API caducan más o menos al año. La versión irá en ajustes, y te avisaré cuando haya que subirla.

**Meta**

- Necesitas una **app en Meta for Developers** (tipo «Empresa») y un **usuario del sistema** en tu Business Manager con permiso `ads_read` y un token que no caduca. Las cuentas publicitarias de los clientes tienen que estar asignadas a ese usuario del sistema.
- Con el **acceso estándar** se leen las cuentas de tu propio Business Manager. Si alguna cuenta de cliente solo la tienes compartida como socio y Meta exige **acceso avanzado**, habría que pasar la **revisión de la app**, que es lenta. Lo comprobamos con una cuenta real en el primer bloque, antes de seguir.
- El **historial de actividad** de Meta trae menos detalle que el de Google: a veces dice «presupuesto actualizado» sin los valores. En ese caso, el valor anterior y el nuevo los deduce el plugin comparando con la sincronización del día anterior.

**General**

- Todo es **solo lectura**: ni Nuria ni Nora tocan campañas. Si más adelante quieres que pausen o cambien algo, sería otra fase, con aprobación en la bandeja.
- El contenido que viene de las plataformas (nombres de campañas, textos de anuncios) se trata como **datos, nunca como instrucciones**.

## Bloques

| Bloque | Qué incluye | Necesita de ti |
|---|---|---|
| **14 · Conexiones y cuentas** | Módulo `ads`; tablas; Conexiones → Google Ads (developer token, MCC, reconectar) y Meta (token del usuario del sistema, cifrado); vincular cada cliente con sus cuentas (con sugerencias por nombre); sincronización de campañas y métricas | Developer token con acceso básico, ID del MCC, app de Meta y token del usuario del sistema |
| **15 · Bitácora de cambios** | Lectura diaria del historial de cambios (Google y Meta); agrupación y filtro de cambios automáticos; vista de la bitácora con motivos editables; antes/después a los 7 días | — |
| **16 · El porqué por Chat** | Mensaje diario de Nuria con los cambios sin motivo; respuesta natural (texto o audio) repartida entre cambios, con «Deshacer»; motivos dados a Nora por adelantado; unión con decisiones de actas; Nuria como especialista de Nora | Elegir la hora del mensaje diario |
| **17 · Informes** | Informe por cliente y periodo (interno y versión cliente con aprobación); sección de campañas en el informe previo a la reunión; pestaña «Campañas» en la hoja de seguimiento; panel «Mis campañas» del portal (resumen claro, con aprobación) | — |

**Orden propuesto: 14 → 15 → 16 → 17.** Mientras tramitas el developer token y la app de Meta, puedo avanzar los bloques 14 y 15 con datos de prueba inventados, y conectarlos a tus cuentas cuando tengas los accesos.

## Preguntas para ti

1. **Google Ads:** ¿tienes ya cuenta de administrador (MCC) con todos los clientes colgando de ella? ¿Tienes developer token? Si lo tienes, ¿con qué nivel de acceso?
2. **Meta:** ¿las cuentas publicitarias de los clientes están en tu Business Manager (propias o compartidas como socio)? ¿Tienes ya alguna app en Meta for Developers?
3. **¿Quién más cambia campañas?** ¿Solo tú, o también Liz o los propios clientes? Si el cambio lo hace otra persona, ¿a quién le pregunta Nuria el motivo?
4. **Mensaje diario:** ¿a qué hora? ¿Prefieres uno al día o un resumen semanal?
