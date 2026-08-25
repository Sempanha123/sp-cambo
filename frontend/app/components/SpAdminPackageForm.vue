<script setup lang="ts">
import type { FormError } from '@nuxt/ui'
import type { AdminModelAlias, AdminPackageInput } from '~/types/admin'
import type { BillingMode } from '~/types/commerce'
import type { DurationUnit, PackageFormState } from '~/utils/packageAdmin'

/**
 * The package write form.
 *
 * `POST /admin/packages` and `PUT /admin/packages/{id}` both take the **whole**
 * package, so this form is seeded from the read response and sends back everything it
 * read plus the operator's changes. A field this form fails to carry is a field the
 * operator erases by opening a package and pressing save; that round trip lives in
 * `~/utils/packageAdmin` and is asserted in `tests/unit/packageAdmin.spec.ts`.
 *
 * Two refusals are deliberate and are the reason this is a component rather than
 * inline markup:
 *
 * - **Model ids must resolve.** `allowed_model_alias_ids` takes the control plane's
 *   own integer ids. If any published id is not an integer the selection cannot be
 *   trusted, and saving is blocked rather than sending a guess that would silently
 *   change which models a package grants.
 * - **Publishing without an established margin needs a written reason.** The control
 *   plane answers 409 and rolls the write back, so the requirement is stated here
 *   before the operator loses their edits — not invented, mirrored.
 *
 * The margin shown while typing is a projection, labelled as one. The control plane
 * recomputes and returns the authoritative figure on every read and write.
 */
const props = defineProps<{
  open: boolean
  /** Seed state: a blank draft, a loaded package, or a clone. */
  initial: PackageFormState
  heading: string
  description: string
  submitLabel: string
  /** From `GET /admin/model-aliases`, so cost and publication state can be shown inline. */
  aliases: AdminModelAlias[]
  /** True while the alias list has not loaded, which is not the same as there being none. */
  aliasesUnavailable: boolean
  /** Slugs held by *other* packages. Must exclude the one being edited. */
  existingSlugs: string[]
  saving: boolean
  /** A non-validation failure, shown as a banner. */
  errorMessage: string | null
}>()

const emit = defineEmits<{
  'update:open': [boolean]
  'submit': [AdminPackageInput]
}>()

const form = ref<PackageFormState>(clonePackageFormState(props.initial))
const formRef = useTemplateRef<{ setErrors: (errors: FormError[]) => void }>('formRef')

/** A structural copy, so editing the form never mutates the caller's seed. */
function clonePackageFormState(state: PackageFormState): PackageFormState {
  return {
    ...state,
    limits: { ...state.limits },
    weights: { ...state.weights },
    allowed_model_alias_ids: [...state.allowed_model_alias_ids]
  }
}

/**
 * Re-seeded on every open rather than on every `initial` change, so a background
 * refresh of the catalogue cannot discard what the operator is part-way through typing.
 */
watch(() => props.open, (open) => {
  if (open) {
    form.value = clonePackageFormState(props.initial)
    formRef.value?.setErrors([])
  }
})

const BILLING_MODES: Array<{ value: BillingMode, label: string, description: string }> = [
  {
    value: 'TOKEN_QUOTA',
    label: 'Token quota',
    description: 'The customer buys a quantity of metered units that is drawn down per request.'
  },
  {
    value: 'CREDIT_BALANCE',
    label: 'Credit balance',
    description: 'The customer buys currency credit that is charged per request.'
  }
]

const DURATION_UNITS: Array<{ value: DurationUnit, label: string }> = [
  { value: 'day', label: 'Days' },
  { value: 'hour', label: 'Hours' },
  { value: 'second', label: 'Seconds' }
]

/**
 * The aliases available to allow, with their internal id as an integer.
 *
 * The id is the control plane's own; nothing about it is derived. An alias whose id is
 * not an integer is kept in the list and reported, because dropping it would make a
 * package look as though it allows fewer models than it does.
 */
