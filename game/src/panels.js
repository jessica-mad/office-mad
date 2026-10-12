// HTML panels shown over the office (or over the classic view). All text that
// comes from the server is escaped with esc().
import { icon } from './icons.js';

export const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

const fmt = new Intl.DateTimeFormat('es-ES', { day: 'numeric', month: 'short', year: 'numeric' });
const fmtMonth = new Intl.DateTimeFormat('es-ES', { month: 'long', year: 'numeric' });
const parseDate = (s) => {
  if (!s) return null;
  const [d, t] = String(s).split(' ');
  const [y, m, day] = d.split('-').map(Number);
  return t ? new Date(Date.UTC(y, m - 1, day, ...t.split(':').map(Number))) : new Date(y, m - 1, day);
};
export const date = (s) => { const d = parseDate(s); return d ? fmt.format(d) : ''; };
const month = (s) => { const d = parseDate(s); return d ? fmtMonth.format(d) : ''; };

const PROCESS_STATUS = { planned: 'Planificado', in_progress: 'En marcha', done: 'Hecho', paused: 'En pausa' };
const TASK_STATUS = { open: 'Pendiente', in_progress: 'En marcha', done: 'Hecho', blocked: 'Bloqueada' };

// Long task details stay folded so lists remain scannable.
const detail = (text) => !text ? '' : text.length <= 90
  ? `<p>${esc(text)}</p>`
  : `<details class="madof-more"><summary>Ver detalle</summary><p>${esc(text)}</p></details>`;

const empty = (text) => `<p class="madof-empty">${esc(text)}</p>`;
const pill = (text, mod = '') => `<span class="madof-pill ${mod}">${esc(text)}</span>`;
const slotList = (slots, readonly = false) => slots.length
  ? slots.map((s) => readonly ? `<p class="madof-slot">${esc(s.label)}</p>` : `<button type="button" class="madof-slot" role="radio" aria-checked="false" data-start="${Number(s.start)}">${esc(s.label)}</button>`).join('')
  : '<p class="madof-meta">No quedan huecos libres en los próximos días. Escríbele a Jessica y lo buscáis juntas.</p>';
const soon = (text) => `<div class="madof-soon">${pill('Pronto', 'is-on')}<p>${esc(text)}</p></div>`;

/* ───────────── menu (reception quick buttons) ───────────── */
export const MENU = [
  { panel: 'proyecto', label: 'Cómo va mi proyecto', icon: 'timeline' },
  { panel: 'reuniones', label: 'Mis reuniones', icon: 'calendar' },
  { panel: 'tareas', label: 'Tareas en compromiso', icon: 'check' },
  { panel: 'ideas', label: 'Ideas para el futuro', icon: 'bulb' },
  { panel: 'documentos', label: 'Mis documentos', icon: 'folder' },
  { panel: 'facturas', label: 'Mis facturas y presupuestos', icon: 'receipt', soon: true },
  { panel: 'campanas', label: 'Mis campañas', icon: 'megaphone', soon: true },
  { panel: 'reunion', label: 'Pedir una reunión', icon: 'plus' },
  { panel: 'encargo', label: 'Nuevo encargo', icon: 'pencil' },
  { panel: 'chat', label: 'Escribir libremente', icon: 'chat', chat: true },
];

export const VISITOR_MENU = [
  { panel: 'que-hacemos', label: 'Qué hacemos', icon: 'chat' },
  { panel: 'primera-reunion', label: 'Pedir una primera reunión', icon: 'calendar' },
  { panel: 'brief', label: 'Dejar un brief', icon: 'pencil' },
];

export function menuButtons(me, { compact = false } = {}) {
  const items = me.role === 'visitor' ? VISITOR_MENU
    : me.role === 'team'
    ? [{ panel: 'mis-tareas', label: 'Mis encargos', icon: 'check' }]
    : MENU.map((m) => (m.panel === 'encargo' ? { ...m, soon: !me.brief } : m))
      .filter((m) => !m.chat || me.chat.enabled).concat(me.role === 'manager' ? [{ panel: 'bandeja', label: 'Bandeja de gerencia', icon: 'inbox' }] : []);
  return `<div class="madof-menu ${compact ? 'is-compact' : ''}">${items.map((m) => `
    <button type="button" class="madof-menu-btn" data-panel="${m.panel}">
      <span class="madof-menu-icon">${icon(m.icon)}</span><span class="madof-menu-label">${esc(m.label)}${m.soon ? '<span class="madof-tag">Pronto</span>' : ''}</span>
    </button>`).join('')}</div>`;
}

export function greeting(me) {
  const r = me.characters.recepcionista;
  const who = me.user.name ? `, ${me.user.name}` : '';
  if (me.role === 'team') return `¡Hola${who}! Soy ${r.name}. Aquí tienes tus encargos.`;
  if (me.role === 'visitor') return `¡Hola! Soy ${r.name}, de la recepción de MAD. ¿En qué te puedo ayudar?`;
  return `¡Hola${who}! Soy ${r.name}, de recepción. ¿Qué necesitas hoy?`;
}

