# AI2 Audit / Release Gate

## Release decision
**BLOCKED — confirmed P1 streaming timeout blocker; full production acceptance remains unproven**

## Findings

### P1-001 — Streaming responses bypass the upstream timeout after headers arrive
- Severity: P1
- Status: FIXED
- Area: Inference gateway / bounded upstream timeouts / streaming recovery
- Evidence: `gateway/src/app.ts:65-75` creates the only upstream timeout, and `gateway/src/app.ts:94-97` clears it and removes the request-disconnect listeners as soon as `fetch()` returns response headers. `gateway/src/app.ts:136-175` then reads the streaming body without another deadline and no longer has the client-disconnect listener wired to the upstream `AbortController`.
- Reproduction: With `upstreamTimeoutMs` set to 100 ms, a mocked upstream returned HTTP 200 headers and one SSE chunk but never closed its `ReadableStream`. The gateway request remained unresolved after 350 ms; no `settle` or `reconcile` call occurred. The existing timeout test only covers a fetch that never returns headers and therefore does not cover this case.
- Expected behavior: The configured, operator-controlled timeout must bound the complete upstream operation, including response-body reads and streaming. A stalled stream or disconnected downstream client must terminate the upstream operation, preserve the ambiguous reservation for reconciliation, and return or close safely without leaving an indefinitely active reservation.
- Smallest safe fix: Keep an operation deadline alive through JSON-body and streaming consumption, and retain a request/response disconnect handler through stream completion. On deadline or disconnect, cancel the reader/upstream body, destroy the downstream response as appropriate, and invoke reconciliation exactly once. Ensure the timeout is cleared only after body consumption and terminal billing handling finish.
- Verification: AI1 implementation check: `cd gateway && ./node_modules/.bin/vitest run` passed 31/31; `./node_modules/.bin/tsc --noEmit` passed. Added headers-then-stalled-body and client-disconnect-after-headers tests asserting bounded termination, upstream reader cancellation, exactly one reconciliation, and no release or settlement. Awaiting independent AI2 verification.

### P1-002 — Telegram partial delivery revokes the already-sent API key
- Severity: P1
- Status: OPEN
- Area: Telegram storefront / exactly-once fulfillment / API-key delivery recovery
- Evidence: `backend/app/Services/TelegramCommerceService.php:482-513` sends the plaintext API key in one Telegram message and the setup instructions in a second message. The catch block at `:514-520` revokes the key and resets the claim whenever either send fails, even when the first message already succeeded.
- Reproduction: Added a focused sqlite feature test with a fake Telegram client that succeeds on the first `sendMessage` and throws on the second. The first plaintext secret was recorded by the fake client; after `reconcile()` the corresponding API key was `REVOKED`, the fulfillment claim was reset to `PENDING`, and the purchase remained undelivered. A retry then created and sent a different secret. Command: `cd backend && ./vendor/bin/phpunit tests/Feature/Feature/Api/V1/TelegramDeliveryAuditTest.php --testdox` — 2 tests and 17 assertions passed, including this reproduction.
- Expected behavior: Once a plaintext secret has been delivered, the system must not revoke that key or issue a second key merely because a follow-up setup message failed. Delivery must be retryable without presenting the customer with an unusable key or duplicate entitlements/credentials; the already-sent key should remain active and the delivery state should record which message remains pending.
- Smallest safe fix: Make Telegram delivery resumable/idempotent per message (or send one atomic message), persist delivery step state, and only finalize the purchase after all required messages succeed. If a later message fails after the secret message succeeds, retain the active key and retry the missing message using the same claim/key; never reset the claim by revoking a secret that may already be visible to the customer.
- Verification: Keep the first-send-success/second-send-failure test and add retries that assert the original key remains ACTIVE, no second key is created, the claim remains associated with that key, and eventual delivery marks the purchase DELIVERED exactly once. Exercise timeout and Telegram API error responses as well as process retry.

Use this format for every finding:

### P0-XXX — Short title
- Severity: P0 / P1 / P2 / P3
- Status: OPEN / FIXED / VERIFIED
- Area:
- Evidence:
- Reproduction:
- Expected behavior:
- Smallest safe fix:
- Verification:

AI1 may mark an implemented item FIXED. Only AI2 should mark it VERIFIED after independent retest.
