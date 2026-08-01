# UI / UX

Status: draft spec, promoted from `docs/brain-dump.md`. See
`data-model.md` for the underlying objects and `api-and-sync.md` for sync
behavior this UI reflects.

## Platform

- **Single-page app**, installed as a PWA (service worker + Web App
  Manifest) — installs once while online, then relaunches and runs
  indefinitely with zero network, no server round-trip to boot.
- Local data storage: IndexedDB, holding both raw synced fragments and
  the cached full-reduce result (see `api-and-sync.md` § Client-side
  caching strategy).
- **App updates are never auto-applied.** New version detection surfaces
  as a prominent, dismissible **"New version available"** button on the
  top bar — user-initiated refresh only, never a forced/silent reload.
  Deliberately conservative given the app may be in active use
  mid-incident.

## Top bar

**Full-bleed instrument bar**: on windows wide enough to fit it, the
entire top bar — journal/event pickers, start time, duration, completion
percentage, local/UTC toggle, theme toggle, and sync status — sits on one
row spanning the full browser width edge to edge, rather than being
confined to the app's centered content column. Narrower windows fall back
to wrapping. Start time, running duration, and the completion percentage
are rendered at roughly **2x** the size of the surrounding UI text — a
deliberate large-numeral "instrument readout" treatment, since these are
the values someone glances at from across a room during an incident.

Left-to-right / logical grouping:

- **Journal selector** (dropdown), with a **"New"** option that opens a
  modal for entering metadata and triggers journal creation.
- **Event selector** (dropdown), same "New" pattern for events.
- **Edit icon** (pencil) next to the currently-selected journal and event
  — one shared entry point for editing metadata on whichever is selected.
  Reuses the same modal/form as "new journal"/"new event," pre-filled
  with current values, submitting as an update rather than a create.
- **Event start time** (with its date on a smaller line beneath, same
  treatment as the entries table) and a **live running duration** computed
  from the start timestamp — ticks while the event is open. Duration has
  no date line, since it's an elapsed span, not an absolute time.
- **Completion percentage** (0–100%), shown only once at least one valid
  `update:` tag exists anywhere in the currently-selected event (see
  Tags, below) — silent/absent otherwise. Scoped to the
  currently-selected event, same as start time/duration.
