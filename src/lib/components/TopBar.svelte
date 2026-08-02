<script>
  // Full-bleed instrument bar — see docs/spec/ui-ux.md § Top bar.
  import {
    knownJournals, currentJournalUuid, currentEventUuid,
    journalMeta, eventsSummary, eventMeta,
    theme, timeMode, syncStatus,
    setTheme, setTimeMode, selectJournal, selectEvent, resetLocalData,
  } from '../stores.js';
  import MetaModal from './MetaModal.svelte';
  import EventStats from './EventStats.svelte';

  let { baseUrl, prefillBootstrapSecret = '' } = $props();

  let modal = $state(null); // 'new-journal' | 'edit-journal' | 'new-event' | 'edit-event' | null

  const timeModeLabels = { utc: 'UTC', local: 'Local', t: 'T' };
  const timeModeIcons = { utc: '◔', local: '◔', t: '±' };

  function cycleTimeMode() {
    const order = ['utc', 'local', 't'];
    setTimeMode(order[(order.indexOf($timeMode) + 1) % order.length]);
  }

  function toggleTheme() {
    const next = $theme === 'dark' ? 'light' : 'dark';
    setTheme(next);
    document.documentElement.setAttribute('data-theme', next);
  }

  async function handleResetLocalData() {
    const ok = confirm(
      'Erase all locally cached journals, identities, and preferences on THIS DEVICE ONLY?\n\n' +
      'Nothing on the server is touched, but any secret this device uses to write to a journal ' +
      '(including one you created) only lives here — you\'ll need a fresh invite or create-journal ' +
      'link to get back in. This cannot be undone.'
    );
    if (!ok) return;
    await resetLocalData();
    location.href = '/'; // full reload — App.svelte's onMount rebuilds everything from the now-empty store
  }

  function onJournalChange(e) {
    const uuid = e.target.value;
    if (uuid === '__new__') {
      // The select's `value` prop is bound to $currentJournalUuid, which
      // this branch does NOT change — so Svelte has nothing to react to
      // and never reassigns the DOM value back. Left alone, the <select>
      // stays showing "+ New journal…" forever (even across Cancel),
      // and since the browser only fires `change` when its value
      // actually differs from the DOM's current one, every later pick —
      // "+ New journal…" again included — silently does nothing. Snap
      // it back to the true current value ourselves, right away.
      e.target.value = $currentJournalUuid ?? '';
      modal = 'new-journal';
      return;
    }
    const j = $knownJournals.find((k) => k.uuid === uuid);
    if (j) selectJournal(j.uuid, j.baseUrl);
  }

  function onEventChange(e) {
    const uuid = e.target.value;
    if (uuid === '__new__') {
      e.target.value = $currentEventUuid ?? ''; // see onJournalChange
      modal = 'new-event';
      return;
    }
    selectEvent(uuid);
  }
</script>

<div class="instrument-bar">
  <div class="picker">
    <div class="picker-field">
      <span class="eyebrow">Journal</span>
      <select aria-label="Select journal" value={$currentJournalUuid ?? ''} onchange={onJournalChange}>
        {#if !$currentJournalUuid}<option value="" disabled selected>— select —</option>{/if}
        {#each $knownJournals as j (j.uuid)}
          <option value={j.uuid}>{j.name}</option>
        {/each}
        <option value="__new__">+ New journal…</option>
      </select>
    </div>
    {#if $currentJournalUuid}
      <button class="icon-btn" title="Edit journal" onclick={() => (modal = 'edit-journal')}>✎</button>
    {/if}
  </div>

  {#if $currentJournalUuid}
    <div class="picker">
      <div class="picker-field">
        <span class="eyebrow">Event</span>
        <select aria-label="Select event" value={$currentEventUuid ?? ''} onchange={onEventChange}>
          {#if !$currentEventUuid}<option value="" disabled selected>— select —</option>{/if}
          {#each Object.entries($eventsSummary) as [uuid, meta] (uuid)}
            <option value={uuid}>{meta?.description?.slice(0, 40) || uuid}{meta?.closed ? ' (closed)' : ''}</option>
          {/each}
          <option value="__new__">+ New event…</option>
        </select>
      </div>
      {#if $currentEventUuid}
        <button class="icon-btn" title="Edit event" onclick={() => (modal = 'edit-event')}>✎</button>
      {/if}
    </div>
  {/if}

  {#if $currentEventUuid}
    <div class="divider-v"></div>
    <EventStats />
  {/if}

  <div class="status-cluster">
    <button class="toggle-btn" onclick={cycleTimeMode} title="Cycle time display: UTC → Local → T (relative)">
      <span aria-hidden="true">{timeModeIcons[$timeMode]}</span> {timeModeLabels[$timeMode]}
    </button>
    <button class="toggle-btn" onclick={toggleTheme} title="Toggle dark / light">
      <span aria-hidden="true">{$theme === 'dark' ? '☾' : '☀'}</span> {$theme === 'dark' ? 'Dark' : 'Light'}
    </button>
    <div class="sync-pill" data-state={$syncStatus}>
      <span class="sync-dot"></span> {$syncStatus === 'synced' ? 'Synced' : $syncStatus === 'syncing' ? 'Syncing' : 'Offline'}
    </div>
    <button class="icon-btn reset-btn" onclick={handleResetLocalData} title="Reset this device — erase all locally cached journals, identities, and preferences (server data untouched)">🗑</button>
  </div>
</div>

{#if modal}
  <MetaModal mode={modal} journalMeta={$journalMeta} eventMeta={$eventMeta} {baseUrl} {prefillBootstrapSecret} onClose={() => (modal = null)} />
{/if}

<style>
  .instrument-bar {
    width: 100%;
    box-sizing: border-box;
    background: var(--surface);
    border-bottom: 1px solid var(--border);
    padding: 12px clamp(16px, 4vw, 48px);
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
  }

  .picker {
    display: flex;
    align-items: center;
    gap: 6px;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 7px 8px 7px 12px;
  }
  .picker-field { display: flex; flex-direction: column; gap: 1px; min-width: 0; }
  .picker-field .eyebrow { line-height: 1; }
  .picker-field select {
    appearance: none;
    background: none;
    border: none;
    color: var(--ink);
    font-size: 0.9rem;
    font-weight: 600;
    padding: 0;
    max-width: 220px;
    text-overflow: ellipsis;
  }
  /* Belt-and-suspenders alongside color-scheme in app.css — some
     browsers (Firefox, some Chromium builds) honor option-level
     background/color directly in the dropdown popup. */
  .picker-field select option {
    background: var(--surface-2);
    color: var(--ink);
  }

  .divider-v { width: 1px; align-self: stretch; background: var(--border); margin: 2px 4px; }

  .status-cluster { display: flex; align-items: center; gap: 8px; margin-left: auto; }
  /* Deliberately subdued at rest (same as any other icon-btn) and only
     signals "careful" on hover — this is a rare, destructive, local-only
     action, not something that should visually compete with everyday
     controls like the sync pill or theme toggle right next to it. */
  .reset-btn:hover { background: var(--danger); color: var(--accent-ink); }
</style>
