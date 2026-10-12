import Phaser from 'phaser';
import EasyStar from 'easystarjs';

const T = 16;              // tile size
const SPEED = 56;          // px per second
const DIRS = ['down', 'left', 'right', 'up'];
const FONT = 'Silkscreen, monospace';
const COLORS = { y: 0xfbb62f, k: 0x0b0b0b, c: 0xfdf8ea };

const rand = (a, b) => a + Math.random() * (b - a);
const pick = (arr) => arr[Math.floor(Math.random() * arr.length)];

/**
 * The office. Characters sit at their desks, wander around, and walk to the
 * manager's office when their agent is waiting for a decision. Rooms and
 * characters are clickable; the HTML layer opens the matching panel.
 */
export default class OfficeScene extends Phaser.Scene {
  constructor() { super('office'); }

  init(data) {
    this.opts = data;            // { assets, me, onRoom, onCharacter, onReady }
    this.characters = {};
    this.states = {};
  }

  preload() {
    const a = this.opts.assets;
    const v = this.opts.v ? '?v=' + encodeURIComponent(this.opts.v) : ''; // cache-busting after a deploy
    this.load.tilemapTiledJSON('office', a + 'office.json' + v);
    this.load.image('tiles', a + 'tileset.png' + v);
    for (const slug of Object.keys(this.opts.me.characters)) {
      this.load.spritesheet('char-' + slug, `${a}characters/${slug}.png${v}`, { frameWidth: 32, frameHeight: 32 });
    }
  }

  create() {
    this.cameras.main.setZoom(2).centerOn(48 * T / 2, 30 * T / 2);
    const map = this.make.tilemap({ key: 'office' });
    const tiles = map.addTilesetImage('mad-office', 'tiles');
    const layers = ['floor', 'walls', 'furniture'].map((name) => map.createLayer(name, tiles, 0, 0));
    this.map = map;

    this.buildPathfinding(map, layers);
    this.spots = {};
    for (const o of map.getObjectLayer('spots').objects) {
      const face = (o.properties || []).find((p) => p.name === 'face')?.value || 'down';
      this.spots[o.name] = { x: o.x, y: o.y, tx: Math.floor(o.x / T), ty: Math.floor(o.y / T), face };
    }
    this.buildRooms(map);
    this.buildCharacters();
    this.opts.onReady?.(this);

    const recep = this.characters.recepcionista;
    if (recep) this.time.delayedCall(700, () => this.say(recep, `¡Hola${this.opts.me.user.name ? ', ' + this.opts.me.user.name : ''}!`, 5000));
  }

  /* ───────────── map helpers ───────────── */

