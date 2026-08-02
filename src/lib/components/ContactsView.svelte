<script>
  // Contact management — see docs/spec/ui-ux.md § Contact management
  // page and docs/spec/identity-and-security.md.
  //
  // Real constraint this has to work around: the server never stores a
  // contact's plaintext secret (only its hash), so "copy invite link" is
  // only possible for a contact whose plaintext THIS device currently
  // holds — its own identity, or one it just regenerated. There is no
  // way to fetch an existing contact's working link from the server.
  import { contacts, currentJournalUuid, journalMeta, getEngine } from '../stores.js';
  import { queueWrite } from '../sync.js';
  import { bulkContacts as apiBulkContacts, inviteContact as apiInviteContact } from '../api.js';
  import { getIdentity } from '../db.js';
  import { generateSecret, hashSecret } from '../secrets.js';
  import { deriveDisplayName } from '../render.js';

  // baseUrl: the API base (/api-prefixed) — used for the actual fetch
  // calls below. siteBaseUrl: where the app itself is served from, used
  // ONLY for building the invite link a browser navigates to directly —
  // must NOT carry the /api prefix. See App.svelte for why these two are
  // deliberately different values, not the same thing twice.
  let { baseUrl, siteBaseUrl } = $props();

  let bulkEmails = $state('');
  let bulkSendInvites = $state(true);
  let bulkBusy = $state(false);
  let bulkResult = $state(null);

  let heldSecrets = $state({}); // contact_uuid -> plaintext secret THIS device currently knows
  let ownContactUuid = $state(null);
  let busyContact = $state(null);

  let editingContact = $state(null); // contact_uuid | null
  let editName = $state('');
  let editShortName = $state('');
  let editEmail = $state('');
  let editBusy = $state(false);

  $effect(() => {
    getIdentity($currentJournalUuid).then((id) => {
      ownContactUuid = id?.contactUuid ?? null;
      if (id) heldSecrets = { ...heldSecrets, [id.contactUuid]: id.secret };
    });
  });

  function inviteUrl(contactUuid, secret) {
    // Prefer the journal's own recorded storage.base_url (authoritative,
    // set server-side at journal creation — see api/routes/journal.php)
    // over the prop, in case the app is ever reached from a different
    // origin than the one the journal itself was created against.
    const site = $journalMeta?.storage?.base_url || siteBaseUrl;
    return `${site}/invite?journal=${$currentJournalUuid}&contact=${contactUuid}&secret=${secret}`;
  }

  /**
   * Regenerate AND reactivate share one function: both are "issue a
   * fresh secret," and reactivating a deleted contact is really just
   * that plus clearing the deleted flag — see deleteContact() below,
   * which sets secret_hash: null. Doing it this way means undo is exact:
   * same mechanism, opposite direction, not a separate parallel path.
   */
  async function regenerate(contactUuid) {
    const reactivating = !!$contacts[contactUuid]?.deleted;
    const prompt = reactivating
      ? 'Reactivate this contact? They\'ll get a fresh key and be restored to active status.'
      : 'Regenerate this key? Whatever device/link currently has the old one will be locked out immediately.';
    if (!confirm(prompt)) return;
    busyContact = contactUuid;
    try {
      const identity = await getIdentity($currentJournalUuid);
      const existing = $contacts[contactUuid];
      const newSecret = generateSecret();
      const fragment = {
        ...existing,
        updated_at: new Date().toISOString(),
        updated_by: identity.contactUuid,
        secret_hash: await hashSecret(newSecret),
        deleted: false,
        deleted_at: null,
        deleted_by: null,
      };
      await queueWrite(getEngine(), `contact.${crypto.randomUUID()}.json`, fragment);
      heldSecrets = { ...heldSecrets, [contactUuid]: newSecret };
    } finally {
      busyContact = null;
    }
  }

  function openEdit(contactUuid) {
    const c = $contacts[contactUuid];
    editName = c?.name || '';
    editShortName = c?.short_name || '';
    editEmail = c?.email || '';
    editingContact = contactUuid;
  }

  function closeEdit() {
    editingContact = null;
  }

  async function saveEdit() {
    editBusy = true;
    try {
      const identity = await getIdentity($currentJournalUuid);
      const existing = $contacts[editingContact];
      const fragment = {
        ...existing,
        updated_at: new Date().toISOString(),
        updated_by: identity.contactUuid,
        name: editName.trim() || null,
        short_name: editShortName.trim() || null,
        email: editEmail.trim() || null,
      };
      await queueWrite(getEngine(), `contact.${crypto.randomUUID()}.json`, fragment);
      editingContact = null;
    } finally {
      editBusy = false;
    }
  }

  /**
   * Soft delete — a metadata flag, not a real removal (see
   * docs/spec/data-model.md § Contact deletion): keeps the record (and
   * therefore proper attribution on their past entries) but clears
   * secret_hash so their old secret can no longer authenticate — same
   * mechanism as regenerate() above, just to a null instead of a new
   * hash. Reversible via the Reactivate button regenerate() becomes
   * once deleted (see the row template) — an ordinary contact edit
   * either way, so undo is exact, not a separate parallel path.
   */
  async function deleteContact(contactUuid) {
    const name = deriveDisplayName($contacts[contactUuid]);
    if (!confirm(`Delete ${name}? Their past entries stay attributed to them, but they'll immediately lose write access. This can be undone later via Reactivate. Continue?`)) return;
    busyContact = contactUuid;
    try {
      const identity = await getIdentity($currentJournalUuid);
      const existing = $contacts[contactUuid];
      const fragment = {
        ...existing,
        updated_at: new Date().toISOString(),
        updated_by: identity.contactUuid,
        secret_hash: null,
        deleted: true,
        deleted_at: new Date().toISOString(),
        deleted_by: identity.contactUuid,
      };
      await queueWrite(getEngine(), `contact.${crypto.randomUUID()}.json`, fragment);
    } finally {
      busyContact = null;
    }
  }

  async function copyLink(contactUuid) {
    const secret = heldSecrets[contactUuid];
    if (!secret) return;
    await navigator.clipboard.writeText(inviteUrl(contactUuid, secret));
  }

  async function sendInviteEmail(contactUuid) {
    const secret = heldSecrets[contactUuid];
    if (!secret) return;
    busyContact = contactUuid;
    try {
      const identity = await getIdentity($currentJournalUuid);
      await apiInviteContact(baseUrl, $currentJournalUuid, contactUuid, identity.contactUuid, identity.secret, secret);
    } finally {
      busyContact = null;
    }
  }

  async function submitBulk() {
    const emails = bulkEmails.split('\n').map((s) => s.trim()).filter(Boolean);
    if (emails.length === 0) return;
    bulkBusy = true;
    bulkResult = null;
    try {
      const identity = await getIdentity($currentJournalUuid);
      const resp = await apiBulkContacts(baseUrl, $currentJournalUuid, identity.contactUuid, identity.secret, emails, bulkSendInvites);
      bulkResult = resp.created;
      // Server already wrote the contact fragments — pull them in on the next sync;
      // also hold their fresh secrets locally in case this device wants to copy/resend.
      const held = {};
      for (const c of resp.created) held[c.contact] = c.secret;
      heldSecrets = { ...heldSecrets, ...held };
      bulkEmails = '';
    } finally {
      bulkBusy = false;
    }
  }
