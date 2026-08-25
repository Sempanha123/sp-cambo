# SP Cambo — How to Run AI1 + AI2 in Two CLI Terminals

## 1. Extract this project

Recommended location:

`C:\Users\Rg Gear\Desktop\SP Cambo`

Preserve your existing secret environment files if you are replacing an older copy:

- `backend\.env`
- `frontend\.env`
- `frontend\.env.local`
- `gateway\.env`

Do not copy secrets into prompts or git.

## 2. Open TWO terminals in the SAME project folder

Terminal 1:

```powershell
cd "C:\Users\Rg Gear\Desktop\SP Cambo"
```

Run your first CLI AI in this terminal, then paste the entire text from:

`START_AI1_PROMPT.txt`

Terminal 2:

```powershell
cd "C:\Users\Rg Gear\Desktop\SP Cambo"
```

Run your second CLI AI in this terminal, then paste the entire text from:

`START_AI2_PROMPT.txt`

## 3. Who does what?

### AI1 = builds/fixes code

AI1 edits production source, adds tests, runs validation, commits checkpoints, and records progress.

### AI2 = checks/audits AI1

AI2 should mostly read/review/test instead of editing the same production files. AI2 writes defects for AI1 in `docs/ai/AI2_AUDIT.md`.

This reduces two-AI file conflicts.

## 4. How they communicate while both are running

They use these shared files:

- `docs/ai/AI1_STATUS.md`
- `docs/ai/AI2_AUDIT.md`
- `docs/ai/PARALLEL_BOARD.md`

Loop:

1. AI2 finds a bug and writes it to `AI2_AUDIT.md`.
2. AI1 reads it and fixes the highest-priority issue.
3. AI1 tests it, creates a checkpoint, and marks it `FIXED`.
4. AI2 independently retests it.
5. AI2 marks it `VERIFIED` only if it really passes.
6. Both continue.

## 5. Important working rule

Do NOT let both AIs freely edit the same production files at the same time.

- AI1 owns implementation.
- AI2 owns audit/release verification.

AI2 should only directly change production code if you intentionally pause AI1 or assign AI2 a separate non-overlapping task.

## 6. Current project checkpoint

This bundle is based on SP Cambo R6.1 and includes the Telegram storefront migration fix for MySQL's 64-character identifier limit.

After restoring your `.env` files, run migrations:

```powershell
cd "C:\Users\Rg Gear\Desktop\SP Cambo\backend"
php artisan migrate
php artisan optimize:clear
```

Then start the native Windows stack as usual. Docker is optional for local development.

## 7. What counts as finished?

Not just "tests pass".

The master file `SP_CAMBO_PRODUCTION_FINISH_AI1_AI2.md` defines the complete release gates. AI2 should only approve Production Ready after real provider → package → payment → key → API → usage → Telegram acceptance passes.
