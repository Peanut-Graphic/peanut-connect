# Hub ↔ Edge protocol contract

This directory holds the **shared contract artifacts** for the seam between this
plugin (the *edge*) and the central Hub. They exist because the two sides live
in **separate repositories** but must agree byte-for-byte on a few wire formats.
Nothing else enforces that agreement — a drift on either side surfaces only as a
runtime failure (e.g. "invalid signature"), with both repos' own unit tests
still green.

## `hub-signing-vectors.json` — request-signing test vectors

The canonical HMAC signature for a Hub→edge request is:

```
canonical = METHOD "\n" ROUTE "\n" TIMESTAMP "\n" NONCE "\n" sha256(BODY)
signature = HMAC-SHA256(canonical, site_key)   // lowercase hex
```

- **Edge side:** `Peanut_Connect_Auth::compute_request_signature()` (`includes/class-connect-auth.php`).
- **Hub side:** `App\Support\PeanutConnectSigner::signature()` (peanut-hub repo).

Each vector pins a `(key, method, route, timestamp, nonce, body)` input to its
`expected_signature`. The vectors deliberately cover edge cases: empty body,
JSON body, lowercase method (must normalize to upper), and a unicode body.

### Both repos MUST run these vectors in CI

- **This repo:** `tests/Test_Signing_Vectors.php` asserts `compute_request_signature()` reproduces every `expected_signature`.
- **The Hub:** add an equivalent test that loads this same JSON and asserts `PeanutConnectSigner::signature()` reproduces every `expected_signature`. Vendor the file in, or fetch it from this repo at a pinned tag.

If you intentionally change the canonicalization, you must (1) regenerate this
fixture, (2) bump its `version`, and (3) update **both** implementations in the
same coordinated rollout — otherwise signed requests break the moment one side
ships ahead of the other. (See the deferred A8b rollout note in
`docs/audits/2026-06-11-hub-consumer-microscope-remediation.md`.)
