import Phaser from 'phaser';
import EasyStar from 'easystarjs';

const T = 16;              // tile size
const SPEED = 40;          // px per second
const FONT = 'Silkscreen, monospace';
const COLORS = { y: 0xfbb62f, k: 0x0b0b0b, c: 0xfdf8ea };
// Character sheets (pixel-agents format): 112×96, 7 columns of 16 px × 3 rows of 32 px.
// Rows: down, up, right (left = right mirrored). Columns: walk 0-2, type 3-4, read 5-6.
const ROW = { down: 0, up: 1, right: 2, left: 2 };
const COLS = 7;
const SIT_OFFSET = 3;      // seated characters sink a little behind the desk
const STANDING = ['secretaria', 'recepcionista']; // work on their feet

const rand = (a, b) => a + Math.random() * (b - a);
const pick = (arr) => arr[Math.floor(Math.random() * arr.length)];
const props = (o) => Object.fromEntries((o.properties || []).map((p) => [p.name, p.value]));

/**
 * The office (docs/oficina-v2.md). Characters sit at their desks facing the visitor, wander
 * around, and go to the bench in front of the manager's door when their agent is waiting for a
 * decision. Walls, furniture and people are drawn in depth order by their bottom edge. Rooms
 * and characters are clickable; the HTML layer opens the matching panel.
 */
export default class OfficeScene extends Phaser.Scene {
  constructor() { super('office'); }

  init(data) {
    this.opts = data;            // { assets, v, me, onRoom, onCharacter, onReady }
    this.characters = {};
    this.states = {};
  }

  preload() {
    const a = this.opts.assets;
    const v = this.opts.v ? '?v=' + encodeURIComponent(this.opts.v) : ''; // cache-busting after a deploy
    this.load.tilemapTiledJSON('office', a + 'office.json' + v);
    this.load.image('tiles', a + 'tileset.png' + v);
    this.load.spritesheet('walls', a + 'walls.png' + v, { frameWidth: 16, frameHeight: 32 });
    for (const slug of Object.keys(this.opts.me.characters)) {
      this.load.spritesheet('char-' + slug, `${a}characters/${slug}.png${v}`, { frameWidth: 16, frameHeight: 32 });
    }
    // The furniture images are listed in the map itself.
    this.load.once('filecomplete-tilemapJSON-office', () => {
      const data = this.cache.tilemap.get('office').data;
      const images = new Set();
      for (const o of data.layers.find((l) => l.name === 'props').objects) {
        const p = props(o);
        images.add(p.image);
        if (p.frames) p.frames.split(',').forEach((f) => images.add(f));
      }
      for (const img of images) this.load.image(img, a + img + v);
    });
  }

  create() {
    const map = this.make.tilemap({ key: 'office' });
    this.map = map;
    this.cameras.main.setZoom(2).centerOn(map.widthInPixels / 2, map.heightInPixels / 2);
    const tiles = map.addTilesetImage('mad-office', 'tiles');
    map.createLayer('floor', tiles, 0, 0).setDepth(-2);
    this.wallLayer = map.createLayer('walls', tiles, 0, 0).setDepth(-1);

    this.buildWalls();
    this.buildProps();
    this.buildPathfinding();
    this.spots = {};
    for (const o of map.getObjectLayer('spots').objects) {
      this.spots[o.name] = { x: o.x, y: o.y, tx: Math.floor(o.x / T), ty: Math.floor(o.y / T), face: props(o).face || 'down' };
    }
    this.buildRooms();
    this.buildCharacters();
    this.opts.onReady?.(this);

    const recep = this.characters.recepcionista;
    if (recep) this.time.delayedCall(700, () => this.say(recep, `¡Hola${this.opts.me.user.name ? ', ' + this.opts.me.user.name : ''}!`, 5000));
  }

  /* ───────────── map ───────────── */

  isWall(x, y) {
    const t = this.wallLayer.getTileAt(x, y);
    return !!(t && t.index > 0);
  }

  /** Walls in 3/4 view: a 16×32 piece standing on each wall tile, chosen by its neighbours (N1 E2 S4 W8). */
  buildWalls() {
    for (let y = 0; y < this.map.height; y++) {
      for (let x = 0; x < this.map.width; x++) {
        if (!this.isWall(x, y)) continue;
        const mask = (this.isWall(x, y - 1) ? 1 : 0) | (this.isWall(x + 1, y) ? 2 : 0) | (this.isWall(x, y + 1) ? 4 : 0) | (this.isWall(x - 1, y) ? 8 : 0);
        this.add.image(x * T, (y + 1) * T, 'walls', mask).setOrigin(0, 1).setDepth((y + 1) * T);
      }
    }
  }

