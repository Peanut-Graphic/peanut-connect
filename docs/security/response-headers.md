# Optional response headers

This feature is off by default. An operator can enable it with `wp option update peanut_connect_security_headers 1`, or disable it with the value `0`. It is also reported as `security_headers` by the existing security settings reader. The update does not enable it on existing installations.

When enabled, frontend page responses add `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy: geolocation=(), camera=(), microphone=(), payment=(), usb=()` and `X-Content-Type-Options: nosniff`. The Permissions Policy deliberately denies those browser features; sites using them should supply a suitable policy through the `peanut_connect_security_headers` filter or leave this option disabled. Admin, login, AJAX and REST handling remain outside this frontend hook.

The filter receives the default name/value map. It can override values, set a value to an empty string to omit that header, or return an empty array to omit the policy. A non-array result is ignored. Header names must be HTTP tokens and values must be nonempty strings without CR, LF or NUL; invalid entries are skipped. Case-insensitive duplicates are emitted only once. A header already queued in PHP is preserved. PHP cannot inspect headers added later by an edge proxy, so edge-managed policies must be coordinated there.

Validation covers standalone policy/default-setting regressions, real WordPress hook registration contracts, and a real WordPress/PHP HTTP fixture: default off, enabled frontend, unchanged admin/login/REST headers, an existing PHP policy, custom/empty/non-array filters and malformed/duplicate filter entries. No production policy option was changed during qualification.