const aliasChoices = computed(() => props.aliases.map(alias => ({
  id: Number(alias.id),
  idUsable: /^\d+$/.test(alias.id),
  publicAlias: alias.public_alias,
  displayName: alias.display_name,
  onSale: alias.publication_ready ?? (alias.enabled && alias.customer_visible),
  publicationBlockers: alias.publication_blockers ?? [],
  costVerified: alias.upstream_cost?.verified_at != null,
  unpriced: isAliasUnpriced(alias)
})))

/** False when any published id is not an integer, which makes the selection unsafe to send. */
const aliasIdsUsable = computed(() => aliasChoices.value.every(choice => choice.idUsable))

const selectedAliases = computed(() => {
  const selected = new Set(form.value.allowed_model_alias_ids)

  return props.aliases.filter(alias => selected.has(Number(alias.id)))
})

const toggleAlias = (id: number, selected: boolean) => {
  const current = new Set(form.value.allowed_model_alias_ids)

  if (selected) {
    current.add(id)
  } else {
    current.delete(id)
  }

  // Server order, so two packages with the same models read alike.
  form.value.allowed_model_alias_ids = aliasChoices.value
    .filter(choice => current.has(choice.id))
    .map(choice => choice.id)
}

/** ------------------------------------------------- projection and advisories */

const exponent = computed(() => parseOptionalInteger(form.value.currency_exponent))
const currencyCode = computed(() => form.value.currency.trim().toUpperCase())

/** Money in the currency the form currently states, or bare minor units if it does not. */
const draftMoney = (minor: string | null) => {
  if (minor === null) {
    return 'Unknown'
  }

  if (typeof exponent.value !== 'number' || !/^[A-Z]{3}$/.test(currencyCode.value)) {
    return formatMinorUnits(minor)
  }

  return formatMoney({ minor, currency: currencyCode.value, exponent: exponent.value })
}

const projection = computed(() => projectProfitability({
  priceMinor: form.value.price_minor.trim(),
  advertisedUnits: form.value.advertised_units.trim(),
  minimumMarginBps: parseOptionalInteger(form.value.minimum_margin_bps) ?? 0,
  aliases: selectedAliases.value
}))

const publishing = computed(() => willPublish(form.value))

const currencyMismatches = computed(() => typeof exponent.value === 'number'
  ? aliasCurrencyMismatches(selectedAliases.value, currencyCode.value, exponent.value)
  : [])

/**
 * Whether a written justification is required to save.
 *
 * Exactly the condition `PackageProfitabilityService::assertPublishable` gates on:
 * a package that is not going on sale is never blocked on margin, however unknown it is.
 */
const overrideRequired = computed(() => publishing.value && projection.value.overrideRequired)

const verdict = computed(() => {
  const { reviewable, profitable, marginBps } = projection.value

  if (!reviewable) {
    return {
      tone: 'neutral' as const,
      label: 'Margin unknown',
      detail: projection.value.missingCostAliases.length > 0
        ? 'Upstream cost is not verified for every selected model, so no margin can be projected.'
        : 'Select at least one model and enter a price and quantity to project a margin.'
    }
  }

  if (profitable === null) {
    return {
      tone: 'neutral' as const,
      label: 'Margin unknown',
      detail: 'The price is zero, so a margin percentage cannot be expressed.'
    }
  }

  return profitable
    ? {
        tone: 'success' as const,
        label: `Projected margin ${formatBasisPoints(marginBps)}`,
        detail: 'At or above the floor set below, so publishing needs no written override.'
      }
    : {
        tone: 'error' as const,
        label: `Projected margin ${formatBasisPoints(marginBps)}`,
        detail: 'Below the floor set below. Publishing will need a written override.'
      }
})

const verdictClass = {
  success: 'text-success',
  error: 'text-error',
  neutral: 'text-muted'
}

/** ------------------------------------------------------------- validation */

/**
 * The control plane's own rules, plus the publication gate it enforces with a 409.
 *
 * `packageFormProblems` mirrors `validated()`, which leaves the override reason
 * optional. The 409 is a separate refusal, so it is stated separately here — the
 * operator learns before submitting rather than after the write is rolled back.
 */
