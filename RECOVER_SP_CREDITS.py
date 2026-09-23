#!/usr/bin/env python3
from pathlib import Path
import re
import shutil
import sys

ROOT = Path(__file__).resolve().parent
PROJECT = Path.cwd()
PAYLOAD = ROOT / "payload"

def fail(msg: str) -> None:
    raise SystemExit(f"[SP Credits recovery] {msg}")

def backup(path: Path) -> None:
    bak = path.with_suffix(path.suffix + ".bak-sp-credits-recovery")
    if path.exists() and not bak.exists():
        shutil.copy2(path, bak)

def copy_payload(rel: str) -> None:
    src = PAYLOAD / rel
    dst = PROJECT / rel
    if not src.exists():
        fail(f"Recovery payload is missing {rel}")
    dst.parent.mkdir(parents=True, exist_ok=True)
    if dst.exists() and dst.read_bytes() == src.read_bytes():
        print(f"already  {rel}")
        return
    backup(dst)
    shutil.copy2(src, dst)
    print(f"replaced {rel}")

def load(rel: str):
    p = PROJECT / rel
    if not p.exists():
        fail(f"Missing project file: {rel}")
    return p, p.read_text(encoding="utf-8")

def save(p: Path, text: str, rel: str) -> None:
    backup(p)
    p.write_text(text, encoding="utf-8", newline="\n")
    print(f"updated  {rel}")

def replace_if_present(rel: str, old: str, new: str, *, all_matches=False) -> None:
    p, text = load(rel)
    if old not in text:
        if new and new in text:
            print(f"already  {rel}")
        else:
            print(f"skip     {rel} (target already changed or not present)")
        return
    changed = text.replace(old, new) if all_matches else text.replace(old, new, 1)
    save(p, changed, rel)

def dedupe_line(rel: str, line: str) -> None:
    p, text = load(rel)
    count = text.count(line)
    if count <= 1:
        return
    first = text.find(line)
    head = text[:first + len(line)]
    tail = text[first + len(line):].replace(line, "")
    save(p, head + tail, rel)

def collapse_duplicate_interface(rel: str, name: str) -> None:
    p, text = load(rel)
    marker = f"export interface {name} {{"
    if text.count(marker) <= 1:
        return

    starts = [m.start() for m in re.finditer(re.escape(marker), text)]
    keep_start = starts[0]
    remove_ranges = []

    for start in starts[1:]:
        # Interfaces here contain no nested braces, so the next standalone }\n is enough.
        end = text.find("\n}\n", start)
        if end == -1:
            fail(f"Could not safely dedupe interface {name} in {rel}")
        end += 3
        if end < len(text) and text[end:end+1] == "\n":
            end += 1
        remove_ranges.append((start, end))

    for start, end in reversed(remove_ranges):
        text = text[:start] + text[end:]

    save(p, text, rel)

# ------------------------------------------------------------------
# 1) CRITICAL FILES FIRST.
# The previous patch stopped before this step. This recovery does it first.
# ------------------------------------------------------------------
for rel in [
    "backend/app/Support/SpCredits.php",
    "backend/database/migrations/2026_09_24_040000_normalize_sp_credit_labels.php",
    "backend/tests/Unit/SpCreditsTest.php",
    "backend/app/Http/Controllers/Api/V1/ResellerCustomerReportingController.php",
    "frontend/app/pages/reseller/customers/[id].vue",
    "frontend/app/types/reseller.ts",
]:
    copy_payload(rel)

# ------------------------------------------------------------------
# 2) Clean duplicates created by the interrupted old patch.
# ------------------------------------------------------------------
for rel in [
    "backend/app/Http/Controllers/Api/V1/Catalog/PackageCatalogController.php",
    "backend/app/Http/Controllers/Api/V1/OrderController.php",
    "backend/app/Http/Controllers/Api/V1/ResellerInventoryController.php",
    "backend/app/Http/Controllers/Api/V1/EntitlementController.php",
    "backend/app/Http/Controllers/Api/V1/ResellerCustomerController.php",
    "backend/app/Http/Controllers/Api/V1/ApiKeyController.php",
]:
    if (PROJECT / rel).exists():
        dedupe_line(rel, "use App\\Support\\SpCredits;\n")

