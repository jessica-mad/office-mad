/**
 * MAD Oficina · "El cartero"
 *
 * Watches the Meet folder, sends new meetings (notes + transcript text) to the
 * website, keeps a "Reuniones MAD" sheet, and carries out the orders the website
 * creates after Jessica approves something: archive a meeting and share it;
 * archive an issued invoice in the shared drive, email it from her Gmail and
 * keep the «Libro MAD» sheet up to date.
 *
 * Script properties (Project settings → Script properties):
 *   MAD_ENDPOINT   https://imad.pro/wp-json/mad-oficina/v1   (shown in MAD Oficina → Reuniones → Apps Script)
 *   MAD_SECRET     shared secret (same page)
 *   ROOT_FOLDER_ID folder where Meet/Gemini leave each meeting (one subfolder per meeting)
 *   ARCHIVE_FOLDER_ID (optional) private archive for approved meetings; by default the script
 *                  creates "Reuniones por cliente" inside ROOT_FOLDER_ID. Meetings are never
 *                  moved to the clients' shared document folders (the team can see those).
 *   SHEET_ID       (optional) created automatically on first run
 *   START_DATE     (optional, YYYY-MM-DD) ignore meetings before this date; set by install()
 *   ACCOUNTING_DRIVE_ID  shared drive «Contabilidad MAD»: «Emitidas/», «Recibidas/» and «Libro MAD» live there
 *   EMITIDAS_FOLDER_ID   (optional) found or created as «Emitidas» in ACCOUNTING_DRIVE_ID
 *   LIBRO_SHEET_ID       (optional) created automatically on first use
 *   INVOICE_CURSOR       (expenses) Gmail is read from this moment on (epoch seconds); set by install(),
 *                        or 12 months back with backfillInvoices(365)
 *
 * The archive folder for received invoices (e.g. «Facturas para MAD») and the Drive folders to
 * import from are chosen in MAD Oficina (Finanzas → Gastos); the script asks for them each run.
 *
 * Run install() once: it asks for permissions and creates the 10-minute trigger.
 */

var NOTES_RE = /(Notas de Gemini|Notes by Gemini)/i;
var TRANSCRIPT_RE = /(Transcripci[oó]n|Transcript)/i;
// Google Meet creates one folder per meeting ("RF x MAD - 2026/10/07 17:57 CEST")
// and one reused folder per recurring meeting ("Divano Toscano x MAD (recurring)").
var DATED_FOLDER_RE = /^(.*?)\s+-\s+(\d{4})\/(\d{2})\/(\d{2})\s+(\d{2}):(\d{2})/;
// Notes: "RF x MAD: 2026/10/07 17:57 CEST - Notas de Gemini" or "La reunión se inició a las 2026/07/24 17:46 CEST - …"
var NOTES_NAME_RE = /^(.*?)(?::\s+|\s+a las\s+|\s+at\s+)(\d{4})\/(\d{2})\/(\d{2})\s+(\d{2}):(\d{2})/i;
// Any date+time in a file name: "2026/10/07 17:57" or "(2026-07-24 17:46 GMT+2)"
var ANY_DATE_RE = /(\d{4})[\/-](\d{2})[\/-](\d{2})\s+(\d{2}):(\d{2})/;
var LEGACY_FOLDER = 'Meet Recordings';
var ARCHIVE_NAME = 'Reuniones por cliente';
var WAIT_MINUTES = 180; // how long to wait for notes before sending a recording on its own
var MATCH_MINUTES = 20; // recording and notes of the same meeting start within this window
var SHEET_HEADERS = ['Fecha', 'Título', 'Cliente', 'Estado', 'Resumen', 'Carpeta', 'Notas', 'Grabación', 'Clave', 'ID web'];

function props_() { return PropertiesService.getScriptProperties(); }

/* ───────────────────────── setup ───────────────────────── */

function install() {
  var p = props_();
  ['MAD_ENDPOINT', 'MAD_SECRET', 'ROOT_FOLDER_ID'].forEach(function (k) {
    if (!p.getProperty(k)) throw new Error('Falta la propiedad del script ' + k);
  });
  if (!p.getProperty('START_DATE')) p.setProperty('START_DATE', Utilities.formatDate(new Date(), 'Europe/Madrid', 'yyyy-MM-dd'));
  if (!p.getProperty('INVOICE_CURSOR')) p.setProperty('INVOICE_CURSOR', String(Math.floor(Date.now() / 1000)));
  ScriptApp.getProjectTriggers().forEach(function (t) { ScriptApp.deleteTrigger(t); });
  ScriptApp.newTrigger('run').timeBased().everyMinutes(10).create();
  sheet_();
  run();
}

/** Main trigger: send new meetings, then carry out approved orders. */
// Must match MAD Oficina (Cartero::VERSION): the website shows when this code is outdated.
var CARTERO_VERSION = '2026-10-17';
var RUN_STARTED = 0, EXPENSES_CFG = null, REPORT = null;

