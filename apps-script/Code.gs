/**
 * MAD Oficina · "El cartero"
 *
 * Watches the Meet folder, sends new meetings (notes + transcript text) to the
 * website, keeps a "Reuniones MAD" sheet, and carries out the orders the website
 * creates after Jessica approves a draft (archive in the client's folder, share).
 *
 * Script properties (Project settings → Script properties):
 *   MAD_ENDPOINT   https://imad.pro/wp-json/mad-oficina/v1   (shown in MAD Oficina → Reuniones → Apps Script)
 *   MAD_SECRET     shared secret (same page)
 *   ROOT_FOLDER_ID folder where Meet/Gemini leave each meeting (one subfolder per meeting)
 *   CLIENTS_FOLDER_ID (optional) parent folder for client folders when a client has none set
 *   SHEET_ID       (optional) created automatically on first run
 *   START_DATE     (optional, YYYY-MM-DD) ignore meetings before this date; set by install()
 *
 * Run install() once: it asks for permissions and creates the 10-minute trigger.
 */

var NOTES_RE = /(Notas de Gemini|Notes by Gemini)/i;
var TRANSCRIPT_RE = /(Transcripci[oó]n|Transcript)/i;
var FOLDER_RE = /^(.*?)\s+-\s+(\d{4})\/(\d{2})\/(\d{2})\s+(\d{2}):(\d{2})/;
var LOOSE_DOC_RE = /^(.*?):\s+(\d{4})\/(\d{2})\/(\d{2})\s+(\d{2}):(\d{2}).*-\s+(Notas de Gemini|Notes by Gemini)/i;
var WAIT_MINUTES = 180; // how long to wait for notes before sending a meeting without them
var SHEET_HEADERS = ['Fecha', 'Título', 'Cliente', 'Estado', 'Resumen', 'Carpeta', 'Notas', 'Grabación', 'Clave', 'ID web'];

function props_() { return PropertiesService.getScriptProperties(); }

/* ───────────────────────── setup ───────────────────────── */

function install() {
  var p = props_();
  ['MAD_ENDPOINT', 'MAD_SECRET', 'ROOT_FOLDER_ID'].forEach(function (k) {
    if (!p.getProperty(k)) throw new Error('Falta la propiedad del script ' + k);
  });
  if (!p.getProperty('START_DATE')) p.setProperty('START_DATE', Utilities.formatDate(new Date(), 'Europe/Madrid', 'yyyy-MM-dd'));
  ScriptApp.getProjectTriggers().forEach(function (t) { ScriptApp.deleteTrigger(t); });
  ScriptApp.newTrigger('run').timeBased().everyMinutes(10).create();
  sheet_();
  run();
}

/** Main trigger: send new meetings, then carry out approved orders. */
function run() {
  var lock = LockService.getScriptLock();
  if (!lock.tryLock(1000)) return;
  try {
    scan();
    processOrders();
  } finally {
    lock.releaseLock();
  }
}

/* ───────────────────────── scanning ───────────────────────── */

function scan() {
  var root = DriveApp.getFolderById(props_().getProperty('ROOT_FOLDER_ID'));
  var start = props_().getProperty('START_DATE') || '1970-01-01';
  var known = knownKeys_();

  // 1. One subfolder per meeting: "RF x MAD - 2026/10/07 17:57 CEST"
  var folders = root.getFolders();
  while (folders.hasNext()) {
    var f = folders.next();
    var m = f.getName().match(FOLDER_RE);
    if (!m) continue;
    var key = 'drive-folder:' + f.getId();
    if (known[key]) continue;
    var startsAt = toDate_(m);
    if (Utilities.formatDate(startsAt, 'Europe/Madrid', 'yyyy-MM-dd') < start) continue;
    var files = filesIn_(f);
    sendMeeting_(key, cleanTitle_(m[1]), startsAt, files, f.getId(), f.getDateCreated());
  }

  // 2. Gemini notes left loose in the root folder: "RF x MAD: 2026/10/07 17:57 CEST - Notas de Gemini"
  var docs = root.getFilesByType(MimeType.GOOGLE_DOCS);
  while (docs.hasNext()) {
    var d = docs.next();
    var dm = d.getName().match(LOOSE_DOC_RE);
    if (!dm) continue;
    var dkey = 'drive-doc:' + d.getId();
    if (known[dkey]) continue;
    var dStart = toDate_(dm);
    if (Utilities.formatDate(dStart, 'Europe/Madrid', 'yyyy-MM-dd') < start) continue;
    sendMeeting_(dkey, cleanTitle_(dm[1]), dStart, { notes: d, transcript: null, recording: null }, null, d.getDateCreated());
  }
}

