// Generates the provisional pixel art for the office:
//   public/tileset.png          16×16 tiles (8 columns)
//   public/characters/{slug}.png 128×192 spritesheets (4 cols × 6 rows of 32×32)
//   public/office.json          Tiled map (orthogonal, JSON) — editable in Tiled
//
// Spritesheet rows: 0 walk down · 1 walk left · 2 walk right · 3 walk up (4 frames each)
//                   4 idle  (down, left, right, up) · 5 sitting (down, left, right, up)
// Anchor point: (16, 30) — centre of the feet.
//
// Everything uses the MAD palette plus a few neutral shades. All characters are original.
import { PNG } from 'pngjs';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const OUT = path.join(ROOT, 'public');

/* ───────────────────────── palette ───────────────────────── */
const P = {
  y: '#FBB62F', y2: '#D99419', y3: '#FFD27A',
  k: '#0B0B0B', k2: '#2B2B2B', k3: '#4A4A4A',
  c: '#FDF8EA', c2: '#EFE6CC',
  g: '#F2F2DC', g2: '#DADAB8', g3: '#C2C29C',
  wood: '#B98149', wood2: '#8F5D2E',
  leaf: '#6F8F3A', leaf2: '#4D6A26',
  grey: '#9C9C94', grey2: '#76766E',
  screen: '#24312B', screen2: '#3E5C47',
  red: '#B5482B',
};
const hex = (h) => [parseInt(h.slice(1, 3), 16), parseInt(h.slice(3, 5), 16), parseInt(h.slice(5, 7), 16), 255];

class Canvas {
  constructor(w, h) { this.w = w; this.h = h; this.png = new PNG({ width: w, height: h }); this.png.data.fill(0); }
  px(x, y, color) {
    if (x < 0 || y < 0 || x >= this.w || y >= this.h || !color) return;
    const [r, g, b, a] = hex(color); const i = (y * this.w + x) * 4;
    this.png.data[i] = r; this.png.data[i + 1] = g; this.png.data[i + 2] = b; this.png.data[i + 3] = a;
  }
  get(x, y) { const i = (y * this.w + x) * 4; return this.png.data[i + 3]; }
  rect(x, y, w, h, color) { for (let j = 0; j < h; j++) for (let i = 0; i < w; i++) this.px(x + i, y + j, color); }
  save(file) { fs.mkdirSync(path.dirname(file), { recursive: true }); fs.writeFileSync(file, PNG.sync.write(this.png)); }
}

/* ───────────────────────── tiles ───────────────────────── */
// Each tile draws into a 16×16 cell at (ox, oy). `collides` feeds the pathfinding grid.
const TILES = [];
const tile = (name, collides, draw) => TILES.push({ name, collides, draw });

const dotsFloor = (c, ox, oy, base, dot) => {
  c.rect(ox, oy, 16, 16, base);
  for (let y = 2; y < 16; y += 6) for (let x = 2; x < 16; x += 6) c.px(ox + x + ((y / 6) % 2 ? 3 : 0), oy + y, dot);
};

