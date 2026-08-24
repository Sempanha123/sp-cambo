# SP Cambo Local Stack / Gateway Secret Fix — 2026-08-24

The Windows local-stack scripts now generate or reuse one internal gateway secret, store the same secret in `backend/.env` and `gateway/.env`, keep it hidden from console output, use the in-memory gateway rate store for local development, and start Laravel, the inference gateway, and the KHQR service on their expected localhost ports.

Use `scripts/APPLY_LOCAL_STACK_FIX.ps1` to prepare local `.env` values, then run `scripts/START_LOCAL_STACK.ps1`. The generated `.env` files remain ignored by Git.
