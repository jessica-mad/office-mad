// The one list of sections of the office: the classic menu, the receptionist's table and each
// character's table all come from here, with the same names. room = the character who shows it.
export const FEATURES = { campaigns: false }; // «Mis campañas» stays hidden until it exists

export const SECTIONS = [
  { id: 'proyecto', label: 'Proyecto', icon: 'timeline', room: 'coordinadora' },
  { id: 'reuniones', label: 'Reuniones', icon: 'calendar', room: 'secretaria' },
  { id: 'tareas', label: 'Tareas', icon: 'check', room: 'coordinadora' },
  { id: 'ideas', label: 'Ideas', icon: 'bulb', room: 'coordinadora' },
  { id: 'documentos', label: 'Documentos', icon: 'folder', room: 'archivera' },
  { id: 'facturas', label: 'Facturas', icon: 'receipt', room: 'contable' },
  { id: 'reunion', label: 'Pedir reunión', icon: 'plus', room: 'recepcionista' },
  { id: 'encargo', label: 'Nuevo encargo', icon: 'pencil', room: 'recepcionista' },
  { id: 'chat', label: 'Mensajes', icon: 'chat', room: 'recepcionista', chat: true },
  { id: 'campanas', label: 'Campañas', icon: 'megaphone', room: 'analista', flag: 'campaigns' },
  { id: 'bandeja', label: 'Bandeja de gerencia', icon: 'inbox', room: 'jessica', manager: true },
];

export const VISITOR_SECTIONS = [
  { id: 'que-hacemos', label: 'Qué hacemos', icon: 'chat' },
  { id: 'primera-reunion', label: 'Primera reunión', icon: 'calendar' },
  { id: 'brief', label: 'Dejar un brief', icon: 'pencil' },
];

export const TEAM_SECTIONS = [{ id: 'mis-tareas', label: 'Mis encargos', icon: 'check', room: 'liz' }];

/**
 * The sections this person sees (the server still decides what each one may show).
 * canSee(me, id) filters by the areas opened to a client user.
 */
export function sectionsFor(me, canSee) {
  if (me.role === 'visitor') return VISITOR_SECTIONS;
  if (me.role === 'team') return TEAM_SECTIONS;
  return SECTIONS
    .filter((s) => (!s.flag || FEATURES[s.flag]) && (!s.manager || me.role === 'manager') && (!s.chat || me.chat?.enabled) && canSee(me, s.id))
    .map((s) => (s.id === 'encargo' ? { ...s, soon: !me.brief } : s));
}

/** A character's table: reception has everything; the others, what lives in their room. */
export function sectionsOf(me, slug, canSee) {
  const all = sectionsFor(me, canSee);
  if (me.role === 'visitor') return slug === 'recepcionista' ? all : null; // «Solo clientes»
  if (me.role === 'team') return slug === 'liz' ? all : null;
  if (slug === 'recepcionista') return all;
  if (slug === 'jessica' && me.role !== 'manager') return all.filter((s) => s.id === 'reunion');
  return all.filter((s) => s.room === slug);
}

export const labelOf = (id) => [...SECTIONS, ...VISITOR_SECTIONS, ...TEAM_SECTIONS].find((s) => s.id === id)?.label || '';