tile('floor', false, (c, x, y) => { c.rect(x, y, 16, 16, P.c); c.px(x + 15, y + 15, P.c2); c.px(x, y + 15, P.c2); });
tile('floor_green', false, (c, x, y) => { c.rect(x, y, 16, 16, P.g); for (let i = 0; i < 16; i += 4) c.px(x + i, y + 15, P.g2); });
tile('rug_yellow', false, (c, x, y) => dotsFloor(c, x, y, P.y, P.y2));
tile('rug_black', false, (c, x, y) => dotsFloor(c, x, y, P.k2, P.y2));
tile('wall_top', true, (c, x, y) => { c.rect(x, y, 16, 16, P.k); c.rect(x, y + 14, 16, 2, P.k2); });
tile('wall_face', true, (c, x, y) => { c.rect(x, y, 16, 16, P.g2); c.rect(x, y + 12, 16, 4, P.g3); c.rect(x, y, 16, 2, P.y); });
tile('wall_window', true, (c, x, y) => {
  c.rect(x, y, 16, 16, P.g2); c.rect(x, y, 16, 2, P.y); c.rect(x + 2, y + 3, 12, 8, P.k); c.rect(x + 3, y + 4, 10, 6, '#BFD8D2');
  c.rect(x + 8, y + 4, 1, 6, P.k); c.px(x + 4, y + 5, P.c); c.rect(x, y + 12, 16, 4, P.g3);
});
tile('wall_board', true, (c, x, y) => {
  c.rect(x, y, 16, 16, P.g2); c.rect(x, y, 16, 2, P.y); c.rect(x + 1, y + 3, 14, 9, P.k); c.rect(x + 2, y + 4, 12, 7, P.c);
  c.rect(x + 3, y + 5, 3, 2, P.y); c.rect(x + 7, y + 5, 5, 1, P.k3); c.rect(x + 7, y + 7, 4, 1, P.k3); c.rect(x + 3, y + 8, 3, 2, P.red); c.rect(x, y + 12, 16, 4, P.g3);
});
tile('desk_l', true, (c, x, y) => { c.rect(x, y + 2, 16, 11, P.wood); c.rect(x, y + 2, 16, 1, P.y3); c.rect(x, y + 13, 16, 2, P.wood2); c.rect(x + 1, y + 15, 2, 1, P.k); });
tile('desk_r', true, (c, x, y) => { c.rect(x, y + 2, 16, 11, P.wood); c.rect(x, y + 2, 16, 1, P.y3); c.rect(x, y + 13, 16, 2, P.wood2); c.rect(x + 13, y + 15, 2, 1, P.k); });
tile('desk_pc', true, (c, x, y) => {
  c.rect(x, y + 2, 16, 11, P.wood); c.rect(x, y + 2, 16, 1, P.y3); c.rect(x, y + 13, 16, 2, P.wood2);
  c.rect(x + 3, y, 10, 8, P.k); c.rect(x + 4, y + 1, 8, 6, P.screen); c.rect(x + 5, y + 2, 4, 1, P.screen2); c.rect(x + 5, y + 4, 6, 1, P.screen2);
  c.rect(x + 7, y + 8, 2, 2, P.k2); c.rect(x + 4, y + 10, 8, 2, P.k3);
});
tile('desk_papers', true, (c, x, y) => {
  c.rect(x, y + 2, 16, 11, P.wood); c.rect(x, y + 2, 16, 1, P.y3); c.rect(x, y + 13, 16, 2, P.wood2);
  c.rect(x + 2, y + 4, 6, 7, P.c); c.rect(x + 3, y + 5, 4, 1, P.k3); c.rect(x + 3, y + 7, 3, 1, P.k3); c.rect(x + 9, y + 5, 4, 5, P.y); c.px(x + 12, y + 3, P.k);
});
tile('chair', false, (c, x, y) => { c.rect(x + 4, y + 3, 8, 9, P.k); c.rect(x + 5, y + 4, 6, 7, P.k3); c.rect(x + 7, y + 12, 2, 2, P.k2); c.rect(x + 5, y + 14, 6, 1, P.k2); });
tile('plant', true, (c, x, y) => {
  c.rect(x + 4, y + 10, 8, 5, P.y2); c.rect(x + 4, y + 10, 8, 1, P.y); c.rect(x + 5, y + 15, 6, 1, P.k2);
  const leaves = [[7, 1], [5, 3], [9, 3], [3, 5], [7, 5], [11, 5], [5, 7], [9, 7], [7, 8]];
  for (const [lx, ly] of leaves) { c.rect(x + lx, y + ly, 3, 2, P.leaf); c.px(x + lx, y + ly + 1, P.leaf2); }
});
tile('shelf', true, (c, x, y) => {
  c.rect(x + 1, y, 14, 16, P.wood2); c.rect(x + 2, y + 1, 12, 14, P.wood);
  for (const sy of [5, 10]) c.rect(x + 2, y + sy, 12, 1, P.wood2);
  const books = [P.y, P.k, P.c, P.red, P.g3, P.y2];
  for (let i = 0; i < 6; i++) { c.rect(x + 3 + i * 2, y + 2, 1, 3, books[i]); c.rect(x + 3 + i * 2, y + 7, 1, 3, books[(i + 2) % 6]); c.rect(x + 3 + i * 2, y + 12, 1, 3, books[(i + 4) % 6]); }
});
tile('cabinet', true, (c, x, y) => {
  c.rect(x + 2, y + 1, 12, 15, P.grey2); c.rect(x + 3, y + 2, 10, 13, P.grey);
  for (const sy of [2, 7, 11]) { c.rect(x + 3, y + sy + 3, 10, 1, P.grey2); c.rect(x + 7, y + sy + 1, 2, 1, P.k); }
});
tile('boxes', true, (c, x, y) => {
  c.rect(x + 1, y + 7, 9, 8, P.wood); c.rect(x + 1, y + 7, 9, 1, P.y3); c.rect(x + 4, y + 9, 3, 1, P.wood2);
  c.rect(x + 7, y + 2, 8, 7, P.c2); c.rect(x + 7, y + 2, 8, 1, P.c); c.rect(x + 9, y + 4, 4, 2, P.y);
});
tile('table_tl', true, (c, x, y) => { c.rect(x + 2, y + 4, 14, 12, P.wood); c.rect(x + 2, y + 4, 14, 1, P.y3); c.rect(x + 2, y + 4, 1, 12, P.wood2); });
tile('table_tr', true, (c, x, y) => { c.rect(x, y + 4, 14, 12, P.wood); c.rect(x, y + 4, 14, 1, P.y3); c.rect(x + 13, y + 4, 1, 12, P.wood2); c.rect(x + 3, y + 7, 5, 4, P.c); });
tile('table_bl', true, (c, x, y) => { c.rect(x + 2, y, 14, 11, P.wood); c.rect(x + 2, y, 1, 11, P.wood2); c.rect(x + 2, y + 11, 14, 2, P.wood2); c.rect(x + 8, y + 3, 5, 4, P.c); });
tile('table_br', true, (c, x, y) => { c.rect(x, y, 14, 11, P.wood); c.rect(x + 13, y, 1, 11, P.wood2); c.rect(x, y + 11, 14, 2, P.wood2); c.rect(x + 3, y + 2, 3, 3, P.y); });
tile('counter', true, (c, x, y) => { c.rect(x, y + 3, 16, 12, P.k); c.rect(x, y + 3, 16, 3, P.y); for (let i = 1; i < 16; i += 4) c.px(x + i, y + 9, P.y2); });
tile('sofa_l', true, (c, x, y) => { c.rect(x + 1, y + 4, 15, 10, P.y2); c.rect(x + 1, y + 4, 15, 4, P.y); c.rect(x + 1, y + 4, 3, 10, P.y2); c.rect(x + 1, y + 14, 15, 1, P.k2); });
tile('sofa_r', true, (c, x, y) => { c.rect(x, y + 4, 15, 10, P.y2); c.rect(x, y + 4, 15, 4, P.y); c.rect(x + 12, y + 4, 3, 10, P.y2); c.rect(x, y + 14, 15, 1, P.k2); });
tile('printer', true, (c, x, y) => { c.rect(x + 2, y + 5, 12, 9, P.c2); c.rect(x + 2, y + 5, 12, 2, P.c); c.rect(x + 4, y + 2, 8, 4, P.c); c.rect(x + 4, y + 10, 8, 1, P.k); c.px(x + 12, y + 8, P.y); });
tile('screens', true, (c, x, y) => {
  c.rect(x, y + 2, 16, 11, P.wood); c.rect(x, y + 13, 16, 2, P.wood2);
  c.rect(x + 1, y, 7, 7, P.k); c.rect(x + 2, y + 1, 5, 5, P.screen); c.rect(x + 2, y + 4, 1, 2, P.y); c.rect(x + 4, y + 3, 1, 3, P.y); c.rect(x + 6, y + 2, 1, 4, P.y);
  c.rect(x + 8, y, 7, 7, P.k); c.rect(x + 9, y + 1, 5, 5, P.screen); c.rect(x + 10, y + 2, 3, 3, P.screen2);
});
tile('easel', true, (c, x, y) => {
  c.rect(x + 3, y + 1, 10, 10, P.c); c.rect(x + 3, y + 1, 10, 1, P.k); c.rect(x + 5, y + 3, 4, 4, P.y); c.rect(x + 8, y + 5, 3, 3, P.red);
  c.px(x + 4, y + 12, P.wood2); c.px(x + 4, y + 13, P.wood2); c.px(x + 11, y + 12, P.wood2); c.px(x + 11, y + 13, P.wood2); c.rect(x + 7, y + 11, 2, 4, P.wood2);
});
tile('mat', false, (c, x, y) => { c.rect(x, y + 1, 16, 14, P.k2); for (let i = 1; i < 16; i += 2) c.rect(x + i, y + 2, 1, 12, P.k3); });
tile('coffee', true, (c, x, y) => { c.rect(x + 2, y + 6, 12, 9, P.wood2); c.rect(x + 3, y + 1, 7, 7, P.k); c.rect(x + 4, y + 2, 5, 3, P.k3); c.rect(x + 10, y + 4, 3, 3, P.c); c.px(x + 11, y + 3, P.c2); });

