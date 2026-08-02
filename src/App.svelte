<script>
  // Top-level shell — screen switching, app bootstrap (theme, invite-link
  // handling), PWA update prompt. See docs/spec/ui-ux.md.
  import { onMount } from 'svelte';
  import { get } from 'svelte/store';
  import TopBar from './lib/components/TopBar.svelte';
  import Composer from './lib/components/Composer.svelte';
  import EntriesTable from './lib/components/EntriesTable.svelte';
  import ContactsView from './lib/components/ContactsView.svelte';
  import { initApp, theme, currentJournalUuid, currentEventUuid, eventMeta, eventsSummary, knownJournals, selectJournal, selectEvent, rememberedBootstrapSecret, rememberBootstrapSecret } from './lib/stores.js';
  import { saveIdentity, saveJournalBookmark, getKnownJournals } from './lib/db.js';

  let view = $state('journal'); // 'journal' | 'contacts'

  // Two DIFFERENT base URLs, deliberately not the same value — see
  // docs/deployment.md (Nginx/Apache configs) once written: the web
  // server's DocumentRoot is webroot/ (the built app), and the PHP
  // backend is reachable only under a separate /api alias. Conflating
  // these was a real bug caught while writing the deployment configs:
  //   - apiBaseUrl: every api.js call (fetch requests) — needs /api.
  //   - siteBaseUrl: user-facing links (invite URLs, create-journal
  //     links) that a browser navigates to directly — must NOT have
  //     /api, since those are loaded by the SPA itself, not proxied to PHP.
  const apiBaseUrl = location.origin + '/api';
  const siteBaseUrl = location.origin;

  let updateAvailable = $state(false);
  let updateSW = null;
  // Pre-filled from a ?bootstrap=... create-journal link (see below) —
  // passed down to the New Journal modal rather than having it read
  // location.search itself, specifically so the URL gets scrubbed
  // immediately on load rather than staying live in the address bar/
  // history for as long as the modal happens to stay closed.
  let prefillBootstrapSecret = $state('');

  // A ?j=&e=... link (see stores.js syncUrlParams) pointing at a journal/
  // event this device doesn't actually have — cleared bookmark, wrong
  // device, stale/bad link. Distinct from "nothing selected yet", which
  // just shows the ordinary hint text.
  let urlError = $state(null);

  onMount(async () => {
    document.documentElement.setAttribute('data-theme', 'dark');
    await initApp();
    document.documentElement.setAttribute('data-theme', get(theme));

    const params = new URLSearchParams(location.search);

    // Invite-link onboarding: /?journal=...&contact=...&secret=... — see
    // docs/spec/identity-and-security.md § Onboarding.
    const journalUuid = params.get('journal');
    const contactUuid = params.get('contact');
    const secret = params.get('secret');
    // Create-journal link: /?bootstrap=... — see
    // docs/spec/identity-and-security.md § Bootstrap.
    const bootstrap = params.get('bootstrap');
    // Deep link to a specific journal/event this device already knows
    // about — written by stores.js's syncUrlParams on every
    // selectJournal/selectEvent, read back here so a reload (including
    // the PWA "new version available" reload) restores exactly where you
    // were instead of dropping back to nothing selected.
    const linkJournalUuid = params.get('j');
    const linkEventUuid = params.get('e');

    if (journalUuid && contactUuid && secret) {
      await saveIdentity(journalUuid, contactUuid, secret);
      await saveJournalBookmark(journalUuid, journalUuid, apiBaseUrl); // name corrected once the journal metadata syncs
      knownJournals.set(await getKnownJournals());
      await selectJournal(journalUuid, apiBaseUrl);
      // selectJournal() already wrote ?j=<uuid> via syncUrlParams, but it
      // only touches j/e — it doesn't know about (and won't strip) the
      // journal/contact/secret params still sitting alongside it, so
      // scrub down to just what selectJournal wrote rather than to '/',
      // or we'd throw away the deep link we just gained along with the
      // plaintext secret we actually need to get rid of.
      history.replaceState({}, '', `/?j=${journalUuid}`);
    } else {
      if (bootstrap) {
        prefillBootstrapSecret = bootstrap;
        await rememberBootstrapSecret(bootstrap); // so future "+ New journal" doesn't need this link again
        history.replaceState({}, '', '/'); // scrub the bootstrap secret out of the URL bar/history immediately, not just on eventual use
      } else {
        prefillBootstrapSecret = get(rememberedBootstrapSecret);
      }

      if (linkJournalUuid) {
        const known = await getKnownJournals();
        const match = known.find((k) => k.uuid === linkJournalUuid);
        if (!match) {
          urlError = 'Journal cannot be found. Please request a new invite link.';
          history.replaceState({}, '', '/');
        } else {
          const firstSync = await selectJournal(match.uuid, match.baseUrl);
          if (linkEventUuid) {
            await firstSync; // give the network pull one real attempt before concluding it's missing — a fresh device's local cache starts empty
            if (get(eventsSummary)[linkEventUuid]) {
              await selectEvent(linkEventUuid);
            } else {
              urlError = 'Event cannot be found. Please request a new invite link.';
            }
          }
        }
      } else {
        const known = await getKnownJournals();
        if (known.length > 0) {
          await selectJournal(known[0].uuid, known[0].baseUrl);
        }
      }
    }

    // PWA update — never auto-applied, see docs/spec/ui-ux.md § Platform.
    if ('serviceWorker' in navigator) {
      try {
        const { registerSW } = await import('virtual:pwa-register');
        updateSW = registerSW({ onNeedRefresh: () => (updateAvailable = true) });
      } catch {
        // not running as an installed/built PWA (e.g. plain `vite dev`) — fine, no-op.
      }
    }
  });

  function applyUpdate() {
    updateSW?.(true);
  }
