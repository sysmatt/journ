// Entry text -> safe HTML. See docs/spec/ui-ux.md § Contact chips and
// § Tags & completion tracking, and docs/spec/data-model.md § Content &
// formatting.
//
// Pipeline:
//   1. Extract tag:/update: tokens into placeholders (they're bare
//      tokens, not markdown syntax, so they can't go through the
//      markdown parser directly).
//   2. Render the rest as sanitized Markdown — contact mentions
//      (`[@Name](contact:{uuid})`) ARE valid markdown link syntax, so
//      they're handled via a custom link renderer instead of a separate
//      extraction pass.
//   3. Sanitize.
//   4. Splice the tag/update chip HTML back in — built directly (with
//      manual escaping), entirely after sanitization, so the sanitizer
//      never needs to know about them.

import { Marked } from 'marked';
import DOMPurify from 'dompurify';

// Unicode Private Use Area characters — never appear in normal text,
// single UTF-16 code units (no surrogate-pair concerns), safe to pass
// through both the markdown parser and DOMPurify untouched.
const PLACEHOLDER_START = '';
const PLACEHOLDER_END = '';

function escapeHtml(str) {
  return String(str).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

/**
 * Scans for `tag:value` / `update:value` tokens (case-insensitive
 * trigger, whitespace-delimited, trailing punctuation on the value
 * stripped) and replaces each with a placeholder marker. Returns the
 * modified text plus the chip HTML to splice back in per marker.
 * @param {string} text
 * @param {Map<string, {fg: string, bg: string, highlight_row: boolean}>} tagConfig - keyed by lowercase tag name; must include a "default" entry
 * @returns {{ text: string, chips: string[], rowHighlight: {fg: string, bg: string} | null }}
 */
function extractTagTokens(text, tagConfig) {
  const chips = [];
  let rowHighlight = null;

  const replaced = text.replace(/\b(tag|update):(\S+)/gi, (match, trigger, rawValue) => {
    const value = rawValue.replace(/[.,;:!?)\]]+$/, ''); // strip trailing punctuation
    const isUpdate = trigger.toLowerCase() === 'update';

    let html;
    if (isUpdate) {
      const num = parseFloat(value);
      if (Number.isNaN(num)) return match; // not a real update: token, leave as plain text
      const pct = Math.max(0, Math.min(100, Math.round(num)));
      html = `<span class="chip chip-update">update:${pct}%</span>`;
    } else {
      const name = value.toLowerCase();
      const def = tagConfig.get(name) || tagConfig.get('default') || { fg: '#16222e', bg: '#cfe0f5', highlight_row: false };
      html = `<span class="chip" style="color:${escapeHtml(def.fg)};background:${escapeHtml(def.bg)}">${escapeHtml(value)}</span>`;
      if (def.highlight_row && !rowHighlight) {
        // First-declared-wins — tagConfig is iterated in declaration
        // order by the caller, and this only ever sets rowHighlight once
        // (see docs/spec/ui-ux.md § Tags: declaration order = precedence).
        rowHighlight = { fg: def.fg, bg: def.bg };
      }
    }

    chips.push(html);
    return `${PLACEHOLDER_START}${chips.length - 1}${PLACEHOLDER_END}`;
  });

  return { text: replaced, chips, rowHighlight };
}

function buildMarked(contactsByUuid) {
  const marked = new Marked();
  const renderer = {
    link({ href, tokens }) {
      if (href && href.startsWith('contact:')) {
        const uuid = href.slice('contact:'.length);
        const contact = contactsByUuid?.[uuid];
        const label = contact ? (contact.short_name || contact.name || contact.email || uuid) : 'unknown';
        return `<span class="chip chip-mention" data-contact="${escapeHtml(uuid)}" tabindex="0">@${escapeHtml(label)}</span>`;
      }
      const text = this.parser.parseInline(tokens);
      return `<a href="${escapeHtml(href)}" target="_blank" rel="noopener noreferrer">${text}</a>`;
    },
  };
  marked.use({ renderer, gfm: true, breaks: true });
  return marked;
}

/**
 * @param {string} text - raw plain-text entry content
 * @param {object} [opts]
 * @param {Record<string, object>} [opts.contactsByUuid] - for resolving mention chip display labels
 * @param {{name: string, fg: string, bg: string, highlight_row: boolean}[]} [opts.tags] - from GET /tags, in declaration order
 * @returns {{ html: string, rowHighlight: {fg: string, bg: string} | null }}
 */
/**
 * Event completion percentage — see docs/spec/ui-ux.md § Tags &
 * completion tracking: whichever `update:` value comes from the entry
 * with the latest effective timestamp, not "highest value seen." Can
 * legitimately move backward. Returns null if no entry has one.
 * @param {object[]} entries
 */
export function computeCompletionPercent(entries) {
  let winner = null;
  for (const entry of entries) {
    const matches = [...(entry.text || '').matchAll(/\bupdate:(\S+)/gi)];
    if (matches.length === 0) continue;
    const raw = matches[matches.length - 1][1].replace(/[.,;:!?)\]]+$/, '').replace('%', '');
    const num = parseFloat(raw);
    if (Number.isNaN(num)) continue;
    const pct = Math.max(0, Math.min(100, Math.round(num)));
    const ts = entry.updated_at || entry.created_at;
    if (!winner || Date.parse(ts) >= Date.parse(winner.updatedAt)) {
      winner = { value: pct, updatedAt: ts };
    }
  }
  return winner ? winner.value : null;
}

/**
 * Display name derivation order (see docs/spec/data-model.md): short
 * name, then first word of full name, then the local-part of email.
 * Computed live from whatever the current reduced contact record is —
 * never stored/cached as its own field.
 */
export function deriveDisplayName(contact) {
  if (!contact) return 'Unknown';
  if (contact.short_name) return contact.short_name;
  if (contact.name) return contact.name.trim().split(/\s+/)[0];
  if (contact.email) return contact.email.split('@')[0];
  return 'Unknown';
}

export function renderEntryText(text, { contactsByUuid = {}, tags = [] } = {}) {
  const tagConfig = new Map(tags.map((t) => [t.name.toLowerCase(), t]));

  const { text: withPlaceholders, chips, rowHighlight } = extractTagTokens(text || '', tagConfig);

  const marked = buildMarked(contactsByUuid);
  const rawHtml = marked.parse(withPlaceholders, { async: false });

  const clean = DOMPurify.sanitize(rawHtml, {
    ALLOWED_TAGS: ['p', 'br', 'strong', 'em', 'a', 'ul', 'ol', 'li', 'code', 'pre', 'blockquote', 'span'],
    ALLOWED_ATTR: ['href', 'target', 'rel', 'class', 'data-contact', 'tabindex'],
  });

  const html = clean.replace(
    new RegExp(`${PLACEHOLDER_START}(\\d+)${PLACEHOLDER_END}`, 'g'),
    (_, i) => chips[Number(i)] ?? ''
  );

  return { html, rowHighlight };
}
