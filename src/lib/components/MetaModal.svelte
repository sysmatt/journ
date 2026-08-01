<script>
  // Shared modal for New/Edit Journal and New/Edit Event — see
  // docs/spec/ui-ux.md § Top bar: "reuses the same modal/form as new,
  // pre-filled with current values, submitting as an update rather than
  // a create."
  import { createJournal, saveJournalName, saveEventMeta } from '../stores.js';

  let { mode, journalMeta = null, eventMeta = null, baseUrl, prefillBootstrapSecret = '', onClose } = $props();

  // Local wall-clock values, no timezone attached (that's the whole
  // point — a UTC ISO string is NOT the same as a local display value,
  // see the bug fixed 2026-08-01). Split into separate date/time inputs
  // rather than one combined <input type="datetime-local"> — that
  // combined widget's time-picker UI is notoriously inconsistent/clunky
  // across browsers (a cramped scrollable spinner on several
  // browser/OS combos); plain type="date" + type="time" behave far
  // better individually.
  function isoToLocalDate(iso) {
    const d = iso ? new Date(iso) : new Date();
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  }
  function isoToLocalTime(iso) {
    const d = iso ? new Date(iso) : new Date();
    const pad = (n) => String(n).padStart(2, '0');
    return `${pad(d.getHours())}:${pad(d.getMinutes())}`;
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
  let startDate = $state(isoToLocalDate(mode === 'edit-event' ? eventMeta?.start_at : null));
  let startTime = $state(isoToLocalTime(mode === 'edit-event' ? eventMeta?.start_at : null));
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
        await saveEventMeta({ description, startAt: new Date(`${startDate}T${startTime}`).toISOString(), closed });
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
        <label for="mm-start-date">Start time</label>
        <div class="start-row">
          <input id="mm-start-date" type="date" bind:value={startDate} />
          <input id="mm-start-time" type="time" bind:value={startTime} aria-label="Start time (time of day)" />
          <button
            type="button"
            class="ghost-btn"
            onclick={() => { startDate = isoToLocalDate(); startTime = isoToLocalTime(); }}
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

<style>
  .start-row { display: flex; gap: 8px; align-items: center; }
  .start-row input[type='date'] { flex: 1.3; }
  .start-row input[type='time'] { flex: 1; }
  .start-row .ghost-btn { padding: 8px 12px; }
</style>