const validate = (state: PackageFormState): FormError[] => {
  const problems: FormError[] = packageFormProblems(state, { existingSlugs: props.existingSlugs })

  if (overrideRequired.value && state.profitability_override_reason.trim() === '') {
    problems.push({
      name: 'profitability_override_reason',
      message: 'Publishing without an established margin needs a written reason. '
        + 'The control plane refuses the write otherwise.'
    })
  }

  return problems
}

/** Maps a 422 back onto the fields that produced it. Exposed so the page owns the call. */
const setServerErrors = (errors: Record<string, string[]>) => {
  formRef.value?.setErrors(
    Object.entries(errors).map(([name, messages]) => ({
      name: packageFieldName(name),
      message: messages[0] ?? 'This value is not valid.'
    }))
  )
}

defineExpose({ setServerErrors })

const submit = () => {
  if (!aliasIdsUsable.value) {
    return
  }

  emit('submit', buildPackageInput(form.value))
}
</script>

<template>
  <UModal
    :open="open"
    :title="heading"
    :description="description"
    :ui="{ content: 'max-w-3xl' }"
    @update:open="emit('update:open', $event)"
  >
    <template #body>
      <UForm
        ref="formRef"
        :state="form"
        :validate="validate"
        class="space-y-6"
        @submit="submit"
      >
        <UAlert
          v-if="errorMessage"
          role="alert"
          icon="i-lucide-circle-alert"
          color="error"
          variant="subtle"
          :description="errorMessage"
        />

        <UAlert
          v-if="!aliasIdsUsable"
          role="alert"
          icon="i-lucide-shield-alert"
          color="error"
          variant="subtle"
          title="Model selection cannot be saved"
          description="The control plane published a model id that is not an integer, so the selection below cannot be sent without guessing. Saving is blocked rather than risk changing which models this package grants."
        />

        <!-- Identity -->
        <div class="space-y-4">
          <SpSectionHeading
            title="Identity"
            description="How the package is referred to internally and shown to customers."
            :level="3"
          />

          <div class="grid gap-4 sm:grid-cols-2">
            <UFormField
              label="Slug"
              name="slug"
              required
              help="Unique, stable, and used in URLs. Changing it breaks existing links."
            >
              <UInput
                v-model="form.slug"
                class="w-full font-mono"
              />
            </UFormField>
            <UFormField
              label="Name"
              name="name"
              required
            >
              <UInput
                v-model="form.name"
                class="w-full"
              />
            </UFormField>
            <UFormField
              label="Subtitle"
              name="subtitle"
              help="Optional supporting line. Leave blank to record none."
            >
              <UInput
                v-model="form.subtitle"
                class="w-full"
              />
            </UFormField>
            <UFormField
              label="Badge"
              name="badge"
              help="Optional short label, e.g. Most popular."
            >
              <UInput
                v-model="form.badge"
                class="w-full"
              />
            </UFormField>
            <UFormField
              label="Family key"
              name="family"
              required
              help="Groups packages that are alternatives to one another."
            >
              <UInput
                v-model="form.family"
                class="w-full font-mono"
              />
            </UFormField>
            <UFormField
              label="Family label"
              name="family_label"
              required
              help="The family name customers see."
            >
              <UInput
                v-model="form.family_label"
                class="w-full"
              />
            </UFormField>
          </div>
        </div>

        <!-- Commercials -->
        <div class="space-y-4">
          <SpSectionHeading
            title="What the customer gets, and pays"
            description="Quantities and money are exact integers. Money is in minor units of the currency stated here."
            :level="3"
          />

          <UFormField
            label="Billing mode"
            name="billing_mode"
            required
          >
            <div class="space-y-2">
              <div
                v-for="mode in BILLING_MODES"
                :key="mode.value"
                class="flex gap-3 rounded-lg border p-3"
                :class="form.billing_mode === mode.value ? 'border-primary bg-primary/5' : 'border-default'"
              >
                <UCheckbox
                  :model-value="form.billing_mode === mode.value"
                  :aria-label="mode.label"
                  class="mt-0.5"
                  @update:model-value="form.billing_mode = mode.value"
                />
                <div class="min-w-0">
                  <p class="text-sm text-highlighted">
                    {{ mode.label }}
                  </p>
                  <p class="text-xs text-muted">
                    {{ mode.description }}
                  </p>
                </div>
              </div>
            </div>
          </UFormField>

          <div class="grid gap-4 sm:grid-cols-2">
            <UFormField
              label="Advertised units"
              name="advertised_units"
              required
              help="The quantity sold, as a whole number."
            >
              <UInput
                v-model="form.advertised_units"
                inputmode="numeric"
                class="w-full"
              />
            </UFormField>
            <UFormField
              label="Unit label"
              name="unit_label"
              required
              help="What one unit is called, e.g. tokens."
            >
              <UInput
                v-model="form.unit_label"
                class="w-full"
              />
            </UFormField>
            <UFormField
              label="Currency"
              name="currency"
              required
              help="Three-letter code."
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
              name="currency_exponent"
              required
              help="Decimal places: USD is 2; KHR is 0."
            >
              <UInput
                v-model="form.currency_exponent"
                inputmode="numeric"
                class="w-full"
              />
            </UFormField>
            <UFormField
              label="Price"
              name="price_minor"
              required
              :help="`Minor units. ${draftMoney(form.price_minor.trim() === '' ? null : form.price_minor.trim())}`"
            >
              <UInput
                v-model="form.price_minor"
                inputmode="numeric"
                class="w-full"
              />
            </UFormField>
            <UFormField
              label="Compare-at price"
              name="compare_at_price_minor"
              :help="form.compare_at_price_minor.trim() === ''
                ? 'Optional. Blank records no was-price.'
                : `Minor units. ${draftMoney(form.compare_at_price_minor.trim())}`"
            >
              <UInput
                v-model="form.compare_at_price_minor"
                inputmode="numeric"
                class="w-full"
              />
            </UFormField>
          </div>

          <UFormField
            label="Lifetime from activation"
            name="duration_amount"
            required
            help="Stored as exact seconds. A lifetime that is not a whole number of days is edited in hours or seconds so it cannot be lengthened by rounding."
          >
            <div class="flex gap-3">
              <UInput
                v-model="form.duration_amount"
                inputmode="numeric"
                class="w-full sm:w-40"
              />
              <USelectMenu
                v-model="form.duration_unit"
                :items="DURATION_UNITS"
                value-key="value"
                class="w-40"
                aria-label="Lifetime unit"
              />
            </div>
          </UFormField>
        </div>

        <!-- Models -->
        <div class="space-y-4">
          <SpSectionHeading
            title="Allowed models"
            description="Exactly the models this package grants. A package allowing none cannot serve a request, and the control plane refuses it."
            :level="3"
          />

          <UFormField
            label="Models"
            name="allowed_model_alias_ids"
            required
          >
            <div class="space-y-3 rounded-lg border border-default p-3">
              <p
                v-if="aliasesUnavailable"
                class="text-sm text-warning"
              >
                The model list could not be loaded, so the current selection cannot be shown or changed.
                Close this form and retry rather than saving a selection that has not been read.
              </p>
              <p
                v-else-if="aliasChoices.length === 0"
                class="text-sm text-muted"
              >
                No public model aliases exist yet. Create a private model under Providers, map it to a public alias, then return here. Pricing and packages intentionally use public aliases rather than private upstream IDs.
              </p>

              <div
                v-for="choice in aliasChoices"
                :key="choice.publicAlias"
                class="flex gap-3"
              >
                <UCheckbox
                  :model-value="form.allowed_model_alias_ids.includes(choice.id)"
                  :aria-label="choice.publicAlias"
                  :disabled="!choice.idUsable"
                  class="mt-0.5"
                  @update:model-value="toggleAlias(choice.id, $event === true)"
                />
                <div class="min-w-0 space-y-1">
                  <div class="flex flex-wrap items-center gap-1.5">
                    <p class="text-sm text-highlighted">
                      {{ choice.displayName }}
                    </p>
                    <UBadge
                      v-if="choice.unpriced"
                      color="neutral"
                      variant="subtle"
                      size="sm"
                    >
                      Not priced
                    </UBadge>
                    <UBadge
                      v-else-if="!choice.costVerified"
                      color="warning"
                      variant="subtle"
                      size="sm"
                    >
                      Cost unverified
                    </UBadge>
                    <UBadge
                      v-if="!choice.onSale"
                      color="warning"
                      variant="subtle"
                      size="sm"
                      :title="choice.publicationBlockers.join(', ')"
                    >
                      Publication blocked
                    </UBadge>
                  </div>
                  <code class="block font-mono text-xs text-dimmed">{{ choice.publicAlias }}</code>
                </div>
              </div>
            </div>
          </UFormField>

          <UAlert
            v-if="currencyMismatches.length > 0"
            role="status"
            icon="i-lucide-triangle-alert"
            color="warning"
            variant="subtle"
            :description="`${currencyMismatches.join(', ')} ${currencyMismatches.length === 1 ? 'is' : 'are'} priced in a different currency or scale than this package. The control plane compares raw minor units without converting, so the margin it reports for this package will not be meaningful.`"
          />
        </div>

        <!-- Limits and metering -->
        <div class="space-y-4">
          <SpSectionHeading
            title="Per-key limits and metering"
            description="Blank records no ceiling at all, which is not the same as zero. A zero weight means that token class is not metered."
            :level="3"
          />

          <div class="grid gap-4 sm:grid-cols-2">
            <UFormField
              v-for="field in PACKAGE_LIMIT_FIELDS"
              :key="field.key"
              :label="field.label"
              :name="`limits.${field.key}`"
            >
              <UInput
                v-model="form.limits[field.key]"
                inputmode="numeric"
                placeholder="No limit"
                class="w-full"
              />
            </UFormField>
          </div>

          <div class="space-y-2">
            <p class="text-xs text-dimmed">
              Metering weights
              <span class="text-dimmed">· microunits per token</span>
            </p>
            <div class="grid gap-4 sm:grid-cols-2">
              <UFormField
                v-for="field in PACKAGE_WEIGHT_FIELDS"
                :key="field.key"
                :label="field.label"
                :name="`weights.${field.key}`"
              >
                <UInput
                  v-model="form.weights[field.key]"
                  inputmode="numeric"
                  placeholder="Not metered separately"
                  class="w-full"
                />
              </UFormField>
            </div>
          </div>
        </div>

        <!-- Publication -->
        <div class="space-y-4">
          <SpSectionHeading
            title="Publication"
            description="A package is on sale only when it is both enabled and customer-visible. That pair is what the margin gate applies to."
            :level="3"
          />

          <div class="space-y-3 rounded-lg border border-default p-3">
            <UCheckbox
              v-model="form.enabled"
              label="Enabled"
              description="A disabled package sells to nobody and cannot be ordered."
            />
            <UCheckbox
              v-model="form.customer_visible"
              label="Visible to customers"
              description="Listed in the public catalogue. Enabled but hidden still honours an existing order flow."
            />
            <UCheckbox
              v-model="form.featured"
              label="Featured"
              description="Given prominence wherever the catalogue highlights a package."
            />
            <UCheckbox
              v-model="form.auto_creates_api_key"
              label="Include API access activation after payment"
              description="After payment, SP Cambo creates a secure activation claim. The customer can create a new key or attach the purchased model access to an existing active key."
            />
          </div>

          <div class="grid gap-4 sm:grid-cols-2">
            <UFormField
              label="Sort order"
              name="sort_order"
              required
              help="Lower sorts first. The control plane orders the catalogue by this."
            >
              <UInput
                v-model="form.sort_order"
                inputmode="numeric"
                class="w-full"
              />
            </UFormField>
            <UFormField
              label="Margin floor"
              name="minimum_margin_bps"
              required
              help="Basis points of price to retain. 2500 is 25%."
            >
              <UInput
                v-model="form.minimum_margin_bps"
                inputmode="numeric"
                class="w-full"
              />
            </UFormField>
            <UFormField
              label="On sale from"
              name="starts_date"
              help="Optional. Recorded at 00:00:00 UTC."
            >
              <UInput
                v-model="form.starts_date"
                type="date"
                class="w-full"
              />
            </UFormField>
            <UFormField
              label="On sale until"
              name="ends_date"
              help="Optional. Recorded at 23:59:59 UTC on this date."
            >
              <UInput
                v-model="form.ends_date"
                type="date"
                class="w-full"
              />
            </UFormField>
          </div>
        </div>

        <!-- Projection -->
        <div class="space-y-3 rounded-lg border border-default p-4">
          <div class="flex flex-wrap items-baseline justify-between gap-2">
            <p class="text-xs text-dimmed">
              Projected margin
              <span class="text-dimmed">· recomputed and replaced by the control plane on save</span>
            </p>
            <UBadge
              color="neutral"
              variant="subtle"
              size="sm"
            >
              Projection
            </UBadge>
          </div>

          <p
            class="flex flex-wrap items-baseline gap-x-2 text-sm"
            :class="verdictClass[verdict.tone]"
          >
            <span class="font-medium">{{ verdict.label }}</span>
            <span class="text-muted">{{ verdict.detail }}</span>
          </p>

          <dl class="space-y-1">
            <div class="flex justify-between gap-3 text-sm">
              <dt class="text-muted">
                Worst-case upstream cost
              </dt>
              <dd class="sp-numeric text-default">
                {{ draftMoney(projection.worstCaseCostMinor) }}
              </dd>
            </div>
            <div class="flex justify-between gap-3 text-sm">
              <dt class="text-muted">
                Margin
              </dt>
              <dd
                class="sp-numeric"
                :class="verdictClass[verdict.tone]"
              >
                {{ draftMoney(projection.marginMinor) }}
              </dd>
            </div>
          </dl>

          <p
            v-if="projection.missingCostAliases.length > 0"
            class="text-xs text-warning"
          >
            No verified upstream cost for {{ projection.missingCostAliases.join(', ') }}. Record it on
            model pricing and the margin becomes calculable.
          </p>

          <p class="text-xs text-muted">
            The worst case is the single highest upstream rate across every selected model and token
            class, scaled by the advertised quantity and rounded up. It is a ceiling on cost, not a
            forecast.
          </p>
        </div>

        <UAlert
          v-if="overrideRequired"
          role="status"
          icon="i-lucide-triangle-alert"
          color="warning"
          variant="subtle"
          title="Publishing this needs a written reason"
          description="This package is going on sale without an established margin, so the control plane requires a justification and records it against your account. Without one it refuses the write and nothing is saved."
        />

        <UFormField
          label="Profitability override reason"
          name="profitability_override_reason"
          :required="overrideRequired"
          :help="overrideRequired
            ? 'At least 10 characters. Shown on the package and recorded in the audit trail.'
            : 'Optional while the margin is established. At least 10 characters if given.'"
        >
          <UTextarea
            v-model="form.profitability_override_reason"
            :rows="3"
            placeholder="Launch pricing for the developer tier, approved for the first quarter."
            class="w-full"
          />
        </UFormField>

        <div class="flex justify-end gap-2 pt-1">
          <UButton
            color="neutral"
            variant="ghost"
            :disabled="saving"
            @click="emit('update:open', false)"
          >
            Cancel
          </UButton>
          <UButton
            type="submit"
            :loading="saving"
            :disabled="!aliasIdsUsable"
          >
            {{ submitLabel }}
          </UButton>
        </div>
      </UForm>
    </template>
  </UModal>
</template>
