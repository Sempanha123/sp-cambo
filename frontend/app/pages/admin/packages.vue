<script setup lang="ts">
import type { AdminPackage, AdminPackageInput } from '~/types/admin'
import type { PackageFormState } from '~/utils/packageAdmin'

/**
 * Catalogue oversight and editing: what SP Cambo is selling, whether it is
 * profitable, and the form that changes it.
 *
 * Requires the `catalog.manage` permission, which is distinct from `admin.view` —
 * access is decided by the control plane, never here.
 *
 * `POST /admin/packages` and `PUT /admin/packages/{id}` take the whole package, so the
 * form is seeded from this page's own read response and sends back everything it read
 * plus the operator's changes. That round trip is the correctness property the write
 * rests on and is asserted in `tests/unit/packageAdmin.spec.ts`.
 *
 * Publishing a package whose margin is not established is refused with a 409 and rolled
 * back, so the written justification the control plane requires is asked for before the
 * operator loses their edits.
 *
 * Every figure displayed comes from the response as-is. The only arithmetic performed
 * in the browser is basis-point-to-percent on an integer, which is exact, and the
 * clearly-labelled margin projection inside the form.
 */
definePageMeta({
  layout: 'dashboard',
  middleware: ['auth']
})

useSeoMeta({
  title: 'Packages',
  description: 'SP Cambo catalogue oversight: package publication state and margin analysis.',
  robots: 'noindex, nofollow'
})

const api = useSpApi()
const toast = useToast()

const packages = await useSpResource('admin:packages', () => api.admin.packages(), { server: false })

/**
 * Loaded for the form, not for this page's own display.
 *
 * The write contract takes the control plane's integer alias ids, and the margin
 * projection needs each alias's upstream cost, so both come from the same read the
 * model-pricing page uses. A failure here disables editing rather than letting the
 * operator save a model selection that was never read back.
 */
const aliases = await useSpResource('admin:model-aliases', () => api.admin.modelAliases(), { server: false })

/** Enabled *and* customer-visible: exactly the condition the backend gates publication on. */
const isLive = isPackageLive

/**
 * A package that customers can buy right now without established profitability.
 *
 * Covers both failure modes: a margin that was computed and came in under the
 * package's own floor, and a margin that could not be computed at all because an
 * allowed alias has no verified upstream cost. The second is not the safer case —
 * it means the cost is unknown, not that it is low.
 */
const isAtRisk = isPackageAtRisk

const all = computed(() => packages.data.value ?? [])
const atRisk = computed(() => all.value.filter(isAtRisk))
const live = computed(() => all.value.filter(isLive))

/** Aliases anywhere in the catalogue whose upstream cost has never been verified. */
const aliasesMissingCost = computed(() => aliasesMissingUpstreamCost(all.value))

type Filter = 'all' | 'live' | 'at_risk' | 'unpublished'

const filters: Array<{ value: Filter, label: string }> = [
  { value: 'all', label: 'All packages' },
  { value: 'live', label: 'On sale' },
  { value: 'at_risk', label: 'Needs attention' },
  { value: 'unpublished', label: 'Not on sale' }
]

const filter = ref<Filter>('all')

/**
 * Server order is preserved inside every filter — the control plane sorts by
 * `sort_order` then id, which is the order customers see. Re-sorting here would
 * misrepresent the catalogue.
 */
const visible = computed(() => {
  switch (filter.value) {
    case 'live':
      return live.value
    case 'at_risk':
      return atRisk.value
    case 'unpublished':
      return all.value.filter(item => !isLive(item))
    default:
      return all.value
  }
})

/** Money in a package's own currency. The profitability figures carry no currency. */
const inPackageCurrency = (item: AdminPackage, minor: string | null) =>
  minor === null ? null : { minor, currency: item.price.currency, exponent: item.price.exponent }

/**
 * Publication state as a single badge.
 *
 * Derived from two independent booleans rather than a backend status string, so it
 * is labelled here rather than through `SpStatusBadge` — collapsing the pair would
 * hide a real distinction: a disabled package sells to nobody, while an enabled but
 * hidden one still honours a direct link or an existing order flow.
 */
