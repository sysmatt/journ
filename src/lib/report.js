// Client-only event report export — CSV / Text / HTML snapshots built
// entirely from data already loaded into the app's stores, never
// touching the network or the sync/fragment model. See
// docs/spec/ui-ux.md § Report export and docs/spec/data-model.md §
// Report export — a report is deliberately NOT a data type in this
// app's model at all, just a one-off rendering into a downloaded file.

import { fmtAbsolute, fmtDuration, fmtDelta } from './time.js';
import { renderEntryText, renderEntryPlainText, deriveDisplayName, computeCompletionPercent } from './render.js';

/**
 * @typedef {object} ReportData
 * @property {string} journalName
 * @property {object} eventMeta - description/start_at/closed/closed_at
 * @property {object[]} entries - full $entries (unfiltered — each builder excludes trashed itself)
 * @property {Record<string, object>} contactsByUuid
 * @property {object[]} tagsConfig
 * @property {'utc'|'local'|'t'} timeMode
 * @property {string|null} tRefEntryUuid
 * @property {'dark'|'light'} theme
 * @property {Date} generatedAt
 */

// ---- shared helpers --------------------------------------------------

function visibleEntries(data) {
  return data.entries.filter((e) => !e.trashed);
}

/** Mirrors EntriesTable.svelte's own rowTime() exactly, collapsed to one display string (no separate date line needed outside the live table's two-line layout). */
function rowTimeString(entry, data) {
  if (data.timeMode === 't') {
    const ref = data.entries.find((e) => e.uuid === data.tRefEntryUuid) || data.entries[0];
    return fmtDelta(entry.created_at, ref?.created_at ?? entry.created_at);
  }
  const f = fmtAbsolute(entry.created_at, data.timeMode === 'local');
  return `${f.time}${f.zone} ${f.date}`;
}

/** The common header block, already formatted as display strings — see docs/spec/ui-ux.md § Report export for the field list and the "absolute, never T-mode" rule for these specifically. */
function reportHeader(data) {
  const useLocal = data.timeMode === 'local';
  const started = fmtAbsolute(data.eventMeta?.start_at, useLocal);
  const generated = fmtAbsolute(data.generatedAt.toISOString(), useLocal);
  const isOpen = !data.eventMeta?.closed;

  let end;
  if (isOpen) {
    end = 'Ongoing';
  } else {
    const f = fmtAbsolute(data.eventMeta?.closed_at, useLocal);
    end = `${f.time}${f.zone} ${f.date}`;
  }

  const durationEnd = isOpen ? data.generatedAt : new Date(data.eventMeta?.closed_at ?? data.generatedAt);
  const completion = computeCompletionPercent(data.entries);

  return {
    journalName: data.journalName || '',
    description: data.eventMeta?.description || '',
    started: `${started.time}${started.zone} ${started.date}`,
    end,
    duration: fmtDuration(data.eventMeta?.start_at, durationEnd),
    completion: completion === null ? '—' : `${completion}%`,
    generatedAt: `${generated.time}${generated.zone} ${generated.date}`,
  };
}

function slugify(s) {
  const slug = (s || '')
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');
  return slug || 'untitled';
}

function pad2(n) {
  return String(n).padStart(2, '0');
}

export function reportFilename(journalName, eventDescription, generatedAt, ext) {
  const stamp = `${generatedAt.getFullYear()}${pad2(generatedAt.getMonth() + 1)}${pad2(generatedAt.getDate())}-${pad2(generatedAt.getHours())}${pad2(generatedAt.getMinutes())}`;
  return `${slugify(journalName)}-${slugify(eventDescription)}-${stamp}.${ext}`;
}

export function downloadFile(filename, content, mimeType) {
  const blob = new Blob([content], { type: mimeType });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
}

// ---- CSV ---------------------------------------------------------------

function csvField(value) {
  const s = String(value ?? '');
  return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
}

function csvRow(fields) {
  return fields.map(csvField).join(',') + '\r\n';
}

export function buildCsvReport(data) {
  const h = reportHeader(data);
  let out = '';
  out += csvRow(['Journal', h.journalName]);
  out += csvRow(['Event', h.description]);
  out += csvRow(['Started', h.started]);
  out += csvRow(['End', h.end]);
  out += csvRow(['Duration', h.duration]);
  out += csvRow(['Completion', h.completion]);
  out += csvRow(['Generated at', h.generatedAt]);
  out += '\r\n';
  out += csvRow(['Time', 'Author', 'Entry']);
  for (const entry of visibleEntries(data)) {
    out += csvRow([
      rowTimeString(entry, data),
      deriveDisplayName(data.contactsByUuid[entry.author]),
      renderEntryPlainText(entry.text, { contactsByUuid: data.contactsByUuid }),
    ]);
  }
  return out;
}

// ---- Text ---------------------------------------------------------------

export function buildTextReport(data) {
  const h = reportHeader(data);
  const lines = [
    `Journal: ${h.journalName}`,
    `Event: ${h.description}`,
    `Started: ${h.started}`,
    `End: ${h.end}`,
    `Duration: ${h.duration}`,
    `Completion: ${h.completion}`,
    `Generated at: ${h.generatedAt}`,
    '',
    '----------------------------------------',
    '',
  ];
  const visible = visibleEntries(data);
  if (visible.length === 0) {
    lines.push('No entries.');
  }
  for (const entry of visible) {
    lines.push(`[${rowTimeString(entry, data)}] ${deriveDisplayName(data.contactsByUuid[entry.author])}`);
    lines.push(renderEntryPlainText(entry.text, { contactsByUuid: data.contactsByUuid }));
    lines.push('');
  }
  return lines.join('\n');
}

