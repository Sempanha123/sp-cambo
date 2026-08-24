import type { AdminPackage } from '~/types/admin'

/**
 * Catalogue-administration rules shared by the admin package and promotion pages.
 *
 * These live outside the components because each one is a decision that must be
 * right rather than merely look right: whether a package is being sold without a
 * proven margin, and whether a promotion's scope can be re-submitted without
 * silently widening it. Both are unit-tested.
 */

/**
 * Enabled *and* customer-visible — exactly the condition
 * `PackageProfitabilityService::assertPublishable` gates on.
 *
 * The two flags are independent: a disabled package sells to nobody, while an
 * enabled-but-hidden one is not offered in the catalogue yet still honours flows
 * that already reference it.
 */
export function isPackageLive(item: AdminPackage): boolean {
  return item.enabled && item.customer_visible
}

/**
 * A package customers can buy right now without established profitability.
 *
 * `profitable !== true` deliberately catches both `false` and `null`. A null margin
 * is not the safer case: it means no upstream cost has been verified for some
 * allowed model, so the true cost is unknown rather than low.
 */
export function isPackageAtRisk(item: AdminPackage): boolean {
  return isPackageLive(item) && item.profitability.profitable !== true
}

/** Every alias across the catalogue with no verified upstream cost, deduplicated. */
export function aliasesMissingUpstreamCost(items: AdminPackage[]): string[] {
  const names = new Set<string>()

  for (const item of items) {
    for (const alias of item.profitability.missing_cost_aliases) {
      names.add(alias)
    }
  }

  return [...names].sort()
}

export interface PackageScopeChoice {
  id: number
  slug: string
}

export interface ResolvedPackageScope {
  /** Internal ids for the slugs that matched a package. */
  ids: number[]
  /** Slugs with no matching package. Non-empty means the scope must not be saved. */
  unresolved: string[]
}

/**
 * Maps a promotion's `package_slugs` back to the `package_ids` a write requires.
 *
 * The asymmetry is real: `GET /admin/promotions` returns slugs while
 * `POST|PUT /admin/promotions` takes internal integer ids. Recovering the mapping
 * needs `GET /admin/packages`, which publishes both.
 *
 * Unresolved slugs are reported rather than dropped, because an empty `package_ids`
 * is not "no change" to the control plane — `PromotionService` only restricts a
 * promotion that has at least one package attached, so an empty array means the
 * discount applies to *every* package. Silently posting a short list would widen a
 * scoped discount across the catalogue.
 */
export function resolvePackageScope(
  slugs: string[],
  choices: PackageScopeChoice[]
): ResolvedPackageScope {
  const bySlug = new Map(choices.map(choice => [choice.slug, choice.id]))
  const ids: number[] = []
  const unresolved: string[] = []

  for (const slug of slugs) {
    const id = bySlug.get(slug)

    if (id === undefined) {
      unresolved.push(slug)
    } else if (!ids.includes(id)) {
      ids.push(id)
    }
  }

  return { ids, unresolved }
}

/**
 * A whole number from operator input, or null when the field is blank.
 *
 * Returns `undefined` for anything that is not a non-negative integer — a decimal
 * point, a thousands separator, a sign, a stray letter — so the caller reports it
 * as invalid. Nothing is coerced or truncated: every one of these values reaches an
 * `integer` validator and lands in a price, a discount or a quota, and quietly
 * turning `10.5` into `10` would be a wrong number presented as an accepted one.
 */
export function parseOptionalInteger(value: string): number | null | undefined {
  const trimmed = value.trim()

  if (trimmed === '') {
    return null
  }

  if (!/^\d+$/.test(trimmed)) {
    return undefined
  }

  const parsed = Number(trimmed)

  // Beyond 2^53 a decimal string no longer survives the round trip through Number.
  return Number.isSafeInteger(parsed) ? parsed : undefined
}
