# SP Cambo Parallel Board

| ID | Priority | Area | Owner | State | Verification |
|---|---|---|---|---|---|
| BOOT-001 | P0 | Release audit | AI2 | DOING | Audit current R6.1 checkpoint; P1-001 already filed |
| PG-ISO-001 | P0 | Playground billing isolation | AI1 | FIXED | Backend 36/225 isolation; FE PlaygroundPage 4/4; Vitest 41/634; lint/typecheck/build pass. Awaiting AI2 VERIFIED |
| TG-MIG-001 | P1 | Telegram migration replay safety | AI1 | FIXED | MigrationIdentifierTest 9/95. Never drops `telegram_announcement_deliveries`. Awaiting AI2 VERIFIED |
| P1-001 | P1 | Gateway streaming timeout after headers | AI1 | FIXED | Gateway Vitest 31/31 + typecheck pass; stalled stream and post-header disconnect cancel upstream and reconcile once. Awaiting AI2 VERIFIED |
| P1-002 | P1 | Telegram partial delivery revokes sent key | AI1 | DOING | Make delivery atomic or persist resumable per-message state; retain the original active key after partial send |
| TG-LINK-001 | P2 | Telegram identity-conflict wording | AI1 | TODO | Align exception text with `already linked` without weakening ownership protection |
| PG-MIG-001 | P1 | Playground credentials/settings replay | AI1 | TODO | `000048` create-if-missing; `000052` must not reset operator singleton |
| BOOT-003 | P0 | Real E2E | AI1 + AI2 | TODO | Provider → Package → Payment → Key → API → Usage → Telegram |
| DOCS-001 | P1 | Production documentation | AI1 | TODO | README.md + required ops/security docs |
| BE-SUITE-001 | P1 | Full backend suite | AI1 | BLOCKED | `php artisan test` exits 139; keep split suites |

## State meanings

- TODO
- DOING
- WAITING_FOR_AUDIT
- FIXED
- VERIFIED
- BLOCKED_EXTERNAL
- DONE

## Notes

- AI1 may mark FIXED. Only AI2 marks VERIFIED.
- Do not claim Production Ready until automated gates, live acceptance, operational requirements, and AI2 sign-off all pass.
- Do not stage pre-existing uncommitted user frontend work, root `pnpm-lock.yaml`, `.claude/`, secrets, or unapproved pnpm builds.