  buildPathfinding(map, layers) {
    const grid = [];
    for (let y = 0; y < map.height; y++) {
      const row = [];
      for (let x = 0; x < map.width; x++) {
        const blocked = layers.slice(1).some((l) => {
          const t = l.getTileAt(x, y);
          return t && t.properties && t.properties.collides;
        });
        row.push(blocked ? 1 : 0);
      }
      grid.push(row);
    }
    this.grid = grid;
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

  buildRooms(map) {
    const manager = this.opts.me.role === 'manager';
    const visitor = this.opts.me.role === 'visitor';
    for (const o of map.getObjectLayer('rooms').objects) {
      const label = (o.properties || []).find((p) => p.name === 'label')?.value || o.name;
      const zone = this.add.zone(o.x, o.y, o.width, o.height).setOrigin(0).setInteractive({ useHandCursor: true });
      zone.on('pointerdown', () => this.opts.onRoom(o.name));

      const plate = this.add.text(o.x + 4, o.y + 3, label.toUpperCase(), {
        fontFamily: FONT, fontSize: '8px', color: '#0B0B0B', backgroundColor: '#FBB62F', padding: { x: 3, y: 1 },
      }).setResolution(2).setDepth(2000).setAlpha(0.95);
      zone.on('pointerover', () => plate.setBackgroundColor('#FDF8EA'));
      zone.on('pointerout', () => plate.setBackgroundColor('#FBB62F'));

      if (visitor && o.name !== 'recepcion') {
        // Visitors only have the reception: other rooms fade to a dark veil (no new art,
        // so it keeps working after the office redesign).
        const veil = this.add.graphics().fillStyle(COLORS.k, 1).fillRect(o.x, o.y, o.width, o.height).setDepth(2500).setAlpha(0);
        const sign = this.add.text(o.x + o.width / 2, o.y + o.height / 2, 'SOLO CLIENTES', {
          fontFamily: FONT, fontSize: '8px', color: '#FBB62F', backgroundColor: '#0B0B0B', padding: { x: 4, y: 2 },
        }).setOrigin(0.5).setResolution(2).setDepth(2600).setAlpha(0);
        this.tweens.add({ targets: veil, alpha: 0.9, duration: 900, delay: 200, ease: 'Sine.easeOut' });
        this.tweens.add({ targets: sign, alpha: 1, duration: 600, delay: 700 });
        plate.setDepth(2600);
        continue;
      }
      if (o.name === 'gerencia' && !manager) {
        this.add.rectangle(o.x, o.y + T, o.width, o.height - T, COLORS.k, 0.28).setOrigin(0).setDepth(1500);
        plate.setText('GERENCIA · PRIVADO');
      }
    }
  }

  /* ───────────── characters ───────────── */

  buildCharacters() {
    const me = this.opts.me;
    for (const [slug, info] of Object.entries(me.characters)) {
      const key = 'char-' + slug;
      DIRS.forEach((dir, row) => {
        this.anims.create({ key: `${slug}-walk-${dir}`, frames: this.anims.generateFrameNumbers(key, { start: row * 4, end: row * 4 + 3 }), frameRate: 8, repeat: -1 });
      });
      const home = this.spots['desk_' + slug];
      if (!home) continue;
      const sprite = this.add.sprite(home.x, home.y + 6, key, 20).setOrigin(0.5, 30 / 32).setInteractive({ useHandCursor: true });
      const ch = { slug, info, sprite, home, at: 'home', busy: false, waitingSpot: null, bubble: null, nextWander: this.time.now + rand(4000, 15000) };
      this.characters[slug] = ch;
      this.sit(ch);

      sprite.on('pointerdown', (p, x, y, e) => { e?.stopPropagation?.(); this.opts.onCharacter(slug); });
      sprite.on('pointerover', () => { if (!ch.bubble) this.tag(ch, `${info.name} · ${info.role}`); });
      sprite.on('pointerout', () => { if (ch.tagObj) { ch.tagObj.destroy(); ch.tagObj = null; } });
    }
  }

  frameIndex(row, dir) { return row * 4 + DIRS.indexOf(dir); }

  sit(ch) {
    ch.sprite.anims.stop();
    ch.sprite.setFrame(this.frameIndex(5, ch.home.face));
    ch.sprite.setPosition(ch.home.x, ch.home.y + 6);
    ch.sprite.setDepth(ch.home.face === 'up' ? ch.home.y - 10 : ch.home.y + 10);
    ch.at = 'home';
  }

  stand(ch, dir) {
    ch.sprite.anims.stop();
    ch.sprite.setFrame(this.frameIndex(4, dir));
  }

  async walkTo(ch, spot) {
    const from = { tx: Math.floor(ch.sprite.x / T), ty: Math.floor((ch.sprite.y - 6) / T) };
    const path = await this.findPath(from, spot);
    if (!path.length) return false;
    ch.busy = true;
    for (const step of path.slice(1)) {
      if (ch.cancel) break;
      const tx = step.x * T + T / 2, ty = step.y * T + T / 2 + 6;
      const dx = tx - ch.sprite.x, dy = ty - ch.sprite.y;
      const dir = Math.abs(dx) > Math.abs(dy) ? (dx > 0 ? 'right' : 'left') : (dy > 0 ? 'down' : 'up');
      ch.sprite.anims.play(`${ch.slug}-walk-${dir}`, true);
      ch.lastDir = dir;
      await new Promise((resolve) => this.tweens.add({
        targets: ch.sprite, x: tx, y: ty, duration: (Math.hypot(dx, dy) / SPEED) * 1000,
        onUpdate: () => { ch.sprite.setDepth(ch.sprite.y); this.follow(ch); }, onComplete: resolve,
      }));
    }
    ch.busy = false;
    const cancelled = ch.cancel; ch.cancel = false;
    this.stand(ch, ch.lastDir || 'down');
    return !cancelled;
  }

  async goHome(ch) {
    if (await this.walkTo(ch, ch.home)) this.sit(ch);
  }

  async wander(ch) {
    const own = Object.keys(this.spots).filter((k) => k.startsWith(`wander_${ch.info.room}_`));
    const corridor = Object.keys(this.spots).filter((k) => k.startsWith('wander_pasillo_'));
    const target = this.spots[pick(Math.random() < 0.7 && own.length ? own : corridor)];
    ch.at = 'away';
    if (!(await this.walkTo(ch, target))) return;
    await new Promise((r) => this.time.delayedCall(rand(2500, 6000), r));
    if (this.stateOf(ch) !== 'esperando_gerencia') await this.goHome(ch);
  }

  stateOf(ch) { return this.states[ch.slug] || 'idle'; }

  async goToManager(ch) {
    const taken = Object.values(this.characters).map((c) => c.waitingSpot).filter(Boolean);
    const spotName = ['gerencia_wait_1', 'gerencia_wait_2', 'gerencia_wait_3'].find((n) => !taken.includes(n)) || 'gerencia_wait_1';
    ch.waitingSpot = spotName;
    ch.at = 'manager';
    if (await this.walkTo(ch, this.spots[spotName])) {
      this.stand(ch, 'up');
      this.say(ch, '¡Jessica, una consulta!', 0, true);
    }
  }

  setStates(states) {
    this.states = states || {};
    for (const ch of Object.values(this.characters)) {
      const s = this.stateOf(ch);
      if (s === 'esperando_gerencia' && ch.at !== 'manager' && ch.slug !== 'jessica') {
        if (ch.busy) ch.cancel = true;
        this.time.delayedCall(ch.busy ? 400 : 0, () => this.goToManager(ch));
      } else if (s !== 'esperando_gerencia' && ch.at === 'manager') {
        ch.waitingSpot = null;
        this.clearBubble(ch);
        this.goHome(ch);
      }
    }
  }

  update(time) {
    for (const ch of Object.values(this.characters)) {
      if (ch.busy || ch.at !== 'home' || ch.slug === 'jessica' || time < ch.nextWander) continue;
      const working = this.stateOf(ch) === 'trabajando';
      ch.nextWander = time + (working ? rand(40000, 80000) : rand(15000, 35000));
      if (ch.slug === 'recepcionista' && Math.random() < 0.6) continue; // she mostly stays at the desk
      this.wander(ch);
    }
  }

  /* ───────────── speech bubbles & tags ───────────── */

  bubbleBox(text, fill, color) {
    const t = this.add.text(0, 0, text, { fontFamily: FONT, fontSize: '8px', color, padding: { x: 4, y: 2 }, wordWrap: { width: 120 } }).setResolution(2);
    const g = this.add.graphics();
    g.fillStyle(COLORS.k, 1).fillRect(-1, -1, t.width + 2, t.height + 2);
    g.fillStyle(fill, 1).fillRect(0, 0, t.width, t.height);
    g.fillStyle(COLORS.k, 1).fillRect(Math.floor(t.width / 2) - 2, t.height + 1, 4, 2).fillRect(Math.floor(t.width / 2) - 1, t.height + 3, 2, 2);
    const c = this.add.container(0, 0, [g, t]).setDepth(3000);
    c.w = t.width; c.h = t.height;
    return c;
  }

  follow(ch) {
    for (const obj of [ch.bubble, ch.tagObj]) {
      if (obj) obj.setPosition(Math.round(ch.sprite.x - obj.w / 2), Math.round(ch.sprite.y - 34 - obj.h));
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