const publicationBadge = (item: AdminPackage): { color: 'success' | 'warning' | 'neutral', label: string } => {
  if (!item.enabled) {
    return { color: 'neutral', label: 'Disabled' }
  }

  return item.customer_visible
    ? { color: 'success', label: 'On sale' }
    : { color: 'warning', label: 'Enabled · hidden' }
}

/**
 * The margin verdict, stated only as far as the analysis went.
 *
 * `reviewable: false` is reported as unknown rather than as a failure, because
 * nothing has been found wrong — the cost inputs simply are not there yet.
 */
const marginVerdict = (item: AdminPackage) => {
  const { reviewable, profitable, margin_bps: marginBps, minimum_margin_bps: floor } = item.profitability

  if (!reviewable) {
    return {
      tone: 'neutral' as const,
      label: 'Margin unknown',
      detail: 'Upstream cost is not verified for every allowed model, so no margin can be calculated.'
    }
  }

  if (profitable === null) {
    return {
      tone: 'neutral' as const,
      label: 'Margin unknown',
      detail: 'The package price is zero, so a margin percentage cannot be expressed.'
    }
  }

  return profitable
    ? {
        tone: 'success' as const,
        label: `Margin ${formatBasisPoints(marginBps)}`,
        detail: `At or above the ${formatBasisPoints(floor)} floor set on this package.`
      }
    : {
        tone: 'error' as const,
        label: `Margin ${formatBasisPoints(marginBps)}`,
        detail: `Below the ${formatBasisPoints(floor)} floor set on this package.`
      }
}

const verdictClass = {
  success: 'text-success',
  error: 'text-error',
  neutral: 'text-muted'
}

interface LimitRow {
  label: string
  value: number
  format: (value: number) => string
}

/** Only the limits the control plane actually stored; an absent key is not a zero. */
const limitRows = (item: AdminPackage): LimitRow[] => {
  const limits = item.limits ?? {}

  const rows: Array<Omit<LimitRow, 'value'> & { value: number | undefined }> = [
    { label: 'Requests / minute', value: limits.requests_per_minute, format: formatCount },
    { label: 'Tokens / minute', value: limits.tokens_per_minute, format: formatCount },
    { label: 'Concurrency', value: limits.concurrency, format: formatCount },
    { label: 'Max request size', value: limits.max_request_bytes, format: formatBytes },
    { label: 'Max output tokens', value: limits.max_output_tokens, format: formatCount }
  ]

  return rows.filter((row): row is LimitRow => typeof row.value === 'number')
}

/**
 * Metering weights, in microunits per token.
 *
 * Zero is meaningful here and must be shown: a zero weight means that token class
 * is not metered at all, which is a pricing decision rather than a missing value.
 */
const billingRuleRows = (item: AdminPackage): Array<{ label: string, value: number }> => {
  const rules = item.billing_rules ?? {}

  const rows: Array<{ label: string, value: number | undefined }> = [
    { label: 'Input', value: rules.input_weight_microunits },
    { label: 'Output', value: rules.output_weight_microunits },
    { label: 'Cache read', value: rules.cache_read_weight_microunits },
    { label: 'Cache write', value: rules.cache_write_weight_microunits },
    { label: 'Reasoning', value: rules.reasoning_weight_microunits }
  ]

  return rows.filter((row): row is { label: string, value: number } => typeof row.value === 'number')
}

/** ------------------------------------------------------------- the editor */

const formOpen = ref(false)
const saving = ref(false)
const formError = ref<string | null>(null)
const formSeed = ref<PackageFormState>(emptyPackageForm())
const formRef = useTemplateRef<{ setServerErrors: (errors: Record<string, string[]>) => void }>('formRef')

/** The package being replaced, or null when the save creates a new one. */
const editingId = ref<string | null>(null)
const heading = ref('New package')
const submitLabel = ref('Create package')

/**
 * Slugs held by *other* packages.
 *
 * The package being edited is excluded, or re-saving it unchanged would report a
 * conflict with itself.
 */
const existingSlugs = computed(() =>
  all.value.filter(item => item.id !== editingId.value).map(item => item.slug))

