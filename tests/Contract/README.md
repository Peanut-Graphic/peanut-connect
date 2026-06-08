# Contract tests (Net 7) — BLOCKED

Contract tests pin the shape of a `/wp-json/peanut-connect/v1/*` response so the
seam between this plugin and its consumers (Hub, the React frontend) can't drift
silently.

**Status: BLOCKED — no WordPress test harness boots in this repo.**

`tests/bootstrap.php` looks for `WP_TESTS_DIR` (the `wp-phpunit` / WP test suite).
When it's absent — which it is here, both locally and in CI — the bootstrap falls
back to the lightweight function mocks in `tests/mocks/wordpress-mocks.php`. Those
mocks are enough for pure-unit and property tests, but they do **not** stand up the
REST API, route registration, `WP_REST_Request`/`WP_REST_Response`, or the DB, so a
real request/response contract cannot be exercised.

## What unblocks it

Wire a real WP test environment (e.g. `wp-env` — the repo already has `.wp-env.json`
— or `bin/install-wp-tests.sh` setting `WP_TESTS_DIR`), then add a test here that
boots the plugin, dispatches a request to a `peanut-connect/v1` route via
`rest_do_request()`, and asserts the response JSON shape (status, keys, types).

The `Contract` PHPUnit suite is already wired in `phpunit.xml` and runs in CI
(non-blocking) so it lights up automatically the moment a test lands here.
