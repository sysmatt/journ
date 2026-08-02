<script>
  // Entries log — see docs/spec/ui-ux.md § Entries table. Most-recent-
  // first, live-updating (no scroll-preservation handling for v1 — see
  // spec), with the UTC/Local/T time-display modes and tag/mention chip
  // rendering.
  import {
    entries, contacts, tagsConfig, timeMode, eventMeta, tRefEntryUuid,
    currentJournalUuid, currentEventUuid, editEntry, trashEntry, journalMeta, theme,
  } from '../stores.js';
  import { renderEntryText, deriveDisplayName } from '../render.js';
  import { attachmentUrl } from '../api.js';
  import { fmtAbsolute, fmtDelta } from '../time.js';
  import { buildCsvReport, buildTextReport, buildHtmlReport, downloadFile, reportFilename } from '../report.js';

  // readOnly: used by the public dashboard's own instance of this same
  // component (docs/spec/ui-ux.md § Public dashboard) — hides the edit/
  // trash row actions and, since a public viewer already knows they're
  // looking at the public link, the "here's the link" footer notice
  // below (that notice is for CONTACTS in the authenticated app, so
  // they can find/re-grab the link — not for the public page itself).
  let { baseUrl, readOnly = false } = $props();

  function dashboardUrl() {
    const site = $journalMeta?.storage?.base_url || location.origin;
    return `${site}/dashboard?journal=${$currentJournalUuid ?? ''}&event=${$eventMeta?.event ?? ''}&secret=${$eventMeta?.public_secret ?? ''}`;
  }

  async function copyDashboardLink() {
    await navigator.clipboard.writeText(dashboardUrl());
  }

  // Report export (docs/spec/ui-ux.md § Report export) — entirely
  // client-side, built from whatever's already in these same stores;
  // no network call, no new endpoint. Authenticated app only per that
  // spec — never rendered when readOnly (the public dashboard).
  function reportData() {
    return {
      journalName: $journalMeta?.name || '',
      eventMeta: $eventMeta,
      entries: $entries,
      contactsByUuid: $contacts,
      tagsConfig: $tagsConfig,
      timeMode: $timeMode,
      tRefEntryUuid: $tRefEntryUuid,
      theme: $theme,
      generatedAt: new Date(),
    };
  }

  function exportReport(build, ext, mimeType) {
    const data = reportData();
    const filename = reportFilename(data.journalName, data.eventMeta?.description, data.generatedAt, ext);
    downloadFile(filename, build(data), mimeType);
  }

  const exportCsv = () => exportReport(buildCsvReport, 'csv', 'text/csv;charset=utf-8');
  const exportHtml = () => exportReport(buildHtmlReport, 'html', 'text/html;charset=utf-8');
  const exportText = () => exportReport(buildTextReport, 'txt', 'text/plain;charset=utf-8');

  let showTrashed = $state(false);
  let editingUuid = $state(null);
  let editText = $state('');

  let closed = $derived(!!$eventMeta?.closed);

  let visibleEntries = $derived($entries.filter((e) => showTrashed || !e.trashed));

  // T-mode: default reference = most recent entry (first in the array).
  $effect(() => {
    if ($timeMode === 't' && !$tRefEntryUuid && $entries.length > 0) {
      tRefEntryUuid.set($entries[0].uuid);
    }
  });

  function rowTime(entry) {
    if ($timeMode === 't') {
      const ref = $entries.find((e) => e.uuid === $tRefEntryUuid) || $entries[0];
      return { display: fmtDelta(entry.created_at, ref?.created_at ?? entry.created_at), date: null };
    }
    const f = fmtAbsolute(entry.created_at, $timeMode === 'local');
    return { display: `${f.time}${f.zone}`, date: f.date };
  }

  function rendered(entry) {
    return renderEntryText(entry.text, { contactsByUuid: $contacts, tags: $tagsConfig });
  }

  function startEdit(entry) {
    editingUuid = entry.uuid;
    editText = entry.text;
  }

  async function saveEdit() {
    await editEntry(editingUuid, editText);
    editingUuid = null;
  }

  function cancelEdit() {
    editingUuid = null;
  }
