# SP Cambo CI Fix R6.2

R6.1 stopped only because it attempted to copy:

```text
frontend/eslint.config.mjs
```

onto the exact same file after the ZIP had already been extracted into the
project root.

The important R6.1 source changes completed before that error:

- route-pools parser fix
- removed unused `formatSpCredits`
- removed unused `selectedBalance`
- fixed `AuditTelegramBotClient::sendMessage()` to return `array`

The ESLint file was also already in its destination because extraction placed it
there.

R6.2 therefore does not copy anything. It verifies the partially-applied state
and then gives you a clean verification script.

## Run

Extract this ZIP over the project root, then:

```powershell
cd "C:\Users\Rg Gear\Desktop\SP Cambo"

powershell -ExecutionPolicy Bypass -File .\APPLY_CI_FIX_R6_2.ps1
```

Then:

```powershell
powershell -ExecutionPolicy Bypass -File .\VERIFY_CI_FIX_R6_2.ps1
```

Do not run R6 or R6.1 again.