dedupe_line(
    "backend/app/Http/Controllers/Api/V1/ResellerCustomerController.php",
    "use Illuminate\\Validation\\ValidationException;\n"
)
dedupe_line(
    "backend/app/Http/Controllers/Api/V1/ResellerInventoryController.php",
    "                'display' => SpCredits::displayLot($lot),\n"
)

collapse_duplicate_interface("frontend/app/types/commerce.ts", "QuotaDisplay")

# ------------------------------------------------------------------
# 3) Ensure key-checker/status separates raw SP Credit settlement units
#    from ordinary Token quota. This prevents double presentation.
# ------------------------------------------------------------------
rel = "backend/app/Http/Controllers/Api/V1/ApiKeyController.php"
p, text = load(rel)

if "use App\\Support\\SpCredits;" not in text:
    text = text.replace(
        "use App\\Support\\AccessAllocationSchema;\n",
        "use App\\Support\\AccessAllocationSchema;\nuse App\\Support\\SpCredits;\n",
        1
    )

old_split = """        $tokenLots = $eligibleLots->where('billing_mode', 'TOKEN_QUOTA');
        $creditLots = $eligibleLots->where('billing_mode', 'CREDIT_BALANCE');

        $quotaRemaining = $tokenLots->isEmpty()
            ? null
            : (string) $tokenLots->sum(fn (EntitlementLot $lot): int => max(0, (int) $lot->remaining_units - (int) $lot->reserved_units));
"""
new_split = """        $tokenLots = $eligibleLots->where('billing_mode', 'TOKEN_QUOTA');
        $spCreditLots = $tokenLots->filter(
            fn (EntitlementLot $lot): bool => SpCredits::isLot($lot)
        );
        $tokenOnlyLots = $tokenLots->reject(
            fn (EntitlementLot $lot): bool => SpCredits::isLot($lot)
        );
        $creditLots = $eligibleLots->where('billing_mode', 'CREDIT_BALANCE');

        $quotaRemaining = $tokenOnlyLots->isEmpty()
            ? null
            : (string) $tokenOnlyLots->sum(fn (EntitlementLot $lot): int => max(0, (int) $lot->remaining_units - (int) $lot->reserved_units));
"""
text = text.replace(old_split, new_split)

# Public checker should calculate SP Credits only from SP Credit lots.
text = text.replace(
    "$spCreditRemaining = $this->spCreditRemaining($tokenLots);",
    "$spCreditRemaining = $this->spCreditRemaining($spCreditLots);"
)

# Signed-in status endpoint needs the same SP Credit value.
status_credit_marker = """        $creditBalances = $this->moneyGroups(
            $creditLots->map(fn (EntitlementLot $lot): array => [
                'minor' => max(0, (int) $lot->remaining_units - (int) $lot->reserved_units),
                'currency' => $lot->currency ?? 'USD',
                'exponent' => (int) ($lot->currency_exponent ?? 6),
            ])->all()
        );

        return response()->json(['data' => [
            'valid' => $status === 'ACTIVE',"""
if status_credit_marker in text:
    text = text.replace(
        status_credit_marker,
        """        $creditBalances = $this->moneyGroups(
            $creditLots->map(fn (EntitlementLot $lot): array => [
                'minor' => max(0, (int) $lot->remaining_units - (int) $lot->reserved_units),
                'currency' => $lot->currency ?? 'USD',
                'exponent' => (int) ($lot->currency_exponent ?? 6),
            ])->all()
        );
        $spCreditRemaining = $this->spCreditRemaining($spCreditLots);

        return response()->json(['data' => [
            'valid' => $status === 'ACTIVE',""",
        1
    )

status_response = """            'token_quota_remaining' => $tokenRemaining,
            'credit_remaining' => count($creditBalances) === 1 ? $creditBalances[0] : null,"""
if status_response in text:
    text = text.replace(
        status_response,
        """            'token_quota_remaining' => $tokenRemaining,
            'sp_credit_remaining' => $spCreditRemaining,
            'credit_remaining' => count($creditBalances) === 1 ? $creditBalances[0] : null,""",
        1
    )

# Funding/details endpoint: don't count Credit raw units as Tokens.
funding_old = """            $spendable = static fn (EntitlementLot $lot): int => max(0, (int) $lot->remaining_units - (int) $lot->reserved_units);
            $tokenRemaining = $lots->where('billing_mode', 'TOKEN_QUOTA')->sum($spendable);
            $creditBalances = $this->moneyGroups("""
