# Nuxt Welcome Fix

The old `START_ALL.ps1` forwarded an extra `--` before Nuxt's host options.
Nuxt treated `--host` as a project directory, generated `frontend/--host`, and
served the default welcome page instead of `frontend/app/app.vue`.

This package fixes the launcher and restores the expected project layout:

```text
SPCambo/
  backend/
  frontend/
  gateway/
  START_ALL.ps1
```

## Start on Windows

1. Extract this ZIP into a new empty directory.
2. Close the service windows from any older SP Cambo copy.
3. Open PowerShell in the extracted `SPCambo` directory.
4. Run:

```powershell
Set-ExecutionPolicy -Scope Process Bypass
.\START_ALL.ps1 -RestartFrontend -CleanFrontendCache
```

Open `http://127.0.0.1:3000` and press `Ctrl+F5` once. The launcher now checks
that the source contains `NuxtPage`, rejects `NuxtWelcome`, and removes the
known legacy `frontend/--host` generated directory if it exists.
