# Oficina v2 · Rediseño de la oficina pixel art

Encargo para rehacer la oficina del portal (`game/`). La API, los permisos, los estados y el contenido de los paneles no cambian. Cambian la escena, cómo se muestra en móvil y escritorio, y cómo se habla con cada personaje.

Tiene dos partes. Hazlas en este orden y enséñame cada una antes de seguir:

- **Parte A · Escena:** planta vertical compacta, kit gráfico nuevo y personajes.
- **Parte B · Pantallas y primer plano:** reparto de la pantalla en móvil y escritorio, y primer plano de cada personaje con su tabla de acciones.

Antes de escribir código, propón el plan (archivos que tocas, orden y riesgos) y espera mi OK, como en el resto de bloques.

# Parte A · Escena

## Referencias

- `game/design/oficina-v2-referencia.png`: así debe quedar (render a ×3).
- `game/design/oficina-v2-referencia-1x.png`: el mismo render a 1×, píxel a píxel.
- `game/design/prototipo_oficina_v2.py`: el script en Python que genera ese render. **Es la fuente de las posiciones exactas**: el mapa ASCII, cada mueble con su columna y fila, los personajes y los colores. No es código de producción: tradúcelo a `generate-assets.mjs` y a `office.json`.

## Qué cambia

1. **Planta vertical y compacta**: el mapa pasa de 48×30 a **18×28 tiles** (288×448 px). Funciona igual en móvil (ancho completo, scroll vertical) y en escritorio.
2. **Kit gráfico de pixel-agents** en lugar de los tiles generados por código: suelos, paredes en vista 3/4 y muebles.
3. **Paleta MAD aplicada al kit**: los suelos y paredes del kit vienen en gris y se tiñen; el mostrador va en mostaza y la moqueta de recepción es negra con puntos mostaza.
4. **Personajes sentados de cara**: detrás de su mesa, mirando hacia abajo, con el ordenador a un lado para que se les vea la cara.
5. **Antesala visible**: los agentes que esperan a gerencia se ponen en el banco que hay delante de la puerta de gerencia, en la planta abierta, a la vista de todos.

## Origen y licencia del kit

- Repositorio: https://github.com/pixel-agents-hq/pixel-agents (licencia **MIT**, © 2026 Pablo De Lucca).
- Carpeta de origen: `webview-ui/public/assets/` (`floors/`, `walls/`, `furniture/`, `characters/`).
- Los personajes derivan de «Metro City» de JIK-A-4 (CC0): https://jik-a-4.itch.io/metrocity-free-topdown-character-pack
- Copia solo los PNG que usemos en `game/public/kit/` (con la misma estructura) y añade `game/public/kit/CREDITS.md` con el aviso MIT completo y el crédito de JIK-A-4. No copies código de pixel-agents ni las mascotas (`pets/`).

## Mapa

Leyenda: `W` muro · `p` parquet · `g` planta abierta · `c` baldosa crema · `h` pasillo · `d` moqueta de recepción · `e` felpudo de entrada.

```
     0         1
     012345678901234567
 0   WWWWWWWWWWWWWWWWWW
 1   WWWWWWWWWWWWWWWWWW
 2   WpppppppWppppppppW   Estudio (Liz) · Gerencia
 3   WpppppppWppppppppW
 4   WpppppppWppppppppW
 5   WpppppppWppppppppW
 6   WpppppppWppppppppW
 7   WWWppWWWWWWWppWWWW   puertas x3-4 y x12-13
 8   WggggggggggggggggW   Planta abierta: proyectos · campañas · administración · antesala/café
 9   WggggggggggggggggW
10   WggggggggggggggggW
11   WggggggggggggggggW
12   WggggggggggggggggW
13   WggggggggggggggggW
14   WggggggggggggggggW
15   WWWWWWhhWWWWWWWWWW   paso al pasillo x6-7
16   WccccWhhWccccccccW   Archivo · Pasillo · Reuniones
17   WccccWhhWccccccccW
18   WcccchhhWccccccccW   puerta del archivo x5
19   WccccWhhWccccccccW
20   WccccWhhWccccccccW
21   WWWWWWddWWWWddWWWW   puertas x6-7 y x12-13
22   WddddddddddddddddW   Recepción
23   WddddddddddddddddW
24   WddddddddddddddddW
25   WddddddddddddddddW
26   WddddddddddddddddW
27   WWWWWWWWeeWWWWWWWW   entrada x8-9
```

