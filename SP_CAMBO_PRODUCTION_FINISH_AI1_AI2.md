# SP Cambo — Production Completion Master Prompt
## Two-Terminal AI1 + AI2 Parallel Workflow

**Project root:** `C:\Users\Rg Gear\Desktop\SP Cambo`  
**Goal:** Finish SP Cambo as a production-ready AI model resale / API gateway / Playground / Telegram storefront platform.

> Important: "100% ready" means every defined release gate below is demonstrated and documented. Do not claim readiness because builds pass alone. Keep working until all critical workflows pass real end-to-end acceptance with production-like configuration.

---

# 1. Current Product Direction

SP Cambo is a customer-facing AI access platform with:

- Laravel control plane / billing backend
- Nuxt customer + admin frontend
- SP Cambo gateway in front of upstream providers / OmniRoute
- MySQL persistence
- Provider connection revisions and model discovery
- Private upstream models + public customer-facing aliases
- Model pricing and package pricing
- Token / credit entitlements
- API keys with expiry, scope, quota, status and usage metering
- Customer Playground
- Public no-login key checker
- Usage logs and live request state
- Redeem / promo codes
- Bakong KHQR payment flow
- Telegram Store bot
- Telegram announcements for new models / packages / stock-like availability
- Khmer + English customer experience
- Claude Code / Anthropic-compatible and OpenAI/Codex-compatible API usage

Preserve the latest working behavior. Do not regress the Provider → Discover → Public Alias → Pricing → Package → Purchase → Key → Usage flow.

---

# 2. Final Product Experience We Want

## Customer website

The customer should be able to:

1. Register/login or use supported public/no-login tools.
2. Browse sellable packages and models.
3. See clear price, quota, validity, supported API protocols and model capability.
4. Buy a package with KHQR.
5. Receive access immediately after verified payment.
6. Reuse an existing API key or create a new key when the product allows it.
7. Copy ready-to-use setup snippets for:
   - Claude Code
   - OpenAI/Codex-compatible clients
   - cURL
8. Use the Playground with:
   - model selector
   - new chat
   - system prompt
   - temperature
   - max output
   - starter prompts
   - free daily quota
   - redeem balance
   - purchased token balance
   - credit balance
   - live request state
9. See usage in near real time:
   - request status
   - provider
   - public model
   - upstream/private model
   - endpoint
   - input tokens
   - output tokens
   - reasoning/cache tokens when available
   - reserved units while running
   - final metered units
   - duration
   - charge
   - error status
10. Paste an API key into the public Key Checker without login and see:
    - status
    - expiry
    - remaining balances
    - model scopes
    - recent requests
    - currently running requests
    - live duration
11. Redeem codes and continue using the Playground.
12. View orders, payment state, entitlements and API keys.
13. Use a polished responsive UI on desktop and mobile.
14. Use Khmer or English consistently.

## Telegram Store

The Telegram bot should be a complete storefront, not a `/link`-first flow.

Customer flow:

`/start → Home → Store → Product → Buy → KHQR → verified payment → entitlement/API key → setup instructions → usage checker`

Persistent Telegram menu should include:

- Store
- Balance
- Models
- Orders
- Updates
- Language

The bot should:

- list current packages
- show package details
- allow quantity only where quantity makes sense
- create payment orders
- send KHQR
- reconcile payment automatically
- deliver API access automatically
- show recent orders
- show customer balance
- show supported models
- switch Khmer/English
- allow opt-in/opt-out announcements
- send new model/package/update announcements
- place a Buy button under announcements when a valid package exists
- never expose secret admin credentials
- never require `/link` for normal storefront purchases

---

# 3. Admin / Control Plane Must Be Complete

Admin should have full safe control over:

## Providers

- create/edit/disable provider
- revision history
- probe
- READY state
- set active revision
- update revision status
- edit/delete safe unused revisions
- discover upstream models
- Select all / Unselect all
- import selected models
- create missing public aliases
- provider health status
- timeout and limits
- credential masking
- audit history
- safe provider deletion with dependency explanation

## Models

Private model controls:

- upstream/internal ID
- display name
- capabilities
- context/output limits
- RPM/TPM/concurrency
- resale verification
- enable/disable

Public alias controls:

- customer-facing alias
- display name
- protocol support
- capabilities
- visibility
- enabled state
- route mapping
- pricing
- publication readiness
- block reason when not publishable

## Pricing