/* ───────────── brief form (visitors and «Nuevo encargo») ───────────── */
function briefForm(ctx, { visitor = false, preview = false } = {}) {
  const o = (visitor ? ctx.config.brief : ctx.me.brief) || { services: {}, timelines: {}, budgets: {} };
  const opts = (map, sel) => Object.entries(map).map(([k, l]) => `<option value="${esc(k)}" ${k === sel ? 'selected' : ''}>${esc(l)}</option>`).join('');
  return `
  <form class="madof-brief" novalidate>
    ${visitor ? `<p class="madof-lead">Cuéntanos qué tienes entre manos. Jessica lo lee personalmente y te responde en uno o dos días laborables.</p>` : '<p class="madof-lead">¿Qué quieres que hagamos? Jessica lo revisa y te propone los siguientes pasos.</p>'}
    ${visitor ? `<div class="madof-fields">
      <label>Tu nombre <input name="name" autocomplete="name" required maxlength="120"></label>
      <label>Email <input name="email" type="email" autocomplete="email" required maxlength="190"></label>
      <label>Empresa o proyecto <span class="madof-meta">(opcional)</span><input name="company" autocomplete="organization" maxlength="160"></label>
      <label>Web <span class="madof-meta">(opcional)</span><input name="website" inputmode="url" placeholder="tuweb.com" maxlength="250"></label>
    </div>` : ''}
    <fieldset class="madof-services"><legend>¿Qué necesitas?</legend>
      ${Object.entries(o.services).map(([k, l]) => `<label class="madof-chip"><input type="checkbox" name="services" value="${esc(k)}"><span>${esc(l)}</span></label>`).join('')}
    </fieldset>
    <label>Cuéntanos el proyecto <textarea name="message" rows="5" required minlength="10" maxlength="4000" placeholder="Qué quieres conseguir, para quién, qué tienes ya hecho…"></textarea></label>
    <div class="madof-fields">
      <label>Plazo aproximado <select name="timeline">${opts(o.timelines, 'unknown')}</select></label>
      <label>Presupuesto orientativo <span class="madof-meta">(opcional)</span><select name="budget">${opts(o.budgets, '')}</select></label>
    </div>
    ${visitor ? consentField(ctx) : ''}
    <p class="madof-meta" aria-live="polite" data-status></p>
    <button class="madof-btn" type="submit" ${preview ? 'disabled' : ''}>${icon('send')} Enviar</button>
  </form>`;
}

let turnstileScript = null;
function loadTurnstile() {
  if (window.turnstile) return Promise.resolve(window.turnstile);
  turnstileScript ??= new Promise((resolve, reject) => {
    const s = document.createElement('script');
    s.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
    s.async = true;
    s.onload = () => resolve(window.turnstile);
    s.onerror = () => { turnstileScript = null; reject(new Error('Turnstile')); };
    document.head.appendChild(s);
  });
  return turnstileScript;
}

/** Renders Turnstile in `.madof-turnstile` (if any). @returns {{needed:boolean, token():string, reset():void}} */
function mountTurnstile(form, ctx, status) {
  const box = form.querySelector('.madof-turnstile');
  let token = '';
  let widget = null;
  if (box) {
    loadTurnstile()
      .then((ts) => { widget = ts.render(box, { sitekey: ctx.config.turnstile, language: 'es', callback: (t) => { token = t; }, 'expired-callback': () => { token = ''; } }); })
      .catch(() => { status.textContent = 'No se ha podido cargar la comprobación anti-robots. Recarga la página.'; });
  }
  return {
    needed: !!box,
    token: () => token,
    reset: () => { if (widget !== null) { window.turnstile?.reset(widget); token = ''; } },
  };
}

const consentField = (ctx) => {
  const privacy = ctx.config.privacy || ctx.me.links?.privacy;
  return `<div class="madof-hp" aria-hidden="true"><label>Fax <input name="fax" tabindex="-1" autocomplete="off"></label></div>
    <label class="madof-consent"><input type="checkbox" name="consent" value="1" required>
      <span>Acepto que MAD guarde estos datos para responderme${privacy ? ` (<a href="${esc(privacy)}" target="_blank" rel="noopener">política de privacidad</a>)` : ''}.</span></label>
    ${ctx.config.turnstile ? '<div class="madof-turnstile"></div>' : ''}`;
};