  buildProps() {
    this.solid = new Set();
    const animated = [];
    for (const o of this.map.getObjectLayer('props').objects) {
      const p = props(o);
      const img = this.add.image(o.x, o.y, p.image).setOrigin(0).setFlipX(!!p.flip).setDepth(p.depth);
      if (p.frames) animated.push({ img, frames: p.frames.split(','), i: 0 });
      if (p.solid) for (let y = p.row + p.bg; y < p.row + p.fh; y++) for (let x = p.col; x < p.col + p.fw; x++) this.solid.add(`${x},${y}`);
    }
    if (animated.length) {
      this.time.addEvent({ delay: 280, loop: true, callback: () => animated.forEach((a) => { a.i = (a.i + 1) % a.frames.length; a.img.setTexture(a.frames[a.i]); }) });
    }
  }

  buildPathfinding() {
    const grid = [];
    for (let y = 0; y < this.map.height; y++) {
      const row = [];
      for (let x = 0; x < this.map.width; x++) row.push(this.isWall(x, y) || this.solid.has(`${x},${y}`) ? 1 : 0);
      grid.push(row);
    }
    this.easystar = new EasyStar.js();
    this.easystar.setGrid(grid);
    this.easystar.setAcceptableTiles([0]);
    this.easystar.enableSync();
  }

  findPath(from, to) {
    return new Promise((resolve) => {
      this.easystar.findPath(from.tx, from.ty, to.tx, to.ty, (path) => resolve(path || []));
      this.easystar.calculate();
    });
  }

  buildRooms() {
    const manager = this.opts.me.role === 'manager';
    const visitor = this.opts.me.role === 'visitor';
    const rooms = this.map.getObjectLayer('rooms').objects;
    for (const o of rooms) {
      let label = props(o).label || o.name;
      if (o.name === 'gerencia' && !manager) label = 'Gerencia · privado';
      const zone = this.add.zone(o.x, o.y, o.width, o.height).setOrigin(0).setInteractive({ useHandCursor: !visitor || o.name === 'recepcion' });
      zone.on('pointerdown', () => this.opts.onRoom(o.name));
      // Room names appear on hover, as in the design (the room buttons under the map name them too).
      const plate = this.add.text(o.x + 3, o.y + (o.y ? 3 : 2 * T + 3), label.toUpperCase(), {
        fontFamily: FONT, fontSize: '8px', color: '#0B0B0B', backgroundColor: '#FBB62F', padding: { x: 3, y: 1 },
      }).setResolution(2).setDepth(2700).setVisible(false);
      zone.on('pointerover', () => plate.setVisible(true));
      zone.on('pointerout', () => plate.setVisible(false));

      // One sign per wide room; the narrow open-plan zones share one (added below).
      if (visitor && o.name !== 'recepcion' && o.width >= 6 * T) this.veilSign(o.x + o.width / 2, o.y + o.height / 2 + (o.y ? 0 : T));
      if (o.name === 'gerencia' && !manager && !visitor) {
        this.add.rectangle(o.x, o.y + 2 * T, o.width, o.height - 2 * T, COLORS.k, 0.28).setOrigin(0).setDepth(1500);
      }
    }
    if (visitor) {
      // Visitors only have the reception: everything above it fades to a dark veil.
      const recep = rooms.find((r) => r.name === 'recepcion');
      const veil = this.add.rectangle(0, 0, this.map.widthInPixels, recep.y - T + 4, COLORS.k, 1).setOrigin(0).setDepth(2500).setAlpha(0);
      const open = rooms.filter((r) => r.width < 6 * T && r.name !== 'recepcion' && r.y === rooms.find((x) => x.name === 'proyectos')?.y);
      if (open.length) this.veilSign(this.map.widthInPixels / 2, open[0].y + open[0].height / 2);
      this.tweens.add({ targets: veil, alpha: 0.9, duration: 900, delay: 200, ease: 'Sine.easeOut' });
      this.tweens.add({ targets: this.children.list.filter((c) => c.name === 'veil-sign'), alpha: 1, duration: 600, delay: 700 });
    }
  }

  veilSign(x, y) {
    this.add.text(x, y, 'SOLO CLIENTES', {
      fontFamily: FONT, fontSize: '8px', color: '#FBB62F', backgroundColor: '#0B0B0B', padding: { x: 3, y: 2 },
    }).setOrigin(0.5).setResolution(2).setDepth(2600).setAlpha(0).setName('veil-sign');
  }

  /* ───────────── characters ───────────── */

