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
 *   ARCHIVE_FOLDER_ID (optional) private archive for approved meetings; by default the script
 *                  creates "Reuniones por cliente" inside ROOT_FOLDER_ID. Meetings are never
 *                  moved to the clients' shared document folders (the team can see those).
 *   SHEET_ID       (optional) created automatically on first run
 *   START_DATE     (optional, YYYY-MM-DD) ignore meetings before this date; set by install()
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
    try { scan(); } finally { processOrders(); }
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
  if (code >= 300) {
    var text = res.getContentText();
    if (/cloudflare|cf-ray|Attention Required/i.test(text + JSON.stringify(res.getAllHeaders()))) {
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