const tileIndex = Object.fromEntries(TILES.map((t, i) => [t.name, i + 1])); // Tiled gids start at 1

function writeTileset() {
  const cols = 8; const rows = Math.ceil(TILES.length / cols);
  const c = new Canvas(cols * 16, rows * 16);
  TILES.forEach((t, i) => t.draw(c, (i % cols) * 16, Math.floor(i / cols) * 16));
  c.save(path.join(OUT, 'tileset.png'));
  return { cols, rows };
}

/* ───────────────────────── map ───────────────────────── */
const W = 48, H = 30;
const grid = (fill = 0) => Array.from({ length: H }, () => Array(W).fill(fill));
const floor = grid(), walls = grid(), furniture = grid();
const set = (layer, x, y, name) => { layer[y][x] = tileIndex[name]; };

// Rooms: [slug, label, x0, y0, x1, y1] (inner floor, inclusive)
const ROOMS = [
  ['reuniones', 'Sala de reuniones', 1, 2, 11, 10],
  ['proyectos', 'Sala de proyectos', 13, 2, 23, 10],
  ['estudio', 'Estudio creativo', 25, 2, 35, 10],
  ['campanas', 'Sala de campañas', 37, 2, 46, 10],
  ['archivo', 'Archivo', 1, 18, 11, 28],
  ['recepcion', 'Recepción', 13, 18, 23, 28],
  ['administracion', 'Administración', 25, 18, 35, 28],
  ['gerencia', 'Despacho de gerencia', 37, 18, 46, 28],
];
const DOORS_X = { reuniones: 6, proyectos: 18, estudio: 30, campanas: 41, archivo: 6, recepcion: 18, administracion: 30, gerencia: 41 };

