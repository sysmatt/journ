<script>
  // Shared modal for New/Edit Journal and New/Edit Event — see
  // docs/spec/ui-ux.md § Top bar: "reuses the same modal/form as new,
  // pre-filled with current values, submitting as an update rather than
  // a create."
  import { createJournal, saveJournalName, saveEventMeta, rememberBootstrapSecret, currentJournalUuid } from '../stores.js';
  import { getIdentity } from '../db.js';
  import { generateSecret } from '../secrets.js';

  let { mode, journalMeta = null, eventMeta = null, baseUrl, prefillBootstrapSecret = '', onClose } = $props();

  // Local wall-clock values, no timezone attached (that's the whole
  // point — a UTC ISO string is NOT the same as a local display value,
  // see the bug fixed 2026-08-01).
  //
  // Time is hour/minute <select> dropdowns, NOT a native
  // <input type="time"> — that control's rendering is wildly
  // inconsistent across browsers/platforms (cramped, sometimes with no
  // visible mouse controls at all, keyboard-scroll-only on some
  // browsers) and its 12h/24h display depends on OS locale, which fights
  // the "24h time everywhere" requirement. Plain <select> elements are
  // one of the most consistently-rendered controls across every
  // browser, and explicit 00-23 hour options give guaranteed 24h format
  // with zero AM/PM ambiguity, no locale dependency.
  function isoToLocalDate(iso) {
    const d = iso ? new Date(iso) : new Date();
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  }
  function isoToLocalHour(iso) {
    const d = iso ? new Date(iso) : new Date();
    return String(d.getHours()).padStart(2, '0');
  }
  function isoToLocalMinute(iso) {
    const d = iso ? new Date(iso) : new Date();
    return String(d.getMinutes()).padStart(2, '0');
  }
  const HOURS = Array.from({ length: 24 }, (_, i) => String(i).padStart(2, '0'));
  const MINUTES = Array.from({ length: 60 }, (_, i) => String(i).padStart(2, '0'));

  const titles = {
    'new-journal': 'New journal',
    'edit-journal': 'Edit journal',
    'new-event': 'New event',
    'edit-event': 'Edit event',
  };

  // $state below intentionally captures only the INITIAL prop value —
  // this component is freshly mounted each time it opens (parent uses
  // {#if modal}), so "seed once from current data, then edit locally"
  // is exactly the wanted behavior, not a missed $derived.
  let name = $state(mode === 'edit-journal' ? (journalMeta?.name ?? '') : '');
  let description = $state(mode === 'edit-event' ? (eventMeta?.description ?? '') : '');
  let startDate = $state(isoToLocalDate(mode === 'edit-event' ? eventMeta?.start_at : null));
  let startHour = $state(isoToLocalHour(mode === 'edit-event' ? eventMeta?.start_at : null));
  let startMinute = $state(isoToLocalMinute(mode === 'edit-event' ? eventMeta?.start_at : null));
  let closed = $state(mode === 'edit-event' ? !!eventMeta?.closed : false);
  let creatorName = $state('');
  let creatorShortName = $state('');
  let creatorEmail = $state('');
  let bootstrapSecret = $state(prefillBootstrapSecret);
  let busy = $state(false);
  let error = $state('');

  // Public dashboard (see docs/spec/ui-ux.md § Public dashboard):
  // enabling/disabling/regenerating are immediate, standalone writes —
  // NOT deferred to this form's own Save button — same mental model as
  // a contact's regenerate-key action in ContactsView.svelte. `closed`/
  // `description`/`startAt` above stay deferred to Save as before; this
  // is independent of that.
  let publicSecret = $state(mode === 'edit-event' ? (eventMeta?.public_secret || null) : null);
  let publicBusy = $state(false);

  function dashboardUrl(secret) {
    const site = journalMeta?.storage?.base_url || location.origin;
    return `${site}/dashboard?journal=${$currentJournalUuid ?? ''}&event=${eventMeta?.event ?? ''}&secret=${secret}`;
  }

  async function togglePublic(checked) {
    publicBusy = true;
    error = '';
    try {
      if (checked) {
        const secret = generateSecret();
        await saveEventMeta({ publicSecret: secret, isNew: false });
        publicSecret = secret;
      } else {
        await saveEventMeta({ publicSecret: null, isNew: false });
        publicSecret = null;
      }
    } catch (e) {
      error = e.message || 'Something went wrong.';
    } finally {
      publicBusy = false;
    }
  }

  async function regeneratePublicSecret() {
    if (!confirm('Regenerate the public dashboard link? Anyone currently using the old link loses access immediately. Continue?')) return;
    publicBusy = true;
    error = '';
    try {
      const secret = generateSecret();
      await saveEventMeta({ publicSecret: secret, isNew: false });
      publicSecret = secret;
    } catch (e) {
      error = e.message || 'Something went wrong.';
    } finally {
      publicBusy = false;
    }
  }

  async function copyDashboardLink() {
    await navigator.clipboard.writeText(dashboardUrl(publicSecret));
  }

  // Creating a journal no longer strictly needs the bootstrap secret —
  // stores.js's createJournal() prefers proving membership via whatever
  // identity this device already has for $currentJournalUuid (see
  // docs/spec/identity-and-security.md § Bootstrap). When that's
  // available, skip asking for a secret at all rather than showing a
  // field that won't even be used.
  let hasOwnIdentity = $state(false);
  $effect(() => {
    if (mode !== 'new-journal' || !$currentJournalUuid) {
      hasOwnIdentity = false;
      return;
    }
    getIdentity($currentJournalUuid).then((id) => { hasOwnIdentity = !!id; });
  });

  async function submit() {
    busy = true;
    error = '';
    try {
      if (mode === 'new-journal') {
        if (!name.trim()) throw new Error('Journal name is required.');
        await createJournal(baseUrl, bootstrapSecret, name.trim(), {
          name: creatorName.trim() || null,
          short_name: creatorShortName.trim() || null,
          email: creatorEmail.trim() || null,
        });
        // Only worth remembering if we actually asked for/used one — a
        // hasOwnIdentity create never touches bootstrapSecret server-side
        // (stores.js's createJournal() picked contact-based auth
        // instead), so saving an empty string here would blow away a
        // real secret this device already remembered from before.
        if (bootstrapSecret) {
          await rememberBootstrapSecret(bootstrapSecret);
        }
      } else if (mode === 'edit-journal') {
        await saveJournalName(name.trim());
      } else {
        await saveEventMeta({ description, startAt: new Date(`${startDate}T${startHour}:${startMinute}`).toISOString(), closed, isNew: mode === 'new-event' });
      }
      onClose();
    } catch (e) {
      error = e.message || 'Something went wrong.';
    } finally {
      busy = false;
    }
  }

  function veilClick(e) {
    if (e.target === e.currentTarget) onClose();
  }
