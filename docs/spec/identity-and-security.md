# Identity, Onboarding & Security Model

Status: draft spec, promoted from `docs/brain-dump.md`. See
`data-model.md` for the Contact data shape. This document covers the
*why* and the *how* of identity, auth, onboarding, and recovery.

## Design stance: identity is loose, on purpose

**There are no privilege levels or permissions in journ. Every contact in
a journal is equal.** Identity is deliberately loose — it exists to
attribute entries to a person and to gate writes at a coarse level, not
to function as a strong security boundary. This stance shapes every
decision below.

## Why contacts are per-journal, not global

Considered and rejected: a single global "person" identity spanning every
journal on an install, to avoid re-inviting the same people to every new
incident.

Kept per-journal instead, because:
- **Self-containment.** Every other design choice pushes toward "a
  journal is a fully independent, portable unit" — exportable, cloneable,
  purgeable as one folder, reconstructable from itself alone. Global
  contacts would break that (a journal would no longer be reconstructable
  from its own folder; exporting it would raise "does the recipient also
  need the separate global contact store?").
- **Access control falls out for free.** "Possession of the per-contact
  secret for *this* journal" is the entire write-access model. A contact
  created for Journal A has zero ability to touch Journal B, trivially,
  with no extra concept needed. Global identity doesn't eliminate the
  need for "who can write to this journal" — it just relocates the
  question, and answering it either means new journals auto-grant access
  to every known global contact (surprising, probably wrong) or requires
  an explicit membership/ACL system — exactly the complexity the
  no-privilege-levels stance avoids everywhere else.
- **It's already baked into the invite design** (below) — invite links
  encode a *(contact-uuid, journal-uuid)* pair, already a per-journal
  identity model.

The actual friction this was solving (re-typing the same people's
name/email for every new journal) is addressed instead by a **client-side,
browser-local "remembered people" autofill** — never synced, not a
data-model concept, purely a convenience suggestion when creating a new
contact. Each journal still gets a fresh UUID and fresh secret per
contact.

## Contact secrets

- Each contact has a **secret credential** used for backend write
  authentication.
- **Only a hash of the secret is stored** in the synced
  `contact.{uuid}.json` record. The plaintext secret is only ever handed
  to the contact themselves, embedded in their invite URL/email — never
  distributed to other clients. The default backend validates incoming
  write requests by hashing the presented secret and comparing to the
  stored hash.
- **"Regenerate key"** replaces the stored hash, invalidating the old
  plaintext secret everywhere immediately — the old holder is locked out
  on their next API call, without the new or old plaintext ever being
  exposed to other clients. Primary purpose is **being able to lock
  someone out**, not preventing impersonation (impersonation isn't a
  major concern under the no-privilege-levels model).
- Open, not yet finalized: whether "regenerate key" requires anything
  beyond generic write access to the journal (leaning toward: no extra
  gate needed, consistent with "everyone equal").

## Onboarding / invite mechanism

- Each contact gets a unique **invite URL**, generated from the contact
  management page, encoding the contact's UUID + the journal's UUID (plus
  the plaintext secret — see above).
- **Bulk invite**: paste a list of emails → creates a contact per email,
  optionally sends each an invite email containing their personal invite
  URL.
- Per-contact UI actions: copy invite URL, send invite email, regenerate
  key (lockout).
- **Multi-device use is expected and fine** — the same contact can reuse
  the same invite URL/secret across multiple devices (e.g. phone +
  laptop) simultaneously. No per-device credential needed; writes from
  either device are still just ordinary UUID-keyed writes, so there's no
  collision risk.
- **Shared-device identity switching** needs no new mechanism — on a
  shared ops-room workstation, switching "who's currently posting" is
  just: go to contact management, open the next person's invite URL. A
  little clunky for rapid handoffs, but workable.
- Email delivery is configured via the server-wide INI config (see
  `operations.md`) — not per-journal config.

## Bootstrap: creating a new journal

A **global secret**, set in the server-wide INI config, gates journal
creation. This is what bootstraps trust before any per-contact secret
exists to check against.

**One atomic operation.** A single bootstrap-secret-authenticated request
creates the journal folder, writes its metadata, *and* writes the initial
contact set with already-working (non-null) secrets — "just the creator"
for a fresh journal, or the full auto-regenerated imported list for a
clone/import (see `api-and-sync.md` § Export & Import). By the time the
call returns success, at least one distributable working secret already
exists.

(This collapses what was originally a two-step "create journal, then
create the first contact" flow, which left a dangling in-between state: a
journal that exists with zero working contacts, indefinitely writable by
the bootstrap secret until someone finished setup. The atomic version has
no such orphan-prone limbo state, and the bootstrap secret's scope becomes
trivial to state precisely: it authorizes exactly one thing — minting a
brand-new journal via that one atomic call — and has zero authority over
anything, including that same journal, a moment later.)

**UX: a shareable link, not a raw secret** — same pattern as contact
invites. An admin generates a "create new journal" link once (embedding
the bootstrap secret) and hands it to whoever's allowed to start new
incidents. End users click/bookmark a link; nobody manually copies a
secret out of server config.

**Intended workflow**: the link mints a brand-new journal *every time
it's used* — unlike a contact invite link, there's no coordination
between separate clicks. The intended usage is exactly **one person
creates the journal, then invites everyone else in** via ordinary contact
invite links — not everyone independently clicking "create" for what they
think is the same incident (that produces separate, disconnected
journals).

This mechanism is accepted as good-for-now, not necessarily final — a
candidate for a better approach later if one presents itself, but not
blocking.

**Alternate auth: proof of existing contact status.** `POST /journal`
also accepts proof of already being a valid contact of some *other*
journal in place of the bootstrap secret — see `payload-shapes.md` §
Auth headers. This is a deliberate widening of who can mint new
journals: from "whoever holds the shared bootstrap secret" to "anyone
already onboarded anywhere on this install." The client prefers this
path automatically whenever this device already has an identity for its
currently-selected journal, so most users never need the bootstrap
secret at all after their first onboarding — it only remains as the
fallback for a device with no journal/identity yet.

This does mean the bootstrap secret's blast radius is no longer as
narrow as "one atomic call, then dead" once contacts exist — any contact
of any journal is transitively equivalent to a bootstrap-secret holder
for the specific purpose of creating new journals. Accepted tradeoff for
this install's use case (any onboarded responder should be able to
start a new incident journal themselves); revisit if a deployment ever
wants journal-creation kept to a narrower group than "everyone with
access to anything."