const open = (seed: PackageFormState, options: { id: string | null, heading: string, submitLabel: string }) => {
  formSeed.value = seed
  editingId.value = options.id
  heading.value = options.heading
  submitLabel.value = options.submitLabel
  formError.value = null
  formOpen.value = true
}

const openCreate = async () => {
  // Public aliases can be created from the Provider page while this Nuxt resource is
  // still cached. Refresh before opening so newly discovered/imported models appear.
  await aliases.refresh()
  open(emptyPackageForm(), {
    id: null,
    heading: 'New package',
    submitLabel: 'Create package'
  })
}

const refreshCatalogueData = async () => {
  await Promise.all([packages.refresh(), aliases.refresh()])
}

const openEdit = (item: AdminPackage) => open(packageFormFrom(item), {
  id: item.id,
  heading: `Edit ${item.name}`,
  submitLabel: 'Save package'
})

const openClone = (item: AdminPackage) => open(clonePackageForm(item), {
  id: null,
  heading: `Copy of ${item.name}`,
  submitLabel: 'Create package'
})

const addingStockId = ref<string | null>(null)

const addStock = async (item: AdminPackage) => {
  if (item.stock_quantity === null || addingStockId.value) return

  const raw = window.prompt(`How many units of stock do you want to add to ${item.name}?`, '10')
  if (raw === null) return
  const quantity = Number(raw.trim())
  if (!Number.isSafeInteger(quantity) || quantity < 1) {
    toast.add({ title: 'Invalid stock quantity', description: 'Enter a positive whole number.', color: 'warning' })
    return
  }

  const reason = window.prompt('Reason for this stock change (required for audit log):', 'Restocked package inventory for customer sales.')
  if (!reason || reason.trim().length < 10) return

  addingStockId.value = item.id
  try {
    const saved = await api.admin.addPackageStock(item.id, quantity, reason.trim())
    await packages.refresh()
    toast.add({
      title: item.stock_quantity === '0' ? `${saved.name} is back in stock` : `Stock added to ${saved.name}`,
      description: `Available stock: ${saved.stock_quantity ?? 'Unlimited'}. Telegram subscribers will receive the matching store update with Buy now.`,
      color: 'success',
      icon: 'i-lucide-package-plus'
    })
  } catch (cause) {
    const error = toSpApiError(cause)
    toast.add({ title: 'Could not add stock', description: error.message, color: 'error' })
  } finally {
    addingStockId.value = null
  }
}