  buildCharacters() {
    const me = this.opts.me;
    for (const [slug, info] of Object.entries(me.characters)) {
      const key = 'char-' + slug;
      for (const [dir, row] of Object.entries({ down: 0, up: 1, right: 2 })) {
        const f = (cols) => cols.map((c) => ({ key, frame: row * COLS + c }));
        this.anims.create({ key: `${slug}-walk-${dir}`, frames: f([0, 1, 2, 1]), frameRate: 8, repeat: -1 });
        this.anims.create({ key: `${slug}-type-${dir}`, frames: f([3, 4]), frameRate: 4, repeat: -1 });
        this.anims.create({ key: `${slug}-read-${dir}`, frames: f([5, 6]), frameRate: 2, repeat: -1 });
      }
      const home = this.spots['desk_' + slug];
      if (!home) continue;
      const sprite = this.add.sprite(0, 0, key, 0).setOrigin(0.5, 1).setInteractive({ useHandCursor: true });
      const ch = { slug, info, sprite, home, seated: !STANDING.includes(slug), at: 'home', busy: false, waitingSpot: null, bubble: null, nextWander: this.time.now + rand(4000, 15000) };
      this.characters[slug] = ch;
      this.sit(ch);

      sprite.on('pointerdown', (p, x, y, e) => { e?.stopPropagation?.(); this.opts.onCharacter(slug); });
      sprite.on('pointerover', () => { if (!ch.bubble) this.tag(ch, `${info.name} · ${info.role}`); });
      sprite.on('pointerout', () => { if (ch.tagObj) { ch.tagObj.destroy(); ch.tagObj = null; } });
    }
  }

  /** Feet on the bottom edge of the tile (as the kit draws them). */
  feet(spot) { return { x: spot.tx * T + T / 2, y: (spot.ty + 1) * T }; }

  face(ch, dir) {
    ch.sprite.setFlipX(dir === 'left');
    ch.dir = dir;
  }

  pose(ch, what) {
    const dir = ch.dir || 'down';
    if (what === 'still') { ch.sprite.anims.stop(); ch.sprite.setFrame(ROW[dir] * COLS + (ch.at === 'home' && ch.seated ? 3 : 0)); return; }
    ch.sprite.anims.play(`${ch.slug}-${what}-${dir === 'left' ? 'right' : dir}`, true);
  }

  /** At the desk: seated behind it facing the room (or on her feet), typing while working. */
  sit(ch) {
    const { x, y } = this.feet(ch.home);
    ch.at = 'home';
    ch.sprite.setPosition(x, y - (ch.seated ? SIT_OFFSET : 0)).setDepth(y - (ch.seated ? SIT_OFFSET : 0));
    this.face(ch, ch.home.face);
    this.pose(ch, this.stateOf(ch) === 'trabajando' ? (ch.seated ? 'type' : 'read') : 'still');
    this.follow(ch);
  }

  async walkTo(ch, spot) {
    const from = { tx: Math.floor(ch.sprite.x / T), ty: Math.floor((ch.sprite.y + (ch.at === 'home' && ch.seated ? SIT_OFFSET : 0) - 1) / T) };
    const path = await this.findPath(from, spot);
    if (!path.length) return false;
    ch.busy = true;
    ch.at = 'away';
    for (const step of path.slice(1)) {
      if (ch.cancel) break;
      const { x: tx, y: ty } = this.feet({ tx: step.x, ty: step.y });
      const dx = tx - ch.sprite.x, dy = ty - ch.sprite.y;
      this.face(ch, Math.abs(dx) > Math.abs(dy) ? (dx > 0 ? 'right' : 'left') : (dy > 0 ? 'down' : 'up'));
      this.pose(ch, 'walk');
      await new Promise((resolve) => this.tweens.add({
        targets: ch.sprite, x: tx, y: ty, duration: (Math.hypot(dx, dy) / SPEED) * 1000,
        onUpdate: () => { ch.sprite.setDepth(ch.sprite.y); this.follow(ch); }, onComplete: resolve,
      }));
    }
    ch.busy = false;
    const cancelled = ch.cancel; ch.cancel = false;
    this.pose(ch, 'still');
    return !cancelled;
  }

  async goHome(ch) {
    if (await this.walkTo(ch, ch.home)) this.sit(ch);
  }

  async wander(ch) {
    const own = Object.keys(this.spots).filter((k) => k.startsWith(`wander_${ch.info.room}_`));
    const corridor = Object.keys(this.spots).filter((k) => k.startsWith('wander_pasillo_'));
    const name = pick(Math.random() < 0.7 && own.length ? own : corridor);
    if (!(await this.walkTo(ch, this.spots[name]))) return;
    this.face(ch, this.spots[name].face);
    this.pose(ch, 'still');
    await new Promise((r) => this.time.delayedCall(rand(2500, 6000), r));
    if (this.stateOf(ch) !== 'esperando_gerencia') await this.goHome(ch);
  }

