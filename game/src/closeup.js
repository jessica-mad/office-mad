// Close-up of a character (docs/oficina-v2.md, parte B): portrait, speech bubble with their
// greeting, and their table of actions. Every tile opens a panel that already exists
// (PANELS in panels.js) inside the same column (desktop) or sheet (phone).
import { icon } from './icons.js';
import { PANELS, esc, canSee, personName, SKELETON } from './panels.js';
import { sectionsOf, labelOf } from './sections.js';

const reduced = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

// One line under the featured action.
const SUMMARY = {
  proyecto: 'El estado de cada ámbito y la línea de tiempo.',
  reuniones: 'Fechas, resúmenes, grabaciones y acuerdos.',
  tareas: 'Lo que hace MAD y lo que te toca, con fechas.',
  ideas: 'Lo que salió en las reuniones y cuándo lo retomamos.',
  documentos: 'Tu carpeta de Drive, ordenada.',
  facturas: 'Facturas y presupuestos, con su estado.',
  reunion: 'Huecos reales en la agenda de Jessica.',
  encargo: 'Cuéntanos qué necesitas y lo preparamos.',
  chat: 'Pregúntame lo que quieras.',
  estudio: 'Los encargos de diseño y vídeo, y cómo van.',
  'mis-tareas': 'Tus encargos, con su brief y su fecha.',
  campanas: 'Un resumen claro de tus campañas.',
  bandeja: 'Lo que espera tu decisión.',
  'que-hacemos': 'Publicidad, diseño y marketing digital.',
  'primera-reunion': 'Elige un hueco para conocernos.',
  brief: 'Cuéntanos tu proyecto y te respondemos.',
};

/** The tiles of a character for this person, from the one list of sections (sections.js). */
export function actionsFor(me, slug) {
  const list = sectionsOf(me, slug, canSee);
  return list ? list.map((x) => ({ panel: x.id, label: x.label, icon: x.icon, soon: x.soon })) : null;
}

/** What the character says: the second greeting for visitors (receptionist) and for Jessica herself. */
export function greetingFor(me, slug) {
  const c = me.characters[slug] || {};
  const alt = (slug === 'recepcionista' && me.role === 'visitor') || (slug === 'jessica' && me.role === 'manager');
  const text = (alt && c.greeting_alt) || c.greeting || '';
  const name = personName(me);
  return name ? text.replace(/\{nombre\}/g, name) : text.replace(/,?\s*\{nombre\}/g, '').replace(/\{nombre\}/g, '');
}

const tabsOf = (actions) => {
  if (actions.length <= 9) return [{ label: '', items: actions }];
  const tabs = [];
  for (let i = 0; i < actions.length; i += 9) tabs.push({ label: `${tabs.length + 1}`, items: actions.slice(i, i + 9) });
  return tabs;
};

/**
 * @param host  element that holds the close-up (side column or sheet)
 * @param ctx   { api, me, config }
 * @param opts  { onBack(): void, waiting(): Promise<object>|null, onResolved(): void }
 */