### Salas (capa `rooms` de Tiled, en tiles)

Las salas de la planta abierta son **zonas** sin paredes: el código solo necesita sus rectángulos para los clics, los rótulos y el velo de visitantes.

| slug | x | y | ancho | alto |
|---|---|---|---|---|
| `estudio` | 1 | 0 | 7 | 7 |
| `gerencia` | 9 | 0 | 8 | 7 |
| `proyectos` | 1 | 8 | 4 | 7 |
| `campanas` | 5 | 8 | 4 | 7 |
| `administracion` | 9 | 8 | 3 | 7 |
| `archivo` | 1 | 15 | 4 | 6 |
| `reuniones` | 9 | 15 | 8 | 6 |
| `recepcion` | 1 | 21 | 16 | 6 |

La antesala y el café (x12-16, y8-14) no son una sala: no se pueden pulsar.

### Puntos (capa `spots`)

| spot | tile | mira hacia |
|---|---|---|
| `desk_liz` | 3, 4 | abajo |
| `desk_jessica` | 12, 4 | abajo |
| `desk_coordinadora` | 2, 10 | abajo |
| `desk_analista` | 6, 10 | abajo |
| `desk_contable` | 10, 10 | abajo |
| `desk_archivera` | 2, 18 | abajo |
| `desk_secretaria` | 15, 18 | izquierda (de pie junto a la mesa) |
| `desk_recepcionista` | 8, 23 | abajo (de pie tras el mostrador) |
| `gerencia_wait_1` … `gerencia_wait_4` | 12, 9 · 13, 9 · 14, 9 · 15, 9 | arriba |
| `entrance` | 8, 26 | arriba |

Pon 2 puntos `wander_<sala>_N` por sala, en suelo libre, y 2 `wander_pasillo_N` en el pasillo (x6-7). Amplía `goToManager()` a 4 sitios de espera.

## Muebles y decoración

Cada mueble tiene su posición exacta en el prototipo, en la sección «Muebles».

Reglas:
- Los objetos de pared (cuadros, relojes, pizarra, estanterías dobles) van con su fila inferior sobre un tile de muro. **Nunca encima de una puerta.**
- Los muebles van en una **capa de objetos de Tiled** (`props`), no en una capa de tiles, porque muchos ocupan varios tiles y se ordenan en profundidad por su borde inferior junto con los personajes.
- El mostrador de recepción son dos `DESK_FRONT` teñidos de mostaza.
- El cartel MAD (x8-11, y20-21) se dibuja con la misma geometría que en el prototipo hasta que tenga el logo.
- Los ordenadores van de lado (`PC_SIDE`) en los extremos de la mesa, para no tapar la cara del personaje.

## Personajes

**Formato nuevo (sustituye al de 128×192):** el de pixel-agents.
- PNG de **112×96**: 7 columnas de 16 px × 3 filas de 32 px.
- Filas: abajo, arriba, derecha. La izquierda se obtiene reflejando la derecha.
- Columnas: caminar 1-3, teclear 1-2, leer 1-2.
- Punto de apoyo: centro de los pies.

Asignación provisional, hasta que Jessica dibuje los suyos sobre esta plantilla:

| slug | origen |
|---|---|
| `liz` | `char_1` |
| `jessica` | `char_2` |
| `coordinadora` | `char_0` |
| `analista` | `char_4` |
| `contable` | `char_5` |
| `archivera` | `char_3`, tono +120° |
| `secretaria` | `char_1`, tono +210° |
| `recepcionista` | `char_5`, tono +170° |

El cambio de tono se hace al generar los PNG, no en el navegador. Solo cambia la ropa: no toques la piel ni el pelo castaño (la función `hue()` del prototipo ya lo hace).

Estados:
- `trabajando`: animación de teclear en su mesa.
- `idle`: sentado o paseando.
- `esperando_gerencia`: va al banco de la antesala y espera mirando arriba, con el bocadillo de siempre.

Actualiza también el texto de ajustes en `modules/portal/views/settings.php` y la sección «Sprites propios» de `docs/bloque-2.md` con el formato nuevo.

## Código que toca

