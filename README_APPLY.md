# SP Cambo Public Alias GIF Icons R7

This update uses the NEW small GIF files uploaded by the user specifically for
model public aliases.

## Files

- `frontend/public/model-alias-icons/codex_small_icon.gif`
- `frontend/public/model-alias-icons/claude_small_icon.gif`
- `frontend/public/model-alias-icons/gemini_small_icon.gif`
- `frontend/app/components/SpPublicAliasIcon.vue`
- `frontend/app/components/SpModelBadge.vue`
- `frontend/app/pages/models.vue`

## Mapping

- Claude / Anthropic / Opus / Sonnet / Haiku -> Claude small GIF
- Gemini / Google AI -> Gemini small GIF
- GPT / OpenAI / ChatGPT / Codex -> Codex small GIF
- Unknown model -> existing `modelPresentation()` fallback icon

## Where it appears

1. Model catalogue:
   the old `Model ID` panel is now labelled **Public alias** and shows the
   matching small animated icon beside the real `model.public_alias`.

2. Model/package alias badges:
   `SpModelBadge` now uses the small GIF icon, so badges such as Claude Haiku,
   Claude Opus, Gemini, GPT/Codex aliases also receive the small artwork.

3. Large model/package header icons:
   unchanged. The larger R5 artwork can remain there, while R7 small GIFs are
   reserved for aliases/chips. This gives the UI a clear size hierarchy.

No model names, public aliases, prices or package data are hard-coded. The
catalogue still renders the real backend `model.public_alias`.

## Apply

Extract over your SP Cambo project root and allow overwrite.

Then:

```powershell
cd frontend
npm run typecheck
npm run build
```

Test:

```text
/models
/pricing
/dashboard/buy
/dashboard/playground
```