for (let y = 0; y < H; y++) for (let x = 0; x < W; x++) set(walls, x, y, 'wall_top');
const carve = (x0, y0, x1, y1, tileName) => { for (let y = y0; y <= y1; y++) for (let x = x0; x <= x1; x++) { walls[y][x] = 0; set(floor, x, y, tileName); } };
// corridor
carve(1, 12, 46, 15, 'floor_green');
for (const [slug, , x0, y0, x1, y1] of ROOMS) {
  carve(x0, y0, x1, y1, slug === 'gerencia' || slug === 'estudio' ? 'floor_green' : 'floor');
  // wall face (north) one row above the floor
  for (let x = x0; x <= x1; x++) set(walls, x, y0 - 1, (x - x0) % 4 === 2 ? 'wall_window' : 'wall_face');
  // door to the corridor (2 tiles wide)
  const dx = DOORS_X[slug];
  if (y0 < 12) { carve(dx, 11, dx + 1, 11, 'floor'); }
  else { carve(dx, 16, dx + 1, 17, 'floor'); }
}
// entrance in reception (south wall)
carve(18, 29, 19, 29, 'floor');
set(furniture, 18, 28, 'mat'); set(furniture, 19, 28, 'mat');
// boards on some north walls
set(walls, 3, 1, 'wall_board'); set(walls, 4, 1, 'wall_board');
set(walls, 15, 1, 'wall_board'); set(walls, 16, 1, 'wall_board');
set(walls, 43, 1, 'wall_board');