  stateOf(ch) { return this.states[ch.slug] || 'idle'; }

  /** Waiting for Jessica: to the bench in front of her door, looking at it. */
  async goToManager(ch) {
    const taken = Object.values(this.characters).map((c) => c.waitingSpot).filter(Boolean);
    const spotName = ['gerencia_wait_1', 'gerencia_wait_2', 'gerencia_wait_3', 'gerencia_wait_4'].find((n) => !taken.includes(n)) || 'gerencia_wait_4';
    ch.waitingSpot = spotName;
    if (await this.walkTo(ch, this.spots[spotName])) {
      ch.at = 'manager';
      this.face(ch, 'up');
      this.pose(ch, 'still');
      this.waitingBubbles();
    }
  }

  /** The first in the queue says it; the rest only raise a hand («!»): four sentences side by side would overlap. */
  waitingBubbles() {
    const queue = Object.values(this.characters).filter((c) => c.at === 'manager' && c.waitingSpot).sort((a, b) => a.waitingSpot.localeCompare(b.waitingSpot));
    queue.forEach((c, i) => { c.lift = !i && queue.length > 1 ? 14 : 0; this.say(c, i ? '!' : '¡Jessica, una consulta!', 0, true); });
  }

  setStates(states) {
    this.states = states || {};
    for (const ch of Object.values(this.characters)) {
      const s = this.stateOf(ch);
      if (s === 'esperando_gerencia' && !ch.waitingSpot && ch.slug !== 'jessica') {
        if (ch.busy) ch.cancel = true;
        this.time.delayedCall(ch.busy ? 400 : 0, () => this.goToManager(ch));
      } else if (s !== 'esperando_gerencia' && ch.waitingSpot) {
        ch.waitingSpot = null;
        ch.lift = 0;
        this.clearBubble(ch);
        ch.at = 'away';
        this.waitingBubbles();
        if (ch.busy) ch.cancel = true;
        this.time.delayedCall(ch.busy ? 400 : 0, () => this.goHome(ch));
      } else if (ch.at === 'home') {
        this.sit(ch); // working ↔ idle at the desk
      }
    }
  }

  update(time) {
    for (const ch of Object.values(this.characters)) {
      if (ch.busy || ch.at !== 'home' || ch.slug === 'jessica' || time < ch.nextWander) continue;
      const working = this.stateOf(ch) === 'trabajando';
      ch.nextWander = time + (working ? rand(40000, 80000) : rand(15000, 35000));
      if (ch.slug === 'recepcionista' && Math.random() < 0.6) continue; // she mostly stays at the counter
      this.wander(ch);
    }
  }

  /* ───────────── speech bubbles & tags ───────────── */

  bubbleBox(text, fill, color) {
    const t = this.add.text(0, 0, text, { fontFamily: FONT, fontSize: '8px', color, padding: { x: 4, y: 2 }, wordWrap: { width: 110 } }).setResolution(2);
    const g = this.add.graphics();
    g.fillStyle(COLORS.k, 1).fillRect(-1, -1, t.width + 2, t.height + 2);
    g.fillStyle(fill, 1).fillRect(0, 0, t.width, t.height);
    g.fillStyle(COLORS.k, 1).fillRect(Math.floor(t.width / 2) - 2, t.height + 1, 4, 2).fillRect(Math.floor(t.width / 2) - 1, t.height + 3, 2, 2);
    const c = this.add.container(0, 0, [g, t]).setDepth(3000);
    c.w = t.width; c.h = t.height;
    return c;
  }

  follow(ch) {
    const W = this.map.widthInPixels;
    for (const obj of [ch.bubble, ch.tagObj]) {
      if (!obj) continue;
      // Kept inside the map: the plan is narrow and bubbles near the walls would be cut.
      const x = Math.max(1, Math.min(W - obj.w - 1, Math.round(ch.sprite.x - obj.w / 2)));
      obj.setPosition(x, Math.max(1, Math.round(ch.sprite.y - 30 - obj.h - (obj === ch.bubble ? ch.lift || 0 : 0))));
    }
  }

  say(ch, text, ms = 4000, sticky = false) {
    this.clearBubble(ch);
    ch.bubble = this.bubbleBox(text, COLORS.c, '#0B0B0B');
    this.follow(ch);
    if (!sticky && ms) this.time.delayedCall(ms, () => this.clearBubble(ch));
  }

  clearBubble(ch) { if (ch.bubble) { ch.bubble.destroy(); ch.bubble = null; } }

  tag(ch, text) {
    if (ch.tagObj) ch.tagObj.destroy();
    ch.tagObj = this.bubbleBox(text, COLORS.y, '#0B0B0B');
    this.follow(ch);
  }
}