// ---- HTML ---------------------------------------------------------------

// Same values as app.css's :root / :root[data-theme="light"] tokens —
// duplicated rather than imported, since a downloaded static file can't
// reference the live app's stylesheet or CSS custom properties; this is
// a frozen snapshot of whichever palette was active at generation time,
// not a page that re-themes itself later (see docs/spec/ui-ux.md §
// Report export).
const PALETTES = {
  dark: {
    bg: '#12151a', surface: '#181d24', surface2: '#1f252d',
    border: '#2a313c', borderSoft: '#232a33',
    ink: '#e7e9ec', inkDim: '#a7aeb8', muted: '#6e7580',
    accent: '#3fc7bc',
  },
  light: {
    bg: '#f5f3ee', surface: '#ffffff', surface2: '#f1efe8',
    border: '#ddd8cc', borderSoft: '#e6e2d6',
    ink: '#1b1e22', inkDim: '#565a60', muted: '#7a7e85',
    accent: '#0e7a70',
  },
};

function escapeHtml(str) {
  return String(str).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

export function buildHtmlReport(data) {
  const pal = PALETTES[data.theme === 'light' ? 'light' : 'dark'];
  const h = reportHeader(data);
  const visible = visibleEntries(data);

  const rows = visible.map((entry) => {
    const rendered = renderEntryText(entry.text, { contactsByUuid: data.contactsByUuid, tags: data.tagsConfig });
    const rowStyle = rendered.rowHighlight ? ` style="background:${rendered.rowHighlight.bg}22;"` : '';
    return `<tr${rowStyle}><td class="t">${escapeHtml(rowTimeString(entry, data))}</td><td class="a">${escapeHtml(deriveDisplayName(data.contactsByUuid[entry.author]))}</td><td class="e">${rendered.html}</td></tr>`;
  }).join('\n        ');

  const emptyRow = `<tr><td colspan="3" class="empty">No entries.</td></tr>`;

  return `<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${escapeHtml(h.description)} — ${escapeHtml(h.journalName)}</title>
<style>
  body { margin: 0; padding: 32px 20px; background: ${pal.bg}; color: ${pal.ink}; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; font-size: 15px; line-height: 1.5; }
  .report { max-width: 900px; margin: 0 auto; }
  h1 { font-size: 1.3rem; margin: 0 0 4px; }
  .journal { color: ${pal.muted}; font-size: 0.85rem; margin-bottom: 20px; }
  .meta { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; background: ${pal.surface}; border: 1px solid ${pal.border}; border-radius: 10px; padding: 16px; margin-bottom: 24px; }
  .meta .k { display: block; font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.06em; color: ${pal.muted}; margin-bottom: 2px; }
  .meta .v { font-family: ui-monospace, "SF Mono", Consolas, monospace; font-size: 0.92rem; }
  table { width: 100%; border-collapse: collapse; background: ${pal.surface}; border: 1px solid ${pal.border}; border-radius: 10px; overflow: hidden; }
  th { text-align: left; font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.06em; color: ${pal.muted}; padding: 9px 14px; border-bottom: 1px solid ${pal.border}; background: ${pal.surface2}; }
  td { padding: 10px 14px; border-bottom: 1px solid ${pal.borderSoft}; vertical-align: top; font-size: 0.9rem; }
  tr:last-child td { border-bottom: none; }
  td.t { font-family: ui-monospace, "SF Mono", Consolas, monospace; font-size: 0.8rem; color: ${pal.inkDim}; white-space: nowrap; }
  td.a { font-weight: 600; font-size: 0.85rem; white-space: nowrap; }
  td.empty { text-align: center; color: ${pal.muted}; padding: 24px; }
  /* Same fix as the live app's .entry-text (EntriesTable.svelte) — the
     rendered entry HTML (marked, via render.js) wraps a plain paragraph
     in <p>, a list in <ul>/<ol>, etc, all carrying the browser's own
     default margin unless reset. Zero it, then add space back only
     BETWEEN multiple blocks within one entry. */
  td.e > * { margin: 0; }
  td.e > * + * { margin-top: 0.6em; }
  .chip { display: inline-flex; align-items: center; font-family: ui-monospace, "SF Mono", Consolas, monospace; font-size: 0.72rem; font-weight: 700; padding: 1px 7px; border-radius: 5px; margin: 0 2px; line-height: 1.6; }
  .chip-mention { background: ${pal.accent}22; color: ${pal.accent}; }
  .chip-update { background: #a9835f; color: #241a10; }
  .footer { margin-top: 18px; font-size: 0.76rem; color: ${pal.muted}; }
</style>
</head>
<body>
  <div class="report">
    <h1>${escapeHtml(h.description)}</h1>
    <div class="journal">${escapeHtml(h.journalName)}</div>
    <div class="meta">
      <div><span class="k">Started</span><span class="v">${escapeHtml(h.started)}</span></div>
      <div><span class="k">End</span><span class="v">${escapeHtml(h.end)}</span></div>
      <div><span class="k">Duration</span><span class="v">${escapeHtml(h.duration)}</span></div>
      <div><span class="k">Completion</span><span class="v">${escapeHtml(h.completion)}</span></div>
    </div>
    <table>
      <thead><tr><th>Time</th><th>Author</th><th>Entry</th></tr></thead>
      <tbody>
        ${visible.length ? rows : emptyRow}
      </tbody>
    </table>
    <div class="footer">Generated ${escapeHtml(h.generatedAt)}</div>
  </div>
</body>
</html>
`;
}