const SPOTS = []; // {name, x, y, face}
const spot = (name, x, y, face = 'down') => SPOTS.push({ name, x, y, face });
const desk = (x, y, kind = 'desk_pc') => { set(furniture, x, y, 'desk_l'); set(furniture, x + 1, y, kind); };

// Sala de reuniones: big table + chairs
set(furniture, 5, 5, 'table_tl'); set(furniture, 6, 5, 'table_tr'); set(furniture, 5, 6, 'table_bl'); set(furniture, 6, 6, 'table_br');
for (const [cx, cy] of [[5, 4], [6, 4], [5, 7], [6, 7], [4, 5], [7, 6]]) set(furniture, cx, cy, 'chair');
desk(9, 3, 'desk_papers'); set(furniture, 10, 4, 'chair'); spot('desk_secretaria', 10, 4, 'up');
set(furniture, 1, 2, 'plant'); set(furniture, 11, 10, 'plant');
spot('wander_reuniones_1', 7, 8); spot('wander_reuniones_2', 3, 8);

// Sala de proyectos
desk(15, 4); set(furniture, 16, 5, 'chair'); spot('desk_coordinadora', 16, 5, 'up');
desk(19, 4, 'desk_papers'); set(furniture, 20, 5, 'chair');
set(furniture, 23, 2, 'plant'); set(furniture, 13, 10, 'cabinet');
spot('wander_proyectos_1', 18, 8); spot('wander_proyectos_2', 21, 8);

// Estudio creativo (Liz)
for (let x = 27; x <= 33; x++) for (let y = 6; y <= 9; y++) set(floor, x, y, 'rug_yellow');
desk(28, 3); set(furniture, 29, 4, 'chair'); spot('desk_liz', 29, 4, 'up');
set(furniture, 33, 3, 'easel'); set(furniture, 25, 2, 'plant'); set(furniture, 35, 10, 'plant'); set(furniture, 25, 10, 'boxes');
spot('wander_estudio_1', 32, 5); spot('wander_estudio_2', 30, 8);

// Sala de campañas
set(furniture, 40, 3, 'desk_l'); set(furniture, 41, 3, 'screens'); set(furniture, 42, 3, 'desk_r'); set(furniture, 41, 4, 'chair'); spot('desk_analista', 41, 4, 'up');
set(furniture, 46, 2, 'plant'); set(furniture, 37, 10, 'cabinet');
spot('wander_campanas_1', 39, 8); spot('wander_campanas_2', 44, 7);

// Archivo
for (let x = 1; x <= 11; x += 2) set(furniture, x, 18, 'shelf');
set(furniture, 1, 22, 'shelf'); set(furniture, 1, 23, 'shelf'); set(furniture, 1, 24, 'shelf');
set(furniture, 10, 26, 'boxes'); set(furniture, 11, 27, 'boxes'); set(furniture, 11, 21, 'cabinet');
desk(5, 23, 'desk_papers'); set(furniture, 6, 24, 'chair'); spot('desk_archivera', 6, 24, 'up');
spot('wander_archivo_1', 8, 20); spot('wander_archivo_2', 3, 27);

// Recepción
for (let x = 14; x <= 22; x++) for (let y = 24; y <= 27; y++) set(floor, x, y, 'rug_black');
for (let x = 16; x <= 20; x++) set(furniture, x, 21, 'counter');
set(furniture, 18, 20, 'chair'); spot('desk_recepcionista', 18, 20, 'down');
set(furniture, 13, 25, 'sofa_l'); set(furniture, 14, 25, 'sofa_r');
set(furniture, 13, 18, 'plant'); set(furniture, 23, 18, 'plant'); set(furniture, 23, 27, 'plant'); set(furniture, 22, 18, 'coffee');
spot('wander_recepcion_1', 15, 22); spot('wander_recepcion_2', 21, 23); spot('entrance', 18, 27, 'up');

// Administración
desk(28, 22, 'desk_papers'); set(furniture, 29, 23, 'chair'); spot('desk_contable', 29, 23, 'up');
set(furniture, 25, 18, 'cabinet'); set(furniture, 26, 18, 'cabinet'); set(furniture, 35, 18, 'printer'); set(furniture, 35, 28, 'plant');
spot('wander_administracion_1', 32, 26); spot('wander_administracion_2', 27, 27);

