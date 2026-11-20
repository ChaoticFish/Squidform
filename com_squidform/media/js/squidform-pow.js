/**
 * SquidForm proof-of-work check (0.6.0). Runs entirely in the browser; no
 * outside calls.
 *
 * The page carries a signed challenge: SHA-256(salt + n) for an unknown n
 * between 0 and m. This finds n (on average m/2 tries, about a second at the
 * default) and puts the answer in the hidden sf_captcha input. It starts
 * when the page loads; if someone presses Submit before it's done, the
 * submit waits and then continues by itself. Back never waits.
 *
 * SHA-256 is computed here rather than with crypto.subtle, which browsers
 * only offer on HTTPS: intranet sites are often plain HTTP.
 */
(() => {
  'use strict';

  const K = new Uint32Array([
    0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
    0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
    0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
    0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
    0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
    0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
    0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
    0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2,
  ]);
  const W = new Uint32Array(64);

  /** SHA-256 of an ASCII string, as lowercase hex. */
  function sha256(str) {
    const len = str.length;
    const blocks = ((len + 9 + 63) >> 6);
    const buf = new Uint8Array(blocks * 64);
    for (let i = 0; i < len; i++) buf[i] = str.charCodeAt(i) & 0xff;
    buf[len] = 0x80;
    const bits = len * 8;
    buf[buf.length - 4] = (bits >>> 24) & 0xff;
    buf[buf.length - 3] = (bits >>> 16) & 0xff;
    buf[buf.length - 2] = (bits >>> 8) & 0xff;
    buf[buf.length - 1] = bits & 0xff;

    let h0 = 0x6a09e667, h1 = 0xbb67ae85, h2 = 0x3c6ef372, h3 = 0xa54ff53a;
    let h4 = 0x510e527f, h5 = 0x9b05688c, h6 = 0x1f83d9ab, h7 = 0x5be0cd19;

    for (let o = 0; o < buf.length; o += 64) {
      for (let t = 0; t < 16; t++) {
        W[t] = (buf[o + t * 4] << 24) | (buf[o + t * 4 + 1] << 16) | (buf[o + t * 4 + 2] << 8) | buf[o + t * 4 + 3];
      }
      for (let t = 16; t < 64; t++) {
        const x = W[t - 15], y = W[t - 2];
        const s0 = ((x >>> 7) | (x << 25)) ^ ((x >>> 18) | (x << 14)) ^ (x >>> 3);
        const s1 = ((y >>> 17) | (y << 15)) ^ ((y >>> 19) | (y << 13)) ^ (y >>> 10);
        W[t] = (W[t - 16] + s0 + W[t - 7] + s1) | 0;
      }
      let a = h0, b = h1, c = h2, d = h3, e = h4, f = h5, g = h6, h = h7;
      for (let t = 0; t < 64; t++) {
        const S1 = ((e >>> 6) | (e << 26)) ^ ((e >>> 11) | (e << 21)) ^ ((e >>> 25) | (e << 7));
        const ch = (e & f) ^ (~e & g);
        const t1 = (h + S1 + ch + K[t] + W[t]) | 0;
        const S0 = ((a >>> 2) | (a << 30)) ^ ((a >>> 13) | (a << 19)) ^ ((a >>> 22) | (a << 10));
        const mj = (a & b) ^ (a & c) ^ (b & c);
        const t2 = (S0 + mj) | 0;
        h = g; g = f; f = e; e = (d + t1) | 0; d = c; c = b; b = a; a = (t1 + t2) | 0;
      }
      h0 = (h0 + a) | 0; h1 = (h1 + b) | 0; h2 = (h2 + c) | 0; h3 = (h3 + d) | 0;
      h4 = (h4 + e) | 0; h5 = (h5 + f) | 0; h6 = (h6 + g) | 0; h7 = (h7 + h) | 0;
    }

    return [h0, h1, h2, h3, h4, h5, h6, h7].map((v) => (v >>> 0).toString(16).padStart(8, '0')).join('');
  }

  function solve(box) {
    const form = box.closest('form');
    const input = box.querySelector('input[name="sf_captcha"]');
    const status = box.querySelector('.sf-pow-status');
    let data;
    try { data = JSON.parse(box.dataset.sfPow); } catch (e) { return; }

    let n = 0;
    let done = false;
    let pending = null; // the button pressed before the work finished

    const step = () => {
      const stop = Math.min(data.m, n + 2500);
      for (; n <= stop; n++) {
        if (sha256(data.s + n) === data.c) {
          input.value = btoa(JSON.stringify({ c: data.c, s: data.s, n, g: data.g }));
          done = true;
          status.textContent = box.dataset.sfPowDone || '';
          if (pending) {
            const b = pending; pending = null;
            if (form.requestSubmit) form.requestSubmit(b); else b.click();
          }
          return;
        }
      }
      if (n > data.m) return; // not found: the server will say so
      window.setTimeout(step, 0);
    };

    // Before SquidForm's own submit handler: wait for the answer on "next".
    form.addEventListener('submit', (e) => {
      const b = e.submitter;
      if (done || (b && b.value === 'back')) return;
      e.preventDefault();
      e.stopImmediatePropagation();
      pending = b || form.querySelector('button[name="sf_nav"][value="next"]');
      status.textContent = box.dataset.sfPowWait || '';
    }, true);

    window.setTimeout(step, 0);
  }

  const boot = () => document.querySelectorAll('[data-sf-pow]').forEach(solve);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();

  // For tests: the same SHA-256 the solver uses.
  window.SquidFormPow = { sha256 };
})();