</script>

<div class="panel">
  <h2>Contacts</h2>
  <p class="sub">{$journalMeta?.name ?? ''} · journal-scoped, not shared across journals</p>

  {#each Object.entries($contacts) as [uuid, c] (uuid)}
    {@const name = deriveDisplayName(c)}
    <div class="contact-row" class:is-deleted={c.deleted}>
      <div class="who">
        <span class="avatar">{name.slice(0, 2).toUpperCase()}</span>
        <div>
          <div class="name">{c.name || '— not set —'}{uuid === ownContactUuid ? ' (you)' : ''}{c.deleted ? ' (deleted)' : ''}</div>
          <div class="short">{c.short_name || '— not set —'}</div>
        </div>
      </div>
      <div></div>
      <div class="email">{c.email || '—'}</div>
      <div class="contact-actions">
        {#if c.deleted}
          <button class="icon-btn" title="Reactivate contact (issues a fresh key)" disabled={busyContact === uuid} onclick={() => regenerate(uuid)}>⟲</button>
        {:else}
          {@const noSecretTip = "Invite secrets aren't stored anywhere — only the device that created or last regenerated this contact's key ever knows it, and only until you navigate away. Use ⟳ Regenerate to issue a new invite link (this immediately logs out any device using the old one)."}
          <button class="icon-btn" title="Edit name / short name / email" disabled={busyContact === uuid} onclick={() => openEdit(uuid)}>✎</button>
          <button class="icon-btn" title={heldSecrets[uuid] ? 'Copy invite link' : noSecretTip} disabled={!heldSecrets[uuid]} onclick={() => copyLink(uuid)}>🔗</button>
          <button class="icon-btn" title={heldSecrets[uuid] ? 'Send invite email' : noSecretTip} disabled={!heldSecrets[uuid] || busyContact === uuid} onclick={() => sendInviteEmail(uuid)}>✉</button>
          <button class="icon-btn" title="Regenerate key (locks out current holder)" disabled={busyContact === uuid} onclick={() => regenerate(uuid)}>⟳</button>
          {#if uuid !== ownContactUuid}
            <button class="icon-btn danger" title="Delete contact (keeps their past entries attributed to them)" disabled={busyContact === uuid} onclick={() => deleteContact(uuid)}>🗑</button>
          {/if}
        {/if}
      </div>
    </div>
  {/each}

  <div class="bulk-box">
    <h3>Bulk invite</h3>
    <div class="bulk-row">
      <textarea bind:value={bulkEmails} placeholder="Paste one email per line…"></textarea>
      <div class="btn-col">
        <label class="checkline"><input type="checkbox" bind:checked={bulkSendInvites} /> Send invites</label>
        <button class="primary-btn" disabled={bulkBusy} onclick={submitBulk}>{bulkBusy ? 'Working…' : 'Create contacts'}</button>
      </div>
    </div>
    {#if bulkResult}
      <p class="sub">Created {bulkResult.length} contact(s){bulkSendInvites ? ` — ${bulkResult.filter((c) => c.invited).length} email(s) sent` : ''}.</p>
    {/if}
  </div>
</div>

{#if editingContact}
  <div class="modal-veil" onclick={(e) => { if (e.target === e.currentTarget) closeEdit(); }} role="presentation">
    <div class="modal" role="dialog" aria-modal="true" aria-label="Edit contact">
      <h3>Edit contact</h3>
      <p class="sub">{editingContact === ownContactUuid ? 'Your own details' : ''}</p>
      <div class="field">
        <label for="cv-name">Name</label>
        <input id="cv-name" type="text" bind:value={editName} />
      </div>
      <div class="field">
        <label for="cv-short">Short name</label>
        <input id="cv-short" type="text" bind:value={editShortName} placeholder="e.g. MattH" />
      </div>
      <div class="field">
        <label for="cv-email">Email</label>
        <input id="cv-email" type="email" bind:value={editEmail} />
      </div>
      <div class="modal-actions">
        <button type="button" class="ghost-btn" onclick={closeEdit} disabled={editBusy}>Cancel</button>
        <button type="button" class="primary-btn" onclick={saveEdit} disabled={editBusy}>{editBusy ? 'Saving…' : 'Save'}</button>
      </div>
    </div>
  </div>
{/if}

<style>
  .panel { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 16px; }
  .panel h2 { margin: 0 0 3px; font-size: 1.05rem; }
  .panel .sub { color: var(--muted); font-size: 0.82rem; margin: 0 0 14px; }

  .contact-row { display: grid; grid-template-columns: 1.3fr 1fr 1.4fr auto; align-items: center; gap: 14px; padding: 11px 4px; border-bottom: 1px solid var(--border-soft); }
  .contact-row:last-of-type { border-bottom: none; }
  .contact-row .who { display: flex; align-items: center; gap: 10px; }
  .contact-row .name { font-weight: 700; font-size: 0.9rem; }
  .contact-row .short { font-family: var(--font-mono); font-size: 0.76rem; color: var(--accent); }
  .contact-row .email { font-size: 0.83rem; color: var(--ink-dim); }
  .contact-actions { display: flex; gap: 3px; }
  .contact-actions .icon-btn { width: 34px; height: 34px; font-size: 1.2rem; border-radius: 7px; }
  .contact-actions .icon-btn:disabled { opacity: 0.35; cursor: default; }
  .contact-actions .icon-btn.danger:hover { background: var(--danger); color: var(--accent-ink); }

  .contact-row.is-deleted .name,
  .contact-row.is-deleted .short,
  .contact-row.is-deleted .email { text-decoration: line-through; color: var(--muted); }

  .bulk-box { margin-top: 18px; border-top: 1px solid var(--border); padding-top: 16px; }
  .bulk-box h3 { margin: 0 0 8px; font-size: 0.85rem; }
  .bulk-row { display: flex; gap: 10px; align-items: flex-start; }
  .bulk-row textarea { flex: 1; min-height: 70px; background: var(--surface-2); border: 1px solid var(--border); border-radius: 8px; padding: 9px 11px; font-family: var(--font-mono); font-size: 0.82rem; }
  .btn-col { display: flex; flex-direction: column; gap: 8px; align-items: flex-start; }
  .checkline { display: flex; align-items: center; gap: 7px; font-size: 0.8rem; color: var(--ink-dim); }

  @media (max-width: 720px) {
    .contact-row { grid-template-columns: 1fr auto; }
    .contact-row .email { display: none; }
  }
</style>
