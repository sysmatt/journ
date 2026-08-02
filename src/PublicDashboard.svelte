<script>
  // Public, read-only, no-login dashboard for a single event — see
  // docs/spec/ui-ux.md § Public dashboard and
  // docs/spec/identity-and-security.md § Public dashboard secret.
  //
  // Deliberately NOT a mode of App.svelte — a completely separate root
  // component (see main.js), mounted instead of App entirely when the
  // path is /dashboard. No journal/event picker, no composer, no
  // Contacts view, no sync engine/offline queue/IndexedDB fragment
  // cache — just a periodic full-refetch of one purpose-built endpoint.
  //
  // It still reuses EventStats and EntriesTable as-is by populating the
  // SAME stores.js singletons the authenticated app uses — safe here
  // specifically because this is a full separate page load (never
  // client-side-routed against '/'), so there's no session that could
  // straddle both an authenticated view and this one.
  import { onMount, onDestroy } from 'svelte';
  import { get } from 'svelte/store';
  import { getDashboard, getDashboardFreshness, getTags } from './lib/api.js';
  import { getPreference } from './lib/db.js';
  import {
    entries, contacts, eventMeta, journalMeta, tagsConfig,
    currentJournalUuid, currentEventUuid, theme, timeMode, setTheme, setTimeMode,
  } from './lib/stores.js';
  import EventStats from './lib/components/EventStats.svelte';
  import EntriesTable from './lib/components/EntriesTable.svelte';

  const apiBaseUrl = location.origin + '/api';
  // Cheap because every tick checks journ_event_freshness() first (a
  // stat()-only scan, no JSON decode) and only pays for the full
  // reduce+fetch when that's actually changed — see
  // journ_route_get_dashboard_freshness() and fetchOnce() below. Faster
  // than the authenticated app's own 20s cadence since this is exactly
  // the "someone's boss is watching a status page" use case.
  const POLL_MS = 5000;

  const params = new URLSearchParams(location.search);
  const journalUuid = params.get('journal');
  const eventUuid = params.get('event');
  const secret = params.get('secret');

  let loading = $state(true);
  let notFound = $state(false);
  let pollTimer = null;
  // undefined (not null) until the first successful check — a real
  // freshness value CAN legitimately be null (e.g. journ_event_freshness
  // finding nothing), and undefined is the one sentinel that can't
  // collide with that, so the very first tick never mistakes "haven't
  // checked yet" for "unchanged."
  let lastUpdatedAt;

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

  function stopPolling() {
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = null;
  }

  async function fetchOnce() {
    try {
      const freshness = await getDashboardFreshness(apiBaseUrl, journalUuid, eventUuid, secret);
      notFound = false;
      if (freshness.updated_at === lastUpdatedAt) {
        return; // nothing changed since last check — skip the expensive full reduce+fetch entirely
      }
      const dash = await getDashboard(apiBaseUrl, journalUuid, eventUuid, secret);
      journalMeta.set({ name: dash.journal_name });
      eventMeta.set(dash.event);
      contacts.set(dash.contacts);
      entries.set(dash.entries);
      currentJournalUuid.set(journalUuid);
      currentEventUuid.set(eventUuid);
      lastUpdatedAt = freshness.updated_at;
    } catch (e) {
      // A 404 specifically means the link is genuinely dead (bad/revoked
      // secret, event archived) — stop polling and say so plainly.
      // Anything else (a network blip) just leaves the last-good data on
      // screen; the next tick tries again.
      if (e?.status === 404) {
        notFound = true;
        stopPolling();
      }
    } finally {
      loading = false;
    }
  }

  onMount(async () => {
    theme.set(await getPreference('theme', 'dark'));
    timeMode.set(await getPreference('timeMode', 'utc'));
    document.documentElement.setAttribute('data-theme', get(theme));

    if (!journalUuid || !eventUuid || !secret) {
      notFound = true;
      loading = false;
      return;
    }

    // Tags are install-wide config, not event data — fetched once, not
    // on every freshness tick; there's no reasonable scenario where tag
    // colors change mid-viewing-session in a way anyone needs live.
    try {
      tagsConfig.set((await getTags(apiBaseUrl)).tags || []);
    } catch {
      // offline/unreachable — chips fall back to defaults, not fatal
    }

    await fetchOnce();
    pollTimer = setInterval(fetchOnce, POLL_MS);
  });

  onDestroy(stopPolling);
</script>

<div class="page">
  <div class="masthead">
    <span class="mark">journ</span>
    <span class="tag">Public dashboard — read only</span>
  </div>

  {#if loading}
    <p class="hint">Loading…</p>
  {:else if notFound}
    <div class="dead-link">
      <h2>This dashboard link no longer works</h2>
      <p>It may have been revoked, turned off, or the event may have been archived. Ask whoever shared it with you for a current link.</p>
    </div>
  {:else}
    <div class="header">
      <div class="titles">
        <span class="journal-name">{$journalMeta?.name ?? ''}</span>
        <h1>{$eventMeta?.description || 'Untitled event'}</h1>
        {#if $eventMeta?.closed}<span class="closed-pill">Closed</span>{/if}
      </div>
      <div class="stats"><EventStats /></div>
      <div class="toggles">
        <button class="toggle-btn" onclick={cycleTimeMode} title="Cycle time display: UTC → Local → T (relative)">
          <span aria-hidden="true">{timeModeIcons[$timeMode]}</span> {timeModeLabels[$timeMode]}
        </button>
        <button class="toggle-btn" onclick={toggleTheme} title="Toggle dark / light">
          <span aria-hidden="true">{$theme === 'dark' ? '☾' : '☀'}</span> {$theme === 'dark' ? 'Dark' : 'Light'}
        </button>
      </div>
    </div>

    <EntriesTable baseUrl={apiBaseUrl} readOnly={true} />
  {/if}
</div>

<style>
  .page { max-width: 1180px; margin: 0 auto; padding: 20px 20px 64px; display: flex; flex-direction: column; gap: 14px; }

  .masthead { display: flex; align-items: baseline; gap: 10px; padding: 2px 2px 10px; }
  .mark { font-family: var(--font-mono); font-size: 1rem; color: var(--accent); letter-spacing: 0.02em; }
  .tag { font-size: 0.78rem; color: var(--muted); }

  .hint { color: var(--muted); font-size: 0.9rem; text-align: center; padding: 40px 0; }

  .dead-link { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 28px 20px; text-align: center; }
  .dead-link h2 { margin: 0 0 8px; font-size: 1.05rem; }
  .dead-link p { margin: 0; color: var(--muted); font-size: 0.88rem; }

  .header {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 16px 18px;
    display: flex;
    align-items: center;
    gap: 18px;
    flex-wrap: wrap;
  }
  .titles { display: flex; flex-direction: column; gap: 2px; min-width: 0; flex: 1; }
  .titles .journal-name { font-size: 0.78rem; color: var(--muted); }
  .titles h1 { margin: 0; font-size: 1.15rem; font-weight: 700; line-height: 1.3; }
  .closed-pill {
    align-self: flex-start;
    margin-top: 4px;
    font-size: 0.7rem;
    font-weight: 700;
    letter-spacing: 0.03em;
    text-transform: uppercase;
    color: var(--muted);
    border: 1px solid var(--border);
    border-radius: 999px;
    padding: 2px 9px;
  }
  .stats { display: flex; align-items: center; gap: 4px; }
  .toggles { display: flex; align-items: center; gap: 8px; }

  @media (max-width: 720px) {
    .header { flex-direction: column; align-items: stretch; }
  }
</style>
