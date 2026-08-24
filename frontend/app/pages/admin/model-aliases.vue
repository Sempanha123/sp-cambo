<script setup lang="ts">
import type { FormError } from '@nuxt/ui'
import type { AdminModelAlias } from '~/types/admin'
import type { AliasPricingFormState } from '~/utils/modelAliasAdmin'

/**
 * Model pricing: what customers are charged per million tokens, and what SP Cambo
 * pays upstream for the same request.
 *
 * Requires the `catalog.manage` permission. Access is decided by the control plane,
 * never here.
 *
 * This is the page that resolves "Margin unknown" on `/admin/packages`. An alias whose
 * upstream cost is unverified counts as having no known cost at all, so every package
 * allowing it becomes unreviewable and can only be published with a written override.
 * Recording the upstream rates and a verification date here is what removes that.
 *
 * Every rate is an exact integer in minor units of the record's own currency, kept as
 * a string end to end. Provider identity and internal routes are not part of this
 * contract and are neither shown nor inferred.
 */
definePageMeta({
  layout: 'dashboard',
  middleware: ['auth']
})

useSeoMeta({
  title: 'Model pricing',
  description: 'SP Cambo model pricing: customer rates, upstream cost and cost verification.',
  robots: 'noindex, nofollow'
})

const api = useSpApi()
const toast = useToast()

const aliases = await useSpResource('admin:model-aliases', () => api.admin.modelAliases(), { server: false })

const all = computed(() => aliases.data.value ?? [])
const priced = computed(() => all.value.filter(alias => !isAliasUnpriced(alias)))
const onSale = computed(() => all.value.filter(alias => alias.enabled && alias.customer_visible))
const needingVerification = computed(() => aliasesNeedingCostVerification(all.value))

type Filter = 'all' | 'needs_cost' | 'unpriced' | 'on_sale'

const filters: Array<{ value: Filter, label: string }> = [
  { value: 'all', label: 'All models' },
  { value: 'needs_cost', label: 'Needs cost verification' },
  { value: 'unpriced', label: 'Not priced' },
  { value: 'on_sale', label: 'Sold to customers' }
]

const filter = ref<Filter>('all')

/**
 * Server order is preserved inside every filter — the control plane sorts by public
 * alias, which is how operators refer to these.
 */
const visible = computed(() => {
  switch (filter.value) {
    case 'needs_cost':
      return needingVerification.value
    case 'unpriced':
      return all.value.filter(isAliasUnpriced)
    case 'on_sale':
      return onSale.value
    default:
      return all.value
  }
})

/** A rate in the alias's own currency, or null when the alias has no pricing record. */
const rateMoney = (alias: AdminModelAlias, minor: string | null) => {
  if (minor === null || alias.currency === null || alias.exponent === null) {
    return null
  }

  return { minor, currency: alias.currency, exponent: alias.exponent }
}

const rateLabel = (alias: AdminModelAlias, minor: string | null) => {
  const money = rateMoney(alias, minor)

  return money === null ? 'Not set' : formatMoney(money)
}

interface RateRow {
  label: string
  sell: string | null
  upstream: string | null
}

/** Both sides of one pricing record, class by class, exactly as returned. */
const rateRows = (alias: AdminModelAlias): RateRow[] => [
  { label: 'Input', sell: alias.sell?.input_per_million_minor ?? null, upstream: alias.upstream_cost?.input_per_million_minor ?? null },
  { label: 'Output', sell: alias.sell?.output_per_million_minor ?? null, upstream: alias.upstream_cost?.output_per_million_minor ?? null },
  { label: 'Cache read', sell: alias.sell?.cache_read_per_million_minor ?? null, upstream: alias.upstream_cost?.cache_read_per_million_minor ?? null },
  { label: 'Cache write', sell: alias.sell?.cache_write_per_million_minor ?? null, upstream: alias.upstream_cost?.cache_write_per_million_minor ?? null },
  { label: 'Reasoning', sell: alias.sell?.reasoning_per_million_minor ?? null, upstream: alias.upstream_cost?.reasoning_per_million_minor ?? null }
]

