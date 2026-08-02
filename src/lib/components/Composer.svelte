<script>
  // Entry composer + @-mention autocomplete — see docs/spec/ui-ux.md §
  // Entry creation area and § Contact chips. v1 approach: plain
  // <textarea>, caret-position measured via a hidden mirror element
  // (the standard technique for this, since textareas don't expose
  // cursor pixel-position natively).
  import { tick } from 'svelte';
  import { contacts, currentEventUuid, currentJournalUuid } from '../stores.js';
  import { postEntry } from '../stores.js';
  import { uploadAttachment } from '../api.js';
  import { getIdentity } from '../db.js';

  let { baseUrl, disabled = false } = $props();

  let text = $state('');
  let textareaEl = $state(null);
  let mirrorEl = $state(null);
  let fileInputEl = $state(null);
  let pendingFiles = $state([]);
  let posting = $state(false);

  let mentionQuery = $state(null); // string while the autocomplete is open, else null
  let mentionStart = $state(0);
  let mentionPos = $state({ top: 0, left: 0 });
  let mentionIndex = $state(0);

  let mentionMatches = $derived(
    mentionQuery === null
      ? []
      : Object.entries($contacts)
          .map(([uuid, c]) => ({ uuid, ...c, display: c.short_name || c.name || c.email || uuid }))
          .filter((c) => {
            const q = mentionQuery.toLowerCase();
            return (c.short_name || '').toLowerCase().includes(q) || (c.name || '').toLowerCase().includes(q) || (c.email || '').toLowerCase().includes(q);
          })
          .slice(0, 8)
  );

  function detectMention() {
    const cursor = textareaEl.selectionStart;
    const upToCursor = text.slice(0, cursor);
    const match = upToCursor.match(/@(\S*)$/);
    if (match) {
      mentionStart = cursor - match[0].length;
      mentionQuery = match[1];
      mentionIndex = 0;
      positionMentionDropdown(cursor);
    } else {
      mentionQuery = null;
    }
  }

  function positionMentionDropdown(cursor) {
    if (!mirrorEl) return;
    mirrorEl.textContent = text.slice(0, cursor);
    const marker = document.createElement('span');
    marker.textContent = '​';
    mirrorEl.appendChild(marker);
    mentionPos = { top: marker.offsetTop + 24, left: Math.min(marker.offsetLeft, 260) };
  }

  async function selectMention(contact) {
    const before = text.slice(0, mentionStart);
    const after = text.slice(textareaEl.selectionStart);
    const token = `[@${contact.display}](contact:${contact.uuid})`;
    text = `${before}${token} ${after}`;
    mentionQuery = null;
    await tick();
    const newPos = before.length + token.length + 1;
    textareaEl.focus();
    textareaEl.setSelectionRange(newPos, newPos);
  }

  function onKeydown(e) {
    if (mentionQuery !== null && mentionMatches.length > 0) {
      if (e.key === 'ArrowDown') { e.preventDefault(); mentionIndex = Math.min(mentionIndex + 1, mentionMatches.length - 1); return; }
      if (e.key === 'ArrowUp') { e.preventDefault(); mentionIndex = Math.max(mentionIndex - 1, 0); return; }
      if (e.key === 'Enter' || e.key === 'Tab') { e.preventDefault(); selectMention(mentionMatches[mentionIndex]); return; }
      if (e.key === 'Escape') { mentionQuery = null; return; }
    }
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      submit();
    }
  }

  function onFileChange(e) {
    pendingFiles = [...pendingFiles, ...e.target.files];
    fileInputEl.value = '';
  }

  function removeFile(i) {
    pendingFiles = pendingFiles.filter((_, idx) => idx !== i);
  }

  async function submit() {
    if (posting || (!text.trim() && pendingFiles.length === 0)) return;
    posting = true;
    try {
      const identity = await getIdentity($currentJournalUuid);
      const attachments = [];
      for (const file of pendingFiles) {
        const meta = await uploadAttachment(baseUrl, $currentJournalUuid, $currentEventUuid, identity.contactUuid, identity.secret, file);
        attachments.push({ uuid: meta.uuid, original_filename: file.name, storage_filename: meta.storage_filename, content_type: meta.content_type, size: meta.size });
      }
      await postEntry(text.trim(), attachments);
      text = '';
      pendingFiles = [];
      mentionQuery = null;
    } finally {
      posting = false;
    }
  }
</script>