export function createCloseup(host, ctx, opts = {}) {
  const { me } = ctx;
  let typing = null;
  let current = null;
  let returnFocus = null;
  let token = 0;

  function portraitUrl(slug) {
    const v = ctx.config.assets_v ? '?v=' + encodeURIComponent(ctx.config.assets_v) : '';
    return `${ctx.config.assets}portraits/${slug}.png${v}`;
  }

  function say(text) {
    const vis = host.querySelector('.madof-cu-text');
    host.querySelector('.madof-cu-live').textContent = text; // read once, whole, by screen readers
    clearInterval(typing);
    if (reduced()) { vis.textContent = text; return; }
    let i = 0;
    vis.textContent = '';
    typing = setInterval(() => { vis.textContent = text.slice(0, ++i); if (i >= text.length) clearInterval(typing); }, 28);
  }

  function tile(a, i, selected) {
    return `<button type="button" class="madof-cu-tile ${selected ? 'is-on' : ''}" data-cu-panel="${a.panel}" data-i="${i}">
      <span class="madof-cu-ic">${icon(a.icon)}</span><span>${esc(a.label)}</span>${a.soon ? '<span class="madof-tag">Pronto</span>' : ''}</button>`;
  }

  function feature(a) {
    if (!a) return '';
    return `<button type="button" class="madof-cu-feature" data-cu-panel="${a.panel}">
      <span class="madof-cu-feature-ic">${icon(a.icon)}</span>
      <span><span class="madof-kicker">${esc(a.label)}</span><span class="madof-cu-feature-sum">${esc(SUMMARY[a.panel] || '')}</span><span class="madof-cu-more">Abrir ›</span></span>
    </button>`;
  }

  /** Jessica sees what a waiting agent brings, with the same answers as in Chat. */
  function waitingCards(items) {
    if (!items?.length) return '';
    return `<div class="madof-cu-waiting" aria-label="Lo que te trae">
      <span class="madof-kicker">Te espera en la antesala</span>
      ${items.map((w) => `<article class="madof-cu-ask" data-approval="${w.id}" data-external="${w.external ? 1 : 0}">
        ${w.type ? `<span class="madof-tag">${esc(w.type)}</span>` : ''}
        <h3>${esc(w.title)}</h3>${w.summary ? `<p>${esc(w.summary)}</p>` : ''}
        <div class="madof-cu-ask-actions">
          <button type="button" class="madof-btn is-small" data-decide="approve">Aprobar tal cual</button>
          <button type="button" class="madof-btn is-small is-ghost" data-decide="reject">Descartar</button>
          <a class="madof-link" href="${esc(w.url)}">Revisar en la bandeja</a>
        </div>
        <p class="madof-cu-ask-msg" role="status"></p>
      </article>`).join('')}
    </div>`;
  }

  async function show(slug, { focus = true } = {}) {
    const c = me.characters[slug];
    if (!c) return;
    const my = ++token;
    if (focus) returnFocus = document.activeElement;
    current = { slug, actions: actionsFor(me, slug) };
    const actions = current.actions;
    const tabs = actions ? tabsOf(actions) : [];
    const roomLabel = me.rooms?.[c.room] || '';
    const locked = !actions;
    host.innerHTML = `
      <section class="madof-cu" aria-label="${esc(`${c.name}, ${c.role}`)}" data-slug="${esc(slug)}">
        <div class="madof-cu-look">
          <div class="madof-cu-top">
            <button type="button" class="madof-cu-back madof-cu-home" data-cu-back>‹ Oficina</button>
            <span class="madof-kicker">${esc(roomLabel)}</span>
          </div>
          <div class="madof-cu-portrait" style="background-image:url('${esc(portraitUrl(slug))}')" role="img" aria-label="${esc(`${c.name} en ${roomLabel}`)}"></div>
          <div class="madof-cu-bubble">
            <span class="madof-kicker">${esc(c.name)} · ${esc(c.role)} ${c.ai ? '<span class="madof-cu-ai">IA</span>' : ''}</span>
            <p class="madof-cu-text" aria-hidden="true"></p>
            <p class="madof-cu-live screen-reader-text" aria-live="polite"></p>
          </div>
        </div>
        <div class="madof-cu-table">
          <div class="madof-cu-wait-slot"></div>
          ${locked ? `<div class="madof-cu-locked"><p>${me.role === 'team' ? 'Aquí atiende a los clientes. Tus encargos están en el estudio de Liz.' : 'Esta parte de la oficina es solo para clientes. En recepción te ayudamos con lo que necesites.'}</p>
              <button type="button" class="madof-btn is-small" data-cu-go="${me.role === 'team' ? 'liz' : 'recepcionista'}">${me.role === 'team' ? 'Ir al estudio' : 'Ir a recepción'}</button></div>`
            : `${tabs.length > 1 ? `<div class="madof-cu-tabs" role="tablist">${tabs.map((t, i) => `<button type="button" role="tab" class="madof-pill ${i ? '' : 'is-on'}" aria-selected="${!i}" data-cu-tab="${i}">${esc(t.label)}</button>`).join('')}</div>` : ''}
              <div class="madof-cu-feature-slot">${feature(actions[0])}</div>
              <div class="madof-cu-grid">${(tabs[0]?.items || []).map((a, i) => tile(a, i, i === 0)).join('')}</div>
              ${!actions.length ? '<p class="madof-empty">Esta parte no está abierta para tu usuario. Si la necesitas, pídeselo a Jessica.</p>' : ''}`}
        </div>
        <div class="madof-cu-panel" hidden>
          <nav class="madof-crumbs" aria-label="Estás en">
            <button type="button" class="madof-cu-back" data-cu-close-panel>‹ ${esc(roomLabel || c.name)}</button>
            <span aria-hidden="true">›</span> <span class="madof-crumbs-here" aria-current="page"></span>
          </nav>
          <h2 class="madof-cu-panel-title" tabindex="-1"></h2>
          <div class="madof-cu-panel-body"></div>
        </div>
      </section>`;
    say(greetingFor(me, slug) || `Hola, soy ${c.name}.`);
    if (focus) host.querySelector(locked ? '[data-cu-go]' : '.madof-cu-feature, .madof-cu-tile')?.focus({ preventScroll: true });

    if (me.role === 'manager' && opts.waiting) {
      try {
        const all = await opts.waiting();
        if (my !== token) return;
        const slot = host.querySelector('.madof-cu-wait-slot');
        if (slot) slot.innerHTML = waitingCards(all?.[slug]);
      } catch { /* the close-up works without it */ }
    }
  }

  async function openPanel(name) {
    const panel = PANELS[name];
    if (!panel) return;
    const sec = host.querySelector('.madof-cu');
    const box = host.querySelector('.madof-cu-panel');
    const body = box.querySelector('.madof-cu-panel-body');
    box.querySelector('.madof-crumbs-here').textContent = labelOf(name) || panel.kicker;
    box.querySelector('.madof-cu-panel-title').textContent = typeof panel.title === 'function' ? panel.title(ctx) : panel.title;
    sec.classList.add('is-panel');
    box.hidden = false;
    box.querySelector('.madof-cu-panel-title').focus({ preventScroll: true });
    host.scrollTop = 0; // the column (or the sheet) scrolls, never the page
    const my = ++token;
    if (panel.needsClient && me.role === 'manager' && !ctx.api.getClient()) { body.innerHTML = '<p class="madof-empty">Elige arriba un cliente para ver su oficina tal y como la ve él.</p>'; return; }
    if (!canSee(me, name)) { body.innerHTML = '<p class="madof-empty">Esta parte de la oficina no está abierta para tu usuario. Si la necesitas, pídeselo a Jessica.</p>'; return; }
    body.innerHTML = SKELETON;
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

  function closePanel() {
    const sec = host.querySelector('.madof-cu');
    const box = host.querySelector('.madof-cu-panel');
    if (!box || box.hidden) return false;
    token++;
    box.hidden = true;
    box.querySelector('.madof-cu-panel-body').innerHTML = '';
    sec.classList.remove('is-panel');
    host.querySelector(`[data-cu-panel="${sec.dataset.lastPanel}"]`)?.focus({ preventScroll: true });
    return true;
  }

  async function decide(card, decision) {
    if (decision === 'approve' && card.dataset.external === '1' && !window.confirm('Al aprobarlo, sale de la oficina (un email, una factura, un evento…). ¿Seguimos?')) return;
    const msg = card.querySelector('.madof-cu-ask-msg');
    card.querySelectorAll('button').forEach((b) => { b.disabled = true; });
    try {
      const res = await ctx.api.resolveApproval(Number(card.dataset.approval), decision);
      msg.textContent = res.message || 'Hecho.';
      card.classList.add('is-done');
      opts.onResolved?.();
    } catch (e) {
      msg.textContent = e.message;
      card.querySelectorAll('button').forEach((b) => { b.disabled = false; });
    }
  }

  host.addEventListener('click', (e) => {
    const t = e.target.closest('[data-cu-panel],[data-cu-back],[data-cu-close-panel],[data-cu-tab],[data-cu-go],[data-decide]');
    if (!t || !host.contains(t)) return;
    if (t.dataset.cuPanel) { host.querySelector('.madof-cu').dataset.lastPanel = t.dataset.cuPanel; openPanel(t.dataset.cuPanel); }
    else if (t.hasAttribute('data-cu-close-panel')) closePanel();
    else if (t.hasAttribute('data-cu-back')) opts.onBack?.();
    else if (t.dataset.cuGo) opts.onGo?.(t.dataset.cuGo);
    else if (t.dataset.decide) decide(t.closest('[data-approval]'), t.dataset.decide);
    else if (t.dataset.cuTab) {
      const i = Number(t.dataset.cuTab);
      const items = tabsOf(current.actions)[i].items;
      host.querySelectorAll('[data-cu-tab]').forEach((b) => { const on = b === t; b.classList.toggle('is-on', on); b.setAttribute('aria-selected', String(on)); });
      host.querySelector('.madof-cu-grid').innerHTML = items.map((a, k) => tile(a, k, k === 0)).join('');
      host.querySelector('.madof-cu-feature-slot').innerHTML = feature(items[0]);
    }
  });
  // The featured card follows the tile under the pointer or the keyboard focus.
  const preview = (e) => {
    const t = e.target.closest?.('.madof-cu-tile');
    if (!t || !current?.actions) return;
    const a = current.actions.find((x) => x.panel === t.dataset.cuPanel);
    host.querySelectorAll('.madof-cu-tile').forEach((b) => b.classList.toggle('is-on', b === t));
    host.querySelector('.madof-cu-feature-slot').innerHTML = feature(a);
  };
  host.addEventListener('pointerover', preview);
  host.addEventListener('focusin', preview);
  host.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    if (closePanel()) e.preventDefault();
    else { e.preventDefault(); opts.onBack?.(); }
  });

  return {
    show,
    get slug() { return current?.slug || null; },
    returnFocus: () => returnFocus,
    clear() { clearInterval(typing); token++; host.innerHTML = ''; current = null; },
  };
}
