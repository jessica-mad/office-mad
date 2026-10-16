// Oficina v2 (docs/oficina-v2.md): builds the office from the pixel-agents kit in public/kit/,
// recoloured to the MAD palette. Port of game/design/prototipo_oficina_v2.py (the source of the
// positions). Writes:
//   public/tileset.png            floor tiles (tinted) + the wall base tile, 16×16
//   public/walls.png              the kit's wall sheet tinted: 16 pieces of 16×32 (index = N1|E2|S4|W8)
//   public/props/*.png            furniture that needs its own colour (mustard counter, MAD sign)
//   public/characters/{slug}.png  112×96 sheets (7 cols of 16 px × 3 rows of 32 px: down, up, right)
//   public/office.json            Tiled map: floor, walls, props (objects), rooms, spots
//
// A character sheet Jessica drew herself is never overwritten: only files this script wrote
// (recorded in characters/.generated.json) or the old 128×192 format are replaced.
import { PNG } from 'pngjs';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const OUT = path.join(ROOT, 'public');
const KIT = path.join(OUT, 'kit');
const T = 16;

/* ───────────────────────── map ───────────────────────── */
// W wall · p parquet · g open plan · c cream tiles · h corridor · d reception carpet · e doormat
const MAP = [
  'WWWWWWWWWWWWWWWWWW',
  'WWWWWWWWWWWWWWWWWW',
  'WpppppppWppppppppW',
  'WpppppppWppppppppW',
  'WpppppppWppppppppW',
  'WpppppppWppppppppW',
  'WpppppppWppppppppW',
  'WWWppWWWWWWWppWWWW',
  'WggggggggggggggggW',
  'WggggggggggggggggW',
  'WggggggggggggggggW',
  'WggggggggggggggggW',
  'WggggggggggggggggW',
  'WggggggggggggggggW',
  'WggggggggggggggggW',
  'WWWWWWhhWWWWWWWWWW',
  'WccccWhhWccccccccW',
  'WccccWhhWccccccccW',
  'WcccchhhWccccccccW',
  'WccccWhhWccccccccW',
  'WccccWhhWccccccccW',
  'WWWWWWddWWWWddWWWW',
  'WddddddddddddddddW',
  'WddddddddddddddddW',
  'WddddddddddddddddW',
  'WddddddddddddddddW',
  'WddddddddddddddddW',
  'WWWWWWWWeeWWWWWWWW',
];
const H = MAP.length, W = MAP[0].length;
if (!MAP.every((r) => r.length === W)) throw new Error('MAP rows differ in length');

/* ───────────────────────── png helpers ───────────────────────── */
const read = (file) => PNG.sync.read(fs.readFileSync(file)); // always RGBA
const blank = (w, h) => { const p = new PNG({ width: w, height: h }); p.data.fill(0); return p; };
const save = (png, file) => { fs.mkdirSync(path.dirname(file), { recursive: true }); fs.writeFileSync(file, PNG.sync.write(png)); };
const hexc = (h) => [1, 3, 5].map((i) => parseInt(h.slice(i, i + 2), 16));
const each = (png, fn) => { for (let i = 0; i < png.data.length; i += 4) if (png.data[i + 3]) fn(png.data, i); return png; };
const copy = (src, sx, sy, w, h, dst, dx, dy) => {
  for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
    const s = ((sy + y) * src.width + sx + x) * 4, d = ((dy + y) * dst.width + dx + x) * 4;
    for (let k = 0; k < 4; k++) dst.data[d + k] = src.data[s + k];
  }
};
const blend = (png, x, y, [r, g, b], a) => {
  const i = (y * png.width + x) * 4, k = a / 255;
  png.data[i] = Math.trunc(r * k + png.data[i] * (1 - k));
  png.data[i + 1] = Math.trunc(g * k + png.data[i + 1] * (1 - k));
  png.data[i + 2] = Math.trunc(b * k + png.data[i + 2] * (1 - k));
};