- `game/scripts/generate-assets.mjs`: genera el mapa nuevo y los PNG teñidos a partir de `game/public/kit/`. Si ya existe un PNG propio en `game/public/characters/`, no lo sobrescribe.
- `game/public/office.json`: capas `floor`, `walls`, `props` (objetos), `rooms` y `spots`.
- `game/src/office/OfficeScene.js`:
  - paredes con el sistema de autoajuste del kit (máscara N=1, E=2, S=4, W=8; sprites de 16×32 apoyados en la base del tile);
  - capa `props` ordenada por profundidad;
  - formato de personaje nuevo;
  - 4 sitios de espera;
  - personajes sentados detrás de la mesa.
- `game/src/office/index.js` y `game/src/portal.css`: lienzo de 18×28 y relación 18/28. En móvil, ancho completo con scroll vertical, sin recortar.
- `modules/portal/Module.php`: el cálculo de versión de assets (`glob`) debe incluir los PNG nuevos del kit.

## Comprobación de la parte A

- Abre la oficina en navegador, en escritorio y en móvil (390 px), y compárala con `oficina-v2-referencia.png`.
- Cada sala y cada personaje se puede pulsar y abre el mismo panel que hoy.
- La vista de visitante sigue oscureciendo todo menos la recepción.
- Fuerza un `esperando_gerencia` (con el seeder) y comprueba que el agente va al banco y vuelve a su mesa al resolverse.
- Commits pequeños, uno por paso.

# Parte B · Pantallas y primer plano

Referencia visual: tableros «08 · Primer plano» y «09 · Tabla de interacciones» del lienzo de diseño, más `game/design/retrato-recepcionista-provisional.png`.

## Reparto de la pantalla

**Escritorio (más de 760 px de ancho):**
- La oficina va a la izquierda, a su altura natural (el mapa es vertical).
- A la derecha va una columna fija de unos 420-480 px: el **panel lateral**. Al entrar muestra la recepción (saludo y menú); al tocar un personaje o una sala, su primer plano.
- Los paneles ya no tapan la oficina: dejan de ser un diálogo modal centrado y pasan a esa columna. La oficina sigue viva al lado.

**Móvil (760 px o menos):**
- La oficina ocupa todo el ancho y se recorre con scroll vertical, sin recortar.
- Al tocar un personaje o una sala, el primer plano sube como una hoja a pantalla completa, con «‹ Oficina» arriba para volver al mismo punto del scroll.
- Debajo del mapa se mantiene la fila de botones de salas (`.madof-rooms`), para quien prefiera no buscar en el mapa.
- **Cambio de norma:** hasta ahora la vista clásica era la de por defecto en móvil (CLAUDE.md, sección 4.5). Con la planta vertical, la oficina pasa a ser la vista por defecto también en móvil. La vista clásica sigue disponible con el botón de siempre y sigue siendo la de por defecto con `prefers-reduced-motion`. Actualiza esa línea de CLAUDE.md.

## Primer plano del personaje

Al tocar un personaje (o su sala):
1. La cámara se acerca a su mesa (unos 300 ms) y el panel lateral, o la hoja en móvil, muestra su primer plano.
2. **Retrato:** ilustración de su busto en su sala. El bocadillo de debajo lleva su nombre, la pastilla «IA» si es un agente y el saludo, que se escribe letra a letra (sin animación con `prefers-reduced-motion`).
3. **Tabla:** debajo del retrato (en móvil) o a su lado (en escritorio, si cabe):
   - una **ficha destacada** con la acción principal y un resumen de una línea;
   - una **rejilla de casillas** de 3 columnas, cada una con su icono pixel de 12×12 y su texto;
   - **pestañas**, solo cuando un personaje tenga más de 9 casillas.
4. Al tocar una casilla se abre el panel que ya existe (`PANELS[...]` de `panels.js`) dentro de la misma columna u hoja, con «‹ Volver» a la tabla.

Cada casilla es un `<button>` de verdad. Hay que poder recorrer todo con teclado y el foco vuelve al personaje al cerrar. El saludo va en una región `aria-live="polite"`.

### Retratos

- PNG de **96×72 px**, que se muestra a ×4-×6 con `image-rendering: pixelated`.
- Ruta: `assets/game/portraits/{slug}.png`. Opcionalmente, `{slug}-hablando.png` y `{slug}-contenta.png` para animar el bocadillo.
- Si falta el retrato, se usa el sprite del personaje ampliado sobre el color de su sala.
- `retrato-recepcionista-provisional.png` es solo una muestra del encuadre: Jessica dibujará los definitivos.

### Tabla de cada personaje

