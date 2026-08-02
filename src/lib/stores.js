// App-wide state + the orchestration layer between UI components and
// db.js/api.js/sync.js/reducer.js. Classic Svelte stores (not runes) so
// this stays importable from plain .js modules, not just components.

import { writable, get } from 'svelte/store';
import * as db from './db.js';
import * as api from './api.js';
import { SyncEngine, queueWrite } from './sync.js';
import { reduceJournalMetadata, reduceContacts, reduceEventMetadata, reduceEntries } from './reducer.js';

// ---- local-only preferences (never synced — see docs/spec/data-model.md) --
export const theme = writable('dark');
export const timeMode = writable('utc'); // 'utc' | 'local' | 't' — see docs/spec/ui-ux.md
export const tRefEntryUuid = writable(null); // T-mode reference row, resets per event selection

// ---- journal/event selection ------------------------------------------
export const knownJournals = writable([]); // [{uuid, name, baseUrl}]
export const currentJournalUuid = writable(null);
export const currentEventUuid = writable(null);

// ---- reduced (LWW-merged) view of whatever's currently selected --------
export const journalMeta = writable(null);
export const contacts = writable({});       // contact_uuid -> record
export const eventsSummary = writable({});  // event_uuid -> reduced metadata (for the event picker)
export const eventMeta = writable(null);
export const entries = writable([]);

export const syncStatus = writable('offline');
export const tagsConfig = writable([]); // from GET /tags, declaration order preserved

// The create-journal (bootstrap) secret is install-wide, not per-contact
// (see docs/spec/identity-and-security.md § Bootstrap) — once someone has
// used it successfully on this device, remembering it locally saves them
// from having to dig up the original create-journal link every time they
// want to start another journal. It's stored the same way as theme/timeMode
// (local-only preference, never synced) — see db.js § preferences.
export const rememberedBootstrapSecret = writable('');

let engine = null;

export async function initApp() {
  theme.set(await db.getPreference('theme', 'dark'));
  timeMode.set(await db.getPreference('timeMode', 'utc'));
  knownJournals.set(await db.getKnownJournals());
  rememberedBootstrapSecret.set(await db.getPreference('bootstrapSecret', ''));
}

export async function rememberBootstrapSecret(secret) {
  rememberedBootstrapSecret.set(secret);
  await db.setPreference('bootstrapSecret', secret);
}

export async function setTheme(value) {
  theme.set(value);
  await db.setPreference('theme', value);
}

export async function setTimeMode(value) {
  timeMode.set(value);
  await db.setPreference('timeMode', value);
}