</script>

<div class="modal-veil" onclick={veilClick} role="presentation">
  <div class="modal" role="dialog" aria-modal="true" aria-label={titles[mode]}>
    <h3>{titles[mode]}</h3>
    {#if mode === 'new-event' || mode === 'edit-event'}
      <p class="sub">{journalMeta?.name ?? ''}</p>
    {/if}

    {#if mode === 'new-journal' || mode === 'edit-journal'}
      <div class="field">
        <label for="mm-name">Journal name</label>
        <input id="mm-name" type="text" bind:value={name} placeholder="Substation 7 — Feeder Outage" />
      </div>
    {/if}

    {#if mode === 'new-journal'}
      {#if hasOwnIdentity}
        <p class="sub">You're already a journal member on this device — no separate secret needed to create another.</p>
      {:else}
        <div class="field">
          <label for="mm-bootstrap">Create-journal secret</label>
          <input id="mm-bootstrap" type="text" bind:value={bootstrapSecret} placeholder="from your create-journal link" />
        </div>
      {/if}
      <div class="field">
        <label for="mm-creator-name">Your name</label>
        <input id="mm-creator-name" type="text" bind:value={creatorName} />
      </div>
      <div class="field">
        <label for="mm-creator-short">Your short name</label>
        <input id="mm-creator-short" type="text" bind:value={creatorShortName} placeholder="e.g. MattH" />
      </div>
      <div class="field">
        <label for="mm-creator-email">Your email</label>
        <input id="mm-creator-email" type="email" bind:value={creatorEmail} />
      </div>
    {/if}

    {#if mode === 'new-event' || mode === 'edit-event'}
      <div class="field">
        <label for="mm-description">Description</label>
        <textarea id="mm-description" bind:value={description} placeholder="What is this event about?"></textarea>
      </div>
      <div class="field">
        <label for="mm-start-date">Start time</label>
        <div class="start-row">
          <input id="mm-start-date" type="date" bind:value={startDate} />
          <select bind:value={startHour} aria-label="Start hour (24h)">
            {#each HOURS as h}<option value={h}>{h}</option>{/each}
          </select>
          <span class="colon">:</span>
          <select bind:value={startMinute} aria-label="Start minute">
            {#each MINUTES as m}<option value={m}>{m}</option>{/each}
          </select>
          <button
            type="button"
            class="ghost-btn"
            onclick={() => { startDate = isoToLocalDate(); startHour = isoToLocalHour(); startMinute = isoToLocalMinute(); }}
          >Now</button>
        </div>
      </div>
      {#if mode === 'edit-event'}
        <div class="field">
          <label class="checkline">
            <input type="checkbox" bind:checked={closed} />
            Closed
          </label>
        </div>

        <div class="field">
          <label class="checkline">
            <input
              type="checkbox"
              checked={!!publicSecret}
              disabled={publicBusy}
              onchange={(e) => togglePublic(e.target.checked)}
            />
            Make public dashboard link
          </label>
        </div>
        {#if publicSecret}
          <div class="field">
            <label for="mm-public-url">Public dashboard link</label>
            <div class="link-row">
              <input id="mm-public-url" type="text" readonly value={dashboardUrl(publicSecret)} />
              <button type="button" class="ghost-btn" onclick={copyDashboardLink}>Copy</button>
            </div>
            <button type="button" class="ghost-btn" disabled={publicBusy} onclick={regeneratePublicSecret}>Regenerate</button>
            <p class="sub">Regenerating immediately revokes access for anyone with the current link.</p>
          </div>
        {/if}
      {/if}
    {/if}

    {#if error}
      <p class="sub" style="color: var(--danger);">{error}</p>
    {/if}

    <div class="modal-actions">
      <button type="button" class="ghost-btn" onclick={onClose} disabled={busy || publicBusy}>Cancel</button>
      <button type="button" class="primary-btn" onclick={submit} disabled={busy || publicBusy}>
        {busy ? 'Saving…' : (mode.startsWith('new') ? 'Create' : 'Save')}
      </button>
    </div>
  </div>
</div>

<style>
  .start-row { display: flex; gap: 6px; align-items: center; }
  .start-row input[type='date'] { flex: 1.6; min-width: 0; }
  .start-row select {
    flex: 1;
    min-width: 0;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 7px;
    padding: 8px 6px;
    color: var(--ink);
    font-size: 0.88rem;
    font-family: var(--font-mono);
    text-align: center;
  }
  .start-row .colon { color: var(--ink-dim); font-weight: 700; }
  .start-row .ghost-btn { padding: 8px 12px; flex-shrink: 0; }

  .link-row { display: flex; gap: 6px; margin-bottom: 8px; }
  .link-row input {
    flex: 1;
    min-width: 0;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 7px;
    padding: 8px 10px;
    color: var(--ink-dim);
    font-family: var(--font-mono);
    font-size: 0.78rem;
  }
  .link-row .ghost-btn { flex-shrink: 0; padding: 8px 12px; }
</style>
