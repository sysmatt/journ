// Thin fetch() wrappers for every endpoint in docs/spec/payload-shapes.md.
// No business logic here (no reduce, no caching) — that's sync.js's job.
// Auth header names match payload-shapes.md § Auth headers exactly.

export class ApiError extends Error {
  constructor(status, body) {
    super(body?.message || `Request failed (${status})`);
    this.status = status;
    this.code = body?.error;
  }
}

/**
 * @param {boolean} isForm - body is FormData; don't force a JSON content-type (the browser sets its own multipart boundary).
 * @param {boolean} streamResponse - return the raw Response for the caller to read (fragment/attachment GETs); otherwise parse JSON and throw ApiError on non-2xx.
 */
async function request(baseUrl, path, { method = 'GET', headers = {}, body, isForm = false, streamResponse = false } = {}) {
  const finalHeaders = { ...headers };
  let finalBody = body;
  if (body !== undefined && !isForm) {
    finalHeaders['Content-Type'] = 'application/json';
    finalBody = JSON.stringify(body);
  }

  const res = await fetch(`${baseUrl}${path}`, { method, headers: finalHeaders, body: finalBody });

  if (streamResponse) {
    if (!res.ok) {
      const data = await res.json().catch(() => null);
      throw new ApiError(res.status, data);
    }
    return res;
  }

  const text = await res.text();
  const data = text ? JSON.parse(text) : null;
  if (!res.ok) throw new ApiError(res.status, data);
  return data;
}

function contactHeaders(contactUuid, secret) {
  return { 'X-Journ-Contact': contactUuid, 'X-Journ-Secret': secret };
}

/** Install-wide, not journal-scoped — see docs/spec/payload-shapes.md § GET /tags. */
export function getTags(baseUrl) {
  return request(baseUrl, '/tags');
}

// ---- bootstrap -----------------------------------------------------------

export function createJournal(baseUrl, bootstrapSecret, body) {
  return request(baseUrl, '/journal', {
    method: 'POST',
    headers: { 'X-Journ-Bootstrap-Secret': bootstrapSecret },
    body,
  });
}

// ---- sync: list / get / put / freshness -----------------------------------

export function listJournalRoot(baseUrl, journalUuid) {
  return request(baseUrl, `/journal/${journalUuid}/list`);
}

export function listEvent(baseUrl, journalUuid, eventUuid) {
  return request(baseUrl, `/journal/${journalUuid}/events/${eventUuid}/list`);
}

export async function getFragment(baseUrl, journalUuid, filename) {
  const res = await request(baseUrl, `/journal/${journalUuid}/${filename}`, { streamResponse: true });
  return res.json();
}

export async function getEventFragment(baseUrl, journalUuid, eventUuid, filename) {
  const res = await request(baseUrl, `/journal/${journalUuid}/events/${eventUuid}/${filename}`, { streamResponse: true });
  return res.json();
}

export function putFragment(baseUrl, journalUuid, filename, contactUuid, secret, content) {
  return request(baseUrl, `/journal/${journalUuid}/${filename}`, {
    method: 'PUT',
    headers: contactHeaders(contactUuid, secret),
    body: content,
  });
}

export function putEventFragment(baseUrl, journalUuid, eventUuid, filename, contactUuid, secret, content) {
  return request(baseUrl, `/journal/${journalUuid}/events/${eventUuid}/${filename}`, {
    method: 'PUT',
    headers: contactHeaders(contactUuid, secret),
    body: content,
  });
}

/** Combined reachability + freshness check — see docs/spec/api-and-sync.md § Sync trigger mechanism. Throws (network error) is how the caller detects "offline." */
export function getFreshness(baseUrl, journalUuid) {
  return request(baseUrl, `/journal/${journalUuid}/freshness`);
}

// ---- events ----------------------------------------------------------

export function archiveEvent(baseUrl, journalUuid, eventUuid, contactUuid, secret) {
  return request(baseUrl, `/journal/${journalUuid}/events/${eventUuid}/archive`, {
    method: 'POST',
    headers: contactHeaders(contactUuid, secret),
    body: {},
  });
}

// ---- attachments -------------------------------------------------------

export function uploadAttachment(baseUrl, journalUuid, eventUuid, contactUuid, secret, file) {
  const form = new FormData();
  form.append('file', file, file.name);
  return request(baseUrl, `/journal/${journalUuid}/events/${eventUuid}/attachments`, {
    method: 'POST',
    headers: contactHeaders(contactUuid, secret),
    body: form,
    isForm: true,
  });
}

export function attachmentUrl(baseUrl, journalUuid, eventUuid, storageFilename) {
  return `${baseUrl}/journal/${journalUuid}/events/${eventUuid}/attachments/${storageFilename}`;
}

// ---- contacts ----------------------------------------------------------

export function inviteContact(baseUrl, journalUuid, targetContactUuid, contactUuid, secret, plaintextSecret) {
  return request(baseUrl, `/journal/${journalUuid}/contacts/${targetContactUuid}/invite`, {
    method: 'POST',
    headers: contactHeaders(contactUuid, secret),
    body: { secret: plaintextSecret },
  });
}

export function bulkContacts(baseUrl, journalUuid, contactUuid, secret, emails, sendInvites) {
  return request(baseUrl, `/journal/${journalUuid}/contacts/bulk`, {
    method: 'POST',
    headers: contactHeaders(contactUuid, secret),
    body: { emails, send_invites: sendInvites },
  });
}

// ---- break-glass recovery ------------------------------------------------

export function recover(baseUrl, journalUuid, recoverySecret, body) {
  return request(baseUrl, `/journal/${journalUuid}/recover`, {
    method: 'POST',
    headers: { 'X-Journ-Recovery-Secret': recoverySecret },
    body,
  });
}
