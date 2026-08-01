<script>
  // Full-bleed instrument bar — see docs/spec/ui-ux.md § Top bar.
  import {
    knownJournals, currentJournalUuid, currentEventUuid,
    journalMeta, eventsSummary, eventMeta, entries,
    theme, timeMode, syncStatus,
    setTheme, setTimeMode, selectJournal, selectEvent,
  } from '../stores.js';
  import { computeCompletionPercent } from '../render.js';
  import MetaModal from './MetaModal.svelte';

  let { baseUrl, prefillBootstrapSecret = '' } = $props();

  let modal = $state(null); // 'new-journal' | 'edit-journal' | 'new-event' | 'edit-event' | null
  let now = $state(new Date());
  $effect(() => {
    const t = setInterval(() => (now = new Date()), 1000);
    return () => clearInterval(t);
  });

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

  function fmtAbsolute(iso, useLocal) {
    if (!iso) return { time: '—', zone: '', date: '' };
    const d = new Date(iso);
    const pad = (n) => String(n).padStart(2, '0');
    const h = useLocal ? d.getHours() : d.getUTCHours();
    const m = useLocal ? d.getMinutes() : d.getUTCMinutes();
    const mo = useLocal ? d.getMonth() : d.getUTCMonth();
    const day = useLocal ? d.getDate() : d.getUTCDate();
    return { time: `${pad(h)}:${pad(m)}`, zone: useLocal ? '' : 'Z', date: `${pad(mo + 1)}-${pad(day)}` };
  }

  function fmtDuration(startIso, end) {
    if (!startIso) return '—';
    const totalSec = Math.max(0, Math.floor((end - new Date(startIso)) / 1000));
    const pad = (n) => String(n).padStart(2, '0');
    const h = Math.floor(totalSec / 3600);
    const m = Math.floor((totalSec % 3600) / 60);
    const s = totalSec % 60;
    return `${pad(h)}:${pad(m)}:${pad(s)}`;
  }

  let started = $derived(fmtAbsolute($eventMeta?.start_at, $timeMode === 'local'));
  let duration = $derived(fmtDuration($eventMeta?.start_at, now));
  let completion = $derived($eventMeta ? computeCompletionPercent($entries) : null);

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

    <div class="stat">
      <span class="eyebrow">Started</span>
      <span class="value tabular">{started.time}{started.zone}</span>
      <span class="date-sub tabular">{started.date}</span>
    </div>
    <div class="stat">
      <span class="eyebrow">Running</span>
      <span class="value tabular">{duration}</span>
      <span class="date-sub tabular" aria-hidden="true" style="visibility:hidden">00-00</span>
    </div>

    {#if completion !== null}
      <div class="completion" style="--pct:{completion}" title="Completion — from most recent update: tag">
        <span class="ring" aria-hidden="true"></span>
        <span class="value tabular">{completion}%</span>
      </div>
    {/if}
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

  .divider-v { width: 1px; align-self: stretch; background: var(--border); margin: 2px 4px; }

  .status-cluster { display: flex; align-items: center; gap: 8px; margin-left: auto; }

  .stat { display: flex; flex-direction: column; gap: 2px; padding: 0 4px; }
  .stat .eyebrow { line-height: 1; }
  .stat .date-sub { font-family: var(--font-mono); font-size: 0.72rem; color: var(--muted); line-height: 1; }
  .stat .value { font-family: var(--font-mono); font-size: 1.8rem; font-weight: 700; line-height: 1; letter-spacing: -0.01em; }

  /* Same fixed neutral colors as .chip-update in app.css — this is the
     same "completion" concept scaled up, deliberately not theme-adaptive
     (see docs/spec/ui-ux.md § Tags: update chips use a fixed color). */
  .completion {
    display: flex;
    align-items: center;
    gap: 10px;
    background: #a9835f;
    color: #241a10;
    border-radius: 10px;
    padding: 5px 13px 5px 10px;
  }
  :global(:root[data-theme='light']) .completion { background: #d8bd9c; color: #2b1c10; }
  .completion .ring {
    width: 33px; height: 33px; border-radius: 50%;
    background: conic-gradient(currentColor calc(var(--pct) * 1%), rgba(0, 0, 0, 0.18) 0);
    display: grid; place-items: center;
    flex-shrink: 0;
  }
  .completion .ring::after { content: ''; width: 21px; height: 21px; border-radius: 50%; background-color: #a9835f; }
  :global(:root[data-theme='light']) .completion .ring::after { background-color: #d8bd9c; }
  .completion .value { font-family: var(--font-mono); font-weight: 700; font-size: 1.625rem; line-height: 1; }
</style>
