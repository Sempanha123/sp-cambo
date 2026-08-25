# SP Cambo Discover → Packages R5

## Fixed behavior

- **Discover upstream** now has **Select all** and **Unselect all** controls.
- Discovery reports whether each upstream model is already registered and whether it already has a public alias.
- The import dialog defaults to **Create missing public aliases so these models appear in Packages**.
- This also repairs models imported by older builds that exist only as private models with no public alias.
- Generated aliases are intentionally **customer hidden**. Review protocols, model capabilities, pricing, and commercial resale permission before using **Publish for sale**.
- The Packages page refreshes both packages and model aliases. Opening **New package** refreshes the alias list first so newly imported models are visible without a hard reload.

## Why the old behavior looked broken

Packages grant `model_aliases` (stable customer-facing model names), not raw `ai_models.internal_model_id` values. Older Discover/import behavior created only the private `ai_models` row, so there was no alias for the Packages form to list.

R5 can create that alias bridge during import while preserving the publication safety gate.

## No database migration

This patch changes API response fields and import behavior only. No schema migration is required.