function filesIn_(folder) {
  var out = { notes: null, transcript: null, recording: null };
  var it = folder.getFiles();
  while (it.hasNext()) {
    var file = it.next();
    var name = file.getName(), type = file.getMimeType();
    if (type === MimeType.GOOGLE_DOCS && NOTES_RE.test(name)) out.notes = file;
    else if (type === MimeType.GOOGLE_DOCS && TRANSCRIPT_RE.test(name)) out.transcript = file;
    else if (type.indexOf('video/') === 0) out.recording = file;
  }
  return out;
}

function sendMeeting_(key, title, startsAt, files, folderId, created) {
  var hasText = files.notes || files.transcript;
  var ageMin = (Date.now() - created.getTime()) / 60000;
  if (!hasText && ageMin < WAIT_MINUTES) return; // Gemini may still be writing

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

  var row = appendRow_([startsAt, title, '', hasText ? 'enviada' : 'sin transcripción', '',
    folderId ? 'https://drive.google.com/drive/folders/' + folderId : '',
    files.notes ? files.notes.getUrl() : '', files.recording ? files.recording.getUrl() : '', key, '']);

  var res = signedFetch_('post', '/hooks/meetings', {
    source_key: key,
    title: title,
    starts_at: startsAt.toISOString(),
    ends_at: endsAt ? endsAt.toISOString() : null,
    calendar_event_id: eventId,
    attendees: attendees,
    drive_folder_id: folderId,
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

/* ───────────────────────── orders ───────────────────────── */

function processOrders() {
  var res = signedFetch_('get', '/hooks/orders', null);
  (res.orders || []).forEach(function (order) {
    try {
      var result = order.type === 'meeting_archive' ? archiveMeeting_(order.payload) : null;
      if (!result) throw new Error('Tipo de orden desconocido: ' + order.type);
      signedFetch_('post', '/hooks/orders/' + order.id + '/done', { ok: true, result: result });
    } catch (e) {
      console.error(e);
      signedFetch_('post', '/hooks/orders/' + order.id + '/done', { ok: false, error: String(e) });
    }
  });
}

function archiveMeeting_(p) {
  var clientFolder = clientFolder_(p.client);
  var reuniones = childFolder_(clientFolder, 'Reuniones');
  var name = p.date + ' – ' + p.title;
  var folder;
  if (p.drive_folder_id) {
    folder = DriveApp.getFolderById(p.drive_folder_id);
    folder.moveTo(reuniones);
    folder.setName(name);
  } else {
    folder = childFolder_(reuniones, name);
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

function shareQuietly_(fileId, email) {
  Drive.Permissions.create({ role: 'reader', type: 'user', emailAddress: email }, fileId, { sendNotificationEmail: false, supportsAllDrives: true });
}

function clientFolder_(client) {
  if (client.drive_folder_id) return DriveApp.getFolderById(client.drive_folder_id);
  var parentId = props_().getProperty('CLIENTS_FOLDER_ID');
  var parent = parentId ? DriveApp.getFolderById(parentId) : childFolder_(DriveApp.getFolderById(props_().getProperty('ROOT_FOLDER_ID')), 'Clientes');
  return childFolder_(parent, client.name);
}

function childFolder_(parent, name) {
  var it = parent.getFoldersByName(name);
  return it.hasNext() ? it.next() : parent.createFolder(name);
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
    headers: { 'X-Mad-Timestamp': ts, 'X-Mad-Signature': sig },
  };
  if (body) opts.payload = payload;
  var res = UrlFetchApp.fetch(endpoint + path, opts);
  var code = res.getResponseCode();
  if (code >= 300) throw new Error('MAD Oficina respondió ' + code + ': ' + res.getContentText().slice(0, 300));
  return JSON.parse(res.getContentText() || '{}');
}

/* ───────────────────────── helpers ───────────────────────── */

function toDate_(m) {
  // Folder/doc names use Madrid local time.
  var s = m[2] + '-' + m[3] + '-' + m[4] + ' ' + m[5] + ':' + m[6];
  return Utilities.parseDate(s, 'Europe/Madrid', 'yyyy-MM-dd HH:mm');
}

function cleanTitle_(t) {
  if (/^La reuni[oó]n se inici[oó]/i.test(t)) return 'Reunión sin título';
  return t.trim();
}

/** Manual helper: send meetings from the last N days that were skipped by START_DATE. */
function backfill(days) {
  var d = new Date(Date.now() - (days || 14) * 86400000);
  props_().setProperty('START_DATE', Utilities.formatDate(d, 'Europe/Madrid', 'yyyy-MM-dd'));
  run();
}
