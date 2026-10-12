// MAD Oficina · client portal. Classic (HTML) view + lazy-loaded pixel office.
import './portal.css';
import { createApi } from './api.js';
import { icon } from './icons.js';
import { PANELS, ROOM_PANEL, CHARACTER_PANEL, menuButtons, greeting, esc } from './panels.js';

const POLL_MS = 25000;
const store = {
  get(k) { try { return window.localStorage.getItem(k); } catch { return null; } },
  set(k, v) { try { v == null ? window.localStorage.removeItem(k) : window.localStorage.setItem(k, v); } catch { /* private mode */ } },
};

async function boot(root) {
  const config = JSON.parse(root.dataset.config || '{}');
  const api = createApi(config);
  let me;
  if (config.visitor) {
    me = visitorMe(config);
  } else {
    try {
      me = await api.me();
    } catch (e) {
      root.innerHTML = `<div class="madof-error">${esc(e.message)}</div>`;
      return;
    }
  }

  if (me.role === 'manager') {
    const saved = Number(store.get('madof-client')) || null;
    if (saved && me.clients.some((c) => c.id === saved)) api.setClient(saved);
  }

  const ctx = { api, me, config };
  root.innerHTML = shell(me, api);
  root.classList.add('is-ready');

  const $ = (sel) => root.querySelector(sel);
  const officeEl = $('.madof-office');
  const classicEl = $('.madof-classic');
  const toggle = $('[data-action="toggle-view"]');
  const dialog = createDialog(root, ctx, (open) => office?.setInputEnabled(!open));
  let office = null;
  let pollTimer = null;

  const prefersClassic = window.matchMedia('(max-width: 760px)').matches || window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  let view = store.get('madof-view') || (prefersClassic ? 'classic' : 'office');

  async function setView(next) {
    view = next;
    store.set('madof-view', next);
    officeEl.hidden = next !== 'office';
    classicEl.hidden = next !== 'classic';
    if (toggle) {
      toggle.innerHTML = next === 'office' ? `${icon('grid')}<span>Vista clásica</span>` : `${icon('game')}<span>Vista oficina</span>`;
      toggle.setAttribute('aria-pressed', String(next === 'office'));
    }
    if (next === 'office' && !office) {
      officeEl.querySelector('.madof-canvas').innerHTML = '<p class="madof-app-loading">Encendiendo las luces de la oficina…</p>';
      try {
        const { createOffice } = await import('./office/index.js');
        officeEl.querySelector('.madof-canvas').innerHTML = '';
        office = await createOffice(officeEl.querySelector('.madof-canvas'), {
          assets: config.assets, v: config.assets_v || config.version, me,
          onRoom: (room) => openRoom(room),
          onCharacter: (slug) => openCharacter(slug),
        });
      } catch (err) {
        console.error(err);
        officeEl.querySelector('.madof-canvas').innerHTML = '<p class="madof-error">No he podido cargar la oficina. Usa la vista clásica.</p>';
      }
    }
    schedulePoll();
  }

  function schedulePoll() {
    clearInterval(pollTimer);
    if (view !== 'office' || me.role === 'visitor') return;
    const tick = async () => {
      if (document.hidden || !office) return;
      try { office.setStates((await api.state()).characters); } catch { /* keep last state */ }
    };
    tick();
    pollTimer = setInterval(tick, POLL_MS);
  }

  function openRoom(room) {
    if (me.role === 'visitor') return dialog.open(room === 'recepcion' ? 'recepcion' : 'solo-clientes');
    if (me.role === 'team') return dialog.open(room === 'estudio' ? 'mis-tareas' : 'recepcion');
    if (room === 'gerencia' && me.role !== 'manager') return dialog.open('privado');
    dialog.open(ROOM_PANEL[room] || 'recepcion');
  }
  function openCharacter(slug) {
    if (me.role === 'visitor') return dialog.open(slug === 'recepcionista' ? 'recepcion' : 'solo-clientes');
    if (me.role === 'team') return dialog.open(slug === 'liz' ? 'mis-tareas' : 'recepcion');
    if (slug === 'jessica' && me.role !== 'manager') return dialog.open('privado');
    dialog.open(CHARACTER_PANEL[slug] || 'recepcion');
  }

  root.addEventListener('click', (e) => {
    const btn = e.target.closest('button[data-panel],a[data-panel],button[data-room],button[data-action]');
    if (!btn || !root.contains(btn)) return;
    if (btn.dataset.panel) dialog.open(btn.dataset.panel);
    else if (btn.dataset.room) openRoom(btn.dataset.room);
    else if (btn.dataset.action === 'toggle-view') setView(view === 'office' ? 'classic' : 'office');
    else if (btn.dataset.action === 'fullscreen') {
      const el = $('.madof-stage');
      if (document.fullscreenElement) document.exitFullscreen(); else el.requestFullscreen?.();
    }
  });

  const picker = $('#madof-client-picker');
  if (picker) {
    picker.addEventListener('change', () => {
      const id = Number(picker.value) || null;
      api.setClient(id);
      store.set('madof-client', id ? String(id) : null);
      $('.madof-who').textContent = id ? me.clients.find((c) => c.id === id).name : 'Gerencia';
    });
  }

  document.addEventListener('visibilitychange', () => { if (!document.hidden) schedulePoll(); });
  setView(view);
}

