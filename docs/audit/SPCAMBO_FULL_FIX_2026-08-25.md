# SP Cambo full feature fix — 2026-08-25

## What this release fixes

### Provider/model/package selling

The provider admin screens were using the removed/invalid Nuxt UI `UToggle` component. That made important booleans look missing, including commercial resale verification, alias enabled/customer-visible state and capability switches. All provider admin toggles now use `USwitch`.

A successful first probe now promotes a pending revision to READY and automatically activates it **only when the provider does not already have an active revision**. Existing active routes are never replaced automatically.

Package publication errors now explain the exact sell-readiness blocker for every selected alias, such as:

- provider disabled or missing
- no active READY connection
- private model disabled or missing
- commercial resale not verified
- public alias disabled
- public alias hidden
- public alias lifecycle status not publishable

Commercial resale verification is intentionally not auto-enabled. An operator must enable it only when SP Cambo actually has permission to resell that upstream model.

### Telegram standalone Store

The normal Telegram sales flow no longer requires `/link` or a website login.

1. Customer opens the private bot and sends `/start` or `/shop`.
2. SP Cambo automatically provisions/reuses a Telegram customer workspace.
3. The bot renders published API-access packages as inline Buy buttons.
4. Buy creates the order and returns the Bakong KHQR payload.
5. The Laravel scheduler reconciles the payment automatically; a customer can also tap **I've paid — check now**.
6. After verification, fulfillment creates a new API key and the bot sends it once together with model aliases, Claude Code PowerShell/macOS/Linux setup, public base URLs and the no-login Key Checker URL.

Legacy explicit `/link` support remains only for backwards compatibility and is no longer advertised.

Telegram webhook registration now subscribes to both `message` and `callback_query` updates.

### Public API-key checker

The checker remains public and does not require login. After the first successful POST check, the plaintext input is cleared. The page can then refresh usage every 10 seconds using the key held **only in page memory**. It is never placed in a URL, localStorage, sessionStorage or a cookie, and it disappears when the page is cleared/closed.

### Customer Playground

`/dashboard/playground` is now a chat Playground instead of a request/snippet builder.

- conversation UI with user/assistant history
- automatic protocol selection from the published alias (`Responses`, `Messages`, then `Chat Completions`)
- daily free quota first
- redeem balance second
- purchased/promotional token or credit balance after that
- buy and redeem actions when spendable funding is exhausted
- optional system prompt, temperature and output limit
- normalized assistant text plus optional raw response inspection
- no customer API key is pasted into the browser Playground

The backend accepts either the old single `prompt` input or the new bounded `messages` conversation, so older clients are not broken.

### Admin Playground routing

Admin can now configure:

- enabled state
- daily free tokens
- maximum output tokens
- free published model aliases
- default Playground alias
- whether customers may switch models
- optional server-side Playground gateway base URL override

The custom URL must point at an SP Cambo/OmniRoute-compatible gateway root. Direct upstream-provider URLs would bypass the SP Cambo routing/billing design and are not the intended configuration.

## Required update steps

From the project root on Windows:

```powershell
cd "C:\Users\Rg Gear\Desktop\SP Cambo\backend"
composer install
php artisan migrate
php artisan optimize:clear

cd "C:\Users\Rg Gear\Desktop\SP Cambo\frontend"
npm install
npm run postinstall

cd "C:\Users\Rg Gear\Desktop\SP Cambo"
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\START_ALL.ps1
```

For the Telegram Store set both bot names where applicable:

- Laravel: `TELEGRAM_BOT_TOKEN`, `TELEGRAM_BOT_USERNAME`, `TELEGRAM_WEBHOOK_SECRET`
- Nuxt: `NUXT_PUBLIC_TELEGRAM_BOT_USERNAME` (username without `@`)

Then register/update the webhook with the public HTTPS backend URL:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\SET_TELEGRAM_WEBHOOK.ps1 -PublicBackendBaseUrl "https://api.example.com"
```

Keep `php artisan schedule:work` running (the provided start scripts include the scheduler) so Telegram payment reconciliation and automatic delivery continue.

## Sell-readiness checklist

For a model to be sellable in a customer-visible package:

1. Provider is enabled.
2. A connection revision probes successfully and is active + READY.
3. Private model is enabled.
4. Commercial resale is verified by the operator where legally/contractually allowed.
5. Public alias is enabled, customer visible and has publishable status (`active` or `beta`).
6. At least one supported API protocol is enabled on the public alias.
7. Model pricing exists; upstream cost is verified or the package has the required explicit profitability override reason.
8. Package is enabled, customer visible, has at least one sell-ready alias and is within its start/end window.

## Validation performed in this environment

- PHP syntax check passed for all PHP files under backend app/routes/migrations/tests.
- Vue template compilation passed for all 82 `.vue` files under `frontend/app`.
- TypeScript parser check passed for all frontend app/test TS files and `nuxt.config.ts`.
- `infra/compose.yaml` parsed successfully as YAML.
- Confirmed there are no remaining `UToggle` references in compiled frontend app sources.

Full Laravel/PHPUnit, Nuxt typecheck/build/tests and live Telegram/Bakong/OmniRoute acceptance could not be executed in this sandbox because the uploaded source does not include `backend/vendor` or `frontend/node_modules`, and the sandbox Node runtime is v22.16 while the current full dependency tree requires Node 24.15+ or Node 26+ (the gateway itself requires Node >=24). Use `scripts/FINAL_ACCEPTANCE.ps1` on the target Windows machine after dependencies are installed for the full executable gate.


## Node compatibility correction

The final repository-level compatibility gate is **Node 24.15+ or Node 26+**. This is the intersection needed by the current gateway and frontend dependency tree. `scripts/CHECK_TOOLCHAIN.ps1` and `scripts/FINAL_ACCEPTANCE.ps1` now enforce this explicitly. Node 22 is too old for the gateway; Node 25 is not accepted by the current Nuxt engine range.