function mountBrief(el, ctx, visitor) {
  const form = el.querySelector('form.madof-brief');
  if (!form) return;
  const status = form.querySelector('[data-status]');
  const button = form.querySelector('button[type=submit]');
  const ts = mountTurnstile(form, ctx, status);
  form.addEventListener('input', () => { if (!button.disabled) status.textContent = ''; });
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const f = new FormData(form);
    const data = Object.fromEntries([...f.entries()].filter(([k]) => k !== 'services'));
    data.services = f.getAll('services');
    if (visitor) {
      if (!data.name?.trim()) { status.textContent = 'Dinos tu nombre.'; return form.elements.name.focus(); }
      if (!form.elements.email.checkValidity() || !data.email) { status.textContent = 'Revisa el email.'; return form.elements.email.focus(); }
      if (!data.consent) { status.textContent = 'Marca la casilla de consentimiento para poder enviarlo.'; return form.elements.consent.focus(); }
      if (ts.needed && !ts.token()) { status.textContent = 'Espera un segundo a la comprobación anti-robots.'; return; }
      data.turnstile = ts.token();
      data.return = window.location.href.split('#')[0];
    }
    if ((data.message || '').trim().length < 10) { status.textContent = 'Cuéntanos un poco más del proyecto.'; return form.elements.message.focus(); }
    button.disabled = true; status.textContent = 'Enviando…';
    try {
      const res = await (visitor ? ctx.api.visitorBrief(data) : ctx.api.clientBrief(data));
      el.innerHTML = res.verify
        ? `<div class="madof-request is-ok">${pill('Casi listo', 'is-on')}<p class="madof-lead" tabindex="-1">Te hemos enviado un email a <strong>${esc(data.email)}</strong>.</p><p>Abre el enlace para confirmar que es tuyo y tu mensaje llegará a Jessica. Si no lo ves, mira en spam.</p></div>`
        : `<div class="madof-request is-ok">${pill('Enviado', 'is-on')}<p class="madof-lead" tabindex="-1">¡Gracias! Jessica ya tiene tu encargo.</p><p>Lo revisa y te propone los siguientes pasos.</p></div>`;
      el.querySelector('.madof-lead')?.focus();
    } catch (err) {
      status.textContent = err.message;
      button.disabled = false;
      ts.reset();
    }
  });
}

/* ───────────── «Qué hacemos»: visitor chat ───────────── */
const nextSteps = () => `<p class="madof-actions-row">
  <button type="button" class="madof-btn" data-panel="primera-reunion">${icon('calendar')} Pedir una primera reunión</button>
  <button type="button" class="madof-btn is-ghost" data-panel="brief">${icon('pencil')} Dejar un brief</button></p>`;

const VCHAT_KEY = 'madof-visitor-chat';
const vstore = {
  get() { try { return JSON.parse(window.sessionStorage.getItem(VCHAT_KEY) || 'null'); } catch { return null; } },
  set(v) { try { window.sessionStorage.setItem(VCHAT_KEY, JSON.stringify(v)); } catch { /* private mode */ } },
};

function visitorChatHtml(ctx) {
  return `
    <p class="madof-meta">Pregúntame qué hacemos en MAD y cómo trabajamos. No te pediré datos personales: si quieres seguir, te paso a la reunión o al brief.</p>
    <ol class="madof-chat-log" aria-live="polite" aria-label="Conversación"></ol>
    <div class="madof-chat-next" hidden>${nextSteps()}</div>
    <form class="madof-chat-form">
      <div class="madof-hp" aria-hidden="true"><label>Fax <input name="fax" tabindex="-1" autocomplete="off"></label></div>
      <label class="screen-reader-text" for="madof-vchat-input">Tu mensaje</label>
      <textarea id="madof-vchat-input" rows="2" maxlength="600" placeholder="Escribe tu pregunta…" required></textarea>
      <button class="madof-btn" type="submit">${icon('send')} Enviar</button>
    </form>
    ${ctx.config.turnstile ? '<div class="madof-turnstile"></div>' : ''}`;
}

function mountVisitorChat(el, ctx) {
  const log = el.querySelector('.madof-chat-log');
  const form = el.querySelector('form');
  const input = form.querySelector('textarea');
  const next = el.querySelector('.madof-chat-next');
  const name = ctx.me.characters.recepcionista?.name || 'Recepción';
  const state = vstore.get() || { token: null, log: [] };
  const ts = state.token ? { needed: false, token: () => '', reset() {} } : mountTurnstile(el, ctx, { set textContent(t) { add('agent', t); } });

  function add(from, text, save = true) {
    const li = document.createElement('li');
    li.className = 'is-' + from;
    li.innerHTML = `<span class="madof-kicker">${from === 'user' ? 'Tú' : esc(name)}</span><p>${esc(text)}</p>`;
    log.appendChild(li); log.scrollTop = log.scrollHeight;
    if (save && from !== 'typing') { state.log.push([from, text]); state.log = state.log.slice(-30); vstore.set(state); }
    return li;
  }
  if (!state.log.length) add('agent', `¡Hola! Soy ${name}, de la recepción de MAD. ¿Qué te gustaría saber de lo que hacemos?`, false);
  state.log.forEach(([f, t]) => add(f, t, false));

  input.addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit(); } });
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const text = input.value.trim();
    if (!text) return;
    if (!state.token && ts.needed && !ts.token()) { add('agent', 'Un segundo, estoy comprobando que no eres un robot. Vuelve a enviarlo en un momento.', false); return; }
    input.value = ''; add('user', text);
    const typing = add('agent', `${name} está escribiendo…`, false); typing.classList.add('is-typing');
    form.querySelector('button').disabled = true;
    try {
      const res = await ctx.api.visitorChat({ message: text, token: state.token, turnstile: ts.token(), fax: form.elements.fax.value });
      typing.remove();
      state.token = res.token; vstore.set(state);
      add('agent', res.reply);
      if (res.suggest !== 'none') next.hidden = false;
      if (res.remaining === 0) { form.hidden = true; next.hidden = false; }
    } catch (err) {
      typing.remove(); add('agent', err.message, false);
      if (err.fallback) { form.hidden = true; next.hidden = false; }
      ts.reset();
    } finally {
      form.querySelector('button').disabled = false; if (!form.hidden) input.focus();
    }
  });
  input.focus();
}

