// Client-side secret generation/hashing — mirrors api/lib/auth.php
// exactly (same entropy, same hash shape), since "regenerate key" is
// just an ordinary client-authored contact-fragment write (see
// docs/spec/identity-and-security.md): the client generates the fresh
// plaintext, hashes it, and only ever sends the hash.

/** 192 bits of entropy, hex-encoded — matches api/lib/auth.php::journ_generate_secret(). */
export function generateSecret() {
  const bytes = new Uint8Array(24);
  crypto.getRandomValues(bytes);
  return [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('');
}

/** @returns {Promise<{algo: 'sha256', hash: string}>} */
export async function hashSecret(plaintext) {
  const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(plaintext));
  const hash = [...new Uint8Array(digest)].map((b) => b.toString(16).padStart(2, '0')).join('');
  return { algo: 'sha256', hash };
}