/** Colorize: grey luminance → gradient between two colours (keeps alpha). */
const tint = (png, dark, light) => {
  const d = hexc(dark), l = hexc(light);
  return each(png, (px, i) => {
    const v = (0.299 * px[i] + 0.587 * px[i + 1] + 0.114 * px[i + 2]) / 255;
    for (let k = 0; k < 3; k++) px[i + k] = Math.trunc(d[k] + (l[k] - d[k]) * v);
  });
};

// colorsys (Python) HLS, so the clothes shift exactly as in the prototype.
const rgbToHls = (r, g, b) => {
  const mx = Math.max(r, g, b), mn = Math.min(r, g, b), l = (mx + mn) / 2;
  if (mx === mn) return [0, l, 0];
  const s = l <= 0.5 ? (mx - mn) / (mx + mn) : (mx - mn) / (2 - mx - mn);
  const rc = (mx - r) / (mx - mn), gc = (mx - g) / (mx - mn), bc = (mx - b) / (mx - mn);
  let h = r === mx ? bc - gc : g === mx ? 2 + rc - bc : 4 + gc - rc;
  h = ((h / 6) % 1 + 1) % 1;
  return [h, l, s];
};
const v_ = (m1, m2, h) => {
  h = ((h % 1) + 1) % 1;
  if (h < 1 / 6) return m1 + (m2 - m1) * h * 6;
  if (h < 0.5) return m2;
  if (h < 2 / 3) return m1 + (m2 - m1) * (2 / 3 - h) * 6;
  return m1;
};
const hlsToRgb = (h, l, s) => {
  if (!s) return [l, l, l];
  const m2 = l <= 0.5 ? l * (1 + s) : l + s - l * s, m1 = 2 * l - m2;
  return [v_(m1, m2, h + 1 / 3), v_(m1, m2, h), v_(m1, m2, h - 1 / 3)];
};
/** Shifts the hue of the clothes only: greys, skin and brown hair (reds/oranges) stay. */
const hue = (png, deg) => each(png, (px, i) => {
  const [h, l, s] = rgbToHls(px[i] / 255, px[i + 1] / 255, px[i + 2] / 255);
  if (s < 0.15 || h < 0.14 || h > 0.95) return;
  const rgb = hlsToRgb((h + deg / 360) % 1, l, s);
  for (let k = 0; k < 3; k++) px[i + k] = Math.trunc(rgb[k] * 255);
});

/* ───────────────────────── tiles ───────────────────────── */
const floor = (i) => read(path.join(KIT, 'floors', `floor_${i}.png`));
const FLOORS = {
  p: tint(floor(6), '#7A4E26', '#D9A867'),
  c: tint(floor(4), '#CFC6A8', '#FBF5E4'),
  g: tint(floor(3), '#B9B993', '#F2F2DC'),
  h: tint(floor(1), '#C9B98F', '#EFE3C4'),
  d: tint(floor(0), '#151515', '#2B2B2B'),
  e: tint(floor(0), '#D99419', '#FBB62F'),
};
// MAD dots on the reception carpet.
for (const [x, y] of [[3, 3], [11, 3], [7, 11], [15, 11]]) blend(FLOORS.d, x, y, [251, 182, 47], 150);
const TILE_ORDER = ['p', 'c', 'g', 'h', 'd', 'e', 'W'];
const TILE_NAMES = { p: 'parquet', c: 'baldosa', g: 'planta', h: 'pasillo', d: 'moqueta', e: 'felpudo', W: 'muro' };
const gid = (ch) => TILE_ORDER.indexOf(ch) + 1;

function writeTileset() {
  const out = blank(TILE_ORDER.length * T, T);
  TILE_ORDER.forEach((ch, i) => {
    if (ch === 'W') { for (let y = 0; y < T; y++) for (let x = 0; x < T; x++) { const d = (y * out.width + i * T + x) * 4; out.data.set([11, 11, 11, 255], d); } }
    else copy(FLOORS[ch], 0, 0, T, T, out, i * T, 0);
  });
  save(out, path.join(OUT, 'tileset.png'));
  save(tint(read(path.join(KIT, 'walls', 'wall_0.png')), '#1C1C1C', '#E8E2C8'), path.join(OUT, 'walls.png'));
}