- **Time display toggle — three modes: UTC / Local / T** *(T mode added
  2026-07-31)*. Cycles on click, same placement pattern as the theme
  toggle.
  - **UTC** and **Local** are the absolute-time modes described
    throughout this doc (entries table, top-bar start time).
  - **T mode** ("T-minus/T-plus," countdown-style) shows entry times
    **relative to a chosen reference entry** instead of absolute
    clock time. Only affects the **entries table** — the top-bar start
    time/duration are unaffected, since duration is already a relative
    measure of its own.
    - A **radio button appears next to the time on each entry row**,
      visible only in T mode. Exactly one row is selected as the
      reference ("T-zero") at a time.
    - **Defaults to the most recent entry** (the top row, given the
      table is newest-first).
    - Every row's displayed time recalculates as `T+hh:mm:ss` (after the
      reference) or `T-hh:mm:ss` (before it); the reference row itself
      always reads `T+00:00:00`.
    - Selecting a different row's radio re-anchors T-zero there and
      recalculates every row's offset immediately.
    - Purpose: makes relative sequencing between events fast to read
      during incident analysis ("how long after the fault was the
      breaker confirmed tripped") without doing timestamp math by hand.
- **Dark/light mode toggle** (dark is default).
- **Sync status icon**, right side: **synced** (no pending local writes,
  server reachable), **syncing** (actively pushing/pulling), or
  **offline** (server unreachable — may have entries queued locally
  waiting to go out). Direct visibility into "is my data actually safe on
  the server yet," given the whole premise is intermittent connectivity.

## Entry creation area

Shown once inside a selected (open) event:

- A simple text entry box, a small attachment drop/click-to-browse area,
  and a **Post** button.
- Hitting **Enter** in the text area also posts. Design intent: creating
  an entry should be as fast/frictionless as possible.
- When the event is **closed**, this entire area is grayed out/disabled
  — no new entries, no edits, no trashing.

### Contact chips (`@`-mentions)

- Typing `@` triggers a filtered autocomplete dropdown of contacts.
  - **v1 input approach**: plain `<textarea>`, no new editor dependency.
    The dropdown is positioned using the standard caret-position-measuring
    technique for textareas. Selecting a contact splices a bracket-token
    (`[@ShortName](contact:{uuid})`) into the text at the cursor.
  - **Autocomplete dropdown shows name and email alongside short name**,
    specifically to help disambiguate when short names collide — also
    doubles as a soft nudge encouraging people to pick unique short names.
  - Accepted tradeoff: while composing, the raw bracket-token syntax is
    visible as literal text, becoming a **chip** only once rendered —
    consistent with how a bare URL already behaves in this design.
  - Resolution is UUID-based, not name-based, so a mention stays
    unambiguous even though short names aren't unique.
  - **Explicitly deferred (not v1)**: a richer `contenteditable`-based
    editor giving true live chip-rendering while typing (closer to the
    Slack/ServiceNow feel) — worth "auditioning" later once there's real
    experience with the plain-textarea version.
- **Rendered chip** is hoverable, with actions (exact action set TBD at
  implementation time).

### Tags & completion tracking

Parsing rule: scanning entry text for whitespace-delimited words
containing an embedded colon (`word:value`). If the word before the colon
matches a known trigger (`tag`, `update` — extensible later), it gets
special rendering instead of staying plain text.

- Trigger-word matching is case-insensitive. Multiple occurrences per
  entry are fine (`tag:rush tag:critical` both become chips). Trailing
  punctuation attached to the value is stripped.

**`tag:sometext`** — free-text tag word, rendered as an inline chip in
place of the raw text. **The chip displays only the value, with the
`tag:` prefix suppressed** (e.g. `tag:critical` renders as a chip reading
"critical," not "tag:critical").
- Colors/behavior are config-driven, one INI section per tag word (see
  `operations.md` for the config format): foreground color, background
  color, and whether the tag **highlights the entire entry row** (default:
  chip-only, no row highlight).
- A handful of well-known tags ship as code defaults (rush, critical,
  action, decision) — overridable and extensible via the same server-wide
  INI config.
- `[tag:default]` is a reserved config section — the fallback style for
  any tag word not otherwise recognized. It's an ordinary tag definition
  like any other, just matched when nothing else matches.
- **Declaration order in the config file is precedence order** — matters
  specifically for resolving row-highlight conflicts: if an entry
  contains multiple tags that each set row-highlight with different
  colors, whichever is declared first wins the row's color. Each tag's
  own chip always keeps its individual color regardless; precedence only
  arbitrates the single shared row-color slot.

**`update:N` or `update:N%`** (`%` optional, same meaning) — tracks a
0–100% completion level for the current event.
- Rendered as a chip with a **fixed neutral color**, not part of the
  configurable tag-color system (functionally different from `tag:` — it
  drives a numeric gauge, not a categorical label). Unlike the `tag:`
  chip, the `update:` prefix is **kept** in the rendered chip (e.g.
  "update:65%"), and the chip **always displays a trailing `%`** for
  clarity regardless of whether the author typed it in the source text.
- **Value resolution**: the event's displayed percentage is whichever
  `update:` value comes from the entry with the latest effective
  timestamp among *all* entries in the event containing one — not
  "highest value seen" or "most recent entry overall." The percentage can
  legitimately move backward over time as a situation is reassessed.
- Malformed/out-of-range values are clamped to 0–100 for display;
  anything that doesn't parse as a number is silently ignored.
- If no entry in an event ever contains `update:`, the app stays silent —
  no percentage shown. Not every event needs a completion measure.

## Entries table

Below the entry-creation area, most-recent-first:

| Column | Contents |
|---|---|
| Date/time | Time shown prominently, with the **date on a smaller line beneath it** (in both UTC and local display modes — a multi-day incident needs date context either way, and local-timezone display can put an entry on a different calendar date than its underlying UTC timestamp). Localized for display (local/UTC toggle), stored as UTC |
| Author | Contact display name (see `data-model.md` § display name derivation) |
| Entry | Rendered text (Markdown + chips) + attachment icons |
| Actions | Edit (pencil), delete (trashcan, soft-delete only) |

- **No "edited" indicator** — an edited entry displays identically to one
  that was never touched. Edit history remains forensic/file-level only,
  by design.
- **Live updates**: new synced entries just appear live in the table as
  they arrive — no special scroll-preservation/"N new entries" handling
  for v1. Deliberately deferred pending real UX experience, rather than
  designing for a problem that might not materialize.

## Global operations

Live at the very bottom of the page:

- Import / export.
- "Show all entries, including deleted" toggle.

## Contact management page

- Per-contact: copy-invite-link icon, send-invite-email icon,
  regenerate-key icon (lockout).
- Bulk invite via pasted email list (creates a contact per email,
  optionally sends invite emails).