function visitorMe(config) {
  return {
    role: 'visitor', user: { name: '' }, client: null, clients: [],
    characters: config.characters || {}, rooms: config.rooms || {},
    chat: { enabled: false }, links: { login: config.login, privacy: config.privacy || null },
  };
}

// Back from the confirmation email (?madof=confirmado).
function notice() {
  const v = new URLSearchParams(window.location.search).get('madof');
  if (v === 'confirmado') return '<div class="madof-request is-ok madof-notice" role="status"><p class="madof-lead">¡Confirmado! Jessica ya tiene tu mensaje y te responderá pronto.</p></div>';
  if (v === 'enlace-no-valido') return '<div class="madof-request madof-notice" role="status"><p>Ese enlace no es válido o ya se usó. Si tu mensaje no llegó, vuelve a enviarlo.</p></div>';
  return '';
}

function shell(me, api) {
  const visitor = me.role === 'visitor';
  const who = visitor ? 'Recepción abierta' : me.role === 'client' ? me.client?.name : me.role === 'team' ? 'Equipo MAD' : (me.clients.find((c) => c.id === api.getClient())?.name || 'Gerencia');
  const rooms = Object.entries(me.rooms).filter(([slug]) => slug !== 'gerencia' || me.role === 'manager');
  return `
  <div class="madof-shell">
    <header class="madof-bar">
      <div class="madof-stamp" aria-label="MAD Oficina"><b>MAD</b><span>·</span>OFICINA</div>
      <div class="madof-who">${esc(who)}</div>
      ${me.role === 'manager' ? `<label class="madof-picker"><span class="madof-kicker">Ver como</span>
        <select id="madof-client-picker"><option value="">Elige cliente…</option>${me.clients.map((c) => `<option value="${c.id}" ${c.id === api.getClient() ? 'selected' : ''}>${esc(c.code)} · ${esc(c.name)}</option>`).join('')}</select></label>` : ''}
      <div class="madof-bar-actions">
        <button type="button" class="madof-btn is-ghost is-small" data-action="toggle-view" aria-pressed="false"></button>
        ${visitor ? `<a class="madof-btn is-small" href="${esc(me.links.login)}">Ya soy cliente: entrar</a>` : `<a class="madof-link" href="${esc(me.links.logout)}">Salir</a>`}
      </div>
    </header>
    <div class="madof-stage">
      <section class="madof-office" hidden aria-label="Oficina">
        <div class="madof-canvas"></div>
        <nav class="madof-rooms" aria-label="Salas de la oficina">
          ${rooms.map(([slug, label]) => `<button type="button" data-room="${slug}">${esc(label)}</button>`).join('')}
          <button type="button" class="madof-fs" data-action="fullscreen" aria-label="Pantalla completa">${icon('expand')}</button>
        </nav>
      </section>
      <section class="madof-classic" hidden aria-label="Recepción">
        <div class="madof-reception">
          ${notice()}
          <div class="madof-reception-head">
            <span class="madof-kicker">Recepción</span>
            <h2 class="madof-reception-title">${esc(greeting(me))}</h2>
          </div>
          ${menuButtons(me)}
        </div>
      </section>
    </div>
    <footer class="madof-foot">
      ${visitor
        ? `Solo guardamos lo que nos envíes, para responderte${me.links.privacy ? ` (<a href="${esc(me.links.privacy)}">política de privacidad</a>)` : ''}.`
        : `Las reuniones pueden grabarse y transcribirse para preparar los resúmenes y las tareas. Tratamos tus datos solo para gestionar tu proyecto${me.links.privacy ? ` (<a href="${esc(me.links.privacy)}">política de privacidad</a>)` : ''}.`}
    </footer>
  </div>
  <div class="madof-backdrop" hidden>
    <div class="madof-dialog" role="dialog" aria-modal="true" aria-labelledby="madof-dialog-title">
      <header class="madof-dialog-head">
        <div><span class="madof-kicker madof-dialog-kicker"></span><h2 id="madof-dialog-title"></h2></div>
        <button type="button" class="madof-close" aria-label="Cerrar">${icon('close')}</button>
      </header>
      <div class="madof-dialog-body"></div>
    </div>
  </div>`;
}