/* ───────────────────────── furniture ───────────────────────── */
// From the kit manifests: footprint in tiles, rows at the back you can stand on (bg),
// whether it stands on the floor and blocks the way (solid).
const F = {
  LARGE_PAINTING: ['LARGE_PAINTING/LARGE_PAINTING', 2, 2, 0, false],
  SMALL_PAINTING: ['SMALL_PAINTING/SMALL_PAINTING', 1, 2, 0, false],
  SMALL_PAINTING_2: ['SMALL_PAINTING_2/SMALL_PAINTING_2', 1, 2, 0, false],
  CLOCK: ['CLOCK/CLOCK', 1, 2, 0, false],
  HANGING_PLANT: ['HANGING_PLANT/HANGING_PLANT', 1, 2, 0, false],
  WHITEBOARD: ['WHITEBOARD/WHITEBOARD', 2, 2, 0, false],
  DOUBLE_BOOKSHELF: ['DOUBLE_BOOKSHELF/DOUBLE_BOOKSHELF', 2, 2, 0, false],
  DESK_FRONT: ['DESK/DESK_FRONT', 3, 2, 1, true],
  TABLE_FRONT: ['TABLE_FRONT/TABLE_FRONT', 3, 4, 1, true],
  SMALL_TABLE_FRONT: ['SMALL_TABLE/SMALL_TABLE_FRONT', 2, 2, 1, true],
  PC_SIDE: ['PC/PC_SIDE', 1, 2, 1, false],
  PC_FRONT_ON_1: ['PC/PC_FRONT_ON_1', 1, 2, 1, false],
  COFFEE: ['COFFEE/COFFEE', 1, 1, 0, false],
  PLANT: ['PLANT/PLANT', 1, 2, 1, true],
  PLANT_2: ['PLANT_2/PLANT_2', 1, 2, 1, true],
  CACTUS: ['CACTUS/CACTUS', 1, 2, 1, true],
  BIN: ['BIN/BIN', 1, 1, 0, true],
  SOFA_FRONT: ['SOFA/SOFA_FRONT', 2, 1, 0, true],
  SOFA_SIDE: ['SOFA/SOFA_SIDE', 1, 2, 0, true],
  WOODEN_BENCH: ['WOODEN_BENCH/WOODEN_BENCH', 1, 1, 0, true],
  CUSHIONED_CHAIR_SIDE: ['CUSHIONED_CHAIR/CUSHIONED_CHAIR_SIDE', 1, 1, 0, true],
  WOODEN_CHAIR_SIDE: ['WOODEN_CHAIR/WOODEN_CHAIR_SIDE', 1, 2, 1, true],
};
const PC_FRAMES = ['kit/furniture/PC/PC_FRONT_ON_1.png', 'kit/furniture/PC/PC_FRONT_ON_2.png', 'kit/furniture/PC/PC_FRONT_ON_3.png'];

const PROPS = []; // { id, image, col, row, w, h, fw, fh, bg, solid, flip, depth, frames }
const put = (id, col, row, o = {}) => {
  const [file, fw, fh, bg, solid] = F[id];
  const image = o.image || `kit/furniture/${file}.png`;
  const png = read(path.join(OUT, image));
  PROPS.push({ id, image, col, row, w: png.width, h: png.height, fw, fh, bg, solid, flip: !!o.flip, depth: o.z ?? (row + fh) * T, frames: o.frames || null });
};
const ON_DESK = (row) => (row + 2) * T + 1; // things on a desk are drawn over it

function writeProps() {
  // Reception counter: two desks in mustard.
  save(tint(read(path.join(KIT, 'furniture', 'DESK', 'DESK_FRONT.png')), '#7A5410', '#FFD27A'), path.join(OUT, 'props', 'DESK_FRONT_mostaza.png'));
  // MAD sign over the reception wall (same geometry as the prototype, until the real logo).
  const sign = blank(61, 23);
  const fill = (x0, y0, x1, y1, c) => { for (let y = y0; y <= y1; y++) for (let x = x0; x <= x1; x++) sign.data.set([...c, 255], (y * sign.width + x) * 4); };
  fill(0, 0, 60, 22, [11, 11, 11]); fill(1, 1, 59, 21, [251, 182, 47]);
  for (const x0 of [10, 24, 38]) fill(x0, 5, x0 + 8, 17, [11, 11, 11]);
  save(sign, path.join(OUT, 'props', 'MAD_SIGN.png'));
}

