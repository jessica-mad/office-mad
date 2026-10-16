# Bloque 2 · Oficina y portal

## Cómo activarlo en la web

1. En **MAD Oficina → Módulos**, activa **Oficina y personajes**. Necesita que **Clientes** también esté activo.
2. Crea una página (por ejemplo `/oficina`) con el shortcode `[mad_oficina]`.
3. En **MAD Oficina → Oficina** puedes cambiar:
   - los nombres y cargos de los personajes,
   - el modelo de la recepcionista (Haiku 5.5 por defecto),
   - su personalidad,
   - el límite de mensajes al día por cliente (40 por defecto).
4. El chat solo aparece si `MAD_OFICINA_ANTHROPIC_KEY` está en `wp-config.php`.

## Qué ve cada persona

| Quién | Ve |
|---|---|
| Cliente | Su oficina. Todo lo que muestran los paneles es suyo y tiene visibilidad `client`. El despacho de gerencia aparece cerrado. |
| Jessica | Un selector «Ver como» para ver la oficina **exactamente** como la ve cada cliente. |
| Liz (`mad_team`) | La oficina y sus encargos. No ve la información de los clientes. |
| Sin sesión iniciada | Un formulario de acceso con el estilo de MAD. |

## Vista oficina y vista clásica

- **Vista oficina:** Phaser 3. Se carga aparte (unos 350 KB comprimido) y solo cuando se usa.
- **Vista clásica:** HTML. Es la vista por defecto en móvil y con «reducir movimiento». El botón de la barra cambia de una vista a otra y se recuerda la elección.

En la vista oficina:
- Al pinchar una sala o un personaje se abre su panel. También hay una fila de botones con las salas para usarla con teclado.
- Los personajes trabajan en su mesa y dan paseos de vez en cuando.
- Si su agente tiene una aprobación pendiente, caminan al despacho de gerencia. El estado se consulta cada 25 s.

## Paneles

**Disponibles:**
- Cómo va mi proyecto: línea de tiempo por ámbito.
- Mis reuniones: resumen, acuerdos, grabación y próxima reunión.
- Tareas en compromiso: «te toca a ti» y «lo hace MAD».
- Ideas para el futuro.
- Mis documentos: enlace y vista de la carpeta de Drive.
- Lo que prepara Liz.
- Chat con la recepcionista.

**Marcados como «Pronto»:**
- Facturas (fase 2).
- Campañas (fase 3).
- Pedir una reunión (bloque 5).
- Nuevo encargo.
- Bandeja de gerencia (bloque 3).

## Recepcionista (seguridad)

- **Solo consulta:** tiene cuatro herramientas de lectura (proyecto, tareas, reuniones e ideas). No puede enviar, crear ni cambiar nada.
- **El cliente lo decide el servidor:** lo que pida el modelo, o un `?client=` en la petición, se ignora.
- **No sigue órdenes de los datos:** las reglas de seguridad se añaden siempre al final del prompt. Lo que devuelven las herramientas y lo que escribe el usuario se trata como datos, no como instrucciones.
- **Historial:** se guarda por usuario. Cada respuesta deja apuntados los tokens y el coste en `agent_log`.

## Sprites propios

Sustituye los archivos `assets/game/characters/{slug}.png` (y en `game/public/characters/` si recompilas). Formato (el de pixel-agents, desde la oficina v2):
- PNG de **112×96**: 7 columnas de 16 px × 3 filas de 32 px.
- Filas: abajo, arriba, derecha. La izquierda se obtiene reflejando la derecha.
- Columnas: caminar 1-3, teclear 1-2, leer 1-2.
- Punto de apoyo: centro de los pies (abajo del todo, en el centro).
- Plantilla: cualquiera de `game/public/kit/characters/char_*.png`.

Los slugs son:
- `recepcionista`
- `secretaria`
- `coordinadora`
- `liz`
- `archivera`
- `contable`
- `analista`
- `jessica`

El mapa (`office.json`) se abre con **Tiled**, pero se genera con `game/scripts/generate-assets.mjs` (posiciones del encargo `docs/oficina-v2.md`): cambia los muebles allí, no en Tiled.

## Recompilar (solo desarrollo)

```bash
cd game && npm install && npm run build   # regenera los sprites provisionales y compila en assets/game
```

`npm run build` vuelve a generar el mapa y los sprites provisionales, pero **nunca sobrescribe un sprite tuyo**: solo sustituye los que generó él (los anota en `characters/.generated.json`).
