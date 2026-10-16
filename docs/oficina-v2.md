# Oficina v2 · Rediseño de la oficina pixel art

Encargo para rehacer la escena de la oficina (`game/`). **Solo cambia el aspecto y la planta**: los paneles, la API, los estados y el portal se quedan como están.

Antes de escribir código, propón el plan (archivos que tocas, orden y riesgos) y espera mi OK, como en el resto de bloques.

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

## Comprobación antes de cerrar

- Abre la oficina en navegador, en escritorio y en móvil (390 px), y compárala con `oficina-v2-referencia.png`.
- Cada sala y cada personaje se puede pulsar y abre el mismo panel que hoy.
- La vista de visitante sigue oscureciendo todo menos la recepción.
- Fuerza un `esperando_gerencia` (con el seeder) y comprueba que el agente va al banco y vuelve a su mesa al resolverse.
- Commits pequeños, uno por paso.