function furnish() {
  const mustard = { image: 'props/DESK_FRONT_mostaza.png' };
  // Estudio (Liz)
  put('LARGE_PAINTING', 2, 0); put('CLOCK', 5, 0); put('HANGING_PLANT', 7, 0);
  put('DESK_FRONT', 2, 4); put('PC_SIDE', 2, 4, { z: ON_DESK(4) });
  put('PLANT', 6, 5); put('CACTUS', 1, 6);
  // Gerencia (Jessica)
  put('LARGE_PAINTING', 10, 0); put('SMALL_PAINTING_2', 14, 0); put('HANGING_PLANT', 16, 0);
  put('DESK_FRONT', 11, 4); put('PC_SIDE', 13, 4, { z: ON_DESK(4), flip: true }); put('COFFEE', 11, 4, { z: ON_DESK(4) });
  put('SOFA_FRONT', 14, 6); put('PLANT_2', 9, 5);
  // Planta abierta + antesala/café. The bench is one tile to the right of the prototype
  // (x13-16) so the door of gerencia (x12-13) stays free; the plant goes down to make room.
  put('WHITEBOARD', 5, 6); put('CLOCK', 9, 6);
  put('DESK_FRONT', 1, 10); put('PC_SIDE', 1, 10, { z: ON_DESK(10) });
  put('DESK_FRONT', 5, 10); put('PC_FRONT_ON_1', 7, 10, { z: ON_DESK(10), frames: PC_FRAMES });
  put('DESK_FRONT', 9, 10); put('PC_SIDE', 11, 10, { z: ON_DESK(10), flip: true });
  for (const x of [13, 14, 15, 16]) put('WOODEN_BENCH', x, 8);
  put('SMALL_TABLE_FRONT', 13, 12); put('COFFEE', 13, 12, { z: (14) * T + 1 }); put('COFFEE', 14, 12, { z: (14) * T + 1 });
  put('CUSHIONED_CHAIR_SIDE', 12, 13); put('CUSHIONED_CHAIR_SIDE', 15, 13, { flip: true });
  put('PLANT', 16, 11); put('PLANT_2', 1, 13); put('BIN', 4, 14);
  // Archivo
  put('DOUBLE_BOOKSHELF', 1, 14); put('DOUBLE_BOOKSHELF', 3, 14);
  put('DESK_FRONT', 1, 18); put('BIN', 4, 20);
  // Pasillo
  put('PLANT', 7, 16);
  // Reuniones
  put('LARGE_PAINTING', 11, 14); put('HANGING_PLANT', 16, 14);
  put('TABLE_FRONT', 11, 16);
  for (const r of [17, 18]) { put('WOODEN_CHAIR_SIDE', 10, r); put('WOODEN_CHAIR_SIDE', 14, r, { flip: true }); }
  put('PLANT_2', 9, 19);
  // Recepción
  put('CLOCK', 3, 20); put('SMALL_PAINTING', 15, 20);
  put('DESK_FRONT', 6, 23, mustard); put('DESK_FRONT', 9, 23, mustard);
  put('SOFA_SIDE', 1, 24); put('SOFA_SIDE', 16, 24, { flip: true });
  put('PLANT', 1, 22); put('PLANT_2', 16, 22);
  // The sign is drawn over everything around it, as in the prototype.
  PROPS.push({ id: 'MAD_SIGN', image: 'props/MAD_SIGN.png', col: 8, row: 20, w: 61, h: 23, fw: 4, fh: 2, bg: 0, solid: false, flip: false, depth: 22 * T, frames: null, px: 8 * T + 2, py: 20 * T + 4 });
}