/**
 * Whether this alias is currently usable as a costed input to package profitability.
 *
 * Unverified is reported as unknown rather than as a fault: nothing has been found
 * wrong, the cost check simply has not been done.
 */
const costState = (alias: AdminModelAlias): { color: 'success' | 'warning' | 'neutral', label: string } => {
  if (isAliasUnpriced(alias)) {
    return { color: 'neutral', label: 'Not priced' }
  }

  return alias.upstream_cost?.verified_at
    ? { color: 'success', label: 'Cost verified' }
    : { color: 'warning', label: 'Cost unverified' }
}

/** --------------------------------------------------------------- the editor */

const formOpen = ref(false)
const saving = ref(false)
const form = ref<AliasPricingFormState>(emptyAliasPricingForm())
const formError = ref<string | null>(null)
const editing = ref<AdminModelAlias | null>(null)
const formRef = useTemplateRef<{ setErrors: (errors: FormError[]) => void }>('formRef')

/** The instant the loaded alias recorded, so an untouched date is sent back verbatim. */
const originalVerifiedAt = ref<string | null>(null)

const openEdit = (alias: AdminModelAlias) => {
  editing.value = alias
  form.value = aliasPricingFormFrom(alias)
  originalVerifiedAt.value = alias.upstream_cost?.verified_at ?? null
  formError.value = null
  formRef.value?.setErrors([])
  formOpen.value = true
}

const validate = (state: AliasPricingFormState): FormError[] => aliasPricingProblems(state)

/** Live sell-versus-cost, recomputed as the operator types. Exact integers only. */
const comparisons = computed(() => rateComparisons(form.value))
const advisories = computed(() => aliasPricingAdvisories(form.value))

/** Display money for a draft rate, in the currency the form currently states. */
const draftMoney = (minor: string | null) => {
  if (minor === null) {
    return 'Unknown'
  }

  const exponent = parseOptionalInteger(form.value.exponent)

  if (typeof exponent !== 'number' || !/^[A-Za-z]{3}$/.test(form.value.currency.trim())) {
    return formatMinorUnits(minor)
  }

  return formatMoney({ minor, currency: form.value.currency.trim().toUpperCase(), exponent })
}