/* ───────────── first meeting (visitors) ───────────── */
async function firstMeetingForm(ctx) {
  const data = await ctx.api.publicSlots();
  if (data.error || !data.slots.length) {
    return `<p class="madof-lead">${esc(data.error || 'Ahora mismo no hay huecos libres en los próximos días.')}</p>
      <p><button type="button" class="madof-btn" data-panel="brief">${icon('pencil')} Dejar un brief</button></p>`;
  }
  return `
  <form class="madof-brief madof-first-meeting" novalidate>
    <p class="madof-lead">Una primera charla de ${Number(data.duration) || 30} minutos por Google Meet con Jessica, para conocernos y ver cómo podemos ayudarte. Elige un hueco:</p>
    <div class="madof-slots" role="radiogroup" aria-label="Huecos libres">${slotList(data.slots)}</div>
    <div class="madof-fields">
      <label>Tu nombre <input name="name" autocomplete="name" required maxlength="120"></label>
      <label>Email <span class="madof-meta">(te llegará la invitación)</span><input name="email" type="email" autocomplete="email" required maxlength="190"></label>
      <label>Empresa o proyecto <span class="madof-meta">(opcional)</span><input name="company" autocomplete="organization" maxlength="160"></label>
    </div>
    <label>¿De qué te gustaría hablar? <span class="madof-meta">(opcional)</span><textarea name="topic" rows="3" maxlength="1000"></textarea></label>
    ${consentField(ctx)}
    <p class="madof-meta" aria-live="polite" data-status></p>
    <button class="madof-btn" type="submit">${icon('check')} Pedir la reunión</button>
    <p class="madof-meta">¿Prefieres contarnos el proyecto por escrito? <button type="button" class="madof-link" data-panel="brief">Deja un brief</button>.</p>
  </form>`;
}

function mountFirstMeeting(el, ctx) {
  const form = el.querySelector('form.madof-first-meeting');
  if (!form) return;
  const status = form.querySelector('[data-status]');
  const button = form.querySelector('button[type=submit]');
  const slots = form.querySelector('.madof-slots');
  const ts = mountTurnstile(form, ctx, status);
  let chosen = null;
  slots.addEventListener('click', (e) => {
    const b = e.target.closest('button[data-start]');
    if (!b) return;
    slots.querySelectorAll('button').forEach((x) => x.setAttribute('aria-checked', String(x === b)));
    chosen = Number(b.dataset.start);
    status.textContent = `Has elegido: ${b.textContent.trim()}`;
  });
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const data = Object.fromEntries(new FormData(form).entries());
    if (!chosen) { status.textContent = 'Primero elige uno de los huecos.'; return slots.querySelector('button')?.focus(); }
    if (!data.name?.trim()) { status.textContent = 'Dinos tu nombre.'; return form.elements.name.focus(); }
    if (!form.elements.email.checkValidity() || !data.email) { status.textContent = 'Revisa el email: ahí te llegará la invitación.'; return form.elements.email.focus(); }
    if (!data.consent) { status.textContent = 'Marca la casilla de consentimiento para poder enviarlo.'; return form.elements.consent.focus(); }
    if (ts.needed && !ts.token()) { status.textContent = 'Espera un segundo a la comprobación anti-robots.'; return; }
    Object.assign(data, { start: chosen, turnstile: ts.token(), return: window.location.href.split('#')[0].split('?')[0] });
    button.disabled = true; status.textContent = 'Enviando…';
    try {
      const res = await ctx.api.visitorMeeting(data);
      el.innerHTML = `<div class="madof-request is-ok">${pill('Casi listo', 'is-on')}<p class="madof-lead" tabindex="-1">Te hemos enviado un email a <strong>${esc(data.email)}</strong>.</p>
        <p>Abre el enlace para confirmar tu petición${res.label ? ` para el <strong>${esc(res.label)}</strong>` : ''}. Cuando Jessica la apruebe, te llegará la invitación de Google Calendar con el enlace de Meet. Si no ves el email, mira en spam.</p></div>`;
      el.querySelector('.madof-lead')?.focus();
    } catch (err) {
      status.textContent = err.message;
      button.disabled = false;
      ts.reset();
    }
  });
}