/* ───────────────────────── rooms & spots ───────────────────────── */
// Open-plan rooms are zones without walls: only their rectangles (clicks, labels, visitor veil).
const ROOMS = [
  ['estudio', 'Estudio creativo', 1, 0, 7, 7],
  ['gerencia', 'Despacho de gerencia', 9, 0, 8, 7],
  ['proyectos', 'Sala de proyectos', 1, 8, 4, 7],
  ['campanas', 'Sala de campañas', 5, 8, 4, 7],
  ['administracion', 'Administración', 9, 8, 3, 7],
  ['archivo', 'Archivo', 1, 15, 4, 6],
  ['reuniones', 'Sala de reuniones', 9, 15, 8, 6],
  ['recepcion', 'Recepción', 1, 21, 16, 6],
];
const SPOTS = [
  ['desk_liz', 3, 4, 'down'], ['desk_jessica', 12, 4, 'down'],
  ['desk_coordinadora', 2, 10, 'down'], ['desk_analista', 6, 10, 'down'], ['desk_contable', 10, 10, 'down'],
  ['desk_archivera', 2, 18, 'down'], ['desk_secretaria', 15, 18, 'left'], ['desk_recepcionista', 8, 23, 'down'],
  ['gerencia_wait_1', 13, 9, 'up'], ['gerencia_wait_2', 14, 9, 'up'], ['gerencia_wait_3', 15, 9, 'up'], ['gerencia_wait_4', 16, 9, 'up'],
  ['entrance', 8, 26, 'up'],
  // Two free floor tiles per room, never in the row right above a wall (hidden by it in 3/4 view).
  ['wander_estudio_1', 5, 3, 'down'], ['wander_estudio_2', 1, 5, 'right'],
  ['wander_gerencia_1', 10, 3, 'down'], ['wander_gerencia_2', 15, 3, 'down'],
  ['wander_proyectos_1', 2, 8, 'down'], ['wander_proyectos_2', 3, 13, 'down'],
  ['wander_campanas_1', 8, 8, 'down'], ['wander_campanas_2', 6, 13, 'down'],
  ['wander_administracion_1', 11, 8, 'down'], ['wander_administracion_2', 10, 13, 'down'],
  ['wander_archivo_1', 3, 16, 'down'], ['wander_archivo_2', 4, 17, 'left'],
  ['wander_reuniones_1', 16, 16, 'down'], ['wander_reuniones_2', 9, 17, 'right'],
  ['wander_recepcion_1', 4, 25, 'down'], ['wander_recepcion_2', 13, 25, 'down'],
  ['wander_pasillo_1', 6, 17, 'down'], ['wander_pasillo_2', 6, 19, 'up'],
];

