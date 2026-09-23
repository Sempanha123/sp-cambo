#!/usr/bin/env python3
from pathlib import Path
import shutil
import sys
from datetime import datetime

ROOT = Path(__file__).resolve().parent
if len(sys.argv) > 1:
    repo = Path(sys.argv[1]).resolve()
else:
    repo = ROOT

targets = {
    "backend/app/Http/Controllers/Api/V1/ApiKeyController.php",
    "backend/tests/Unit/ApiKeyCheckTest.php",
    "frontend/app/types/api.ts",
    "frontend/app/pages/public/key-checker.vue",
    "frontend/tests/component/PublicKeyCheckerPage.spec.ts",
}

missing = [p for p in targets if not (repo / p).is_file()]
if missing:
    print("ERROR: repository root not found, or expected files are missing:")
    for p in missing:
        print(" -", p)
    print("\nUsage:")
    print("  python APPLY_FIX.py /path/to/sp-cambo")
    sys.exit(1)

stamp = datetime.now().strftime("%Y%m%d-%H%M%S")

def backup(path: Path):
    backup_path = path.with_name(path.name + f".bak-sp-credit-fix-{stamp}")
    shutil.copy2(path, backup_path)
    return backup_path

def replace_once(text: str, old: str, new: str, label: str) -> str:
    count = text.count(old)
    if count != 1:
        raise RuntimeError(f"{label}: expected exactly 1 match, found {count}. File was not changed.")
    return text.replace(old, new, 1)

changed = []

# 1) Backend
path = repo / "backend/app/Http/Controllers/Api/V1/ApiKeyController.php"
s = path.read_text(encoding="utf-8")

old = """        $creditBalances = $this->moneyGroups(
            $creditLots->map(fn (EntitlementLot $lot): array => [
                'minor' => max(0, (int) $lot->remaining_units - (int) $lot->reserved_units),
                'currency' => $lot->currency ?? 'USD',
                'exponent' => (int) ($lot->currency_exponent ?? 6),
            ])->all()
        );

        $usageTotals = UsageRecord::query()
"""
new = """        $creditBalances = $this->moneyGroups(
            $creditLots->map(fn (EntitlementLot $lot): array => [
                'minor' => max(0, (int) $lot->remaining_units - (int) $lot->reserved_units),
                'currency' => $lot->currency ?? 'USD',
                'exponent' => (int) ($lot->currency_exponent ?? 6),
            ])->all()
        );
        $spCreditRemaining = $this->spCreditRemaining($tokenLots);

        $usageTotals = UsageRecord::query()
"""
s = replace_once(s, old, new, "ApiKeyController: calculate SP Credit remaining")

old = """            'quota_remaining' => $quotaRemaining,
            'credit_remaining' => count($creditBalances) === 1 ? $creditBalances[0] : null,
"""
new = """            'quota_remaining' => $quotaRemaining,
            'sp_credit_remaining' => $spCreditRemaining,
            'credit_remaining' => count($creditBalances) === 1 ? $creditBalances[0] : null,
"""
s = replace_once(s, old, new, "ApiKeyController: return SP Credit remaining")

old = """            ->get(['id', 'billing_mode', 'original_units', 'remaining_units', 'reserved_units', 'unit_label', 'currency', 'currency_exponent', 'package_name', 'source_type', 'allowed_model_aliases', 'activated_at', 'expires_at', 'access_scope', 'bound_api_key_id', 'fulfillment_claim_id']);
"""
new = """            ->get(['id', 'billing_mode', 'original_units', 'remaining_units', 'reserved_units', 'unit_label', 'currency', 'currency_exponent', 'package_name', 'source_type', 'allowed_model_aliases', 'billing_snapshot', 'activated_at', 'expires_at', 'access_scope', 'bound_api_key_id', 'fulfillment_claim_id']);
"""
s = replace_once(s, old, new, "ApiKeyController: load billing snapshot")

