// Sync orchestration — list/diff/pull/push against one journal's backend.
// See docs/spec/api-and-sync.md § Sync trigger mechanism.
//
// Deliberately does NOT run the reducer itself — pulling/pushing raw
// fragments and computing the reduced view are separate concerns.
// Callers (stores.js) react to onDataChange to re-derive whatever's
// currently being viewed. Keeps this module framework-agnostic and unit
// testable without Svelte or a DOM.

import * as api from './api.js';
import * as db from './db.js';

const DEFAULT_INTERVAL_MS = 20000;

export class SyncEngine {
  /**
   * @param {object} opts
   * @param {string} opts.baseUrl
   * @param {string} opts.journalUuid
   * @param {() => {contactUuid: string, secret: string} | null} opts.getIdentity
   * @param {(status: 'synced'|'syncing'|'offline') => void} [opts.onStatusChange]
   * @param {() => void} [opts.onDataChange] - fired after new fragments land locally (pull or successful push)
   */
  constructor({ baseUrl, journalUuid, getIdentity, onStatusChange, onDataChange, intervalMs = DEFAULT_INTERVAL_MS }) {
    this.baseUrl = baseUrl;
    this.journalUuid = journalUuid;
    this.getIdentity = getIdentity;
    this.onStatusChange = onStatusChange || (() => {});
    this.onDataChange = onDataChange || (() => {});
    this.intervalMs = intervalMs;
    this._timer = null;
    this._lastServerUpdatedAt = null;
    this._status = 'offline';
  }

  /** @returns {Promise<void>} the first sync attempt — callers may fire-and-forget this (the common case) or await it once when they need to know a pull was at least attempted before, e.g., deciding a deep-linked event doesn't exist. */
  start() {
    const first = this.syncNow();
    this._timer = setInterval(() => this.syncNow(), this.intervalMs);
    return first;
  }

  stop() {
    if (this._timer) clearInterval(this._timer);
    this._timer = null;
  }

  _setStatus(status) {
    if (status !== this._status) {
      this._status = status;
      this.onStatusChange(status);
    }
  }

  /** Called immediately after a local write, in addition to the periodic timer — see docs/spec/api-and-sync.md. */
  async syncNow() {
    const pending = await db.getPendingWrites(this.journalUuid);
    if (pending.length > 0) {
      this._setStatus('syncing');
      const pushOk = await this._pushPending();
      if (!pushOk) {
        this._setStatus('offline');
        return;
      }
    }

    this._setStatus('syncing');
    let freshness;
    try {
      freshness = await api.getFreshness(this.baseUrl, this.journalUuid);
    } catch {
      this._setStatus('offline');
      return;
    }

    if (freshness.updated_at !== this._lastServerUpdatedAt) {
      try {
        await this._pull();
        this._lastServerUpdatedAt = freshness.updated_at;
        this.onDataChange();
      } catch {
        this._setStatus('offline');
        return;
      }
    }

    this._setStatus('synced');
  }

  /** @returns {Promise<boolean>} true if the queue is now empty (or was already), false if a network error stopped it partway (still offline). */
  async _pushPending() {
    const pending = await db.getPendingWrites(this.journalUuid);
    for (const item of pending) {
      const identity = this.getIdentity();
      if (!identity) return true; // nothing we can do without an identity; not a network problem
      try {
        const parts = item.path.split('/'); // "metadata.x.json" OR "events/{uuid}/entry.y.json"
        if (parts[0] === 'events') {
          const [, eventUuid, filename] = parts;
          await api.putEventFragment(this.baseUrl, this.journalUuid, eventUuid, filename, identity.contactUuid, identity.secret, item.body);
        } else {
          await api.putFragment(this.baseUrl, this.journalUuid, item.path, identity.contactUuid, identity.secret, item.body);
        }
        await db.putFragment(this.journalUuid, item.path, item.body);
        await db.removePendingWrite(item.id);
      } catch (err) {
        if (err?.status === 409) {
          // Already landed on a prior attempt — safe to treat as success (immutable, content-addressed filenames; see docs/spec/data-model.md).
          await db.putFragment(this.journalUuid, item.path, item.body);
          await db.removePendingWrite(item.id);
          continue;
        }
        if (err?.status) {
          // A real rejection (e.g. 401) — leave it queued for visibility rather than silently dropping data.
          continue;
        }
        return false; // network error — stop, still offline
      }
    }
    return true;
  }

  async _pull() {
    const root = await api.listJournalRoot(this.baseUrl, this.journalUuid);
    const known = new Set(await db.getFragmentPaths(this.journalUuid));

    for (const filename of root.files) {
      if (!known.has(filename)) {
        const content = await api.getFragment(this.baseUrl, this.journalUuid, filename);
        await db.putFragment(this.journalUuid, filename, content);
      }
    }

    for (const eventUuid of root.events) {
      const eventList = await api.listEvent(this.baseUrl, this.journalUuid, eventUuid);
      for (const filename of eventList.files) {
        const path = `events/${eventUuid}/${filename}`;
        if (!known.has(path)) {
          const content = await api.getEventFragment(this.baseUrl, this.journalUuid, eventUuid, filename);
          await db.putFragment(this.journalUuid, path, content);
        }
      }
    }
  }
}

/**
 * Writes a fragment optimistically: stored locally + queued immediately,
 * so the UI reflects it right away regardless of connectivity, then a
 * push is attempted right away (sync-on-post — see
 * docs/spec/api-and-sync.md § Sync trigger mechanism).
 */
export async function queueWrite(engine, path, content) {
  await db.putFragment(engine.journalUuid, path, content);
  await db.addPendingWrite(engine.journalUuid, path, content);
  engine.onDataChange();
  await engine.syncNow();
}