/** What this run did, sent to the website with the tick (Jessica sees it in «El cartero»). */
function note_(key, n) { if (REPORT) REPORT[key] = (REPORT[key] || 0) + (n === undefined ? 1 : n); }
function oops_(msg) { console.warn(msg); if (REPORT && REPORT.errors.length < 10) REPORT.errors.push(String(msg).slice(0, 300)); }

/** The expenses configuration, asked once per run (two identical signed requests in the same second would be taken as a replay). */
function expensesConfig_() {
  if (!EXPENSES_CFG) EXPENSES_CFG = signedFetch_('get', '/hooks/expenses/config', null);
  return EXPENSES_CFG;
}

function run() {
  var lock = LockService.getScriptLock();
  if (!lock.tryLock(1000)) return;
  RUN_STARTED = Date.now(); EXPENSES_CFG = null;
  REPORT = { meetings: 0, gmail: 0, drive: null, orders_done: 0, orders_failed: 0, errors: [] };
  try {
    try { scan(); } catch (e) { oops_('Reuniones: ' + e); }
    try { scanInvoices_(); } catch (e) { oops_('Facturas de Gmail: ' + e); }
    try { importDrive_(); } catch (e) { oops_('Archivo de Drive: ' + e); }
    processOrders(); tick_();
  } finally {
    lock.releaseLock();
  }
}

/* ───────────────────────── scanning ───────────────────────── */

/**
 * The unit is the Gemini notes doc (one per meeting, also inside recurring
 * folders). Recordings are matched to notes by date and time; a recording with
 * no notes after WAIT_MINUTES is sent on its own.
 */
