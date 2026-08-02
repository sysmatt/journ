<script>
  // Started/Running/Completion instrument-readout — factored out of
  // TopBar so it can be shared verbatim with PublicDashboard (see
  // docs/spec/ui-ux.md § Top bar and § Public dashboard). Reads
  // $eventMeta/$entries/$timeMode straight from stores rather than
  // taking props — both callers already populate those same stores
  // (TopBar via the authenticated sync engine, PublicDashboard via its
  // own polling fetch), so there's nothing to plumb through.
  import { eventMeta, entries, timeMode } from '../stores.js';
  import { computeCompletionPercent } from '../render.js';

  let now = $state(new Date());
  $effect(() => {
    const t = setInterval(() => (now = new Date()), 1000);
    return () => clearInterval(t);
  });

  function pad(n) { return String(n).padStart(2, '0'); }

  function fmtAbsolute(iso, useLocal) {
    if (!iso) return { time: '—', zone: '', date: '' };
    const d = new Date(iso);
    const h = useLocal ? d.getHours() : d.getUTCHours();
    const m = useLocal ? d.getMinutes() : d.getUTCMinutes();
    const mo = useLocal ? d.getMonth() : d.getUTCMonth();
    const day = useLocal ? d.getDate() : d.getUTCDate();
    return { time: `${pad(h)}:${pad(m)}`, zone: useLocal ? '' : 'Z', date: `${pad(mo + 1)}-${pad(day)}` };
  }

  function fmtDuration(startIso, end) {
    if (!startIso) return '—';
    const totalSec = Math.max(0, Math.floor((end - new Date(startIso)) / 1000));
    const h = Math.floor(totalSec / 3600);
    const m = Math.floor((totalSec % 3600) / 60);
    const s = totalSec % 60;
    return `${pad(h)}:${pad(m)}:${pad(s)}`;
  }

  let started = $derived(fmtAbsolute($eventMeta?.start_at, $timeMode === 'local'));
  let duration = $derived(fmtDuration($eventMeta?.start_at, now));
  let completion = $derived($eventMeta ? computeCompletionPercent($entries) : null);
</script>

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

<style>
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