marker = """    /** @param array<int, array{minor:int,currency:string,exponent:int}> $rows */
    private function moneyGroups(array $rows): array
"""
helper = """    /**
     * SP Credits are token-backed TOKEN_QUOTA packages. Convert only lots whose
     * immutable billing snapshot identifies package_kind=SP_CREDITS.
     *
     * The decimal is returned as a string so quota display never depends on float
     * arithmetic. Current catalog packages use 100,000 billable Tokens per Credit.
     */
    private function spCreditRemaining(iterable $tokenLots): ?string
    {
        $scale = 1_000_000;
        $scaledTotal = 0;
        $hasSpCreditLot = false;

        foreach ($tokenLots as $lot) {
            if (! $lot instanceof EntitlementLot) {
                continue;
            }

            $snapshot = is_array($lot->billing_snapshot) ? $lot->billing_snapshot : [];
            $rules = is_array($snapshot['billing_rules'] ?? null) ? $snapshot['billing_rules'] : [];

            if (($rules['package_kind'] ?? null) !== 'SP_CREDITS') {
                continue;
            }

            $billableUnitsPerCredit = (int) ($rules['sp_credit_billable_units'] ?? 0);
            if ($billableUnitsPerCredit <= 0) {
                continue;
            }

            $hasSpCreditLot = true;
            $remaining = max(0, (int) $lot->remaining_units - (int) $lot->reserved_units);

            $wholeCredits = intdiv($remaining, $billableUnitsPerCredit);
            $remainder = $remaining % $billableUnitsPerCredit;
            $scaledTotal += ($wholeCredits * $scale)
                + intdiv($remainder * $scale, $billableUnitsPerCredit);
        }

        if (! $hasSpCreditLot) {
            return null;
        }

        $whole = intdiv($scaledTotal, $scale);
        $fraction = $scaledTotal % $scale;

        if ($fraction === 0) {
            return (string) $whole;
        }

        return $whole.'.'.rtrim(str_pad((string) $fraction, 6, '0', STR_PAD_LEFT), '0');
    }

    /** @param array<int, array{minor:int,currency:string,exponent:int}> $rows */
    private function moneyGroups(array $rows): array
"""
s = replace_once(s, marker, helper, "ApiKeyController: add SP Credit conversion helper")

backup(path)
path.write_text(s, encoding="utf-8")
changed.append(str(path.relative_to(repo)))

# 2) Backend regression test
path = repo / "backend/tests/Unit/ApiKeyCheckTest.php"
s = path.read_text(encoding="utf-8")
marker = """    public function test_check_excludes_entitlements_outside_the_key_model_scope(): void
"""
addition = r"""    public function test_check_reports_token_backed_sp_credit_remaining_and_zero_when_exhausted(): void
    {
        $user = User::factory()->create();
        $alias = $this->alias();
        $issued = $this->issueKey($user, $alias);

        $lot = $this->grant($user, $alias, 'TOKEN_QUOTA', 10_000_000, [
            'package_kind' => 'SP_CREDITS',
            'display_units' => 100,
            'display_unit_label' => 'Credits',
            'sp_credit_billable_units' => 100_000,
        ]);
        $lot->forceFill([
            'package_name' => 'Codex $100 Credits',
            'remaining_units' => 133_107,
            'reserved_units' => 0,
        ])->save();

        $response = $this->postJson('/api/v1/keys/check', ['api_key' => $issued['secret']])->assertOk();

        $response->assertJsonPath('data.package', 'Codex $100 Credits');
        $response->assertJsonPath('data.quota_remaining', '133107');
        $response->assertJsonPath('data.sp_credit_remaining', '1.33107');
        $response->assertJsonPath('data.credit_remaining', null);

        $lot->forceFill(['remaining_units' => 0, 'reserved_units' => 0])->save();

        $this->postJson('/api/v1/keys/check', ['api_key' => $issued['secret']])
            ->assertOk()
            ->assertJsonPath('data.quota_remaining', '0')
            ->assertJsonPath('data.sp_credit_remaining', '0');
    }

"""
s = replace_once(s, marker, addition + marker, "ApiKeyCheckTest: add SP Credit regression test")
backup(path)
path.write_text(s, encoding="utf-8")
changed.append(str(path.relative_to(repo)))