funding_new = """            $spendable = static fn (EntitlementLot $lot): int => max(0, (int) $lot->remaining_units - (int) $lot->reserved_units);
            $allTokenLots = $lots->where('billing_mode', 'TOKEN_QUOTA');
            $spCreditFundingLots = $allTokenLots->filter(
                fn (EntitlementLot $lot): bool => SpCredits::isLot($lot)
            );
            $tokenRemaining = $allTokenLots
                ->reject(fn (EntitlementLot $lot): bool => SpCredits::isLot($lot))
                ->sum($spendable);
            $spCreditRemaining = $this->spCreditRemaining($spCreditFundingLots);
            $creditBalances = $this->moneyGroups("""
text = text.replace(funding_old, funding_new)

funding_response = """                'token_quota_remaining' => (string) $tokenRemaining,
                'credit_balances' => $creditBalances,"""
if funding_response in text:
    text = text.replace(
        funding_response,
        """                'token_quota_remaining' => (string) $tokenRemaining,
                'sp_credit_remaining' => $spCreditRemaining,
                'credit_balances' => $creditBalances,""",
        1
    )

# Funding rows should expose the same display object used elsewhere.
days_line = """                    'days_remaining' => $expiresAt === null ? null : max(0, (int) ceil(now()->diffInSeconds($expiresAt, false) / 86400)),
                ];"""
if days_line in text:
    text = text.replace(
        days_line,
        """                    'days_remaining' => $expiresAt === null ? null : max(0, (int) ceil(now()->diffInSeconds($expiresAt, false) / 86400)),
                    'display' => SpCredits::displayLot($lot),
                ];""",
        1
    )

save(p, text, rel)

# ------------------------------------------------------------------
# 4) Finish frontend commerce contracts that old patch did not reach.
# ------------------------------------------------------------------
rel = "frontend/app/types/commerce.ts"
p, text = load(rel)

if "export interface QuotaDisplay" not in text:
    text = text.replace(
        "export type BillingMode = 'TOKEN_QUOTA' | 'CREDIT_BALANCE'\n",
        """export type BillingMode = 'TOKEN_QUOTA' | 'CREDIT_BALANCE'

export interface QuotaDisplay {
  kind: 'SP_TOKENS' | 'SP_CREDITS'
  unit_label: string
  raw_units_per_display_unit: string
  original: string
  remaining: string
  reserved: string
  available: string
  sold: string | null
}
""",
        1
    )

if "  display?: QuotaDisplay | null\n}" not in text:
    text = text.replace(
        "  bound_api_key: { id: string, label: string, masked_key: string } | null\n}",
        "  bound_api_key: { id: string, label: string, masked_key: string } | null\n  display?: QuotaDisplay | null\n}",
        1
    )

# Add SP Credit field to key funding and signed-in key status types.
details_marker = """  token_quota_remaining: string | null
  credit_balances: MoneyAmount[]"""
if details_marker in text:
    text = text.replace(
        details_marker,
        """  token_quota_remaining: string | null
  sp_credit_remaining?: string | null
  credit_balances: MoneyAmount[]""",
        1
    )

status_marker = """  token_quota_remaining: string | null
  credit_remaining: MoneyAmount | null"""
if status_marker in text:
    text = text.replace(
        status_marker,
        """  token_quota_remaining: string | null
  sp_credit_remaining?: string | null
  credit_remaining: MoneyAmount | null""",
        1
    )

funding_marker = """    days_remaining: number | null
  }>"""
if funding_marker in text:
    text = text.replace(
        funding_marker,
        """    days_remaining: number | null
    display?: QuotaDisplay | null
  }>""",
        1
    )

save(p, text, rel)

# ------------------------------------------------------------------
# 5) Finish Entitlements page. The old script failed here only because the
#    same safe render pattern occurred three times.
# ------------------------------------------------------------------
rel = "frontend/app/pages/dashboard/entitlements.vue"
p, text = load(rel)

