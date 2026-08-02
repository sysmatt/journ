// Shared time-formatting primitives — extracted so EntriesTable.svelte,
// EventStats.svelte, and report.js all render identically instead of
// three near-copies drifting apart. Pure functions, no stores/DOM.

export function pad(n) {
  return String(n).padStart(2, '0');
}

/** @returns {{time: string, zone: string, date: string}} */
export function fmtAbsolute(iso, useLocal) {
  if (!iso) return { time: '—', zone: '', date: '' };
  const d = new Date(iso);
  const h = useLocal ? d.getHours() : d.getUTCHours();
  const m = useLocal ? d.getMinutes() : d.getUTCMinutes();
  const mo = useLocal ? d.getMonth() : d.getUTCMonth();
  const day = useLocal ? d.getDate() : d.getUTCDate();
  return { time: `${pad(h)}:${pad(m)}`, zone: useLocal ? '' : 'Z', date: `${pad(mo + 1)}-${pad(day)}` };
}

/** @param {Date} end */
export function fmtDuration(startIso, end) {
  if (!startIso) return '—';
  const totalSec = Math.max(0, Math.floor((end - new Date(startIso)) / 1000));
  const h = Math.floor(totalSec / 3600);
  const m = Math.floor((totalSec % 3600) / 60);
  const s = totalSec % 60;
  return `${pad(h)}:${pad(m)}:${pad(s)}`;
}

/** T-mode relative offset — `T+hh:mm:ss` / `T-hh:mm:ss`. */
export function fmtDelta(entryIso, refIso) {
  const deltaMs = new Date(entryIso).getTime() - new Date(refIso).getTime();
  const sign = deltaMs < 0 ? '−' : '+';
  const totalSec = Math.round(Math.abs(deltaMs) / 1000);
  const h = Math.floor(totalSec / 3600);
  const m = Math.floor((totalSec % 3600) / 60);
  const s = totalSec % 60;
  return `T${sign}${pad(h)}:${pad(m)}:${pad(s)}`;
}
