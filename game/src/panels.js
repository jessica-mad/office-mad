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
  { panel: 'reunion', label: 'Pedir una reunión', icon: 'plus', soon: true },
  { panel: 'encargo', label: 'Nuevo encargo', icon: 'pencil', soon: true },
  { panel: 'chat', label: 'Escribir libremente', icon: 'chat', chat: true },
];

export function menuButtons(me, { compact = false } = {}) {
  const items = me.role === 'team'
    ? [{ panel: 'mis-tareas', label: 'Mis encargos', icon: 'check' }]
    : MENU.filter((m) => !m.chat || me.chat.enabled).concat(me.role === 'manager' ? [{ panel: 'bandeja', label: 'Bandeja de gerencia', icon: 'inbox' }] : []);
  return `<div class="madof-menu ${compact ? 'is-compact' : ''}">${items.map((m) => `
    <button type="button" class="madof-menu-btn" data-panel="${m.panel}">
      <span class="madof-menu-icon">${icon(m.icon)}</span><span class="madof-menu-label">${esc(m.label)}${m.soon ? '<span class="madof-tag">Pronto</span>' : ''}</span>
    </button>`).join('')}</div>`;
}

export function greeting(me) {
  const r = me.characters.recepcionista;
  const who = me.user.name ? `, ${me.user.name}` : '';
  if (me.role === 'team') return `¡Hola${who}! Soy ${r.name}. Aquí tienes tus encargos.`;
  return `¡Hola${who}! Soy ${r.name}, de recepción. ¿Qué necesitas hoy?`;
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
  reunion: { kicker: 'Recepción', title: 'Pedir una reunión', render: () => soon('Pronto podrás elegir un hueco libre de la agenda de Jessica desde aquí. Mientras tanto, escríbele directamente.') },
  encargo: { kicker: 'Recepción', title: 'Nuevo encargo', render: () => soon('Pronto podrás contarnos un nuevo encargo con un pequeño formulario de briefing.') },
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