# Credit formatter if not already completed.
old_formatter = """const formatSpCreditBalance = (value: string | null | undefined) => {
  if (value == null) return '$0'
  const amount = Number(value)

  return Number.isFinite(amount)
    ? `$${amount.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 5 })}`
    : value
}
"""
if old_formatter in text:
    text = text.replace(
        old_formatter,
        """const formatSpCreditBalance = (value: string | null | undefined) =>
  value == null ? '0 Credits' : formatDecimalQuantity(value, 'Credits')

const lotDisplayAmount = (
  lot: EntitlementLot,
  field: 'original' | 'remaining' | 'reserved' | 'available'
) => {
  if (lot.billing_mode === 'CREDIT_BALANCE' && field === 'remaining' && lot.remaining_amount) {
    return formatMoney(lot.remaining_amount)
  }

  if (lot.display) {
    return formatDecimalQuantity(lot.display[field], lot.display.unit_label)
  }

  const raw = field === 'original'
    ? lot.original_units
    : field === 'reserved'
      ? lot.reserved_units
      : lot.remaining_units

  return formatUnits(raw)
}
""",
        1
    )

text = text.replace(
    "{{ formatUnits(lot.original_units) }} {{ customerUnitLabel(lot.unit_label) }}",
    "{{ lotDisplayAmount(lot, 'original') }}"
)
text = text.replace(
    "{{ formatUnits(lot.original_units) }} purchased",
    "{{ lotDisplayAmount(lot, 'original') }} purchased"
)
text = text.replace(
    "{{ formatUnits(lot.original_units) }}",
    "{{ lotDisplayAmount(lot, 'original') }}"
)
text = text.replace(
    "{{ formatUnits(lot.reserved_units) }}",
    "{{ lotDisplayAmount(lot, 'reserved') }}"
)
remaining_block = """{{ lot.billing_mode === 'CREDIT_BALANCE' && lot.remaining_amount
                    ? formatMoney(lot.remaining_amount)
                    : formatUnits(lot.remaining_units) }}"""
text = text.replace(remaining_block, "{{ lotDisplayAmount(lot, 'remaining') }}")

save(p, text, rel)

# ------------------------------------------------------------------
# 6) Usage page: Credit balance uses Credits, never "$<credit-count>".
# Generic Token cache savings stay Tokens.
# ------------------------------------------------------------------
rel = "frontend/app/pages/dashboard/usage.vue"
p, text = load(rel)

old_usage_formatter = """const formatSpCredits = (value: string | null | undefined) => {
  if (value == null) return '—'
  const amount = Number(value)
  if (!Number.isFinite(amount)) return '—'
  return `$${amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 5 })}`
}
"""
if old_usage_formatter in text:
    text = text.replace(
        old_usage_formatter,
        """const formatSpCredits = (value: string | null | undefined) =>
  value == null ? '—' : formatDecimalQuantity(value, 'Credits')
""",
        1
    )

text = text.replace(
    'class="grid gap-3 sm:grid-cols-2 xl:grid-cols-6"',
    'class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4 2xl:grid-cols-7"',
    1
)

saved_card = """            <SpMetric
              label="Saved by cache"
              icon="i-lucide-sparkles"""
if 'label="Available Credits"' not in text and saved_card in text:
    text = text.replace(
        saved_card,
        """            <SpMetric
              label="Available Credits"
              icon="i-lucide-wallet-cards"
              tone="success"
              :value="balance.data.value?.sp_credit_quota
                ? formatSpCredits(balance.data.value.sp_credit_quota.remaining)
                : '0 Credits'"
              hint="Current spendable SP Credit balance"
            />
            <SpMetric
              label="Saved by cache"
              icon="i-lucide-sparkles""",
        1
    )

text = text.replace(
    ':hint="`${formatSpCredits(summary.data.value.credits_saved)} Credits kept through cache reuse`"',
    ':hint="`${formatUnits(summary.data.value.saved_tokens)} Tokens kept through smart reuse`"'
)

# Remove misleading per-model Credits value from generic TOKEN_QUOTA summary.
credits_model_block = """                  <div class="text-right">
                    <dt class="text-[11px] leading-4 text-dimmed">Credits</dt>
                    <dd class="sp-numeric text-sm font-semibold leading-5 text-primary">{{ formatSpCredits(entry.sp_credits_used) }}</dd>
                  </div>
"""
text = text.replace(credits_model_block, "")

save(p, text, rel)

# ------------------------------------------------------------------
# 7) Public checker wording.
# ------------------------------------------------------------------
replace_if_present(
    "frontend/app/pages/public/key-checker.vue",
    "text: 'Token quota and exact currency-scaled credit.'",
    "text: 'Token quota, SP Credits, and wallet credit are shown separately.'"
)

print("")
print("Recovery complete.")
print("Critical helper/migration were copied before any optional cleanup.")
print("Next: run the verification commands from README.txt.")
