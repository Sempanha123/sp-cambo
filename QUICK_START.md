# SP Cambo R24 — Stable OmniRoute Combos + Profitable Public Models

## Fresh database

This intentionally deletes existing local database data:

```powershell
cd backend
php artisan optimize:clear
php artisan migrate:fresh --seed
cd ..
.\START_ALL.ps1
```

## Stable routing map

The private OmniRoute combo IDs stay exactly as configured in OmniRoute:

| Public API alias | Public display name | Private/Internal OmniRoute model ID |
|---|---|---|
| `openai-codex` | GPT-5.6 Sol | `OpenAI Codex` |
| `gemini-google-ai-studio` | Gemini 3.6 Flash | `Gemini Google AI Studio` |

SP Cambo never replaces those private combo IDs with `gpt-5.6-sol` or `gemini-3.6-flash`. OmniRoute remains responsible for account rotation/failover behind each combo.

## Seeded customer limits

Both public models and every sale package use these customer safety caps:

- Requests/minute: `60`
- Tokens/minute: `200,000`
- Concurrency: `4`
- Max request size: `1,048,576 bytes` (1 MiB)
- Max output per customer request/package key: `16,384 tokens`

The public capability metadata still advertises the underlying model ceiling (GPT-5.6 Sol 128K output; Gemini 3.6 Flash 64K output), while the sale cap keeps spend predictable. The 200K TPM cap also keeps ordinary GPT-5.6 Sol requests below OpenAI's >272K-input surcharge threshold, which protects the seeded margin assumptions.

## Seeded pricing and private profit accounting

Model pricing uses USD exponent `3` so `$0.075 / 1M` is represented exactly. Customer wallets remain ordinary USD cents.

### GPT-5.6 Sol

| Usage class | Customer rate / 1M | Private upstream reference / 1M | Gross margin on customer revenue |
|---|---:|---:|---:|
| Input | $5.000 | $4.000 | 20% |
| Output | $25.000 | $20.000 | 20% |
| Cache read | $0.500 | $0.400 | 20% |
| Cache write | $6.250 | $5.000 | 20% |
| Reasoning | $25.000 | $20.000 | 20% |

The private reference uses the official GPT-5.6 Sol public API pricing snapshot. OpenAI states GPT-5.6 Sol is $4/M input, $0.40/M cached input and $20/M output, with cache writes billed at 1.25x uncached input.

### Gemini 3.6 Flash

| Usage class | Customer rate / 1M | Private upstream reference / 1M | Gross margin on customer revenue |
|---|---:|---:|---:|
| Input | $1.000 | $0.750 | 25% |
| Output | $5.000 | $3.750 | 25% |
| Cache read | $0.100 | $0.075 | 25% |
| Cache write | $1.000 | N/A per request | N/A |
| Reasoning/thinking | $5.000 | $3.750 | 25% |

Google bills explicit cache storage by token-hour instead of a normal per-request cache-write token price. The Gemini usage adapter records native cache-write tokens as zero, so ordinary request profit remains calculable without inventing a fake upstream write rate.

> `25%` above uses `(sell - cost) / sell`; the seeded GPT rates are 20% margin and the seeded Gemini rates are 25% margin. Token-package prices are separately guarded at a minimum 20% projected margin.

## Provider connection

Runtime provider URL/API key still come from the Admin-managed encrypted database connection revision:

```text
Admin -> Providers -> OmniRoute -> Probe -> READY -> Activate
```

No fixed OmniRoute URL or key is required in `.env`.

## Visibility

Customers see public model names, customer rates, usage, charge, balance and expiry. Upstream cost, gross profit, margin, provider secrets and private combo IDs are admin-only.

## Stop

```powershell
.\STOP_ALL.ps1
```

Only `START_ALL.ps1` and `STOP_ALL.ps1` are required for the local SP Cambo stack.