function createDialog(root, ctx, onToggle = () => {}) {
  const backdrop = root.querySelector('.madof-backdrop');
  const box = root.querySelector('.madof-dialog');
  const body = root.querySelector('.madof-dialog-body');
  const title = root.querySelector('#madof-dialog-title');
  const kicker = root.querySelector('.madof-dialog-kicker');
  let returnFocus = null;
  let token = 0;

  const close = () => { backdrop.hidden = true; body.innerHTML = ''; onToggle(false); returnFocus?.focus?.(); };
  root.querySelector('.madof-close').addEventListener('click', close);
  backdrop.addEventListener('click', (e) => { if (e.target === backdrop) close(); });
  backdrop.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') { e.preventDefault(); close(); }
    if (e.key === 'Tab') { // keep focus inside the dialog
      const f = [...box.querySelectorAll('button, a[href], textarea, input, select, iframe, [tabindex]:not([tabindex="-1"])')].filter((n) => !n.disabled && n.offsetParent !== null);
      if (!f.length) return;
      if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); }
      else if (!e.shiftKey && document.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
    }
  });

  async function open(name) {
    const panel = PANELS[name];
    if (!panel) return;
    const my = ++token;
    if (backdrop.hidden) returnFocus = document.activeElement;
    kicker.textContent = panel.kicker;
    title.textContent = typeof panel.title === 'function' ? panel.title(ctx) : panel.title;
    box.dataset.openPanel = name;
    backdrop.hidden = false;
    onToggle(true);
    if (panel.needsClient && ctx.me.role === 'manager' && !ctx.api.getClient()) {
      body.innerHTML = '<p class="madof-empty">Elige arriba un cliente para ver su oficina tal y como la ve él.</p>';
      root.querySelector('.madof-close').focus();
      return;
    }
    body.innerHTML = '<p class="madof-app-loading">Un momento…</p>';
    root.querySelector('.madof-close').focus();
    try {
      const html = await panel.render(ctx);
      if (my !== token) return;
      body.innerHTML = html;
      try { panel.mount?.(body, ctx); } catch (err) { console.error('[MAD Oficina]', err); }
    } catch (e) {
      if (my !== token) return;
      body.innerHTML = `<p class="madof-error">${esc(e.message)}</p>`;
    }
  }
  return { open, close };
}

// Self-contained start: does not rely on window.onload or on other scripts of
// the theme/plugins (their errors cannot stop this module).
function start() {
  const root = document.getElementById('mad-oficina');
  if (!root || root.dataset.booted) return;
  root.dataset.booted = '1';
  boot(root).catch((err) => {
    console.error('[MAD Oficina]', err);
    root.innerHTML = '<div class="madof-error">No se ha podido abrir la oficina. Recarga la página.</div>';
  });
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
else start();