## Public dashboard secret (per-event, read-only sharing)

A per-**event** secret, opt-in, that grants read-only access to that one
event's metadata + entries to anyone holding the link — no contact
identity, no journal membership, no write access of any kind.

**Why this needs its own enforcement, unlike everything else.** Every
other read in this system is deliberately ungated (`payload-shapes.md` §
Auth headers: "reads are never gated") — journal/event UUIDs are the
only access control, and that's fine because UUIDs are 122 bits of
unguessable entropy only ever handed to legitimate contacts. A public
dashboard breaks that assumption on purpose: the whole point is handing
a link to people who are *not* contacts, with the ability to later
**revoke** it. If the dashboard link just carried the real journal/event
UUIDs, revocation would be theater — the old link's UUIDs would still
work forever against the ordinary list/get endpoints. So the dashboard
is served by its own pair of endpoints (`GET
/journal/{uuid}/events/{uuid}/dashboard` and its cheap `.../freshness`
sibling — see `payload-shapes.md`) that actually check the secret
server-side via `X-Journ-Dashboard-Secret`, completely separate from the
normal always-open sync API. Regenerating the secret means the old
value simply stops matching — genuine revocation, not obscurity.

**Storage: plaintext, not hashed — deliberately different from contact
secrets.** It lives as `public_secret` on the event metadata fragment
(`data-model.md` § Event), alongside `description`/`start_at`/etc, an
ordinary field in an ordinary LWW-reduced write — no bespoke server
endpoint needed to set/clear/regenerate it, `saveEventMeta()` handles it
exactly like any other field. Contact secrets are hash-only specifically
to protect *other people's write-capable identities* from a compromised
store; that threat doesn't apply here — every contact of the journal
already gets this event's full metadata (public_secret included)
synced to their device regardless, since they already have complete
read+write access to it anyway. The secret only meaningfully gates
*non*-contacts, and it's never sent to anyone who isn't already either
a contact or already holds the dashboard link.

**Lifecycle**, driven entirely from the event's edit UI (`ui-ux.md` §
Public dashboard):
- Enabling generates a fresh secret and sets it.
- Regenerating replaces it — old links stop working immediately, same
  "this locks out the current holder" warning pattern as contact-key
  regeneration.
- Disabling clears it to `null` — the dashboard 404s for everyone until
  re-enabled, which mints an unrelated new secret (an old, disabled link
  never comes back to life).

**What's deliberately excluded from the dashboard response** — see
`payload-shapes.md` for the exact shape:
- Contact emails — only `name`/`short_name` are exposed, needed purely
  to resolve `@mention` chip labels and entry authorship.
- Attachment references — stripped from entries entirely server-side,
  not merely unlinked client-side.
- The Composer, journal/event pickers, and Contacts view have no
  presence on this page at all; it's a distinct, minimal client route
  (`ui-ux.md`), not a restricted mode of the main app shell.

## Break-glass recovery

Closes a real gap: if a journal's contacts are all locked out (most
realistically, its sole contact loses their device/secret), there's no
path back in through the ordinary bootstrap secret — its authority is
already spent and never covers existing journals.

- **Non-solo case needs no new mechanism.** Any other contact who still
  has working access can already regenerate + resend the locked-out
  person's invite via ordinary contact management.
- **Total lockout** requires a **second, distinct secret** in the
  server-wide INI config, separate from the journal-creation secret.
  Different risk levels justify separating them: journal-creation only
  ever produces new empty journals (low stakes); the recovery secret can
  reach into any existing journal's data, so it's meant to be held more
  tightly — e.g. only by the server operator, not distributed as a link
  the way the create-journal secret is.
- The recovery action itself is narrow and single-purpose: given a
  journal UUID, add one new emergency contact with a working secret. It
  does **not** restore or reactivate any of the old dead secrets — those
  stay dead, same as ordinary regeneration. It just seeds one working
  foothold; the recovered contact then uses normal contact-management
  tools to bring everyone else back in.
- **Deliberately not given the nice shareable-link UX** that
  journal-creation got — meant to be rare, high-trust, and manually
  operated. Friction here is intentional, consistent with how
  compaction/attic recovery is treated as manual/out-of-band rather than
  polished in-app UX.
- Not a new risk category — same "possession of a secret grants access"
  pattern already accepted everywhere else in this design.

## Summary of secrets in server config

See `operations.md` for the full INI reference. Three distinct
install-level secrets exist, each with a different scope and blast
radius:

| Secret | Grants | Held by |
|---|---|---|
| Journal-creation (bootstrap) | Mint a brand-new, empty journal | Anyone allowed to start new incidents (shareable link) |
| Recovery | Add an emergency contact to *any existing* journal | Server operator only (deliberately not link-shared) |
| Per-contact secret (not install-level) | Write access to one specific journal, as one specific contact | Whoever that contact invites |