function scan() {
  var root = DriveApp.getFolderById(props_().getProperty('ROOT_FOLDER_ID'));
  var start = props_().getProperty('START_DATE') || '1970-01-01';
  var known = knownKeys_();

  var places = [{ folder: root, dated: null }];
  var archiveId = archiveRoot_().getId();
  var sub = root.getFolders();
  while (sub.hasNext()) {
    var f = sub.next();
    if (f.getName() === LEGACY_FOLDER || f.getId() === archiveId) continue;
    var dm = f.getName().match(DATED_FOLDER_RE);
    places.push({ folder: f, dated: dm ? cleanTitle_(dm[1]) : null });
  }

  places.forEach(function (place) {
    var docs = [], videos = [], transcripts = [];
    var it = place.folder.getFiles();
    while (it.hasNext()) {
      var file = it.next(), type = file.getMimeType(), name = file.getName();
      if (type === MimeType.GOOGLE_DOCS && NOTES_RE.test(name)) docs.push(file);
      else if (type === MimeType.GOOGLE_DOCS && TRANSCRIPT_RE.test(name)) transcripts.push(file);
      else if (type.indexOf('video/') === 0) videos.push(file);
    }
    var usedVideos = {};

    docs.forEach(function (doc) {
      var m = doc.getName().match(NOTES_NAME_RE) || doc.getName().match(ANY_DATE_RE) && [null, ''].concat(doc.getName().match(ANY_DATE_RE).slice(1));
      var startsAt = m ? toDate_(m) : doc.getDateCreated();
      var video = closest_(videos, startsAt);
      if (video) usedVideos[video.getId()] = true;
      var key = 'drive-doc:' + doc.getId();
      if (known[key] || day_(startsAt) < start) return;
      var title = place.dated || (m && m[1] ? cleanTitle_(m[1]) : 'Reunión sin título');
      sendMeeting_(key, title, startsAt, {
        notes: doc, transcript: closest_(transcripts, startsAt), recording: video,
        folderId: place.folder.getId(), ownFolder: !!place.dated
      });
    });

    // Recordings without Gemini notes (notes turned off, or still being written).
    videos.forEach(function (video) {
      if (usedVideos[video.getId()]) return;
      var vm = video.getName().match(ANY_DATE_RE);
      var startsAt = vm ? toDate_([null, null].concat(vm.slice(1))) : video.getDateCreated();
      var key = 'drive-video:' + video.getId();
      if (known[key] || day_(startsAt) < start) return;
      if ((Date.now() - video.getDateCreated().getTime()) / 60000 < WAIT_MINUTES) return;
      var title = place.dated || cleanTitle_(video.getName().replace(/\s+[-(].*$/, ''));
      sendMeeting_(key, title, startsAt, {
        notes: null, transcript: closest_(transcripts, startsAt), recording: video,
        folderId: place.folder.getId(), ownFolder: !!place.dated
      });
    });
  });
}

/** File whose name carries the date/time closest to `when` (within MATCH_MINUTES). */
function closest_(files, when) {
  var best = null, bestDiff = MATCH_MINUTES + 1;
  files.forEach(function (f) {
    var m = f.getName().match(ANY_DATE_RE);
    if (!m) return;
    var diff = Math.abs(toDate_([null, null].concat(m.slice(1))).getTime() - when.getTime()) / 60000;
    if (diff < bestDiff) { best = f; bestDiff = diff; }
  });
  return best;
}

function sendMeeting_(key, title, startsAt, files) {
  var text = '', attendees = [], eventId = null, endsAt = null;
  [files.notes, files.transcript].forEach(function (f) {
    if (!f) return;
    var doc = DocumentApp.openById(f.getId());
    text += docText_(doc) + '\n\n';
    attendees = attendees.concat(mailtoLinks_(doc));
    eventId = eventId || calendarEventId_(doc);
  });

  if (eventId) {
    try {
      var ev = CalendarApp.getEventById(eventId + '@google.com') || CalendarApp.getEventById(eventId);
      if (ev) {
        endsAt = ev.getEndTime();
        ev.getGuestList(true).forEach(function (g) { attendees.push({ email: g.getEmail(), name: g.getName() || '' }); });
      }
    } catch (e) { console.warn('Calendar: ' + e); }
  }

  var row = appendRow_([startsAt, title, '', text ? 'enviada' : 'sin transcripción', '',
    'https://drive.google.com/drive/folders/' + files.folderId,
    files.notes ? files.notes.getUrl() : '', files.recording ? files.recording.getUrl() : '', key, '']);

  note_('meetings');
  var res = signedFetch_('post', '/hooks/meetings', {
    source_key: key,
    title: title,
    starts_at: startsAt.toISOString(),
    ends_at: endsAt ? endsAt.toISOString() : null,
    calendar_event_id: eventId,
    attendees: attendees,
    // Only one-off meeting folders are moved as a whole; recurring folders stay where Meet expects them.
    drive_folder_id: files.ownFolder ? files.folderId : null,
    gemini_notes_id: files.notes ? files.notes.getId() : null,
    transcript_file_id: files.transcript ? files.transcript.getId() : null,
    recording_file_id: files.recording ? files.recording.getId() : null,
    notes_text: text.trim(),
    sheet_row: row
  });
  sheet_().getRange(row, 10).setValue(res.meeting_id || '');
}

/** All tabs of a Google Doc (Gemini puts the transcript in its own tab). */
function docText_(doc) {
  var parts = [];
  var walk = function (tabs) {
    tabs.forEach(function (tab) {
      if (tab.getType() === DocumentApp.TabType.DOCUMENT_TAB) {
        parts.push('# ' + tab.getTitle() + '\n' + tab.asDocumentTab().getBody().getText());
      }
      walk(tab.getChildTabs());
    });
  };
  try { walk(doc.getTabs()); } catch (e) { parts = [doc.getBody().getText()]; }
  return parts.join('\n\n');
}

/** Every link in the first tab of a doc: [{url, text}]. Links are not part of getText(). */
function links_(doc) {
  var out = [];
  var tabs = doc.getTabs();
  var body = tabs.length ? tabs[0].asDocumentTab().getBody() : doc.getBody();
  var visit = function (el) {
    if (el.getType() === DocumentApp.ElementType.TEXT) {
      var t = el.asText(), idx = t.getTextAttributeIndices(), s = t.getText();
      for (var i = 0; i < idx.length; i++) {
        var url = t.getLinkUrl(idx[i]);
        if (url) out.push({ url: url, text: s.substring(idx[i], i + 1 < idx.length ? idx[i + 1] : s.length).trim() });
      }
    } else if (el.getNumChildren) {
      for (var c = 0; c < el.getNumChildren(); c++) visit(el.getChild(c));
    }
  };
  visit(body);
  return out;
}

/** Attendees in Gemini notes are mailto links on their names. */
function mailtoLinks_(doc) {
  return links_(doc).filter(function (l) { return l.url.indexOf('mailto:') === 0; })
    .map(function (l) { return { email: l.url.slice(7).split('?')[0], name: l.text }; });
}

/** The notes link the Calendar event: .../calendar/event?eid=BASE64URL("eventId calendarEmail"). */
function calendarEventId_(doc) {
  var link = links_(doc).filter(function (l) { return /calendar\.google\.com\/calendar\/event\?eid=/.test(l.url); })[0];
  if (!link) return null;
  try {
    var eid = link.url.match(/eid=([A-Za-z0-9_-]+)/)[1];
    while (eid.length % 4) eid += '=';
    var decoded = Utilities.newBlob(Utilities.base64DecodeWebSafe(eid)).getDataAsString();
    return decoded.split(' ')[0];
  } catch (e) { return null; }
}

/** Asks the website to process waiting meetings (it answers at once and works in the background). */
function tick_() {
  var body = {};
  if (REPORT) { REPORT.seconds = Math.round((Date.now() - RUN_STARTED) / 1000); body = { report: REPORT }; }
  try { signedFetch_('post', '/hooks/tick', body); }
  catch (e) { console.warn('tick: ' + e); } // a slow answer is fine: the website keeps working
}

/* ───────────────────────── orders ───────────────────────── */

function processOrders() {
  var res = signedFetch_('get', '/hooks/orders', null);
  (res.orders || []).forEach(function (order) {
    try {
      var handlers = {
        meeting_archive: archiveMeeting_,
        invoice_archive: archiveInvoice_,
        invoice_test: testInvoice_,
        libro_upsert: function (p) { upsertLibro_(p.rows || []); return { rows: (p.rows || []).length }; },
        expense_archive: archiveExpense_,
        expense_move: moveExpense_,
        gmail_filter: gmailFilter_,
        libro_sync: function (p) { syncLibro_(p.rows || []); return { rows: (p.rows || []).length }; },
      };
      var result = handlers[order.type] ? handlers[order.type](order.payload) : null;
      if (!result) throw new Error('Tipo de orden desconocido: ' + order.type);
      signedFetch_('post', '/hooks/orders/' + order.id + '/done', { ok: true, result: result });
      note_('orders_done');
    } catch (e) {
      note_('orders_failed');
      oops_('Encargo ' + order.type + ': ' + e);
      signedFetch_('post', '/hooks/orders/' + order.id + '/done', { ok: false, error: String(e) });
    }
  });
}

/**
 * Approved meeting → "<ROOT>/Reuniones por cliente/<CODE · Cliente>/AAAA-MM-DD – Título".
 * The archive lives inside the private Meet folder, never in the client's shared
 * document folder. Clients only get access to the specific files shared with them.
 */
function archiveMeeting_(p) {
  var clientFolder = clientArchive_(p.client);
  var name = p.date + ' – ' + p.title;
  var folder;
  if (p.drive_folder_id) {
    // One-off meeting folder created by Meet: move it as a whole.
    folder = DriveApp.getFolderById(p.drive_folder_id);
    folder.moveTo(clientFolder);
    folder.setName(name);
  } else {
    // Recurring meeting (or loose files): move only this session's files.
    folder = childFolder_(clientFolder, name);
    [p.recording_file_id].concat(p.notes_file_ids || []).forEach(function (id) {
      if (id) DriveApp.getFileById(id).moveTo(folder);
    });
  }
  // Share without Google's notification email: the website sends its own summary.
  (p.share_recording || []).forEach(function (email) { if (p.recording_file_id) shareQuietly_(p.recording_file_id, email); });
  (p.share_notes || []).forEach(function (email) { (p.notes_file_ids || []).forEach(function (id) { shareQuietly_(id, email); }); });

  if (p.sheet_row) {
    var sh = sheet_();
    sh.getRange(p.sheet_row, 3).setValue(p.client.code + ' · ' + p.client.name);
    sh.getRange(p.sheet_row, 4).setValue('archivada');
    sh.getRange(p.sheet_row, 5).setValue(p.summary || '');
    sh.getRange(p.sheet_row, 6).setValue(folder.getUrl());
  }
  return { folder_id: folder.getId() };
}

/** Private archive root inside the Meet folder (or ARCHIVE_FOLDER_ID). */
function archiveRoot_() {
  var p = props_(), id = p.getProperty('ARCHIVE_FOLDER_ID');
  if (id) return DriveApp.getFolderById(id);
  var folder = childFolder_(DriveApp.getFolderById(p.getProperty('ROOT_FOLDER_ID')), ARCHIVE_NAME);
  p.setProperty('ARCHIVE_FOLDER_ID', folder.getId());
  return folder;
}

/** "<CODE> · <Name>" folder; found by code so renaming the client does not split the archive. */
function clientArchive_(client) {
  var root = archiveRoot_(), it = root.getFolders(), prefix = client.code + ' · ';
  while (it.hasNext()) {
    var f = it.next();
    if (f.getName().indexOf(prefix) === 0) return f;
  }
  return root.createFolder(prefix + client.name);
}

function shareQuietly_(fileId, email) {
  Drive.Permissions.create({ role: 'reader', type: 'user', emailAddress: email }, fileId, { sendNotificationEmail: false, supportsAllDrives: true });
}

function childFolder_(parent, name) {
  var it = parent.getFoldersByName(name);
  return it.hasNext() ? it.next() : parent.createFolder(name);
}

/* ───────────────────────── invoices ───────────────────────── */

/** The PDF exactly as the website renders it (signed request). */
function invoicePdf_(id) {
  var res = signedFetch_('get', '/hooks/invoices/' + id + '/pdf', null);
  return Utilities.newBlob(Utilities.base64Decode(res.base64), 'application/pdf', res.filename);
}

function emitidasRoot_() {
  var p = props_(), id = p.getProperty('EMITIDAS_FOLDER_ID');
  if (id) return DriveApp.getFolderById(id);
  var drive = p.getProperty('ACCOUNTING_DRIVE_ID');
  if (!drive) throw new Error('Falta la propiedad del script ACCOUNTING_DRIVE_ID (la unidad compartida de contabilidad).');
  var folder = childFolder_(DriveApp.getFolderById(drive), 'Emitidas');
  p.setProperty('EMITIDAS_FOLDER_ID', folder.getId());
  return folder;
}

/**
 * Issued invoice → «Emitidas/AAAA/Tn/<número> <cliente>.pdf», email from Jessica's
 * Gmail (only if she chose to send it) and its row in «Libro MAD». Safe to retry:
 * the file is reused and an invoice is never emailed twice.
 */
function archiveInvoice_(p) {
  var folder = childFolder_(childFolder_(emitidasRoot_(), p.folder[0]), p.folder[1]);
  var existing = folder.getFilesByName(p.filename);
  var file = existing.hasNext() ? existing.next() : folder.createFile(invoicePdf_(p.invoice_id));
  var sent = false;
  if (p.email && p.email.to) {
    var key = 'SENT_INVOICE_' + p.invoice_id, props = props_();
    if (!props.getProperty(key)) {
      MailApp.sendEmail({ to: p.email.to, subject: p.email.subject, body: p.email.body, attachments: [file.getBlob()] });
      props.setProperty(key, new Date().toISOString());
    }
    sent = true;
  }
  var row = p.row || {};
  row['PDF'] = file.getUrl();
  if (sent) row['Estado'] = 'Enviada';
  upsertLibro_([row]);
  return { file_id: file.getId(), url: file.getUrl(), sent: sent };
}

/** «Enviarme una prueba»: the draft PDF to Jessica only; nothing is archived. */
function testInvoice_(p) {
  MailApp.sendEmail({ to: p.email.to, subject: p.email.subject, body: p.email.body, attachments: [invoicePdf_(p.invoice_id)] });
  return { sent: true };
}

/* ───────────────────────── supplier invoices (Gmail) ───────────────────────── */

var INVOICE_BUDGET_MS = 4 * 60 * 1000; // leave time for the orders in the same run

/** Reads Gmail from this many days back (run once by hand: backfillInvoices(365)). */
function backfillInvoices(days) {
  props_().setProperty('INVOICE_CURSOR', String(Math.floor(Date.now() / 1000) - (days || 365) * 86400));
  scanInvoices_();
}

/**
 * New emails with a PDF that look like an invoice (keywords or a known supplier)
 * since the cursor, oldest first. Each new PDF goes to the website, which reads
 * it and decides; the same file is never sent twice. Read-only: nothing in
 * Gmail is changed.
 */
function scanInvoices_() {
  var p = props_(), started = RUN_STARTED || Date.now();
  var cursor = Number(p.getProperty('INVOICE_CURSOR') || 0);
  if (!cursor) return;
  var cfg = expensesConfig_();
  var terms = (cfg.keywords || []).concat((cfg.senders || []).map(function (e) { return 'from:' + e; }));
  var q = 'has:attachment filename:pdf after:' + cursor + ' -in:sent -in:chats -in:trash -in:spam (' + terms.join(' OR ') + ')';
  var ids = [], token = null;
  do {
    var page = Gmail.Users.Messages.list('me', { q: q, maxResults: 100, pageToken: token });
    (page.messages || []).forEach(function (m) { ids.push(m.id); });
    token = page.nextPageToken;
  } while (token && ids.length < 1000);
  ids.reverse(); // Gmail lists newest first: oldest first, so the cursor only moves forward

  for (var i = 0; i < ids.length; i++) {
    if (Date.now() - started > INVOICE_BUDGET_MS) break; // the next run carries on
    var m = Gmail.Users.Messages.get('me', ids[i], { format: 'full' }), head = headers_(m), pdfs = pdfParts_(m.payload, []);
    var files = pdfs.map(function (part) {
      var data = part.body.attachmentId ? Gmail.Users.Messages.Attachments.get('me', m.id, part.body.attachmentId).data : part.body.data;
      var bytes = gmailBytes_(data);
      return { name: part.filename, bytes: bytes, hash: hex_(Utilities.computeDigest(Utilities.DigestAlgorithm.SHA_256, bytes)) };
    }).filter(function (f) { return f.bytes.length <= (cfg.max_bytes || 4194304); });
    if (files.length) {
      var unknown = signedFetch_('post', '/hooks/expenses/known', { hashes: files.map(function (f) { return f.hash; }) }).unknown || [];
      files.forEach(function (f) {
        if (unknown.indexOf(f.hash) < 0) return;
        try {
          signedFetch_('post', '/hooks/expenses', { message_id: m.id, from: head.from, subject: head.subject, date: new Date(Number(m.internalDate)).toISOString(), filename: f.name, base64: Utilities.base64Encode(f.bytes) });
          note_('gmail');
        } catch (e) {
          // One bad file never stops the rest: it is logged and the scan goes on.
          oops_('No he podido entregar «' + f.name + '» (' + (head.subject || m.id) + '): ' + e);
        }
      });
    }
    p.setProperty('INVOICE_CURSOR', String(Math.max(cursor, Math.floor(Number(m.internalDate) / 1000))));
  }
}

/**
 * Attachment data from the Gmail advanced service: depending on the call it comes
 * as bytes already or as web-safe base64 without the «=» padding (which
 * base64DecodeWebSafe rejects with «Could not decode string»).
 */
function gmailBytes_(data) {
  if (!data) return [];
  if (typeof data !== 'string') return data;
  var b64 = data.replace(/-/g, '+').replace(/_/g, '/').replace(/\s/g, '');
  while (b64.length % 4) b64 += '=';
  return Utilities.base64Decode(b64);
}

function headers_(m) {
  var out = {};
  (m.payload.headers || []).forEach(function (h) { out[h.name.toLowerCase()] = h.value; });
  return out;
}

function pdfParts_(part, out) {
  if (!part) return out;
  if (part.filename && /\.pdf$/i.test(part.filename) && part.body && (part.body.attachmentId || part.body.data)) out.push(part);
  (part.parts || []).forEach(function (child) { pdfParts_(child, out); });
  return out;
}

function hex_(raw) {
  return raw.map(function (b) { return ('0' + ((b + 256) % 256).toString(16)).slice(-2); }).join('');
}

/** The archive folder: the one chosen in MAD Oficina (each order carries it) or «Recibidas». */
function recibidasRoot_(rootId) {
  if (rootId) return DriveApp.getFolderById(rootId);
  var p = props_(), id = p.getProperty('RECIBIDAS_FOLDER_ID');
  if (id) return DriveApp.getFolderById(id);
  var drive = p.getProperty('ACCOUNTING_DRIVE_ID');
  if (!drive) throw new Error('Falta la propiedad del script ACCOUNTING_DRIVE_ID (la unidad compartida de contabilidad).');
  var folder = childFolder_(DriveApp.getFolderById(drive), 'Recibidas');
  p.setProperty('RECIBIDAS_FOLDER_ID', folder.getId());
  return folder;
}

function folderPath_(root, parts) {
  return parts.reduce(function (f, name) { return childFolder_(f, name); }, root);
}

/** A received invoice (or its proof of payment) → «<archivo>/AAAA/AAAA-Tn/» (or «No son mías/AAAA/»). */
function archiveExpense_(p) {
  var res = signedFetch_('get', '/hooks/expenses/' + p.expense_id + '/file', null);
  var folder = folderPath_(recibidasRoot_(p.root), p.folder);
  var existing = folder.getFilesByName(p.name);
  var file = existing.hasNext() ? existing.next() : folder.createFile(Utilities.newBlob(Utilities.base64Decode(res.base64), res.mime || 'application/pdf', p.name));
  return { file_id: file.getId(), url: file.getUrl() };
}

/** Already in Drive: the file goes to its place and takes its name (Jessica changed the data, or it came from the archive). */
function moveExpense_(p) {
  var file = DriveApp.getFileById(p.file_id);
  file.moveTo(folderPath_(recibidasRoot_(p.root), p.folder));
  file.setName(p.name);
  return { file_id: file.getId(), url: file.getUrl() };
}

/* ───────────────────────── Drive archive import ───────────────────────── */

var IMPORT_TYPES = ['application/pdf', 'image/png', 'image/jpeg', 'image/webp'];
var IMPORT_BUDGET_MS = 4 * 60 * 1000; // from the start of the run: the orders still fit

/**
 * Jessica's old archive (and what is already in the new one), once: every PDF or image
 * from the chosen year on goes to the website, which reads it. Her folders are only
 * read; files already in the archive folder are moved and renamed there, and copies
 * of a known file go to «Duplicados». Nothing is deleted.
 */
function importDrive_() {
  var cfg = expensesConfig_();
  var imp = cfg.drive_import || {};
  if (!imp.active || !(imp.folders || []).length) return;
  var root = cfg.archive_root || '', max = cfg.max_bytes || 4194304, files = [], errors = 0, skipped = 0;
  imp.folders.forEach(function (id) {
    try { walk_(DriveApp.getFolderById(id), [], id === root, imp.since || 2025, files, 0); }
    catch (e) { errors++; oops_('No puedo abrir la carpeta de Drive ' + id + ': ' + e); }
  });
  var unknown = {};
  for (var i = 0; i < files.length; i += 400) {
    var res = signedFetch_('post', '/hooks/expenses/known', { refs: files.slice(i, i + 400).map(function (f) { return 'drive:' + f.id; }) });
    (res.unknown_refs || []).forEach(function (r) { unknown[r] = true; });
  }
  var todo = files.filter(function (f) { return unknown['drive:' + f.id]; });
  var sent = 0, cut = false;
  for (var j = 0; j < todo.length; j++) {
    if (Date.now() - (RUN_STARTED || Date.now()) > IMPORT_BUDGET_MS) { cut = true; break; } // the next run carries on
    var f = todo[j];
    if (f.size > max) { skipped++; console.warn('Demasiado grande, no lo envío: ' + f.path + '/' + f.name); continue; }
    try {
      var bytes = DriveApp.getFileById(f.id).getBlob().getBytes();
      var r = signedFetch_('post', '/hooks/expenses', { source: 'drive', file_id: f.id, folder: f.path, in_archive: f.inArchive, filename: f.name, date: f.created, base64: Utilities.base64Encode(bytes) });
      if (r.duplicate && root) DriveApp.getFileById(f.id).moveTo(childFolder_(DriveApp.getFolderById(root), 'Duplicados'));
      sent++;
    } catch (e) {
      errors++;
      oops_('No he podido entregar «' + f.name + '» (' + f.path + '): ' + e);
    }
  }
  var pending = todo.length - sent - skipped - errors;
  if (REPORT) REPORT.drive = { files: files.length, sent: sent, pending: Math.max(0, pending), errors: errors + skipped };
  signedFetch_('post', '/hooks/expenses/import', { files: files.length, sent: sent, pending: Math.max(0, pending), errors: errors + skipped, done: !cut && pending <= 0 });
}

/** Folders by year and quarter; years before «since» (by the year in the folder's name) are skipped. */
function walk_(folder, path, inArchive, since, out, depth) {
  var name = folder.getName(), year = name.match(/\b(?:19|20)\d\d\b/);
  if (depth > 0 && year && Number(year[0]) < since) return;
  if (inArchive && depth === 1 && (name === 'No son mías' || name === 'Duplicados')) return;
  var here = path.concat([name]);
  var it = folder.getFiles();
  while (it.hasNext()) {
    var file = it.next(), type = file.getMimeType();
    if (IMPORT_TYPES.indexOf(type) < 0) continue;
    out.push({ id: file.getId(), name: file.getName(), path: here.join('/'), inArchive: inArchive, created: file.getDateCreated().toISOString(), size: file.getSize() });
  }
  if (depth >= 4) return;
  var sub = folder.getFolders();
  while (sub.hasNext()) walk_(sub.next(), here, inArchive, since, out, depth + 1);
}

/** Approved by Jessica: a Gmail filter that labels a supplier's emails «Facturas». */
function gmailFilter_(p) {
  var labels = Gmail.Users.Labels.list('me').labels || [];
  var label = labels.filter(function (l) { return l.name === p.label; })[0] || Gmail.Users.Labels.create({ name: p.label }, 'me');
  Gmail.Users.Settings.Filters.create({ criteria: { from: p.from.join(' OR ') }, action: { addLabelIds: [label.id] } }, 'me');
  return { label: label.id };
}

/* ───────────────────────── Libro MAD ───────────────────────── */

function libro_() {
  var p = props_(), id = p.getProperty('LIBRO_SHEET_ID');
  if (id) return SpreadsheetApp.openById(id);
  var drive = p.getProperty('ACCOUNTING_DRIVE_ID');
  if (!drive) throw new Error('Falta la propiedad del script ACCOUNTING_DRIVE_ID (la unidad compartida de contabilidad).');
  var ss = SpreadsheetApp.create('Libro MAD');
  DriveApp.getFileById(ss.getId()).moveTo(DriveApp.getFolderById(drive));
  ss.getSheets()[0].setName('Emitidas');
  p.setProperty('LIBRO_SHEET_ID', ss.getId());
  return ss;
}

/** «Emitidas» tab with the columns of the rows the website sends (the last one, ID, is the key). */
function emitidasSheet_(headers) {
  var ss = libro_(), sh = ss.getSheetByName('Emitidas') || ss.insertSheet('Emitidas');
  if (sh.getLastRow() === 0) {
    sh.appendRow(headers);
    sh.setFrozenRows(1);
    sh.getRange(1, 1, 1, headers.length).setFontWeight('bold').setBackground('#FBB62F');
  }
  return sh;
}

function rowValues_(headers, row) {
  return headers.map(function (h) { return row[h] === undefined || row[h] === null ? '' : row[h]; });
}

function formatLibro_(sh, headers, rows) {
  if (rows < 1) return;
  ['Base', 'IVA', 'IRPF', 'Total'].forEach(function (h) {
    var c = headers.indexOf(h) + 1;
    if (c > 0) sh.getRange(2, c, rows, 1).setNumberFormat('#,##0.00 €');
  });
}

function upsertLibro_(rows) {
  if (!rows.length) return;
  var headers = Object.keys(rows[0]), sh = emitidasSheet_(headers);
  var idCol = headers.indexOf('ID') + 1, last = sh.getLastRow(), at = {};
  if (last > 1) sh.getRange(2, idCol, last - 1, 1).getValues().forEach(function (r, i) { if (r[0] !== '') at[String(r[0])] = i + 2; });
  rows.forEach(function (row) {
    var values = [rowValues_(headers, row)], r = at[String(row.ID)];
    if (r) sh.getRange(r, 1, 1, headers.length).setValues(values);
    else { sh.appendRow(values[0]); at[String(row.ID)] = sh.getLastRow(); }
  });
  formatLibro_(sh, headers, sh.getLastRow() - 1);
}

/** Whole book at once (first time, or to repair it): rewrites the tab. */
function syncLibro_(rows) {
  if (!rows.length) return;
  var headers = Object.keys(rows[0]), sh = emitidasSheet_(headers);
  // Keep the Drive links the cartero already knows (the website may not have them yet).
  var pdfCol = headers.indexOf('PDF'), idCol = headers.indexOf('ID'), known = {}, last = sh.getLastRow();
  if (last > 1) sh.getRange(2, 1, last - 1, headers.length).getValues().forEach(function (r) { if (r[pdfCol]) known[String(r[idCol])] = r[pdfCol]; });
  var values = rows.map(function (row) {
    if (!row.PDF && known[String(row.ID)]) row.PDF = known[String(row.ID)];
    return rowValues_(headers, row);
  });
  if (last > 1) sh.getRange(2, 1, last - 1, sh.getLastColumn()).clearContent();
  sh.getRange(2, 1, values.length, headers.length).setValues(values);
  formatLibro_(sh, headers, values.length);
}

/* ───────────────────────── sheet ───────────────────────── */

function sheet_() {
  var p = props_(), id = p.getProperty('SHEET_ID'), ss;
  if (id) {
    ss = SpreadsheetApp.openById(id);
  } else {
    ss = SpreadsheetApp.create('Reuniones MAD');
    DriveApp.getFileById(ss.getId()).moveTo(DriveApp.getFolderById(p.getProperty('ROOT_FOLDER_ID')));
    p.setProperty('SHEET_ID', ss.getId());
    var sh0 = ss.getSheets()[0];
    sh0.setName('Reuniones');
    sh0.appendRow(SHEET_HEADERS);
    sh0.setFrozenRows(1);
    sh0.getRange(1, 1, 1, SHEET_HEADERS.length).setFontWeight('bold').setBackground('#FBB62F');
  }
  return ss.getSheetByName('Reuniones');
}

function appendRow_(values) {
  var sh = sheet_();
  sh.appendRow(values);
  return sh.getLastRow();
}

function knownKeys_() {
  var sh = sheet_(), n = sh.getLastRow(), out = {};
  if (n < 2) return out;
  sh.getRange(2, 9, n - 1, 1).getValues().forEach(function (r) { if (r[0]) out[r[0]] = true; });
  return out;
}

/* ───────────────────────── http ───────────────────────── */

function signedFetch_(method, path, body) {
  var p = props_();
  var endpoint = p.getProperty('MAD_ENDPOINT').replace(/\/+$/, '');
  var route = endpoint.replace(/^https?:\/\/[^/]+/, '').replace(/^.*\/wp-json/, '') + path; // "/mad-oficina/v1/hooks/..."
  var payload = body ? JSON.stringify(body) : '';
  var ts = String(Math.floor(Date.now() / 1000));
  var raw = Utilities.computeHmacSha256Signature(ts + '\n' + method.toUpperCase() + '\n' + route + '\n' + payload, p.getProperty('MAD_SECRET'), Utilities.Charset.UTF_8);
  var sig = raw.map(function (b) { return ('0' + ((b + 256) % 256).toString(16)).slice(-2); }).join('');
  var opts = {
    method: method, muteHttpExceptions: true, contentType: 'application/json; charset=utf-8',
    headers: { 'X-Mad-Timestamp': ts, 'X-Mad-Signature': sig, 'X-Mad-Version': CARTERO_VERSION },
  };
  if (body) opts.payload = payload;
  var res = UrlFetchApp.fetch(endpoint + path, opts);
  var code = res.getResponseCode();
  if (code >= 300) {
    var text = res.getContentText();
    // A real Cloudflare block is an HTML page from Cloudflare (every response carries a cf-ray
    // header, so headers alone say nothing). Anything else is WordPress answering.
    if (/<html/i.test(text) && /cloudflare|Attention Required|cf-error-details/i.test(text) && !/wp-json|mad-oficina|"code"\s*:/i.test(text)) {
      throw new Error('Cloudflare ha bloqueado la petición (' + code + ') antes de llegar a WordPress. ' +
        'Crea en Cloudflare una regla que omita la seguridad para /wp-json/mad-oficina/v1/hooks/ (ver apps-script/README.md).');
    }
    throw new Error('MAD Oficina respondió ' + code + ': ' + text.slice(0, 300));
  }
  return JSON.parse(res.getContentText() || '{}');
}

/* ───────────────────────── helpers ───────────────────────── */

function day_(d) { return Utilities.formatDate(d, 'Europe/Madrid', 'yyyy-MM-dd'); }

/** m = [_, _, yyyy, MM, dd, HH, mm] (Madrid local time, as Meet writes it). */
function toDate_(m) {
  // Folder/doc names use Madrid local time.
  var s = m[2] + '-' + m[3] + '-' + m[4] + ' ' + m[5] + ':' + m[6];
  return Utilities.parseDate(s, 'Europe/Madrid', 'yyyy-MM-dd HH:mm');
}

function cleanTitle_(t) {
  t = (t || '').replace(/\s*\(recurring\)\s*$/i, '').trim();
  if (!t || /^La reuni[oó]n se inici[oó]/i.test(t) || /^The meeting started/i.test(t)) return 'Reunión sin título';
  return t;
}

/** Manual helper: send meetings from the last N days that were skipped by START_DATE. */
function backfill(days) {
  var d = new Date(Date.now() - (days || 14) * 86400000);
  props_().setProperty('START_DATE', Utilities.formatDate(d, 'Europe/Madrid', 'yyyy-MM-dd'));
  run();
}