</script>

{#if updateAvailable}
  <button type="button" class="update-banner" onclick={applyUpdate}>
    New version available — click to refresh
  </button>
{/if}

{#if urlError}
  <div class="error-banner">
    <span>{urlError}</span>
    <button type="button" class="dismiss" onclick={() => (urlError = null)} aria-label="Dismiss">&times;</button>
  </div>
{/if}

<TopBar baseUrl={apiBaseUrl} {prefillBootstrapSecret} />

<div class="app">
  <div class="masthead">
    <span class="mark">journ</span>
    <div class="view-switch">
      <button type="button" class:is-active={view === 'journal'} onclick={() => (view = 'journal')}>Journal</button>
      <button type="button" class:is-active={view === 'contacts'} onclick={() => (view = 'contacts')}>Contacts</button>
    </div>
  </div>

  {#if !$currentJournalUuid}
    <p class="hint">Select or create a journal above to begin.</p>
  {:else if view === 'contacts'}
    <ContactsView baseUrl={apiBaseUrl} {siteBaseUrl} />
  {:else if !$currentEventUuid}
    <p class="hint">Select or create an event above to begin.</p>
  {:else}
    <Composer baseUrl={apiBaseUrl} disabled={!!$eventMeta?.closed} />
    <EntriesTable baseUrl={apiBaseUrl} />
  {/if}
</div>

<style>
  .update-banner {
    display: block;
    width: 100%;
    appearance: none;
    border: none;
    border-bottom: 1px solid var(--accent-soft);
    background: var(--accent);
    color: var(--accent-ink);
    font-weight: 700;
    font-size: 0.85rem;
    padding: 8px;
    cursor: pointer;
  }

  .error-banner {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    width: 100%;
    box-sizing: border-box;
    border-bottom: 1px solid var(--danger);
    background: var(--danger);
    color: var(--accent-ink);
    font-weight: 700;
    font-size: 0.85rem;
    padding: 8px;
  }
  .error-banner .dismiss {
    appearance: none;
    border: none;
    background: none;
    color: inherit;
    font-size: 1.1rem;
    line-height: 1;
    cursor: pointer;
    padding: 0 2px;
  }

  .app {
    max-width: 1180px;
    margin: 0 auto;
    padding: 20px 20px 64px;
    display: flex;
    flex-direction: column;
    gap: 14px;
  }

  .masthead { display: flex; align-items: baseline; justify-content: space-between; gap: 16px; padding: 2px 2px 10px; }
  .mark { font-family: var(--font-mono); font-size: 1rem; color: var(--accent); letter-spacing: 0.02em; }

  .view-switch { display: flex; gap: 2px; background: var(--surface-2); border: 1px solid var(--border); border-radius: 7px; padding: 2px; }
  .view-switch button { appearance: none; border: none; background: transparent; color: var(--ink-dim); font-size: 0.8125rem; font-weight: 600; padding: 6px 13px; border-radius: 5px; cursor: pointer; }
  .view-switch button.is-active { background: var(--surface); color: var(--ink); box-shadow: 0 1px 0 var(--border-soft); }

  .hint { color: var(--muted); font-size: 0.9rem; text-align: center; padding: 40px 0; }
</style>