</script>

<div class="log">
  <div class="log-head">
    <span>Time</span><span>Author</span><span>Entry</span><span></span>
  </div>

  {#each visibleEntries as entry (entry.uuid)}
    {@const t = rowTime(entry)}
    {@const r = rendered(entry)}
    <div class="entry-row" class:is-trashed={entry.trashed} style={r.rowHighlight ? `background: ${r.rowHighlight.bg}22;` : ''}>
      <div class="ts">
        {#if $timeMode === 't'}
          <input
            type="radio"
            name="t-ref"
            class="t-radio"
            checked={entry.uuid === $tRefEntryUuid}
            onchange={() => tRefEntryUuid.set(entry.uuid)}
          />
        {/if}
        <div class="ts-stack tabular">
          <span class="time">{t.display}</span>
          {#if t.date}<span class="date">{t.date}</span>{/if}
        </div>
      </div>

      <div class="author">
        <span class="avatar">{deriveDisplayName($contacts[entry.author]).slice(0, 2).toUpperCase()}</span>
        <span class="name">{deriveDisplayName($contacts[entry.author])}</span>
      </div>

      <div class="entry-text">
        {#if editingUuid === entry.uuid}
          <div class="edit-row">
            <textarea bind:value={editText}></textarea>
            <button type="button" class="ghost-btn" onclick={cancelEdit}>Cancel</button>
            <button type="button" class="primary-btn" onclick={saveEdit}>Save</button>
          </div>
        {:else}
          {@html r.html}
          {#each entry.attachments || [] as att}
            <div class="attach-tag">
              <a href={attachmentUrl(baseUrl, $currentJournalUuid, $currentEventUuid, att.storage_filename)} target="_blank" rel="noopener noreferrer">
                📎 {att.original_filename}
              </a>
            </div>
          {/each}
        {/if}
      </div>

      <div class="row-actions">
        {#if !readOnly && !closed && editingUuid !== entry.uuid}
          <button class="icon-btn" title="Edit" onclick={() => startEdit(entry)}>✎</button>
          <button class="icon-btn" title={entry.trashed ? 'Restore' : 'Trash'} onclick={() => trashEntry(entry.uuid, !entry.trashed)}>🗑</button>
        {/if}
      </div>
    </div>
  {:else}
    <div class="empty">No entries yet.</div>
  {/each}
</div>

<div class="ops-bar">
  <label class="checkline">
    <input type="checkbox" bind:checked={showTrashed} />
    Show trashed entries
  </label>

  {#if !readOnly}
    <div class="ops-divider"></div>
    <span class="export-label">Export:</span>
    <button type="button" class="ghost-btn export-btn" onclick={exportCsv} title="Download this event as a CSV report">CSV</button>
    <button type="button" class="ghost-btn export-btn" onclick={exportHtml} title="Download this event as a standalone HTML report">HTML</button>
    <button type="button" class="ghost-btn export-btn" onclick={exportText} title="Download this event as a plain-text report">TXT</button>
  {/if}
</div>

{#if !readOnly && $eventMeta?.public_secret}
  <div class="public-notice">
    <span>This event is being shared publicly with this URL: <code>{dashboardUrl()}</code></span>
    <button type="button" class="ghost-btn" onclick={copyDashboardLink}>Copy</button>
  </div>
{/if}

<style>
  .log { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; overflow: hidden; }
  .log-head {
    display: grid;
    grid-template-columns: 108px 118px 1fr 64px;
    gap: 14px;
    padding: 9px 16px;
    border-bottom: 1px solid var(--border);
    background: var(--surface-2);
  }
  .log-head span { font-family: var(--font-mono); font-size: 0.65rem; letter-spacing: 0.09em; text-transform: uppercase; color: var(--muted); }

  .entry-row {
    display: grid;
    grid-template-columns: 108px 118px 1fr 64px;
    gap: 14px;
    padding: 6px 16px;
    border-bottom: 1px solid var(--border-soft);
    align-items: start;
  }
  .entry-row:last-child { border-bottom: none; }
  .entry-row.is-trashed { opacity: 0.5; }
  .entry-row.is-trashed .entry-text { text-decoration: line-through; text-decoration-color: var(--muted); }

  .ts { display: flex; align-items: center; gap: 7px; }
  .ts-stack { font-family: var(--font-mono); font-size: 0.8rem; color: var(--ink-dim); white-space: nowrap; display: flex; flex-direction: row; align-items: baseline; gap: 6px; }
  .ts-stack .date { font-size: 0.66rem; color: var(--muted); }
  .t-radio { width: 14px; height: 14px; flex-shrink: 0; accent-color: var(--accent); cursor: pointer; }

  .author { display: flex; align-items: center; gap: 7px; min-width: 0; }
  .author .avatar { width: 20px; height: 20px; font-size: 0.6rem; }
  .author .name { font-size: 0.83rem; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

  .entry-text { font-size: 0.92rem; line-height: 1.3; }
  .entry-text :global(a) { color: var(--accent); text-decoration: none; border-bottom: 1px solid var(--accent-soft); }
  /* Markdown output (marked, via render.js) wraps a plain paragraph of
     text in <p>, a list in <ul>/<ol>, etc — all block-level, all
     carrying the browser's own default margin unless reset. Reset every
     direct child to zero, then add space back ONLY between consecutive
     children, so a single-paragraph entry (the overwhelming majority)
     sits flush with no top/bottom buffer, while a rarer multi-block
     entry still gets visual separation between its parts. */
  .entry-text :global(> *) { margin: 0; }
  .entry-text :global(> * + *) { margin-top: 0.6em; }
  .edit-row { display: flex; flex-direction: column; gap: 6px; align-items: flex-start; }
  .edit-row textarea { width: 100%; min-height: 60px; background: var(--surface-2); border: 1px solid var(--border); border-radius: 6px; padding: 6px 8px; font-size: 0.88rem; }

  .attach-tag { display: inline-flex; align-items: center; gap: 5px; font-size: 0.74rem; color: var(--ink-dim); background: var(--surface-2); border: 1px solid var(--border); padding: 2px 8px 2px 6px; border-radius: 6px; margin-top: 6px; }
  .attach-tag a { color: inherit; text-decoration: none; }

  .row-actions { display: flex; gap: 2px; justify-content: flex-end; }
  .row-actions .icon-btn { width: 26px; height: 26px; font-size: 1rem; border-radius: 6px; }

  .empty { padding: 24px; text-align: center; color: var(--muted); font-size: 0.85rem; }

  .ops-bar { display: flex; align-items: center; gap: 10px; padding: 10px 4px 0; }
  .checkline { display: flex; align-items: center; gap: 7px; font-size: 0.8rem; color: var(--ink-dim); }

  .ops-divider { width: 1px; height: 18px; background: var(--border); }
  .export-label { font-size: 0.76rem; color: var(--muted); }
  .export-btn { padding: 5px 11px; font-size: 0.74rem; font-family: var(--font-mono); font-weight: 700; letter-spacing: 0.02em; }

  .public-notice {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 10px 4px 0;
    padding: 9px 12px;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 8px;
    font-size: 0.8rem;
    color: var(--ink-dim);
  }
  .public-notice code { font-family: var(--font-mono); color: var(--ink); word-break: break-all; }
  .public-notice .ghost-btn { flex-shrink: 0; margin-left: auto; padding: 5px 11px; }

  @media (max-width: 720px) {
    .log-head, .entry-row { grid-template-columns: 84px 1fr 46px; }
    .log-head span:nth-child(2), .author { display: none; }
  }
</style>