/* ───────────── panels ───────────── */
// Each panel: { kicker, title, needsClient, render(ctx) → html string | Promise<html>, mount?(el, ctx) }
export const PANELS = {
  recepcion: {
    kicker: 'Recepción', title: (ctx) => ctx.me.characters.recepcionista.name,
    render: (ctx) => `<p class="madof-lead">${esc(greeting(ctx.me))}</p>${menuButtons(ctx.me, { compact: true })}`,
  },

  proyecto: {
    kicker: 'Sala de proyectos', title: 'Cómo va tu proyecto', needsClient: true,
    async render({ api }) {
      const areas = await api.section('timeline');
      if (!areas.length) return empty('Todavía no hay procesos en marcha. En cuanto arranquemos, los verás aquí.');
      return areas.map((a) => `
        <section class="madof-area">
          <h3 class="madof-kicker">${esc(a.label)}</h3>
          <ol class="madof-timeline">${a.items.map((i) => `
            <li class="is-${esc(i.status)}">
              <div class="madof-timeline-head"><strong>${esc(i.title)}</strong>${pill(PROCESS_STATUS[i.status] || i.status, i.status === 'in_progress' ? 'is-on' : '')}</div>
              <div class="madof-meta">${esc([date(i.starts_at), date(i.ends_at)].filter(Boolean).join(' → '))}</div>
              ${i.description ? `<p>${esc(i.description)}</p>` : ''}
            </li>`).join('')}
          </ol>
        </section>`).join('');
    },
  },

  reuniones: {
    kicker: 'Sala de reuniones', title: 'Tus reuniones', needsClient: true,
    async render({ api }) {
      const list = await api.section('meetings');
      if (!list.length) return empty('Aún no hay reuniones con resumen. Después de cada reunión, aquí tendrás lo que hablamos.');
      return list.map((m) => `
        <article class="madof-item">
          <div class="madof-date">${esc(date(m.starts_at))}</div>
          <h3>${esc(m.title)}</h3>
          ${m.summary ? `<p>${esc(m.summary)}</p>` : ''}
          ${m.agreements.length ? `<h4 class="madof-kicker">Acuerdos</h4><ul>${m.agreements.map((a) => `<li>${esc(a)}</li>`).join('')}</ul>` : ''}
          <div class="madof-actions">
            ${m.recording ? `<a class="madof-btn is-ghost" href="${esc(m.recording)}" target="_blank" rel="noopener">${icon('play')} Ver grabación</a>` : ''}
            ${m.next_meeting ? `<span class="madof-meta">Próxima reunión: <strong>${esc(/^\d{4}-\d{2}-\d{2}/.test(m.next_meeting) ? date(m.next_meeting) : m.next_meeting)}</strong></span>` : ''}
          </div>
        </article>`).join('');
    },
  },

  tareas: {
    kicker: 'Sala de proyectos', title: 'Tareas en compromiso', needsClient: true,
    async render({ api }) {
      const t = await api.section('tasks');
      const list = (items, none) => items.length ? `<ul class="madof-tasks">${items.map((i) => `
        <li class="${i.status === 'done' ? 'is-done' : ''} ${i.overdue ? 'is-overdue' : ''}">
          <span class="madof-check">${i.status === 'done' ? icon('check', 'Hecha') : ''}</span>
          <div><strong>${esc(i.title)}</strong>
            <div class="madof-meta">${esc(i.owner)}${i.due_date ? ` · ${esc(date(i.due_date))}` : ''} · ${esc(TASK_STATUS[i.status] || i.status)}${i.overdue ? ' · <b>con retraso</b>' : ''}</div>
            ${detail(i.description)}</div>
        </li>`).join('')}</ul>` : empty(none);
      return `<div class="madof-cols2">
        <section><h3 class="madof-kicker">Te toca a ti</h3>${list(t.client, 'Nada pendiente por tu parte. ¡Bien!')}</section>
        <section><h3 class="madof-kicker">Lo hace MAD</h3>${list(t.mad, 'Ahora mismo no tenemos tareas abiertas.')}</section>
      </div>`;
    },
  },

  ideas: {
    kicker: 'Sala de proyectos', title: 'Ideas para el futuro', needsClient: true,
    async render({ api }) {
      const ideas = await api.section('ideas');
      if (!ideas.length) return empty('Las ideas que salgan en las reuniones se guardarán aquí, con la fecha en que las retomaremos.');
      return `<div class="madof-grid-cards">${ideas.map((i) => `
        <article class="madof-idea">${icon('bulb')}<h3>${esc(i.title)}</h3>
          ${i.description ? `<p>${esc(i.description)}</p>` : ''}
          ${i.resurface_at ? `<div class="madof-meta">La retomamos en <strong>${esc(month(i.resurface_at))}</strong></div>` : ''}
        </article>`).join('')}</div>`;
    },
  },

  documentos: {
    kicker: 'Archivo', title: 'Tus documentos', needsClient: true,
    async render({ api }) {
      const { folder } = await api.section('documents');
      if (!folder) return empty('Todavía no hemos enlazado tu carpeta de Drive. Pídeselo a Jessica.');
      return `<p>Tus documentos viven en una carpeta compartida de Google Drive. Para verla tienes que entrar con la cuenta de Google con la que la compartimos.</p>
        <p><a class="madof-btn" href="${esc(folder.url)}" target="_blank" rel="noopener">${icon('folder')} Abrir en Google Drive</a></p>
        <iframe class="madof-drive" src="${esc(folder.embed)}" title="Carpeta de documentos en Google Drive" loading="lazy"></iframe>`;
    },
  },

  estudio: {
    kicker: 'Estudio creativo', title: (ctx) => `Lo que prepara ${ctx.me.characters.liz.name}`, needsClient: true,
    async render({ api, me }) {
      const t = await api.section('tasks');
      const liz = t.mad.filter((i) => i.owner === me.characters.liz.name || i.owner === 'Liz');
      if (!liz.length) return empty(`${me.characters.liz.name} no tiene encargos tuyos abiertos ahora mismo.`);
      return `<ul class="madof-tasks">${liz.map((i) => `<li><span class="madof-check"></span><div><strong>${esc(i.title)}</strong>
        <div class="madof-meta">${i.due_date ? esc(date(i.due_date)) + ' · ' : ''}${esc(TASK_STATUS[i.status] || i.status)}</div>${detail(i.description)}</div></li>`).join('')}</ul>`;
    },
  },

  'mis-tareas': {
    kicker: 'Estudio creativo', title: 'Tus encargos',
    async render({ api }) {
      const items = await api.teamTasks();
      if (!items.length) return empty('No tienes encargos abiertos. ¡A disfrutar!');
      return `<ul class="madof-tasks">${items.map((i) => `<li class="${i.due_date && i.due_date < new Date().toISOString().slice(0, 10) ? 'is-overdue' : ''}">
        <span class="madof-check"></span><div><strong>${esc(i.title)}</strong>
        <div class="madof-meta">${i.client ? `<span class="madof-code">${esc(i.client.code)}</span> ${esc(i.client.name)} · ` : ''}${i.due_date ? esc(date(i.due_date)) : 'sin fecha'}</div>
        ${detail(i.description)}</div></li>`).join('')}</ul>`;
    },
  },

  facturas: { kicker: 'Administración', title: 'Facturas y presupuestos', render: () => soon('Muy pronto verás aquí tus facturas y presupuestos, con su estado (pendiente o pagada).') },
  campanas: { kicker: 'Sala de campañas', title: 'Tus campañas', render: () => soon('Estamos preparando un resumen claro de tus campañas de Google y Meta.') },
  reunion: {
    kicker: 'Recepción', title: 'Pedir una reunión', needsClient: true,
    async render({ api }) {
      let data;
      try { data = await api.meetingRequests(); } catch (e) {
        if (e.status === 404) return soon('Pronto podrás elegir un hueco libre de la agenda de Jessica desde aquí. Mientras tanto, escríbele directamente.');
        throw e;
      }
      const r = data.request;
      if (r && (r.status === 'pending' || r.status === 'processing')) {
        return `<div class="madof-request is-pending">${pill('Pendiente', 'is-on')}
          <p class="madof-lead">Has pedido reunión para el <strong>${esc(r.label)}</strong>.</p>
          <p>Jessica la confirma en cuanto la vea y te llegará la invitación de Google Calendar con el enlace de Meet.</p></div>`;
      }
      let last = '';
      if (r && r.status === 'approved') {
        last = `<div class="madof-request is-ok">${pill('Confirmada', 'is-on')}<p>Tu última reunión pedida: <strong>${esc(r.label)}</strong>.${r.meet ? ` <a href="${esc(r.meet)}" target="_blank" rel="noopener">Enlace de Meet</a>` : ''}</p></div>`;
      } else if (r && r.status === 'rejected') {
        last = `<div class="madof-request">${pill('No pudo ser')}<p>Jessica no pudo el ${esc(r.label)}. Elige otro hueco, por favor.</p></div>`;
      }
      if (data.error) return last + `<p class="madof-lead">${esc(data.error)}</p>`;
      if (!data.can_book) return last + `<p class="madof-meta">Estos son los huecos libres que vería el cliente.</p>${slotList(data.slots, true)}`;
      const test = data.as_manager
        ? `<p class="madof-request is-test">${pill('Prueba de gerencia', 'is-on')} La petición irá a tu bandeja marcada como prueba y solo te invitará a ti.</p>`
        : '';
      return `${last}${test}
        <p class="madof-lead">Estos huecos están libres ahora mismo en la agenda de Jessica. Elige uno:</p>
        <div class="madof-slots" role="radiogroup" aria-label="Huecos libres">${slotList(data.slots)}</div>
        <details class="madof-more madof-other-times"><summary>Ninguno me va bien</summary>
          <form class="madof-interpret">
            <label for="madof-avail">Cuéntame qué días u horas te vienen bien</label>
            <input id="madof-avail" type="text" maxlength="300" placeholder="Ej.: el jueves por la tarde o el lunes que viene" required>
            <button class="madof-btn is-ghost" type="submit">Buscar huecos</button>
          </form>
        </details>
        <form class="madof-request-form">
          <label for="madof-topic">¿De qué quieres hablar? <span class="madof-meta">(opcional)</span></label>
          <textarea id="madof-topic" rows="2" maxlength="500"></textarea>
          <p class="madof-meta" aria-live="polite" data-status></p>
          <button class="madof-btn" type="submit">${icon('check')} Pedir esta reunión</button>
        </form>`;
    },
    mount(el, ctx) {
      const form = el.querySelector('.madof-request-form');
      if (!form) return;
      const button = form.querySelector('button');
      const status = form.querySelector('[data-status]');
      const slots = el.querySelector('.madof-slots');
      let chosen = null;
      const isTest = !!el.querySelector('.is-test');
      const pick = (b) => {
        slots.querySelectorAll('button').forEach((x) => x.setAttribute('aria-checked', String(x === b)));
        chosen = Number(b.dataset.start);
        status.textContent = `Has elegido: ${b.textContent.trim()}`;
      };
      slots.addEventListener('click', (e) => { const b = e.target.closest('button[data-start]'); if (b) pick(b); });

      el.querySelector('.madof-interpret').addEventListener('submit', async (e) => {
        e.preventDefault();
        const input = e.target.querySelector('input');
        const b = e.target.querySelector('button');
        b.disabled = true; status.textContent = 'Buscando en la agenda…';
        try {
          const res = await ctx.api.interpretAvailability(input.value.trim());
          slots.innerHTML = slotList(res.slots);
          chosen = null;
          status.textContent = res.slots.length ? 'Te he encontrado estos huecos.' : 'No hay huecos libres en esas fechas. Prueba con otras o elige uno de la lista.';
        } catch (err) { status.textContent = err.message; } finally { b.disabled = false; }
      });

      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (!chosen) { status.textContent = 'Primero elige uno de los huecos de arriba.'; slots.querySelector('button')?.focus(); return; }
        button.disabled = true; status.textContent = 'Enviando…';
        try {
          const res = await ctx.api.requestMeeting(chosen, form.querySelector('textarea').value.trim());
          el.innerHTML = res.status === 'approved'
            ? `<div class="madof-request is-ok">${pill('Confirmada', 'is-on')}<p class="madof-lead">¡Hecho! Reunión el <strong>${esc(res.label)}</strong>. Te llega la invitación de Google Calendar con el enlace de Meet.</p></div>`
            : `<div class="madof-request is-pending">${pill('Pendiente', 'is-on')}<p class="madof-lead">Petición enviada para el <strong>${esc(res.label)}</strong>.</p><p>${isTest ? 'Ya la tienes en tu bandeja como «Prueba de gerencia». Si la apruebas, se crea un evento real en tu calendario solo contigo.' : 'Jessica la confirma en cuanto la vea y te llegará la invitación por email.'}</p></div>`;
          el.querySelector('.madof-lead')?.setAttribute('tabindex', '-1');
          el.querySelector('.madof-lead')?.focus();
        } catch (err) {
          status.textContent = err.message; button.disabled = false;
        }
      });
    },
  },
  encargo: {
    kicker: 'Recepción', title: 'Nuevo encargo',
    render: (ctx) => {
      if (!ctx.me.brief) return soon('Pronto podrás contarnos un nuevo encargo con un pequeño formulario de briefing.');
      if (ctx.me.role !== 'client') return `<p class="madof-meta">Este formulario es para tus clientes: lo que envíen te llega a Leads y a tu Chat.</p>${briefForm(ctx, { preview: true })}`;
      return briefForm(ctx, { visitor: false });
    },
    mount: (el, ctx) => mountBrief(el, ctx, false),
  },
  brief: {
    kicker: 'Recepción', title: 'Cuéntanos tu proyecto',
    render: (ctx) => briefForm(ctx, { visitor: true }),
    mount: (el, ctx) => mountBrief(el, ctx, true),
  },
  'solo-clientes': {
    kicker: 'Oficina', title: 'Solo para clientes',
    render: (ctx) => `<p class="madof-lead">Esta sala es para los clientes de MAD: aquí ven sus proyectos, reuniones y tareas.</p>
      <p>Si ya trabajas con nosotros, entra con tu usuario. Si no, pasa por recepción y cuéntanos tu proyecto.</p>
      <p class="madof-actions-row"><a class="madof-btn" href="${esc(ctx.me.links.login)}">Ya soy cliente: entrar</a>
      <button type="button" class="madof-btn is-ghost" data-panel="brief">${icon('pencil')} Dejar un brief</button></p>`,
  },
  'que-hacemos': {
    kicker: 'Recepción', title: (ctx) => `Habla con ${ctx.me.characters.recepcionista?.name || 'recepción'}`,
    render: (ctx) => ctx.config.chat ? visitorChatHtml(ctx) : `<p class="madof-lead">Ahora mismo no puedo charlar, pero Jessica te responde encantada.</p>${nextSteps()}`,
    mount: (el, ctx) => { if (ctx.config.chat) mountVisitorChat(el, ctx); },
  },
  'primera-reunion': {
    kicker: 'Recepción', title: 'Pedir una primera reunión',
    render: (ctx) => firstMeetingForm(ctx),
    mount: (el, ctx) => mountFirstMeeting(el, ctx),
  },
  bandeja: {
    kicker: 'Despacho de gerencia', title: 'Bandeja de gerencia',
    render: ({ me }) => {
      const n = me.inbox?.pending || 0;
      return `<p class="madof-lead">${n ? `Tienes <strong>${n}</strong> ${n === 1 ? 'petición' : 'peticiones'} esperando tu decisión.` : 'No hay nada pendiente. Todo en orden.'}</p>
        <p><a class="madof-btn" href="${esc(me.inbox?.url || '#')}">${icon('inbox')} Abrir la bandeja</a></p>`;
    },
  },
  privado: { kicker: 'Despacho de gerencia', title: 'Despacho de Jessica', render: (ctx) => `<p>Aquí trabaja ${esc(ctx.me.characters.jessica.name)}. Si necesitas hablar con ella, pide una reunión o escribe en recepción.</p>` },

  chat: {
    kicker: 'Recepción', title: (ctx) => `Habla con ${ctx.me.characters.recepcionista.name}`, needsClient: true,
    render: (ctx) => `
      <p class="madof-meta">Te respondo con lo que hay en tu oficina: proyectos, tareas, reuniones e ideas. Para pedir algo nuevo, usa los botones de recepción.</p>
      <ol class="madof-chat-log" aria-live="polite" aria-label="Conversación"></ol>
      <form class="madof-chat-form">
        <label class="screen-reader-text" for="madof-chat-input">Tu mensaje</label>
        <textarea id="madof-chat-input" rows="2" maxlength="1000" placeholder="Escribe tu pregunta…" required></textarea>
        <button class="madof-btn" type="submit">${icon('send')} Enviar</button>
      </form>
      <p class="madof-meta madof-chat-remaining">${ctx.me.chat.remaining != null ? `Te quedan ${ctx.me.chat.remaining} mensajes hoy.` : ''}</p>`,
    async mount(el, { api, me }) {
      const log = el.querySelector('.madof-chat-log');
      const form = el.querySelector('form');
      const input = el.querySelector('textarea');
      const remaining = el.querySelector('.madof-chat-remaining');
      const name = me.characters.recepcionista.name;
      const add = (from, text) => {
        const li = document.createElement('li');
        li.className = 'is-' + from;
        li.innerHTML = `<span class="madof-kicker">${from === 'user' ? 'Tú' : esc(name)}</span><p>${esc(text)}</p>`;
        log.appendChild(li); log.scrollTop = log.scrollHeight;
        return li;
      };
      try {
        const history = await api.chatHistory();
        if (!history.length) add('agent', greeting(me));
        history.forEach((m) => add(m.from, m.text));
      } catch (e) { add('agent', e.message); }

      input.addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit(); } });
      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const text = input.value.trim();
        if (!text) return;
        input.value = ''; add('user', text);
        const typing = add('agent', `${name} está escribiendo…`); typing.classList.add('is-typing');
        form.querySelector('button').disabled = true;
        try {
          const res = await api.chatSend(text);
          typing.remove(); add('agent', res.reply);
          remaining.textContent = `Te quedan ${res.remaining} mensajes hoy.`;
        } catch (err) {
          typing.remove(); add('agent', err.message);
        } finally {
          form.querySelector('button').disabled = false; input.focus();
        }
      });
      input.focus();
    },
  },
};

// Which panel opens when a room or a character is clicked in the office.
export const ROOM_PANEL = {
  recepcion: 'recepcion', reuniones: 'reuniones', proyectos: 'tareas', estudio: 'estudio',
  archivo: 'documentos', administracion: 'facturas', campanas: 'campanas', gerencia: 'bandeja',
};
export const CHARACTER_PANEL = {
  recepcionista: 'recepcion', secretaria: 'reuniones', coordinadora: 'proyecto', liz: 'estudio',
  archivera: 'documentos', contable: 'facturas', analista: 'campanas', jessica: 'bandeja',
};
