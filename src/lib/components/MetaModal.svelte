<script>
  // Shared modal for New/Edit Journal and New/Edit Event — see
  // docs/spec/ui-ux.md § Top bar: "reuses the same modal/form as new,
  // pre-filled with current values, submitting as an update rather than
  // a create."
  import { createJournal, saveJournalName, saveEventMeta } from '../stores.js';

  let { mode, journalMeta = null, eventMeta = null, baseUrl, prefillBootstrapSecret = '', onClose } = $props();

  // <input type="datetime-local"> edits a LOCAL wall-clock value with no
  // timezone attached — it is NOT the same string as a UTC ISO timestamp.
  // Bug fixed 2026-08-01: feeding it a UTC value directly (just sliced)
  // made it display the UTC hour labeled as if it were local, and on
  // submit the browser then reinterpreted that value as local and
  // converted it back to UTC again — silently shifting start_at by the
  // viewer's UTC offset. This converts properly in both directions using
  // the LOCAL Date getters (not the UTC ones).
  function isoToLocalInputValue(iso) {
    const d = iso ? new Date(iso) : new Date();
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
  }

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
  let startAt = $state(isoToLocalInputValue(mode === 'edit-event' ? eventMeta?.start_at : null));
  let closed = $state(mode === 'edit-event' ? !!eventMeta?.closed : false);
  let creatorName = $state('');
  let creatorShortName = $state('');
  let creatorEmail = $state('');
  let bootstrapSecret = $state(prefillBootstrapSecret);
  let busy = $state(false);
  let error = $state('');

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
      } else if (mode === 'edit-journal') {
        await saveJournalName(name.trim());
      } else {
        await saveEventMeta({ description, startAt: new Date(startAt).toISOString(), closed });
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
      <div class="field">
        <label for="mm-bootstrap">Create-journal secret</label>
        <input id="mm-bootstrap" type="text" bind:value={bootstrapSecret} placeholder="from your create-journal link" />
      </div>
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
        <label for="mm-start">Start time</label>
        <input id="mm-start" type="datetime-local" bind:value={startAt} />
      </div>
      {#if mode === 'edit-event'}
        <div class="field">
          <label class="checkline">
            <input type="checkbox" bind:checked={closed} />
            Closed
          </label>
        </div>
      {/if}
    {/if}

    {#if error}
      <p class="sub" style="color: var(--danger);">{error}</p>
    {/if}

    <div class="modal-actions">
      <button type="button" class="ghost-btn" onclick={onClose} disabled={busy}>Cancel</button>
      <button type="button" class="primary-btn" onclick={submit} disabled={busy}>
        {busy ? 'Saving…' : (mode.startsWith('new') ? 'Create' : 'Save')}
      </button>
    </div>
  </div>
</div>