// Despacho de gerencia (Jessica)
for (let x = 39; x <= 45; x++) for (let y = 21; y <= 25; y++) set(floor, x, y, 'rug_yellow');
desk(41, 21); set(furniture, 42, 20, 'chair'); spot('desk_jessica', 42, 20, 'down');
set(furniture, 37, 18, 'plant'); set(furniture, 46, 18, 'shelf'); set(furniture, 45, 27, 'sofa_l'); set(furniture, 46, 27, 'sofa_r');
spot('gerencia_wait_1', 40, 24, 'up'); spot('gerencia_wait_2', 42, 24, 'up'); spot('gerencia_wait_3', 44, 24, 'up');

// corridor wander points (coffee chats)
for (const [px, py] of [[1, 12], [46, 12], [16, 15], [33, 15]]) set(furniture, px, py, 'plant');
set(furniture, 24, 12, 'coffee');
spot('wander_pasillo_1', 10, 13); spot('wander_pasillo_2', 24, 14); spot('wander_pasillo_3', 36, 13);

function writeMap({ cols, rows }) {
  const layer = (id, name, data) => ({ id, name, type: 'tilelayer', width: W, height: H, x: 0, y: 0, opacity: 1, visible: true, data: data.flat() });
  const objects = [];
  let oid = 1;
  for (const [slug, label, x0, y0, x1, y1] of ROOMS) {
    objects.push({ id: oid++, name: slug, type: 'room', x: x0 * 16, y: (y0 - 1) * 16, width: (x1 - x0 + 1) * 16, height: (y1 - y0 + 2) * 16, rotation: 0, visible: true,
      properties: [{ name: 'label', type: 'string', value: label }] });
  }
  const spots = SPOTS.map((s) => ({ id: oid++, name: s.name, type: 'spot', point: true, x: s.x * 16 + 8, y: s.y * 16 + 8, width: 0, height: 0, rotation: 0, visible: true,
    properties: [{ name: 'face', type: 'string', value: s.face }] }));

  const map = {
    type: 'map', version: '1.10', tiledversion: '1.11.0', orientation: 'orthogonal', renderorder: 'right-down',
    width: W, height: H, tilewidth: 16, tileheight: 16, infinite: false, nextlayerid: 6, nextobjectid: oid,
    layers: [
      layer(1, 'floor', floor), layer(2, 'walls', walls), layer(3, 'furniture', furniture),
      { id: 4, name: 'rooms', type: 'objectgroup', draworder: 'topdown', opacity: 1, visible: true, x: 0, y: 0, objects },
      { id: 5, name: 'spots', type: 'objectgroup', draworder: 'topdown', opacity: 1, visible: true, x: 0, y: 0, objects: spots },
    ],
    tilesets: [{
      firstgid: 1, name: 'mad-office', image: 'tileset.png', imagewidth: cols * 16, imageheight: rows * 16,
      tilewidth: 16, tileheight: 16, tilecount: TILES.length, columns: cols, margin: 0, spacing: 0,
      tiles: TILES.map((t, i) => ({ id: i, properties: [{ name: 'name', type: 'string', value: t.name }, { name: 'collides', type: 'bool', value: t.collides }] })),
    }],
  };
  fs.writeFileSync(path.join(OUT, 'office.json'), JSON.stringify(map));
}

/* ───────────────────────── characters ───────────────────────── */
const CAST = {
  recepcionista: { skin: '#F1C9A5', hair: P.k, hairStyle: 'bob', shirt: P.y, shirt2: P.y2, pants: P.k2, extra: 'headset' },
  secretaria: { skin: '#D9A47A', hair: '#8E3B1F', hairStyle: 'bun', shirt: P.g2, shirt2: P.g3, pants: P.wood2, extra: 'glasses' },
  coordinadora: { skin: '#F1C9A5', hair: '#E3B95C', hairStyle: 'ponytail', shirt: P.k2, shirt2: P.k, pants: P.k3, extra: 'headband' },
  liz: { skin: '#E8B98F', hair: '#4A2C1A', hairStyle: 'long', shirt: P.c, shirt2: P.c2, pants: P.k2, extra: 'beret' },
  archivera: { skin: '#C98E66', hair: P.grey, hairStyle: 'bun', shirt: P.wood, shirt2: P.wood2, pants: P.k3, extra: 'glasses' },
  contable: { skin: '#A66E4A', hair: P.k, hairStyle: 'short', shirt: P.c, shirt2: P.y2, pants: P.k2, extra: 'vest' },
  analista: { skin: '#F1C9A5', hair: '#8E3B1F', hairStyle: 'short', shirt: P.leaf, shirt2: P.leaf2, pants: P.k2, extra: 'headphones' },
  jessica: { skin: '#E8B98F', hair: '#2B1A10', hairStyle: 'long', shirt: P.k, shirt2: P.y, pants: P.k, extra: 'blazer' },
};

