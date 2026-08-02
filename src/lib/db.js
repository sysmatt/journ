// Local storage layer — IndexedDB. Holds raw synced/written fragments
// (the source of truth the reducer runs over — see reducer.js and
// docs/spec/api-and-sync.md § Client-side caching strategy), plus small
// local-only state: known journals, this device's identity per journal,
// UI preferences, and entries queued while offline.
//
// No external dependency (e.g. the "idb" package) — a thin hand-rolled
// promise wrapper, consistent with "no unnecessary weight" elsewhere.

const DB_NAME = 'journ';
const DB_VERSION = 1;

/** @type {Promise<IDBDatabase>|null} */
let dbPromise = null;

function openDb() {
  if (dbPromise) return dbPromise;
  dbPromise = new Promise((resolve, reject) => {
    const req = indexedDB.open(DB_NAME, DB_VERSION);
    req.onupgradeneeded = () => {
      const db = req.result;
      // path = e.g. "metadata.abc.json" or "events/{uuid}/entry.xyz.json" — globally unique per journal.
      const fragments = db.createObjectStore('fragments', { keyPath: ['journalUuid', 'path'] });
      fragments.createIndex('byJournal', 'journalUuid');

      db.createObjectStore('journals', { keyPath: 'uuid' });       // known/bookmarked journals
      db.createObjectStore('identities', { keyPath: 'journalUuid' }); // this device's contact+secret per journal
      db.createObjectStore('preferences', { keyPath: 'key' });      // theme, time-display mode, etc.

      const pending = db.createObjectStore('pendingWrites', { keyPath: 'id', autoIncrement: true });
      pending.createIndex('byJournal', 'journalUuid');
    };
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
  return dbPromise;
}

/** Promisifies a single IDBRequest. */
function reqToPromise(req) {
  return new Promise((resolve, reject) => {
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}

async function withStore(storeName, mode, fn) {
  const db = await openDb();
  const tx = db.transaction(storeName, mode);
  const store = tx.objectStore(storeName);
  const result = await fn(store);
  await new Promise((resolve, reject) => {
    tx.oncomplete = () => resolve();
    tx.onerror = () => reject(tx.error);
  });
  return result;
}

// ---- fragments -------------------------------------------------------

/** @param {string} path e.g. "metadata.abc.json" or "events/{uuid}/entry.xyz.json" */
export async function putFragment(journalUuid, path, content) {
  return withStore('fragments', 'readwrite', (store) =>
    reqToPromise(store.put({ journalUuid, path, content, fetchedAt: new Date().toISOString() }))
  );
}

export async function getFragmentPaths(journalUuid) {
  const rows = await withStore('fragments', 'readonly', (store) =>
    reqToPromise(store.index('byJournal').getAll(IDBKeyRange.only(journalUuid)))
  );
  return rows.map((r) => r.path);
}

/** Decoded content of every locally-known fragment for a journal whose path starts with `prefix` (e.g. "" for journal-root, "events/{uuid}/" for one event). */
export async function getFragmentsByPrefix(journalUuid, prefix = '') {
  const rows = await withStore('fragments', 'readonly', (store) =>
    reqToPromise(store.index('byJournal').getAll(IDBKeyRange.only(journalUuid)))
  );
  return rows.filter((r) => r.path.startsWith(prefix)).map((r) => r.content);
}

// ---- known journals ----------------------------------------------------

export async function saveJournalBookmark(uuid, name, baseUrl) {
  return withStore('journals', 'readwrite', (store) => reqToPromise(store.put({ uuid, name, baseUrl })));
}

export async function getKnownJournals() {
  return withStore('journals', 'readonly', (store) => reqToPromise(store.getAll()));
}

/**
 * Keeps a bookmark's display name in sync with the real journal name once
 * it's known. An invited contact's bookmark is seeded with the raw journal
 * uuid as a placeholder (App.svelte has no name to show yet at invite
 * time) — this is what's meant to replace it once the first sync pulls
 * real metadata. Returns true if the name actually changed (callers use
 * this to decide whether knownJournals needs re-reading).
 */
export async function syncJournalBookmarkName(uuid, name) {
  return withStore('journals', 'readwrite', async (store) => {
    const existing = await reqToPromise(store.get(uuid));
    if (!existing || existing.name === name) return false;
    await reqToPromise(store.put({ ...existing, name }));
    return true;
  });
}

// ---- identity (this device's contact + secret, per journal) -----------

export async function saveIdentity(journalUuid, contactUuid, secret) {
  return withStore('identities', 'readwrite', (store) =>
    reqToPromise(store.put({ journalUuid, contactUuid, secret }))
  );
}

export async function getIdentity(journalUuid) {
  return withStore('identities', 'readonly', (store) => reqToPromise(store.get(journalUuid)));
}

// ---- preferences (client-side only — never synced, see data-model.md) --

export async function getPreference(key, fallback = null) {
  const row = await withStore('preferences', 'readonly', (store) => reqToPromise(store.get(key)));
  return row ? row.value : fallback;
}

export async function setPreference(key, value) {
  return withStore('preferences', 'readwrite', (store) => reqToPromise(store.put({ key, value })));
}

// ---- pending writes (queued while offline; drives the sync-status icon) --

export async function addPendingWrite(journalUuid, path, body) {
  return withStore('pendingWrites', 'readwrite', (store) =>
    reqToPromise(store.add({ journalUuid, path, body, queuedAt: new Date().toISOString() }))
  );
}

export async function getPendingWrites(journalUuid) {
  return withStore('pendingWrites', 'readonly', (store) =>
    reqToPromise(store.index('byJournal').getAll(IDBKeyRange.only(journalUuid)))
  );
}

export async function removePendingWrite(id) {
  return withStore('pendingWrites', 'readwrite', (store) => reqToPromise(store.delete(id)));
}

// ---- full local reset ("forget this device") ---------------------------

/**
 * Wipes every locally cached journal, identity, preference, and pending
 * write on this device — nothing on the server is touched (the server
 * has no concept of this). Closes the open connection first; an IDB
 * database can't be deleted while something still holds it open, which
 * would otherwise hang this on `onblocked` forever.
 */
export async function resetAllLocalData() {
  if (dbPromise) {
    const db = await dbPromise;
    db.close();
    dbPromise = null;
  }
  return new Promise((resolve, reject) => {
    const req = indexedDB.deleteDatabase(DB_NAME);
    req.onsuccess = () => resolve();
    req.onerror = () => reject(req.error);
    req.onblocked = () => resolve(); // proceeds once whatever's blocking it releases; caller reloads regardless
  });
}