function writeMap() {
  const floorData = [], wallData = [];
  for (let y = 0; y < H; y++) for (let x = 0; x < W; x++) {
    const ch = MAP[y][x];
    floorData.push(ch === 'W' ? 0 : gid(ch));
    wallData.push(ch === 'W' ? gid('W') : 0);
  }
  // Every spot must be on free floor: the scene walks there.
  const solid = new Set();
  for (const p of PROPS) if (p.solid) for (let y = p.row + p.bg; y < p.row + p.fh; y++) for (let x = p.col; x < p.col + p.fw; x++) solid.add(`${x},${y}`);
  for (const [name, x, y] of SPOTS) if (MAP[y][x] === 'W' || solid.has(`${x},${y}`)) throw new Error(`spot ${name} (${x},${y}) is not free`);

  let oid = 1;
  const prop = (k, v) => ({ name: k, type: typeof v === 'boolean' ? 'bool' : typeof v === 'number' ? 'int' : 'string', value: v });
  const tileLayer = (id, name, data) => ({ id, name, type: 'tilelayer', width: W, height: H, x: 0, y: 0, opacity: 1, visible: true, data });
  const props = PROPS.map((p) => {
    const bottom = (p.row + p.fh) * T;
    return {
      id: oid++, name: p.id, type: 'prop', x: p.px ?? p.col * T, y: p.py ?? bottom - p.h, width: p.w, height: p.h, rotation: 0, visible: true,
      properties: [prop('image', p.image), prop('col', p.col), prop('row', p.row), prop('fw', p.fw), prop('fh', p.fh), prop('bg', p.bg),
        prop('solid', p.solid), prop('flip', p.flip), prop('depth', p.depth), ...(p.frames ? [prop('frames', p.frames.join(','))] : [])],
    };
  });
  const rooms = ROOMS.map(([slug, label, x, y, w, h]) => ({ id: oid++, name: slug, type: 'room', x: x * T, y: y * T, width: w * T, height: h * T, rotation: 0, visible: true, properties: [prop('label', label)] }));
  const spots = SPOTS.map(([name, x, y, face]) => ({ id: oid++, name, type: 'spot', point: true, x: x * T + T / 2, y: y * T + T / 2, width: 0, height: 0, rotation: 0, visible: true, properties: [prop('face', face)] }));
  const map = {
    type: 'map', version: '1.10', tiledversion: '1.11.0', orientation: 'orthogonal', renderorder: 'right-down',
    width: W, height: H, tilewidth: T, tileheight: T, infinite: false, nextlayerid: 6, nextobjectid: oid,
    layers: [
      tileLayer(1, 'floor', floorData), tileLayer(2, 'walls', wallData),
      { id: 3, name: 'props', type: 'objectgroup', draworder: 'index', opacity: 1, visible: true, x: 0, y: 0, objects: props },
      { id: 4, name: 'rooms', type: 'objectgroup', draworder: 'topdown', opacity: 1, visible: true, x: 0, y: 0, objects: rooms },
      { id: 5, name: 'spots', type: 'objectgroup', draworder: 'topdown', opacity: 1, visible: true, x: 0, y: 0, objects: spots },
    ],
    tilesets: [{
      firstgid: 1, name: 'mad-office', image: 'tileset.png', imagewidth: TILE_ORDER.length * T, imageheight: T,
      tilewidth: T, tileheight: T, tilecount: TILE_ORDER.length, columns: TILE_ORDER.length, margin: 0, spacing: 0,
      tiles: TILE_ORDER.map((ch, i) => ({ id: i, properties: [prop('name', TILE_NAMES[ch]), prop('collides', ch === 'W')] })),
    }],
  };
  fs.writeFileSync(path.join(OUT, 'office.json'), JSON.stringify(map));
}

/* ───────────────────────── characters ───────────────────────── */
// Provisional cast from the kit until Jessica draws hers on the same template.
const CAST = {
  liz: [1, 0], jessica: [2, 0], coordinadora: [0, 0], analista: [4, 0], contable: [5, 0],
  archivera: [3, 120], secretaria: [1, 210], recepcionista: [5, 170],
};

function writeCharacters() {
  const dir = path.join(OUT, 'characters');
  const recordFile = path.join(dir, '.generated.json');
  const record = fs.existsSync(recordFile) ? JSON.parse(fs.readFileSync(recordFile, 'utf8')) : {};
  const sha = (buf) => crypto.createHash('sha256').update(buf).digest('hex');
  const kept = [];
  for (const [slug, [n, shift]] of Object.entries(CAST)) {
    const file = path.join(dir, `${slug}.png`);
    if (fs.existsSync(file)) {
      const buf = fs.readFileSync(file);
      const { width, height } = PNG.sync.read(buf);
      const oldFormat = width !== 112 || height !== 96;
      if (!oldFormat && record[slug] !== sha(buf)) { kept.push(slug); continue; } // drawn by Jessica
    }
    const png = read(path.join(KIT, 'characters', `char_${n}.png`));
    if (shift) hue(png, shift);
    const buf = PNG.sync.write(png);
    fs.mkdirSync(dir, { recursive: true });
    fs.writeFileSync(file, buf);
    record[slug] = sha(buf);
  }
  fs.writeFileSync(recordFile, JSON.stringify(record, null, 2) + '\n');
  return kept;
}

writeTileset();
writeProps();
furnish();
writeMap();
const kept = writeCharacters();
console.log(`map ${W}×${H} · ${PROPS.length} props · ${Object.keys(CAST).length} characters${kept.length ? ' (kept: ' + kept.join(', ') + ')' : ''}`);