// Draws one 32×32 frame. dir: down|left|right|up · pose: walk0..3 | idle | sit
function drawCharacter(c, ox, oy, s, dir, pose) {
  const flip = dir === 'left'; // side views are drawn facing right
  const side = dir === 'left' || dir === 'right';
  const P_ = (x, y, col) => c.px(ox + (flip ? 31 - x : x), oy + y, col);
  const R = (x, y, w, h, col) => { for (let j = 0; j < h; j++) for (let i = 0; i < w; i++) P_(x + i, y + j, col); };
  const walkPhase = pose.startsWith('walk') ? Number(pose.slice(4)) : -1;
  const bob = walkPhase === 1 || walkPhase === 3 ? 1 : 0;
  const sit = pose === 'sit';
  const dy = (sit ? 3 : 0) + bob;

  // legs
  if (!sit) {
    if (!side) {
      const lOff = walkPhase === 1 ? -1 : walkPhase === 3 ? 1 : 0;
      R(12, 25, 3, 4 + Math.max(0, lOff), s.pants); R(17, 25, 3, 4 + Math.max(0, -lOff), s.pants);
      R(12, 29 + Math.max(0, lOff), 3, 1, P.k); R(17, 29 + Math.max(0, -lOff), 3, 1, P.k);
    } else {
      if (walkPhase === 1 || walkPhase === 3) { R(12, 25, 3, 4, s.pants); R(17, 25, 3, 4, s.pants); R(11, 29, 4, 1, P.k); R(17, 29, 4, 1, P.k); }
      else { R(14, 25, 4, 4, s.pants); R(14, 29, 5, 1, P.k); }
    }
  } else if (dir === 'down') {
    R(12, 26, 3, 2, s.pants); R(17, 26, 3, 2, s.pants); R(12, 28, 3, 1, P.k); R(17, 28, 3, 1, P.k);
  }

  // body
  const bx = side ? 12 : 10, bw = side ? 8 : 12;
  R(bx, 16 + dy, bw, 9 - (sit ? 1 : 0), s.shirt);
  R(bx, 23 + dy - (sit ? 1 : 0), bw, 1, s.shirt2);
  if (s.extra === 'vest' || s.extra === 'blazer') {
    if (!side && dir === 'down') { R(10, 16 + dy, 3, 8, s.shirt2); R(19, 16 + dy, 3, 8, s.shirt2); }
    else if (side) R(bx + 2, 16 + dy, 4, 8, s.shirt2);
    else R(10, 16 + dy, 12, 2, s.shirt2);
  }
  // arms
  const swing = walkPhase === 1 ? 1 : walkPhase === 3 ? -1 : 0;
  if (!side) {
    R(9, 17 + dy + swing, 2, 6, s.shirt); R(21, 17 + dy - swing, 2, 6, s.shirt);
    R(9, 23 + dy + swing, 2, 1, s.skin); R(21, 23 + dy - swing, 2, 1, s.skin);
  } else {
    R(14 + swing, 17 + dy, 3, 6, s.shirt2); R(14 + swing, 23 + dy, 3, 1, s.skin);
  }

  // head
  const hy = 4 + dy;
  R(10, hy + 2, 12, 10, s.skin);
  R(11, hy + 1, 10, 1, s.skin); R(11, hy + 12, 10, 1, s.skin);
  // hair base (top + back)
  R(10, hy, 12, 4, s.hair); R(9, hy + 2, 1, 6, s.hair); R(22, hy + 2, 1, 6, s.hair);
  if (dir === 'up') { R(10, hy, 12, 12, s.hair); R(11, hy + 12, 10, 1, s.hair); }
  if (side) { R(10, hy, 5, 11, s.hair); R(15, hy, 7, 3, s.hair); }
  switch (s.hairStyle) {
    case 'bob': R(9, hy + 2, 2, 9, s.hair); R(21, hy + 2, 2, 9, s.hair); break;
    case 'long': R(9, hy + 2, 2, 14, s.hair); R(21, hy + 2, 2, 14, s.hair); if (dir === 'up' || side) R(10, hy + 10, side ? 5 : 12, 6, s.hair); break;
    case 'bun': R(13, hy - 3, 6, 3, s.hair); break;
    case 'ponytail': if (dir === 'up') R(14, hy + 12, 4, 5, s.hair); else if (side) R(8, hy + 4, 3, 7, s.hair); break;
    default: break; // short
  }
  // face
  if (dir === 'down') {
    R(13, hy + 7, 1, 2, P.k); R(18, hy + 7, 1, 2, P.k);
    P_(12, hy + 10, '#E89A8A'); P_(19, hy + 10, '#E89A8A'); R(15, hy + 10, 2, 1, P.k3);
  } else if (side) {
    R(19, hy + 7, 1, 2, P.k); P_(20, hy + 10, '#E89A8A'); P_(21, hy + 8, s.skin);
  }
  // accessories
  if (s.extra === 'glasses' && dir !== 'up') {
    if (dir === 'down') { R(12, hy + 6, 3, 3, P.k); R(17, hy + 6, 3, 3, P.k); R(13, hy + 7, 1, 1, '#BFD8D2'); R(18, hy + 7, 1, 1, '#BFD8D2'); R(15, hy + 7, 2, 1, P.k); }
    else { R(18, hy + 6, 3, 3, P.k); P_(19, hy + 7, '#BFD8D2'); }
  }
  if (s.extra === 'headset' || s.extra === 'headphones') {
    R(10, hy - 1, 12, 1, P.k); R(9, hy + 5, 2, 4, P.k); R(21, hy + 5, 2, 4, P.k);
    if (s.extra === 'headset' && dir === 'down') { R(9, hy + 9, 1, 2, P.k); R(10, hy + 11, 2, 1, P.y2); }
  }
  if (s.extra === 'headband') R(10, hy + 2, 12, 1, P.y);
  if (s.extra === 'beret') { R(10, hy - 2, 12, 3, P.y); R(9, hy - 1, 2, 2, P.y); R(15, hy - 3, 2, 1, P.y2); }
}

