# SP Cambo V2 Progress 10 — 2026-08-24

Progress 10 is rebuilt from the Progress 9 release checkpoint. It preserves the completed V2 implementation and adds a single-command final acceptance runner so the remaining dependency-complete release gates can be reproduced on the intended Windows/Node 24/Composer/Docker workstation.

## Added in Progress 10

- `scripts/FINAL_ACCEPTANCE.ps1`
  - validates required toolchain and requires Node 24+;
  - optionally installs Composer, Nuxt and gateway dependencies;
  - validates backend PHP syntax;
  - runs the complete Laravel test suite;
  - runs Nuxt prepare, lint, typecheck, Vitest and production build;
  - runs gateway frozen pnpm install, typecheck, Vitest and production build;
  - validates and builds the Docker Compose stack unless skipped;
  - prints the explicit live Bakong, Telegram, OmniRoute, Claude Code and OpenAI/Codex smoke-test checklist;
  - supports `-SkipInstall`, `-SkipDocker`, `-SkipLive` and `-ContinueOnFailure` for repeatable local/CI diagnosis.

## Rebuild validation in this runner

- Backend release-scope PHP syntax (`app`, `bootstrap`, `config`, `database`, `routes`, `tests`): **223/223 pass `php -l`**.
- The Progress 9 feature set and regression coverage are preserved unchanged.
- Dependency-complete Laravel/Nuxt/gateway test suites and Docker/live-service acceptance are not falsely reported as passing in this rebuild environment because Composer/pnpm dependencies and Docker are unavailable here.

## Final release command

From the repository root on the target Windows workstation:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\FINAL_ACCEPTANCE.ps1 -ContinueOnFailure
```

Do not label the project FINAL until all automated gates pass and the printed live-service acceptance checks have been completed with real credentials.
