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

  async function regenerate(contactUuid) {
    if (!confirm('Regenerate this key? Whatever device/link currently has the old one will be locked out immediately.')) return;
    busyContact = contactUuid;
    try {
      const identity = await getIdentity($currentJournalUuid);
      const existing = $contacts[contactUuid];
      const newSecret = generateSecret();
      const fragment = { ...existing, updated_at: new Date().toISOString(), updated_by: identity.contactUuid, secret_hash: await hashSecret(newSecret) };
      await queueWrite(getEngine(), `contact.${crypto.randomUUID()}.json`, fragment);
      heldSecrets = { ...heldSecrets, [contactUuid]: newSecret };
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
    <div class="contact-row">
      <div class="who">
        <span class="avatar">{name.slice(0, 2).toUpperCase()}</span>
        <div>
          <div class="name">{c.name || '— not set —'}{uuid === ownContactUuid ? ' (you)' : ''}</div>
          <div class="short">{c.short_name || '— not set —'}</div>
        </div>
      </div>
      <div></div>
      <div class="email">{c.email || '—'}</div>
      <div class="contact-actions">
        <button class="icon-btn" title="Copy invite link" disabled={!heldSecrets[uuid]} onclick={() => copyLink(uuid)}>🔗</button>
        <button class="icon-btn" title="Send invite email" disabled={!heldSecrets[uuid] || busyContact === uuid} onclick={() => sendInviteEmail(uuid)}>✉</button>
        <button class="icon-btn" title="Regenerate key (locks out current holder)" disabled={busyContact === uuid} onclick={() => regenerate(uuid)}>⟳</button>
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