const submit = async (input: AdminPackageInput) => {
  const id = editingId.value

  saving.value = true
  formError.value = null

  try {
    const saved = id === null
      ? await api.admin.createPackage(input)
      : await api.admin.updatePackage(id, input)

    formOpen.value = false
    await packages.refresh()

    toast.add({
      title: id === null ? `${saved.name} created` : `${saved.name} saved`,
      // The authoritative verdict is the one the control plane just recomputed, not
      // the projection the form showed while typing.
      description: isLive(saved)
        ? `On sale now. ${saved.profitability.reviewable
          ? `Margin ${formatBasisPoints(saved.profitability.margin_bps)}.`
          : 'Margin could not be calculated for it.'}`
        : 'Saved but not on sale: it is disabled or hidden from customers.',
      color: isAtRisk(saved) ? 'warning' : 'success',
      icon: isAtRisk(saved) ? 'i-lucide-triangle-alert' : 'i-lucide-circle-check'
    })
  } catch (cause) {
    const error = toSpApiError(cause)

    formRef.value?.setServerErrors(error.errors)

    /*
     * A 409 profitability refusal is not a field fault and rolls the whole write back,
     * so it is stated as a banner rather than marked on an input. Nothing was saved.
     */
    formError.value = error.isValidation ? null : error.message
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <SpDashboardPage
    title="Packages"
    icon="i-lucide-package"
    description="Every package in the catalogue, including ones customers cannot see, with the margin analysis the control plane uses to gate publication."
  >
    <template #actions>
      <UButton
        color="neutral"
        variant="subtle"
        icon="i-lucide-refresh-cw"
        :loading="packages.loading.value || aliases.loading.value"
        @click="refreshCatalogueData"
      >
        Refresh
      </UButton>
      <UButton
        icon="i-lucide-plus"
        :disabled="packages.forbidden.value || packages.unavailable.value"
        @click="openCreate()"
      >
        New package
      </UButton>
    </template>

    <SpAsyncSection
      :loading="packages.initialLoading.value"
      :unavailable="packages.unavailable.value"
      :forbidden="packages.forbidden.value"
      :failed="packages.failed.value"
      :empty="packages.isEmpty.value"
      :offline="packages.error.value?.code === 'network_unreachable'"
      :error-message="packages.error.value?.message"
      error-title="The package catalogue could not be loaded"
      forbidden-permission="catalog.manage"
      :forbidden-code="packages.error.value?.code"
      empty-title="No packages exist yet"
      empty-description="Nothing is for sale until a package is created. Use New package above to create the first one."
      empty-icon="i-lucide-package"
      loading-variant="cards"
      :loading-count="3"
      @retry="packages.refresh()"
    >
      <div class="space-y-6">
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
          <SpMetric
            label="Packages"
            icon="i-lucide-package"
            :value="formatCount(all.length)"
            hint="Including hidden and disabled"
          />
          <SpMetric
            label="On sale"
            icon="i-lucide-shopping-cart"
            :value="formatCount(live.length)"
            hint="Enabled and customer-visible"
          />
          <SpMetric
            label="Needs attention"
            icon="i-lucide-triangle-alert"
            :value="formatCount(atRisk.length)"
            hint="On sale without established margin"
            :tone="atRisk.length > 0 ? 'error' : 'success'"
          />
          <SpMetric
            label="Models missing cost"
            icon="i-lucide-circle-help"
            :value="formatCount(aliasesMissingCost.length)"
            hint="No verified upstream cost"
            :tone="aliasesMissingCost.length > 0 ? 'warning' : 'success'"
          />
          <SpMetric
            label="Sold out"
            icon="i-lucide-package-x"
            :value="formatCount(all.filter(item => item.stock_quantity === '0').length)"
            hint="Finite packages with no stock"
            :tone="all.some(item => item.stock_quantity === '0') ? 'warning' : 'success'"
          />
        </div>

        <UAlert
          v-if="atRisk.length > 0"
          color="error"
          variant="subtle"
          icon="i-lucide-triangle-alert"
          :title="`${formatCount(atRisk.length)} package${atRisk.length === 1 ? ' is' : 's are'} on sale without an established margin`"
          :description="`Customers can buy ${atRisk.length === 1 ? 'it' : 'them'} right now. Each was published with a written override, shown on the package below. Verify the upstream cost of the listed models, or withdraw the package from sale.`"
        />

        <UAlert
          v-if="aliasesMissingCost.length > 0"
          color="warning"
          variant="subtle"
          icon="i-lucide-circle-help"
          title="Some models have no verified upstream cost"
          :description="`Profitability cannot be calculated for any package that allows ${aliasesMissingCost.length === 1 ? 'this model' : 'these models'}: ${aliasesMissingCost.join(', ')}. Record the upstream rates and a verification date against each model alias.`"
          :actions="[{
            label: 'Open model pricing',
            to: '/admin/model-aliases',
            color: 'neutral',
            variant: 'subtle',
            icon: 'i-lucide-route'
          }]"
        />

        <div class="flex flex-wrap items-center justify-between gap-3">
          <SpSectionHeading
            title="Catalogue"
            description="Shown in the order customers see, as sorted by the control plane."
            :level="3"
          />

          <USelectMenu
            v-model="filter"
            :items="filters"
            value-key="value"
            class="w-full sm:w-56"
            aria-label="Filter packages"
          />
        </div>

        <p
          v-if="visible.length === 0"
          class="rounded-lg border border-dashed border-default px-4 py-8 text-center text-sm text-muted"
        >
          No package matches this filter.
        </p>

        <ul
          v-else
          class="space-y-4"
        >
          <li
            v-for="item in visible"
            :key="item.id"
            class="sp-admin-record overflow-hidden rounded-xl border bg-elevated/30"
            :class="isAtRisk(item) ? 'border-error/40' : 'border-default'"
          >
            <div class="flex flex-col gap-4 border-b border-default p-5 sm:flex-row sm:items-start sm:justify-between">
              <div class="min-w-0 space-y-2">
                <div class="flex flex-wrap items-center gap-2">
                  <h3 class="font-medium text-highlighted">
                    {{ item.name }}
                  </h3>
                  <UBadge
                    :color="publicationBadge(item).color"
                    variant="subtle"
                    size="sm"
                  >
                    {{ publicationBadge(item).label }}
                  </UBadge>
                  <UBadge
                    color="neutral"
                    variant="subtle"
                    size="sm"
                  >
                    {{ item.billing_mode === 'TOKEN_QUOTA' ? 'Token quota' : 'Credit balance' }}
                  </UBadge>
                  <UBadge
                    :color="item.stock_quantity === '0' ? 'warning' : 'neutral'"
                    variant="subtle"
                    size="sm"
                  >
                    {{ item.stock_quantity === null ? 'Unlimited stock' : item.stock_quantity === '0' ? 'Sold out' : `${formatCount(Number(item.stock_quantity))} in stock` }}
                  </UBadge>
                </div>
                <code class="block font-mono text-xs text-dimmed">{{ item.slug }}</code>
              </div>

              <div class="shrink-0 space-y-2 text-left sm:text-right">
                <p class="sp-numeric text-xl font-semibold text-highlighted">
                  {{ formatMoney(item.price) }}
                </p>
                <p class="text-xs text-muted">
                  {{ formatDurationSeconds(item.duration_seconds) }} from activation
                </p>
                <div class="flex gap-2 sm:justify-end">
                  <UButton
                    color="neutral"
                    variant="subtle"
                    icon="i-lucide-pencil"
                    size="sm"
                    @click="openEdit(item)"
                  >
                    Edit
                  </UButton>
                  <UButton
                    v-if="item.stock_quantity !== null"
                    color="neutral"
                    variant="subtle"
                    icon="i-lucide-package-plus"
                    size="sm"
                    :loading="addingStockId === item.id"
                    @click="addStock(item)"
                  >
                    Add stock
                  </UButton>
                  <UButton
                    color="neutral"
                    variant="ghost"
                    icon="i-lucide-copy"
                    size="sm"
                    :aria-label="`Copy ${item.name} into a new draft`"
                    @click="openClone(item)"
                  >
                    Copy
                  </UButton>
                </div>
              </div>
            </div>

            <dl class="grid gap-x-6 gap-y-4 border-b border-default p-5 sm:grid-cols-2 lg:grid-cols-4">
              <div>
                <dt class="text-xs text-dimmed">
                  Advertised units
                </dt>
                <dd class="sp-numeric text-sm text-default">
                  {{ formatUnits(item.advertised_units) }}
                </dd>
              </div>
              <div>
                <dt class="text-xs text-dimmed">
                  Package stock
                </dt>
                <dd class="sp-numeric text-sm text-default">
                  {{ item.stock_quantity === null ? 'Unlimited' : formatCount(Number(item.stock_quantity)) }}
                </dd>
              </div>
              <div>
                <dt class="text-xs text-dimmed">
                  Worst-case upstream cost
                </dt>
                <dd class="sp-numeric text-sm text-default">
                  {{
                    item.profitability.worst_case_cost_minor === null
                      ? 'Unknown'
                      : formatMoney(inPackageCurrency(item, item.profitability.worst_case_cost_minor))
                  }}
                </dd>
              </div>
              <div>
                <dt class="text-xs text-dimmed">
                  Margin
                </dt>
                <dd
                  class="sp-numeric text-sm"
                  :class="verdictClass[marginVerdict(item).tone]"
                >
                  {{
                    item.profitability.margin_minor === null
                      ? 'Unknown'
                      : formatMoney(inPackageCurrency(item, item.profitability.margin_minor))
                  }}
                </dd>
              </div>
              <div>
                <dt class="text-xs text-dimmed">
                  Margin floor
                </dt>
                <dd class="sp-numeric text-sm text-default">
                  {{ formatBasisPoints(item.minimum_margin_bps) }}
                </dd>
              </div>
            </dl>

            <div class="space-y-4 p-5">
              <p
                class="flex flex-wrap items-baseline gap-x-2 text-sm"
                :class="verdictClass[marginVerdict(item).tone]"
              >
                <span class="font-medium">{{ marginVerdict(item).label }}</span>
                <span class="text-muted">{{ marginVerdict(item).detail }}</span>
              </p>

              <div
                v-if="item.profitability.missing_cost_aliases.length > 0"
                class="space-y-1.5"
              >
                <p class="text-xs text-dimmed">
                  Models with no verified upstream cost
                </p>
                <div class="flex flex-wrap gap-1.5">
                  <UBadge
                    v-for="alias in item.profitability.missing_cost_aliases"
                    :key="alias"
                    color="warning"
                    variant="subtle"
                    size="sm"
                    class="font-mono"
                  >
                    {{ alias }}
                  </UBadge>
                </div>
              </div>

              <div class="space-y-1.5">
                <p class="text-xs text-dimmed">
                  Allowed models
                </p>
                <div
                  v-if="item.allowed_model_aliases.length > 0"
                  class="flex flex-wrap gap-1.5"
                >
                  <UBadge
                    v-for="alias in item.allowed_model_aliases"
                    :key="alias"
                    color="neutral"
                    variant="subtle"
                    size="sm"
                    class="font-mono"
                  >
                    {{ alias }}
                  </UBadge>
                </div>
                <p
                  v-else
                  class="text-sm text-warning"
                >
                  None. This package grants access to no model, so it cannot serve a request.
                </p>
              </div>

              <div class="grid gap-4 sm:grid-cols-2">
                <div
                  v-if="limitRows(item).length > 0"
                  class="space-y-1.5"
                >
                  <p class="text-xs text-dimmed">
                    Per-key limits
                  </p>
                  <dl class="space-y-1">
                    <div
                      v-for="row in limitRows(item)"
                      :key="row.label"
                      class="flex justify-between gap-3 text-sm"
                    >
                      <dt class="text-muted">
                        {{ row.label }}
                      </dt>
                      <dd class="sp-numeric text-default">
                        {{ row.format(row.value) }}
                      </dd>
                    </div>
                  </dl>
                </div>

                <div
                  v-if="billingRuleRows(item).length > 0"
                  class="space-y-1.5"
                >
                  <p class="text-xs text-dimmed">
                    Metering weights
                    <span class="text-dimmed">· microunits per token</span>
                  </p>
                  <dl class="space-y-1">
                    <div
                      v-for="row in billingRuleRows(item)"
                      :key="row.label"
                      class="flex justify-between gap-3 text-sm"
                    >
                      <dt class="text-muted">
                        {{ row.label }}
                      </dt>
                      <dd class="sp-numeric text-default">
                        {{ formatCount(row.value) }}
                      </dd>
                    </div>
                  </dl>
                </div>
              </div>

              <div
                v-if="item.profitability_override_reason"
                class="rounded-lg bg-warning/10 px-4 py-3"
              >
                <p class="text-xs font-medium text-warning">
                  Published with a profitability override
                </p>
                <p class="mt-1 text-sm text-default">
                  {{ item.profitability_override_reason }}
                </p>
              </div>
            </div>
          </li>
        </ul>
      </div>
    </SpAsyncSection>

    <SpAdminPackageForm
      ref="formRef"
      v-model:open="formOpen"
      :initial="formSeed"
      :heading="heading"
      description="The whole package is replaced on save, so every field below is sent — including the ones you did not change. The margin shown is a projection; the control plane recomputes it."
      :submit-label="submitLabel"
      :aliases="aliases.data.value ?? []"
      :aliases-unavailable="aliases.failed.value || aliases.unavailable.value || aliases.forbidden.value"
      :existing-slugs="existingSlugs"
      :saving="saving"
      :error-message="formError"
      @submit="submit"
    />
  </SpDashboardPage>
</template>