const submit = async () => {
  const target = editing.value

  if (!target) {
    return
  }

  saving.value = true
  formError.value = null

  try {
    const saved = await api.admin.updateModelAliasPricing(
      target.id,
      buildAliasPricingInput(form.value, { originalVerifiedAt: originalVerifiedAt.value })
    )

    formOpen.value = false
    await aliases.refresh()

    toast.add({
      title: `Pricing saved for ${saved.public_alias}`,
      description: saved.upstream_cost?.verified_at
        ? 'Upstream cost is verified, so packages allowing this model can have their margin calculated.'
        : 'Upstream cost is not verified, so packages allowing this model still have no calculable margin.',
      color: saved.upstream_cost?.verified_at ? 'success' : 'warning',
      icon: saved.upstream_cost?.verified_at ? 'i-lucide-circle-check' : 'i-lucide-circle-help'
    })
  } catch (cause) {
    const error = toSpApiError(cause)

    formRef.value?.setErrors(
      Object.entries(error.errors).map(([name, messages]) => ({
        name: aliasPricingFieldName(name),
        message: messages[0] ?? 'This value is not valid.'
      }))
    )

    formError.value = error.isValidation ? null : error.message
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <SpDashboardPage
    title="Model pricing"
    icon="i-lucide-route"
    description="What each model costs a customer per million tokens, and what SP Cambo pays upstream for the same tokens. Package margin is calculated from the upstream side, and only once it has been verified."
  >
    <template #actions>
      <UButton
        color="neutral"
        variant="subtle"
        icon="i-lucide-refresh-cw"
        :loading="aliases.loading.value"
        @click="aliases.refresh()"
      >
        Refresh
      </UButton>
    </template>

    <SpAsyncSection
      :loading="aliases.initialLoading.value"
      :unavailable="aliases.unavailable.value"
      :forbidden="aliases.forbidden.value"
      :failed="aliases.failed.value"
      :empty="aliases.isEmpty.value"
      :offline="aliases.error.value?.code === 'network_unreachable'"
      :error-message="aliases.error.value?.message"
      error-title="Model pricing could not be loaded"
      forbidden-permission="catalog.manage"
      :forbidden-code="aliases.error.value?.code"
      empty-title="No models exist yet"
      empty-description="Models are registered in the control plane. This page prices the ones that exist; it does not create them."
      empty-icon="i-lucide-route"
      loading-variant="cards"
      :loading-count="3"
      @retry="aliases.refresh()"
    >
      <div class="space-y-6">
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          <SpMetric
            label="Models"
            icon="i-lucide-route"
            :value="formatCount(all.length)"
            hint="Including hidden and disabled"
          />
          <SpMetric
            label="Sold to customers"
            icon="i-lucide-badge-check"
            :value="formatCount(onSale.length)"
            hint="Enabled and customer-visible"
          />
          <SpMetric
            label="Priced"
            icon="i-lucide-wallet"
            :value="formatCount(priced.length)"
            hint="Has a pricing record"
            :tone="priced.length < all.length ? 'warning' : 'success'"
          />
          <SpMetric
            label="Needs cost verification"
            icon="i-lucide-circle-help"
            :value="formatCount(needingVerification.length)"
            hint="On sale, upstream cost unverified"
            :tone="needingVerification.length > 0 ? 'warning' : 'success'"
          />
        </div>

        <UAlert
          v-if="needingVerification.length > 0"
          color="warning"
          variant="subtle"
          icon="i-lucide-circle-help"
          :title="`${formatCount(needingVerification.length)} model${needingVerification.length === 1 ? '' : 's'} on sale with no verified upstream cost`"
          :description="`Package margin cannot be calculated for any package that allows ${needingVerification.length === 1 ? 'it' : 'them'}: ${needingVerification.map(alias => alias.public_alias).join(', ')}. Record the upstream rates and the date they were checked, and publication no longer needs a written override.`"
        />

        <div class="flex flex-wrap items-center justify-between gap-3">
          <SpSectionHeading
            title="Models"
            description="Shown in the order the control plane returns, by public alias."
            :level="3"
          />

          <USelectMenu
            v-model="filter"
            :items="filters"
            value-key="value"
            class="w-full sm:w-64"
            aria-label="Filter models"
          />
        </div>

        <p
          v-if="visible.length === 0"
          class="rounded-lg border border-dashed border-default px-4 py-8 text-center text-sm text-muted"
          role="status"
        >
          No model matches this filter.
        </p>

        <ul
          v-else
          class="space-y-4"
        >
          <li
            v-for="alias in visible"
            :key="alias.id"
            class="overflow-hidden rounded-xl border bg-elevated/30"
            :class="costState(alias).color === 'warning' ? 'border-warning/40' : 'border-default'"
          >
            <div class="flex flex-col gap-4 border-b border-default p-5 sm:flex-row sm:items-start sm:justify-between">
              <div class="min-w-0 space-y-2">
                <div class="flex flex-wrap items-center gap-2">
                  <h3 class="font-medium text-highlighted">
                    {{ alias.display_name }}
                  </h3>
                  <SpStatusBadge :status="alias.status" />
                  <UBadge
                    :color="costState(alias).color"
                    variant="subtle"
                    size="sm"
                  >
                    {{ costState(alias).label }}
                  </UBadge>
                  <UBadge
                    v-if="!alias.enabled"
                    color="neutral"
                    variant="subtle"
                    size="sm"
                  >
                    Disabled
                  </UBadge>
                  <UBadge
                    v-else-if="!alias.customer_visible"
                    color="warning"
                    variant="subtle"
                    size="sm"
                  >
                    Enabled · hidden
                  </UBadge>
                </div>
                <code class="block font-mono text-xs text-dimmed">{{ alias.public_alias }}</code>
              </div>

              <UButton
                color="neutral"
                variant="subtle"
                icon="i-lucide-pencil"
                size="sm"
                class="shrink-0"
                @click="openEdit(alias)"
              >
                {{ isAliasUnpriced(alias) ? 'Set pricing' : 'Edit pricing' }}
              </UButton>
            </div>

            <div
              v-if="isAliasUnpriced(alias)"
              class="p-5"
            >
              <p class="text-sm text-muted">
                This model has no pricing record, so it has no customer rate and no known upstream cost.
                It is not the same as being free — nothing has been priced at all.
              </p>
            </div>

            <div
              v-else
              class="space-y-4 p-5"
            >
              <div class="overflow-x-auto">
                <table class="w-full min-w-md text-sm">
                  <caption class="sr-only">
                    Customer rate and upstream cost per million tokens for {{ alias.public_alias }}
                  </caption>
                  <thead>
                    <tr class="text-left text-xs text-dimmed">
                      <th
                        scope="col"
                        class="pb-2 font-normal"
                      >
                        Per million tokens
                      </th>
                      <th
                        scope="col"
                        class="pb-2 text-right font-normal"
                      >
                        Customer rate
                      </th>
                      <th
                        scope="col"
                        class="pb-2 text-right font-normal"
                      >
                        Upstream cost
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr
                      v-for="row in rateRows(alias)"
                      :key="row.label"
                      class="border-t border-default/60"
                    >
                      <th
                        scope="row"
                        class="py-1.5 text-left font-normal text-muted"
                      >
                        {{ row.label }}
                      </th>
                      <td class="sp-numeric py-1.5 text-right text-default">
                        {{ rateLabel(alias, row.sell) }}
                      </td>
                      <td class="sp-numeric py-1.5 text-right text-default">
                        {{ rateLabel(alias, row.upstream) }}
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>

              <p
                v-if="alias.upstream_cost?.verified_at"
                class="text-xs text-muted"
              >
                Upstream cost last verified {{ formatDateTime(alias.upstream_cost.verified_at) }}.
              </p>
              <p
                v-else
                class="text-xs text-warning"
              >
                Upstream cost has never been verified, so it counts as no known cost at all — whatever
                rates are recorded above. Every package allowing this model reports an unknown margin.
              </p>
            </div>
          </li>
        </ul>
      </div>
    </SpAsyncSection>

    <UModal
      v-model:open="formOpen"
      :title="editing ? `Pricing for ${editing.public_alias}` : 'Pricing'"
      description="Saving replaces the whole pricing record, not just the fields you changed. Every rate is in integer minor units per million tokens."
      :ui="{ content: 'max-w-2xl' }"
    >
      <template #body>
        <UForm
          ref="formRef"
          :state="form"
          :validate="validate"
          class="space-y-5"
          @submit="submit"
        >
          <UAlert
            v-if="formError"
            role="alert"
            icon="i-lucide-circle-alert"
            color="error"
            variant="subtle"
            :description="formError"
          />

          <div class="grid gap-4 sm:grid-cols-2">
            <UFormField
              label="Currency"
              name="currency"
              required
              help="Three-letter code. Applies to both sides of this record."
            >
              <UInput
                v-model="form.currency"
                maxlength="3"
                autocapitalize="characters"
                class="w-full"
              />
            </UFormField>
            <UFormField
              label="Currency exponent"
              name="exponent"
              required
              help="Decimal places: USD is 2; KHR is 0."
            >
              <UInput
                v-model="form.exponent"
                inputmode="numeric"
                class="w-full"
              />
            </UFormField>
          </div>

          <div class="space-y-3">
            <SpSectionHeading
              title="Customer rate"
              description="What a customer is charged per million tokens. Input and output are required; a blank optional class means no separate rate is recorded for it."
              :level="3"
            />

            <div class="grid gap-4 sm:grid-cols-2">
              <UFormField
                v-for="field in ALIAS_RATE_FIELDS"
                :key="`sell-${field.key}`"
                :label="field.label"
                :name="`sell.${field.key}`"
                :required="field.required"
              >
                <UInput
                  v-model="form.sell[field.key]"
                  inputmode="numeric"
                  :placeholder="field.required ? '0' : 'Not set'"
                  class="w-full"
                />
              </UFormField>
            </div>
          </div>

          <div class="space-y-3">
            <SpSectionHeading
              title="Upstream cost"
              description="What SP Cambo pays for the same tokens. Package margin is calculated from these, and only while the verification below is recorded."
              :level="3"
            />

            <div class="grid gap-4 sm:grid-cols-2">
              <UFormField
                v-for="field in ALIAS_RATE_FIELDS"
                :key="`upstream-${field.key}`"
                :label="field.label"
                :name="`upstream.${field.key}`"
              >
                <UInput
                  v-model="form.upstream[field.key]"
                  inputmode="numeric"
                  placeholder="Not set"
                  class="w-full"
                />
              </UFormField>
            </div>

            <div class="space-y-3 rounded-lg border border-default p-3">
              <UCheckbox
                v-model="form.upstream_verified"
                label="Upstream cost is verified"
                description="Confirms these rates were checked against the provider's own published prices. Without it every package allowing this model reports an unknown margin, however many rates are filled in."
              />

              <UFormField
                v-if="form.upstream_verified"
                label="Verified on"
                name="upstream_verified_date"
                required
                help="Recorded at 00:00:00 UTC on this date. Leaving the date as loaded keeps the exact instant already stored."
              >
                <UInput
                  v-model="form.upstream_verified_date"
                  type="date"
                  class="w-full sm:w-56"
                />
              </UFormField>
            </div>
          </div>

          <div class="space-y-2 rounded-lg border border-default p-3">
            <p class="text-xs text-dimmed">
              Margin per million tokens
              <span class="text-dimmed">· exact, in this record's currency</span>
            </p>
            <dl class="space-y-1">
              <div
                v-for="comparison in comparisons"
                :key="comparison.key"
                class="flex justify-between gap-3 text-sm"
              >
                <dt class="text-muted">
                  {{ comparison.label }}
                </dt>
                <dd
                  class="sp-numeric"
                  :class="comparison.marginMinor === null
                    ? 'text-dimmed'
                    : comparison.belowCost ? 'text-error' : 'text-success'"
                >
                  {{ comparison.marginMinor === null ? 'Unknown' : draftMoney(comparison.marginMinor) }}
                </dd>
              </div>
            </dl>
            <p class="text-xs text-muted">
              Unknown means one side has no rate recorded. That is not the same as free.
            </p>
          </div>

          <UAlert
            v-for="note in advisories"
            :key="note"
            role="status"
            icon="i-lucide-triangle-alert"
            color="warning"
            variant="subtle"
            :description="note"
          />

          <UFormField
            label="Why"
            name="reason"
            required
            help="At least 10 characters. Written to the audit trail against your account, with the rates before and after."
          >
            <UTextarea
              v-model="form.reason"
              :rows="3"
              placeholder="Provider published new rates on 20 August; checked against their pricing page."
              class="w-full"
            />
          </UFormField>

          <div class="flex justify-end gap-2 pt-1">
            <UButton
              color="neutral"
              variant="ghost"
              :disabled="saving"
              @click="formOpen = false"
            >
              Cancel
            </UButton>
            <UButton
              type="submit"
              :loading="saving"
            >
              Save pricing
            </UButton>
          </div>
        </UForm>
      </template>
    </UModal>
  </SpDashboardPage>
</template>