function outline(c) {
  const add = [];
  for (let y = 0; y < c.h; y++) for (let x = 0; x < c.w; x++) {
    if (c.get(x, y)) continue;
    const fx = Math.floor(x / 32) * 32, fy = Math.floor(y / 32) * 32; // never bleed across frames
    for (const [dx, dy] of [[1, 0], [-1, 0], [0, 1], [0, -1]]) {
      const nx = x + dx, ny = y + dy;
      if (nx < fx || ny < fy || nx >= fx + 32 || ny >= fy + 32) continue;
      if (c.get(nx, ny)) { add.push([x, y]); break; }
    }
  }
  for (const [x, y] of add) c.px(x, y, P.k);
}

function writeCharacters() {
  const DIRS = ['down', 'left', 'right', 'up'];
  for (const [slug, s] of Object.entries(CAST)) {
    const c = new Canvas(128, 192);
    DIRS.forEach((dir, row) => { for (let f = 0; f < 4; f++) drawCharacter(c, f * 32, row * 32, s, dir, 'walk' + f); });
    DIRS.forEach((dir, col) => drawCharacter(c, col * 32, 4 * 32, s, dir, 'idle'));
    DIRS.forEach((dir, col) => drawCharacter(c, col * 32, 5 * 32, s, dir, 'sit'));
    outline(c);
    c.save(path.join(OUT, 'characters', `${slug}.png`));
  }
}

const sheet = writeTileset();
writeMap(sheet);
writeCharacters();
console.log(`tileset: ${TILES.length} tiles · map ${W}×${H} · ${Object.keys(CAST).length} characters`);