<div class="composer">
  <div class="composer-row">
    <textarea
      bind:this={textareaEl}
      bind:value={text}
      oninput={detectMention}
      onkeydown={onKeydown}
      onclick={detectMention}
      placeholder="Add an entry… (Enter to post)"
      {disabled}
    ></textarea>
    <label class="attach-slot" class:has-files={pendingFiles.length > 0}>
      <input bind:this={fileInputEl} type="file" multiple onchange={onFileChange} style="display:none" {disabled} />
      <span aria-hidden="true">📎</span>
      <span>{pendingFiles.length > 0 ? `${pendingFiles.length} file${pendingFiles.length > 1 ? 's' : ''}` : 'attach'}</span>
    </label>
    <button type="button" class="post-btn" onclick={submit} disabled={disabled || posting}>
      {posting ? 'Posting…' : 'Post'}
    </button>
  </div>

  {#if pendingFiles.length > 0}
    <div class="pending-files">
      {#each pendingFiles as file, i}
        <span class="attach-tag">
          📎 {file.name}
          <button type="button" class="remove" onclick={() => removeFile(i)} aria-label="Remove {file.name}">×</button>
        </span>
      {/each}
    </div>
  {/if}

  <div class="composer-hint">
    <kbd>Enter</kbd> posts · <kbd>Shift</kbd>+<kbd>Enter</kbd> for newline · <kbd>@</kbd> mentions a contact ·
    <code>tag:</code> / <code>update:</code> annotate the entry
  </div>

  <!-- Off-screen mirror used purely to measure caret pixel position for the mention dropdown. -->
  <div bind:this={mirrorEl} class="mirror" aria-hidden="true"></div>

  {#if mentionQuery !== null && mentionMatches.length > 0}
    <div class="mention-demo" style="top:{mentionPos.top}px; left:{mentionPos.left}px;">
      {#each mentionMatches as c, i}
        <button
          type="button"
          class="mention-opt"
          class:is-focused={i === mentionIndex}
          onclick={() => selectMention(c)}
        >
          <span class="avatar">{(c.short_name || c.name || '??').slice(0, 2).toUpperCase()}</span>
          <span class="who">
            <span class="short">{c.display}</span>
            <span class="full">{c.name || ''}{c.name && c.email ? ' · ' : ''}{c.email || ''}</span>
          </span>
        </button>
      {/each}
    </div>
  {/if}
</div>

<style>
  .composer { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 12px; position: relative; }
  .composer-row { display: flex; gap: 10px; align-items: flex-end; }
  textarea {
    flex: 1;
    resize: vertical;
    min-height: 46px;
    max-height: 160px;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 10px 12px;
    font-size: 0.9375rem;
    line-height: 1.45;
  }
  textarea::placeholder { color: var(--muted); }
  textarea:disabled { opacity: 0.5; }

  .attach-slot {
    display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 2px;
    width: 58px; height: 46px;
    border: 1px dashed var(--border);
    border-radius: 8px;
    color: var(--muted);
    font-size: 0.65rem;
    flex-shrink: 0;
    cursor: pointer;
  }
  .attach-slot:hover, .attach-slot.has-files { border-color: var(--accent); color: var(--accent); }

  .post-btn {
    appearance: none; border: none; background: var(--accent); color: var(--accent-ink);
    font-weight: 700; font-size: 0.875rem; padding: 0 20px; height: 46px; border-radius: 8px; cursor: pointer; flex-shrink: 0;
  }
  .post-btn:hover { filter: brightness(1.08); }
  .post-btn:disabled { opacity: 0.6; cursor: default; }

  .pending-files { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
  .attach-tag {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 0.74rem; color: var(--ink-dim);
    background: var(--surface-2); border: 1px solid var(--border);
    padding: 2px 6px 2px 8px; border-radius: 6px;
  }
  .attach-tag .remove { appearance: none; border: none; background: none; color: var(--muted); cursor: pointer; font-size: 0.9rem; line-height: 1; padding: 0 2px; }
  .attach-tag .remove:hover { color: var(--danger); }

  .composer-hint { font-size: 0.72rem; color: var(--muted); margin-top: 7px; padding-left: 2px; }
  .composer-hint kbd { font-family: var(--font-mono); background: var(--surface-3); border: 1px solid var(--border); border-radius: 4px; padding: 0 4px; font-size: 0.68rem; }
  .composer-hint code { font-family: var(--font-mono); font-size: 0.68rem; }

  .mirror {
    position: absolute;
    visibility: hidden;
    white-space: pre-wrap;
    word-wrap: break-word;
    font-size: 0.9375rem;
    line-height: 1.45;
    padding: 10px 12px;
    width: calc(100% - 100px);
    top: 0; left: 12px;
  }

  .mention-demo {
    position: absolute;
    width: 268px;
    background: var(--surface-3);
    border: 1px solid var(--border);
    border-radius: 9px;
    box-shadow: 0 12px 28px rgba(0, 0, 0, 0.35);
    overflow: hidden;
    z-index: 5;
  }
  .mention-opt {
    appearance: none; border: none; width: 100%; text-align: left; cursor: pointer;
    display: flex; align-items: center; gap: 9px;
    padding: 8px 10px;
    border-bottom: 1px solid var(--border-soft);
    background: var(--surface-3); color: var(--ink);
  }
  .mention-opt:last-child { border-bottom: none; }
  .mention-opt.is-focused, .mention-opt:hover { background: var(--surface-2); }
  .mention-opt .who { display: flex; flex-direction: column; gap: 0; min-width: 0; }
  .mention-opt .short { font-size: 0.82rem; font-weight: 700; }
  .mention-opt .full { font-size: 0.72rem; color: var(--ink-dim); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
</style>