- customer input/output/cache/reasoning rates
- upstream costs
- cost verification timestamp
- margin calculation
- package worst-case margin
- explicit profitability override with reason
- warning when selling below floor

## Packages

- name/slug/description
- price
- validity
- token quota / credit quota
- allowed models
- customer visibility
- enabled/on-sale state
- optional API-key fulfillment
- package copy
- archive/disable
- margin enforcement
- package publication validation

## Keys / Entitlements

- issue/revoke/disable
- expiry
- model scope
- token and credit balance
- active reservation accounting
- key reuse/new-key choice after purchase
- usage history
- last used
- masked display
- one-time reveal for new secret

## Redeem codes

- create/edit/disable
- expiry
- max redemptions
- per-user limit
- token/credit grant
- model scope
- audit trail

## Telegram Store Admin

- bot configuration state
- customer count
- subscriber count
- announcement queue
- delivery state
- sent/failed/skipped counts
- manual announcement composer
- attach package to announcement
- Buy button
- resend failed announcements safely

---

# 4. Production-Critical Engineering Requirements

These are P0 and must be correct before release.

## Billing and metering

- atomic reservations
- idempotent settlement
- no double charge
- no lost charge after successful upstream use
- bounded reservation
- timeout handling
- client disconnect handling
- failed-settlement reconciliation
- exact final usage when provider reports it
- safe estimation when final usage is unavailable
- token quota and credit balance never confused
- expiry respected at request time
- model scope respected
- disabled/revoked key rejected immediately

## Payments

- order creation idempotent
- payment verification server-side
- never trust client "paid" state
- duplicate webhook/reconciliation safe
- one payment cannot fulfill twice
- fulfillment transactionally safe
- failed fulfillment recoverable
- audit trail preserved
- payment/order/entitlement state machine documented

## Gateway

- never forward customer SP Cambo API key upstream
- map public alias → private model
- use active READY provider revision
- sanitize upstream errors
- enforce body/output/RPM/TPM/concurrency limits
- handle streaming safely
- support required Anthropic/OpenAI/Codex endpoints
- observability callback must never block inference
- timeout must be bounded by gateway safety ceiling

## Security

- secrets only in environment / secure storage
- no secrets in git
- no secrets in frontend bundles
- credential masking
- CSRF/session/auth review
- authorization/RBAC on every admin endpoint
- rate limit public checker
- rate limit auth and purchase endpoints
- validate Telegram webhook secret
- validate payment callbacks
- sanitize user-controlled text
- no SQL injection
- no mass-assignment privilege escalation
- safe CORS configuration
- secure production cookies
- HTTPS-ready
- password reset/email verification behavior reviewed
- logs must not print full API keys/payment secrets

## Data integrity

- migrations must be rerunnable where appropriate
- MySQL identifier names below limits
- foreign keys/indexes intentional
- backup/restore documented
- no destructive cascade that deletes billing history
- provider/model deletion protects historical usage

---

# 5. UX / Design Completion

Do not copy another service's UI. Use the screenshots only as workflow inspiration.

Create a coherent **SP Cambo design system**:

- one spacing scale
- one radius scale
- consistent cards
- consistent modal widths
- consistent status badges
- consistent table/list empty states
- consistent loading skeletons
- consistent error states
- responsive mobile layouts
- keyboard accessibility
- visible focus states
- usable contrast
- compact admin density
- cleaner customer pages
- bilingual Khmer/English labels
- no broken/duplicate actions
- no dead buttons
- no hidden critical controls

Recommended customer navigation:

- Overview
- Playground
- Models
- Buy / Plans
- API Keys
- Usage
- Orders
- Redeem
- Setup Guide
- Support

Recommended admin navigation:

- Overview
- Providers
- Models / Pricing
- Packages
- Orders / Payments
- Customers
- API Keys
- Entitlements
- Redeem Codes
- Telegram Store
- Usage / Metering
- Audit Log
- Settings
- System Health

---

# 6. Operational Readiness

Before declaring production-ready, add/verify:

- `/health` endpoints for backend/gateway
- dependency health:
  - MySQL
  - gateway
  - upstream provider
  - scheduler
  - Telegram
  - KHQR service
- queue/scheduler status
- structured logs
- request correlation ID
- admin system-health page
- failed payment reconciliation queue
- failed Telegram delivery retry
- failed settlement reconciliation
- database backup instructions
- restore test instructions
- environment template
- production environment checklist
- startup scripts
- graceful shutdown
- version/release identifier
- changelog
- rollback instructions