Las casillas abren paneles que ya existen. No inventes datos nuevos ni endpoints.

| Personaje | Saludo | Casillas (panel que abren) | Quién |
|---|---|---|---|
| Recepcionista | «¡Hola, {nombre}! ¿Qué necesitas hoy?» | Mi proyecto (`proyecto`), Reuniones (`reuniones`), Tareas (`tareas`), Ideas (`ideas`), Documentos (`documentos`), Facturas (`facturas`), Pedir reunión (`reunion`), Nuevo encargo (`encargo`), Escribir (`chat`) | Clientes |
| Recepcionista (visitante) | «¡Hola! ¿En qué te ayudamos?» | Qué hacemos (`que-hacemos`), Primera reunión (`primera-reunion`), Dejar un brief (`brief`) | Visitantes |
| Secretaria de actas | «Aquí tienes todo lo que hablamos.» | Mis reuniones (`reuniones`) | Clientes |
| Coordinadora | «Esto es lo que tenemos en marcha.» | Cómo va mi proyecto (`proyecto`), Tareas en compromiso (`tareas`), Ideas para el futuro (`ideas`) | Clientes |
| Liz | «Mira lo que estoy diseñando para ti.» | Encargos de diseño y vídeo (`estudio`). Para Liz: Mis encargos (`mis-tareas`) | Clientes y Liz |
| Archivera | «Tus documentos, bien ordenados.» | Mis documentos (`documentos`) | Clientes |
| Contable | «Facturas y presupuestos, al día.» | Mis facturas y presupuestos (`facturas`) | Clientes |
| Analista | «Muy pronto te enseño tus campañas.» | Mis campañas · Pronto (`campanas`) | Clientes |
| Jessica | Para Jessica: «¿Qué revisamos hoy?» · Para el cliente: «Está trabajando.» | Para Jessica: Bandeja (`bandeja`). Para el cliente: Pedir reunión (`reunion`) | Gerencia y clientes |

Se respetan las reglas que ya existen:
- `canSee()` oculta las casillas de áreas que el cliente no tiene.
- Los visitantes solo pueden hablar con la recepcionista; los demás personajes muestran «Solo clientes».
- El despacho de gerencia sigue siendo privado.

Los saludos son editables junto a los nombres, en Ajustes → Oficina.

### Agente esperando a gerencia

Si Jessica toca a un agente que está en la antesala (`esperando_gerencia`), su primer plano muestra la pregunta que le trae, con los mismos botones de aprobar o responder de la bandeja. Al cliente ese agente le muestra su tabla normal.

## Comprobación de la parte B

- Escritorio a 1280 y 1440 px: oficina y panel lateral a la vez, sin scroll horizontal.
- Móvil a 390 px: oficina a todo el ancho, el primer plano como hoja y «‹ Oficina» que vuelve al mismo punto.
- Con teclado: llegar a una casilla, abrir su panel y volver.
- Visitante, cliente, Liz y gerencia ven cada uno sus casillas y nada más.

# Cómo ha quedado (notas de implementación)

- **Escritorio (más de 760 px):** la oficina a la izquierda y la columna del primer plano a la derecha.
  - La columna se queda fija al bajar por la página.
  - Si hay sitio (columna de 588 px o más), el retrato va a la izquierda y la tabla a la derecha, como en el boceto (`game/design/boceto-primer-plano.png`).
  - Al abrir una casilla, el panel ocupa el lado de la tabla y el retrato se estrecha.
- **Móvil:** el primer plano es una hoja a pantalla completa; «‹ Oficina» vuelve al mismo punto del scroll.
- **Retratos:** se generan recortando la propia oficina (cada personaje en su mesa) en `assets/game/portraits/{slug}.png`, de 96×72. Si Jessica dibuja los suyos, basta con sustituir esos PNG.
- **Antesala:**
  - Jessica ve lo que trae cada agente, con «Aprobar tal cual» y «Descartar» (lo mismo que en Chat) y «Revisar en la bandeja» para editar antes de aprobar.
  - Endpoints, solo para gerencia: `GET /office/waiting` y `POST /office/approvals/{id}`.
- **Saludos:** se editan en Ajustes → Oficina. `{nombre}` es el nombre de quien entra. La recepcionista tiene un saludo aparte para visitantes y Jessica uno para ella misma.
- **Pestañas:** solo aparecen con más de 9 casillas. Hoy ningún personaje llega a 9 casillas, así que no se ven.