async function findEventUuids(journalUuid) {
  const paths = await db.getFragmentPaths(journalUuid);
  const uuids = new Set();
  for (const p of paths) {
    const m = p.match(/^events\/([^/]+)\//);
    if (m) uuids.add(m[1]);
  }
  return uuids;
}

async function recomputeJournalLevel(journalUuid) {
  const meta = reduceJournalMetadata(await db.getFragmentsByPrefix(journalUuid, 'metadata.'));
  journalMeta.set(meta);
  // An invited contact's journal bookmark starts out showing the raw
  // journal uuid as a placeholder name (see App.svelte's onboarding
  // branch — there's no real name to show yet at invite time). Nothing
  // else ever corrects it, so without this it stays a uuid in the
  // journal picker forever, even long after the real name has synced.
  if (meta?.name && (await db.syncJournalBookmarkName(journalUuid, meta.name))) {
    knownJournals.set(await db.getKnownJournals());
  }
  contacts.set(reduceContacts(await db.getFragmentsByPrefix(journalUuid, 'contact.')));

  const summary = {};
  for (const eventUuid of await findEventUuids(journalUuid)) {
    summary[eventUuid] = reduceEventMetadata(await db.getFragmentsByPrefix(journalUuid, `events/${eventUuid}/metadata.`));
  }
  eventsSummary.set(summary);
}

async function recomputeEventLevel(journalUuid, eventUuid) {
  if (!eventUuid) {
    eventMeta.set(null);
    entries.set([]);
    return;
  }
  eventMeta.set(reduceEventMetadata(await db.getFragmentsByPrefix(journalUuid, `events/${eventUuid}/metadata.`)));
  entries.set(reduceEntries(await db.getFragmentsByPrefix(journalUuid, `events/${eventUuid}/entry.`)));
}

export function getEngine() {
  return engine;
}

/**
 * "Forget this device" — stops syncing and wipes every locally cached
 * journal/identity/preference. Local-only; nothing server-side is
 * touched. Callers should reload the page immediately after (App.svelte's
 * onMount is what actually rebuilds all the in-memory state — there's no
 * point resetting Svelte stores by hand here just to have onMount
 * re-derive them from a now-empty IndexedDB a moment later).
 */
export async function resetLocalData() {
  if (engine) {
    engine.stop();
    engine = null;
  }
  await db.resetAllLocalData();
}

/**
 * Reflects the current journal/event into the URL (replaceState — no
 * history-entry growth, so browser back/forward deliberately does NOT
 * step through events) so a reload or the PWA update-and-reload no
 * longer drops back to "nothing selected" — see App.svelte's onMount,
 * which reads these same `j`/`e` params to restore this on load.
 */
function syncUrlParams(journalUuid, eventUuid) {
  const url = new URL(window.location.href);
  if (journalUuid) url.searchParams.set('j', journalUuid); else url.searchParams.delete('j');
  if (eventUuid) url.searchParams.set('e', eventUuid); else url.searchParams.delete('e');
  window.history.replaceState({}, '', url);
}

export async function selectJournal(journalUuid, baseUrl) {
  if (engine) engine.stop();
  currentJournalUuid.set(journalUuid);
  currentEventUuid.set(null);
  eventMeta.set(null);
  entries.set([]);
  syncUrlParams(journalUuid, null);

  engine = new SyncEngine({
    baseUrl,
    journalUuid,
    getIdentity: () => get(_identityCache),
    onStatusChange: (status) => syncStatus.set(status),
    onDataChange: async () => {
      await recomputeJournalLevel(journalUuid);
      const evUuid = get(currentEventUuid);
      if (evUuid) await recomputeEventLevel(journalUuid, evUuid);
    },
  });

  const identity = await db.getIdentity(journalUuid);
  _identityCache.set(identity);

  await recomputeJournalLevel(journalUuid);
  // Fire-and-forget for normal callers (switching journals should feel
  // instant off the local cache) — but hand back the first-sync promise
  // so a caller that specifically needs to know a pull was *attempted*
  // (e.g. App.svelte validating a deep-linked event actually exists
  // before giving up on it) can choose to await it.
  const firstSync = engine.start();

  try {
    tagsConfig.set((await api.getTags(baseUrl)).tags);
  } catch {
    // offline on first load — chips fall back to defaults until a sync succeeds
  }

  return firstSync;
}

// Internal — identity doesn't need to be reactive UI state, just needs to
// be readable synchronously from the sync engine's getIdentity() callback.
const _identityCache = writable(null);

export async function selectEvent(eventUuid) {
  currentEventUuid.set(eventUuid);
  tRefEntryUuid.set(null);
  syncUrlParams(get(currentJournalUuid), eventUuid);
  await recomputeEventLevel(get(currentJournalUuid), eventUuid);
}

// ---- writes --------------------------------------------------------------

function nowIso() {
  return new Date().toISOString();
}

export async function postEntry(text, attachments = []) {
  const journalUuid = get(currentJournalUuid);
  const eventUuid = get(currentEventUuid);
  const identity = get(_identityCache);
  const now = nowIso();
  const entryUuid = crypto.randomUUID();

  const fragment = {
    v: 1,
    entries: [
      { uuid: entryUuid, journal: journalUuid, event: eventUuid, author: identity.contactUuid, created_at: now, updated_at: now, text, trashed: false, attachments },
    ],
  };

  await queueWrite(engine, `events/${eventUuid}/entry.${entryUuid}.json`, fragment);
  await recomputeEventLevel(journalUuid, eventUuid);
}

/**
 * Edits an existing entry. IMPORTANT: the fragment filename is a FRESH
 * uuid, distinct from the entry's own persistent `uuid` — see
 * docs/spec/data-model.md § Entry write model for why reusing the
 * entry's own uuid as the filename would break (immutable/add-only
 * storage would reject the edit as a duplicate of the original).
 */
export async function editEntry(entryUuid, newText) {
  const journalUuid = get(currentJournalUuid);
  const eventUuid = get(currentEventUuid);
  const identity = get(_identityCache);
  const existing = get(entries).find((e) => e.uuid === entryUuid);
  if (!existing) return;

  const fragment = {
    v: 1,
    entries: [{ ...existing, author: identity.contactUuid, updated_at: nowIso(), text: newText }],
  };

  const fragmentUuid = crypto.randomUUID(); // fresh — NOT entryUuid, see docstring above
  await queueWrite(engine, `events/${eventUuid}/entry.${fragmentUuid}.json`, fragment);
  await recomputeEventLevel(journalUuid, eventUuid);
}

/** Trashing is a metadata-flag edit, not a delete — see docs/spec/data-model.md § Entry deletion semantics. Same filename-freshness rule as editEntry. */
export async function trashEntry(entryUuid, trashed = true) {
  const journalUuid = get(currentJournalUuid);
  const eventUuid = get(currentEventUuid);
  const identity = get(_identityCache);
  const existing = get(entries).find((e) => e.uuid === entryUuid);
  if (!existing) return;

  const fragment = {
    v: 1,
    entries: [{ ...existing, author: identity.contactUuid, updated_at: nowIso(), trashed }],
  };

  await queueWrite(engine, `events/${eventUuid}/entry.${crypto.randomUUID()}.json`, fragment);
  await recomputeEventLevel(journalUuid, eventUuid);
}

/**
 * `isNew` is REQUIRED, not inferred — see docs/brain-dump.md-worthy bug
 * fixed 2026-08-01: this used to guess create-vs-edit from
 * currentJournalUuid/eventMeta store state, which is still populated
 * with the PREVIOUSLY selected event when opening "+ New event" (nothing
 * clears it first) — so creating a new event while one was already
 * selected silently overwrote the old one instead of creating a second.
 * The caller (MetaModal) always unambiguously knows which case it is via
 * its own `mode` prop — trust that, don't re-derive it from state that
 * can be stale.
 */
export async function saveEventMeta({ description, startAt, closed, isNew }) {
  const journalUuid = get(currentJournalUuid);
  const eventUuid = isNew ? crypto.randomUUID() : get(currentEventUuid);
  const identity = get(_identityCache);
  const existing = isNew ? null : get(eventMeta);

  const fragment = {
    v: 1,
    journal: journalUuid,
    event: eventUuid,
    updated_at: nowIso(),
    updated_by: identity.contactUuid,
    start_at: startAt ?? existing?.start_at ?? nowIso(),
    creator: existing?.creator ?? identity.contactUuid,
    description: description ?? existing?.description ?? '',
    closed: closed ?? existing?.closed ?? false,
    closed_at: (closed ?? existing?.closed) ? (existing?.closed_at ?? nowIso()) : null,
    closed_by: (closed ?? existing?.closed) ? identity.contactUuid : null,
  };

  await queueWrite(engine, `events/${eventUuid}/metadata.${crypto.randomUUID()}.json`, fragment);
  await recomputeJournalLevel(journalUuid);
  if (isNew) {
    await selectEvent(eventUuid);
  } else {
    await recomputeEventLevel(journalUuid, eventUuid);
  }
  return eventUuid;
}

/**
 * Journal creation — gated by the bootstrap secret, normally arriving
 * embedded in a "create new journal" link (see
 * docs/spec/identity-and-security.md § Bootstrap), pre-filled by
 * App.svelte from the URL on load. Manual entry is also accepted as a
 * fallback (e.g. for local testing without going through a real link).
 */
export async function createJournal(baseUrl, bootstrapSecret, name, creator) {
  const resp = await api.createJournal(baseUrl, bootstrapSecret, { mode: 'create', name, creator });
  const journalUuid = resp.journal;
  const me = resp.contacts[0];
  await db.saveIdentity(journalUuid, me.contact, me.secret);
  await db.saveJournalBookmark(journalUuid, name, baseUrl);
  knownJournals.set(await db.getKnownJournals());
  await selectJournal(journalUuid, baseUrl);
  return journalUuid;
}

export async function saveJournalName(name) {
  const journalUuid = get(currentJournalUuid);
  const identity = get(_identityCache);
  const existing = get(journalMeta);

  const fragment = { ...existing, updated_at: nowIso(), updated_by: identity.contactUuid, name };
  await queueWrite(engine, `metadata.${crypto.randomUUID()}.json`, fragment);
  await recomputeJournalLevel(journalUuid);
}