Docker is optional for local Windows development. Production deployment may use Docker or native services, but both modes must be documented clearly.

---

# 7. Documentation Required for Release

Create/update:

- `README.md`
- `docs/PRODUCTION_DEPLOYMENT.md`
- `docs/PRODUCTION_CHECKLIST.md`
- `docs/ARCHITECTURE.md`
- `docs/BILLING_AND_METERING.md`
- `docs/PAYMENT_FLOW.md`
- `docs/TELEGRAM_STOREFRONT.md`
- `docs/PLAYGROUND.md`
- `docs/API_COMPATIBILITY.md`
- `docs/ADMIN_OPERATIONS.md`
- `docs/BACKUP_RESTORE.md`
- `docs/TROUBLESHOOTING.md`
- `docs/SECURITY.md`
- `docs/RELEASE_NOTES.md`

Never document or commit real secrets.

---

# 8. AI1 + AI2 Parallel Collaboration Protocol

We will run two terminals against the same working repository.

## Roles

**AI1 = IMPLEMENTER**

AI1 owns code changes.

AI1 may:

- edit production code
- edit tests
- create migrations
- improve UI
- add features
- run tests/builds
- commit checkpoints

AI1 should NOT edit AI2's audit report except to mark findings resolved.

**AI2 = AUDITOR / RELEASE GATEKEEPER**

AI2 is primarily read-only for production code.

AI2 may:

- inspect current source
- run tests/builds
- review diffs
- find regressions
- test UX/API behavior
- security-review changes
- write audit findings
- propose exact fixes

AI2 should NOT silently rewrite large production areas while AI1 is actively editing them. If AI2 finds a defect, write it to the shared audit queue first.

## Shared coordination files

Maintain these files:

`docs/ai/AI1_STATUS.md`

AI1 updates after each meaningful implementation batch:

- current task
- files changed
- migrations added
- commands run
- results
- known remaining issue
- checkpoint commit hash

`docs/ai/AI2_AUDIT.md`

AI2 writes:

- severity: P0 / P1 / P2 / P3
- finding
- file/line
- reproduction
- expected behavior
- smallest safe fix
- verification command
- status: OPEN / FIXED / VERIFIED

`docs/ai/PARALLEL_BOARD.md`

Single source of truth:

| ID | Priority | Area | Owner | State | Verification |
|---|---|---|---|---|---|
| P0-001 | P0 | Billing | AI1 | DOING | backend test |
| P0-002 | P0 | Payment | AI2 | AUDIT | E2E |
| ... | ... | ... | ... | ... | ... |

## Handshake

AI1 loop:

1. Read `AI2_AUDIT.md`.
2. Fix highest-priority OPEN finding.
3. Update code/tests.
4. Run focused verification.
5. Commit a checkpoint.
6. Update `AI1_STATUS.md`.
7. Mark finding `FIXED`, not `VERIFIED`.
8. Continue to next task.

AI2 loop:

1. Read `AI1_STATUS.md`.
2. Inspect latest checkpoint/diff.
3. Re-run affected verification.
4. If correct, mark `VERIFIED`.
5. If not, keep/reopen finding with reproduction.
6. Audit another product area.
7. Continue until no P0/P1 findings remain.

This allows both terminals to "communicate" through repository files while running at the same time.

---

# 9. Concurrency Safety Rules

Because both AIs use the same working tree:

- AI2 should avoid editing production source while AI1 is editing.
- AI2 can freely edit only `docs/ai/AI2_AUDIT.md` and audit-only notes.
- AI1 should avoid editing `docs/ai/AI2_AUDIT.md` except status markers.
- AI1 should make small checkpoint commits frequently.
- AI2 should review committed checkpoints where possible.
- Do not run destructive migrations while the other terminal is using the DB.
- Do not run `git reset --hard`, `git clean -fdx`, force push or destructive database resets without explicit user approval.
- Do not delete tests to make acceptance green.
- Do not lower validation standards to make the release appear complete.
- A stale test may be updated only when the intended product behavior has clearly changed and the new behavior is verified.

---

# 10. Work Priority

## P0 — Must work before production

1. Provider active READY routing
2. Public alias route readiness
3. Package publication
4. KHQR payment verification
5. One-time fulfillment
6. API-key issuance
7. Gateway authentication
8. Metering/reservation/settlement
9. Key expiry/scope/quota
10. Telegram purchase → payment → key delivery
11. Scheduler/reconciliation
12. No-login key checker security
13. Production auth/RBAC
14. Secret handling
15. Migrations on clean DB and upgraded DB