# 3) Frontend API type
path = repo / "frontend/app/types/api.ts"
s = path.read_text(encoding="utf-8")
old = """  quota_remaining?: string | null
  credit_remaining?: MoneyAmount | null
"""
new = """  quota_remaining?: string | null
  /** Token-backed SP Credit display balance; separate from real money CREDIT_BALANCE. */
  sp_credit_remaining?: string | null
  credit_remaining?: MoneyAmount | null
"""
s = replace_once(s, old, new, "api.ts: add sp_credit_remaining")
backup(path)
path.write_text(s, encoding="utf-8")
changed.append(str(path.relative_to(repo)))

# 4) Public key checker UI
path = repo / "frontend/app/pages/public/key-checker.vue"
s = path.read_text(encoding="utf-8")

marker = """const fundingLabel = computed(() => {
"""
addition = r"""const formatSpCredits = (value: string | null | undefined): string | null => {
  if (value === null || value === undefined) return null

  const raw = String(value).trim()
  const match = raw.match(/^(\d+)(?:\.(\d+))?$/)
  if (!match) return `${raw} Credits`

  const wholeRaw = match[1] || '0'
  const fraction = (match[2] || '').replace(/0+$/, '')
  const whole = wholeRaw.replace(/\B(?=(\d{3})+(?!\d))/g, ',')
  const display = `${whole}${fraction ? `.${fraction}` : ''}`
  const singular = wholeRaw === '1' && fraction === ''

  return `${display} ${singular ? 'Credit' : 'Credits'}`
}

const creditRemainingLabel = computed(() => {
  const spCredits = formatSpCredits(keyStatus.value?.sp_credit_remaining)
  if (spCredits !== null) return spCredits

  if (keyStatus.value?.credit_remaining || keyStatus.value?.credit_balances?.length) {
    return formatMoneySet(keyStatus.value.credit_remaining, keyStatus.value.credit_balances)
  }

  return 'No balance'
})

"""
s = replace_once(s, marker, addition + marker, "key-checker.vue: add SP Credit formatter")

old = """:value="keyStatus.credit_remaining || keyStatus.credit_balances?.length ? formatMoneySet(keyStatus.credit_remaining, keyStatus.credit_balances) : 'No balance'"""
new = """:value="creditRemainingLabel"""
s = replace_once(s, old, new, "key-checker.vue: render SP Credit balance")

backup(path)
path.write_text(s, encoding="utf-8")
changed.append(str(path.relative_to(repo)))

# 5) Frontend component regression tests
path = repo / "frontend/tests/component/PublicKeyCheckerPage.spec.ts"
s = path.read_text(encoding="utf-8")
marker = """  it('renders zero quota as zero and keeps credit/spend as exact money objects', async () => {
"""
addition = r"""  it('renders token-backed SP Credits instead of incorrectly saying No balance', async () => {
    const page = await submitKey({
      ...activeResponse(),
      package: 'Codex $100 Credits',
      quota_remaining: '133107',
      sp_credit_remaining: '1.33107',
      credit_remaining: null,
      credit_balances: []
    })

    const text = page.text()
    expect(text).toMatch(/Quota remaining\s*133,107/)
    expect(text).toMatch(/Credit remaining\s*1\.33107 Credits/)
    expect(text).not.toMatch(/Credit remaining\s*No balance/)
  })

  it('renders zero SP Credits when the token-backed credit lot is exhausted', async () => {
    const page = await submitKey({
      ...activeResponse(),
      package: 'Codex $100 Credits',
      quota_remaining: '0',
      sp_credit_remaining: '0',
      credit_remaining: null,
      credit_balances: []
    })

    const text = page.text()
    expect(text).toMatch(/Quota remaining\s*0/)
    expect(text).toMatch(/Credit remaining\s*0 Credits/)
  })

"""
s = replace_once(s, marker, addition + marker, "PublicKeyCheckerPage.spec.ts: add SP Credit UI tests")
backup(path)
path.write_text(s, encoding="utf-8")
changed.append(str(path.relative_to(repo)))

print("SP Cambo SP Credit key-checker fix applied successfully.")
print("Changed files:")
for p in changed:
    print(" -", p)
print("\nRecommended checks:")
print("  cd backend && php artisan test --filter=ApiKeyCheckTest")
print("  cd ../frontend && pnpm typecheck")
print("  pnpm test -- PublicKeyCheckerPage.spec.ts")
print("  git diff")
