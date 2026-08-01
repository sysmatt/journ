// The ONE generalized LWW reducer (docs/spec/data-model.md § Read/merge
// strategy) — the client-side twin of api/lib/reducer.php. Same
// algorithm, same shapes; kept as a deliberate mirror rather than a
// shared import, since client and server are different runtimes.
//
// Used per docs/spec/api-and-sync.md § Client-side caching strategy:
// "full reduce, then cache the result" — this module is always run over
// the COMPLETE fragment set on every change, never patched incrementally.

/**
 * Given a flat list of {id, updated_at, data} records, keeps the latest
 * (by updated_at) record per id. This is the entire LWW algorithm —
 * everything else here just adapts fragment shapes into this common form.
 * @param {{id: string, updated_at: string, data: object}[]} records
 * @returns {Map<string, {id: string, updated_at: string, data: object}>}
 */
export function reduceByLatest(records) {
  const winners = new Map();
  for (const record of records) {
    const existing = winners.get(record.id);
    if (!existing || Date.parse(record.updated_at) >= Date.parse(existing.updated_at)) {
      winners.set(record.id, record);
    }
  }
  return winners;
}

/** @param {object[]} fragments — decoded metadata.*.json contents */
export function reduceJournalMetadata(fragments) {
  const records = fragments.map((f) => ({ id: f.journal, updated_at: f.updated_at, data: f }));
  const winners = reduceByLatest(records);
  const first = winners.values().next().value;
  return first ? first.data : null;
}

/** @returns {Record<string, object>} contact_uuid -> current contact record */
export function reduceContacts(fragments) {
  const records = fragments.filter((f) => f.contact).map((f) => ({ id: f.contact, updated_at: f.updated_at, data: f }));
  const winners = reduceByLatest(records);
  const result = {};
  for (const [id, w] of winners) result[id] = w.data;
  return result;
}

export function reduceEventMetadata(fragments) {
  const records = fragments.map((f) => ({ id: f.event, updated_at: f.updated_at, data: f }));
  const winners = reduceByLatest(records);
  const first = winners.values().next().value;
  return first ? first.data : null;
}

/**
 * @param {{entries: object[]}[]} fragments — decoded entry.*.json contents (each holds 1+ entries)
 * @returns {object[]} entries, most-recent-first (see docs/spec/ui-ux.md)
 */
export function reduceEntries(fragments) {
  const records = [];
  for (const fragment of fragments) {
    for (const entry of fragment.entries || []) {
      if (!entry.uuid) continue;
      records.push({ id: entry.uuid, updated_at: entry.updated_at || entry.created_at, data: entry });
    }
  }
  const winners = reduceByLatest(records);
  const entries = [...winners.values()].map((w) => w.data);
  entries.sort((a, b) => Date.parse(b.created_at || 0) - Date.parse(a.created_at || 0));
  return entries;
}