## P1 — Required for professional release

1. Playground full flow
2. Live usage/status
3. usage filtering/details
4. Telegram announcements
5. Telegram language
6. admin Telegram control
7. redeem codes
8. customer orders
9. customer entitlements
10. polished empty/loading/error states
11. responsive layouts
12. system health
13. audit log
14. backup/restore
15. deploy docs

## P2 — Polish

1. better dashboard metrics
2. search/filter/sort
3. pagination
4. CSV export where useful
5. notification center
6. theme refinement
7. keyboard UX
8. accessibility
9. richer setup guide
10. release notes

---

# 11. Real End-to-End Acceptance

Tests are necessary but not sufficient.

The release is not complete until these real flows pass:

## Provider + Model

- create provider
- create/probe revision
- revision becomes READY
- activate revision
- discover models
- Select all / Unselect all
- import
- create missing public aliases
- verify resale where permitted
- set protocols
- set pricing
- publish model
- model appears in package picker

## Package

- create package
- select model
- configure price/quota/validity
- validate margin
- publish
- package appears to website + Telegram Store

## Website purchase

- create order
- generate KHQR
- payment verified
- order fulfilled exactly once
- entitlement created
- API key issued/reused as configured
- setup snippets displayed
- order visible

## Telegram purchase

- `/start`
- Store
- choose package
- Buy
- KHQR
- payment verified
- API key delivered
- Claude Code/OpenAI setup delivered
- order appears
- balance appears

## Gateway/API

Use delivered key:

- OpenAI-compatible request
- Anthropic-compatible request
- streaming request
- invalid key rejection
- expired key rejection
- model-not-allowed rejection
- insufficient quota rejection

Verify upstream never sees the customer's SP Cambo key.

## Usage

During an active request:

- request appears as RESERVED/CONNECTING/STREAMING
- live duration increments
- provider/model visible
- reservation visible

After completion:

- SETTLED
- input/output usage
- duration
- charge
- quota decreased correctly
- key checker shows same result

## Playground

- daily free works
- quota decreases
- exhausted state blocks correctly
- redeem code adds balance
- chat resumes
- purchased balance works
- model switching obeys admin configuration

## Telegram announcements

- new package queues announcement
- subscribed user receives it
- Buy button works
- mute updates works
- Khmer/English works
- retry failed delivery works

---

# 12. Automated Release Gates

Run focused tests during development.

Before release run all applicable gates:

```powershell
cd "C:\Users\Rg Gear\Desktop\SP Cambo"

.\scripts\FINAL_ACCEPTANCE.ps1 -ContinueOnFailure -FixLint -SkipDocker
```

Also verify manually:

```powershell
cd backend
php artisan migrate:status
php artisan schedule:list

cd ..\frontend
npm run lint
npm run typecheck
npm test
npm run build

cd ..\gateway
pnpm test
pnpm run build
```

If PHP full-suite crashes because of a Windows native runtime issue, isolate suites/processes. Do not hide real assertion failures.

---

# 13. Definition of Done

SP Cambo may be labeled **Release Candidate** only when:

- no open P0 finding
- no open P1 finding that affects money, access, security, routing, fulfillment or data integrity
- backend focused + full applicable tests pass
- frontend lint/typecheck/tests/build pass
- gateway tests/build pass
- migrations pass on:
  - a clean database
  - the current upgraded database
- real provider routing works
- real purchase/fulfillment works
- real Telegram purchase works
- real API usage works
- real usage metering matches
- no-login checker works safely
- scheduler/reconciliation works
- backup/restore procedure exists
- deployment documentation exists
- production secrets are configured outside git
- release checklist is signed off by AI2

SP Cambo may be labeled **Production Ready** only after the Release Candidate is deployed to a production-like/staging environment and the real end-to-end acceptance above passes there.

---

# 14. AI1 Full Prompt — IMPLEMENTER

You are AI1, the implementation owner for SP Cambo.

Read this entire file first. Then read:

- `docs/ai/PARALLEL_BOARD.md`
- `docs/ai/AI2_AUDIT.md`
- `docs/ai/AI1_STATUS.md`
- current git status/diff
- relevant architecture/API docs

Your mission is to finish SP Cambo to the Definition of Done in this file.

Rules:

- Continue working autonomously; do not stop after one fix.
- Always take the highest-severity OPEN AI2 finding first.
- Preserve working features.
- Fix root causes rather than masking failures.
- Add/update tests for each meaningful fix.
- Keep migrations safe for existing production-like data.
- Never delete tests merely to get green.
- Never commit secrets.
- Never force-reset user work.
- Keep UI coherent with SP Cambo's own design.
- Do not copy competitor UIs.
- Update `docs/ai/AI1_STATUS.md` after every meaningful batch.
- Update `docs/ai/PARALLEL_BOARD.md`.
- Commit small checkpoints so AI2 can audit stable snapshots.
- Mark findings FIXED, but only AI2 may mark them VERIFIED.
- When one task passes, immediately continue to the next open P0/P1 item.
- Do not say "done" until every release gate and real acceptance item is satisfied or a genuine external credential/service blocker is documented with exact reproduction and next action.

Start by auditing the current repository state and converting the remaining work into explicit P0/P1 tasks on the parallel board. Then implement continuously.

---

# 15. AI2 Full Prompt — AUDITOR / RELEASE GATEKEEPER

You are AI2, the independent auditor and release gatekeeper for SP Cambo.

Read this entire file first. Then read:

- `docs/ai/PARALLEL_BOARD.md`
- `docs/ai/AI1_STATUS.md`
- `docs/ai/AI2_AUDIT.md`
- current git log/diff
- relevant architecture/API docs

Your mission is not to praise the implementation. Your mission is to find anything that could prevent safe production release.

Audit continuously while AI1 implements.

Rules:

- Primarily read-only for production code.
- Write findings to `docs/ai/AI2_AUDIT.md`.
- Assign P0/P1/P2/P3 severity.
- Include exact reproduction, evidence, expected behavior, smallest safe fix and verification.
- Re-test every AI1 FIXED finding.
- Only mark VERIFIED after independent proof.
- Test money flow, idempotency, entitlement, key scope, provider routing, streaming, timeout, scheduler, Telegram, Playground, public checker and security boundaries.
- Look for stale tests that conflict with the intended product; distinguish stale tests from genuine regressions.
- Do not lower standards simply to get green.
- Do not edit large production areas while AI1 is actively editing them.
- Keep auditing after a green build.
- Perform real end-to-end acceptance once credentials/services are available.
- Do not approve production while any P0 is open.
- Do not approve production while a P1 affects billing, payments, access, security, routing, fulfillment or data integrity.
- At the end, produce a release decision in `docs/ai/AI2_AUDIT.md`:
  - BLOCKED
  - RELEASE CANDIDATE
  - PRODUCTION READY
  with evidence.

Start by reviewing AI1's latest checkpoint and independently finding the next highest-risk defect.

---

# 16. Short Terminal Prompts

## Terminal 1 — AI1

```text
Read SP_CAMBO_PRODUCTION_FINISH_AI1_AI2.md completely. You are AI1 IMPLEMENTER. Work continuously on the highest-priority OPEN P0/P1 items, implement fixes/features, add tests, checkpoint commits, and update docs/ai/AI1_STATUS.md + PARALLEL_BOARD.md. Read AI2_AUDIT.md before every new batch. Do not stop until the Definition of Done is reached or an external credential blocker is precisely documented.
```

## Terminal 2 — AI2

```text
Read SP_CAMBO_PRODUCTION_FINISH_AI1_AI2.md completely. You are AI2 AUDITOR/RELEASE GATEKEEPER. Audit AI1's latest checkpoints continuously, run independent tests and real acceptance, write exact P0/P1/P2 findings to docs/ai/AI2_AUDIT.md, and verify AI1 fixes. Do not approve or stop while release-blocking issues remain. Only AI2 may mark findings VERIFIED and declare Release Candidate/Production Ready.
```

---

# 17. First Recommended Tasks for the Two Terminals

AI1 should begin with:

- ensure R6.1 migration succeeds on both partial-upgrade and clean DB
- finish Playground UX/live usage
- finish Telegram Store + announcements
- add admin system-health/operational controls
- tighten payment + fulfillment idempotency
- improve production deployment documentation
- resolve every P0/P1 found by AI2

AI2 should immediately audit:

- migration upgrade paths
- package purchase payment idempotency
- duplicate Telegram/payment delivery
- gateway reservation/settlement
- API-key scope/expiry
- public no-login key checker
- provider/public alias deletion safety
- Playground balance ordering
- announcement subscription/privacy behavior
- real API-key leakage to upstream
- production secret/config exposure

Keep both terminals running until the release criteria are genuinely satisfied.
