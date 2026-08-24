<script setup lang="ts">
import type { FormError } from '@nuxt/ui'
import type { AdminPromotion, AdminPromotionInput, AdminPromotionType } from '~/types/admin'

/**
 * Promotion management: what discounts exist, and what they apply to.
 *
 * Requires `catalog.manage`. Access is decided by the control plane, never here.
 *
 * Promotion package scope is read as both ids and slugs. An empty `package_ids`
 * means the promotion applies to every package, so unresolved scope is blocked
 * instead of silently widening a discount. Every monetary rule carries an explicit
 * currency and exponent and remains integer minor units end to end.
 */
definePageMeta({
  layout: 'dashboard',
  middleware: ['auth']
})

useSeoMeta({
  title: 'Promotions',
  description: 'SP Cambo promotion management: discount codes, scope and redemption limits.',
  robots: 'noindex, nofollow'
})

const api = useSpApi()
const toast = useToast()

const promotions = await useSpResource('admin:promotions', () => api.admin.promotions(), { server: false })

/** Loaded solely to map `package_slugs` back to the `package_ids` a write needs. */
const packages = await useSpResource('admin:packages', () => api.admin.packages(), { server: false })

const packageChoices = computed(() =>
  (packages.data.value ?? []).map(item => ({
    id: Number(item.id),
    slug: item.slug,
    name: item.name
  }))
)

/** True once every package id is a real number, which slug→id mapping depends on. */
const mappingUsable = computed(() =>
  packages.data.value !== null && packageChoices.value.every(choice => Number.isInteger(choice.id))
)

const TYPE_LABELS: Record<AdminPromotionType, string> = {
  PERCENTAGE: 'Percentage off',
  FIXED: 'Fixed discount',
  PRICE_OVERRIDE: 'Price override',
  BONUS: 'Bonus units',
  FREE: 'Free'
}

const typeChoices = (Object.keys(TYPE_LABELS) as AdminPromotionType[]).map(value => ({
  value,
  label: TYPE_LABELS[value]
}))

/**
 * Wall-clock reference for the schedule badges, set after mount.
 *
 * Left null during render so the server and the browser never disagree about
 * whether a window is open.
 */
const nowMs = ref<number | null>(null)

onMounted(() => {
  nowMs.value = Date.now()

  const timer = setInterval(() => {
    nowMs.value = Date.now()
  }, 30_000)

  onBeforeUnmount(() => clearInterval(timer))
})

const instant = (value: string | null) => {
  if (!value) {
    return null
  }

  const parsed = Date.parse(value)

  return Number.isNaN(parsed) ? null : parsed
}

/**
 * Whether a promotion can be redeemed at this moment.
 *
 * `enabled` alone is not the answer: a disabled promotion never applies, and an
 * enabled one outside its window does not either. Redemption caps are shown
 * separately because the control plane does not publish redemption counts, so this
 * page cannot tell whether a cap has been reached.
 */
const scheduleState = (promotion: AdminPromotion) => {
  if (!promotion.enabled) {
    return { color: 'neutral' as const, label: 'Disabled' }
  }

  const now = nowMs.value

  if (now === null) {
    return null
  }

  const starts = instant(promotion.starts_at)
  const ends = instant(promotion.ends_at)

  if (starts !== null && starts > now) {
    return { color: 'info' as const, label: 'Scheduled' }
  }

  if (ends !== null && ends <= now) {
    return { color: 'neutral' as const, label: 'Ended' }
  }

  return { color: 'success' as const, label: 'Live' }
}

/** The one figure that defines each type, stated in the units the API uses. */
const promotionMoney = (promotion: AdminPromotion, minor: string | null) => minor === null
  ? 'Not set'
  : formatMoney({ minor, currency: promotion.currency, exponent: promotion.currency_exponent })

const valueSummary = (promotion: AdminPromotion) => {
  switch (promotion.type) {
    case 'PERCENTAGE':
      return { label: 'Discount', value: formatBasisPoints(promotion.percentage_bps) }
    case 'FIXED':
      return { label: 'Discount', value: promotionMoney(promotion, promotion.fixed_discount_minor) }
    case 'BONUS':
      return { label: 'Bonus', value: `${formatUnits(promotion.bonus_units)} units` }
    case 'PRICE_OVERRIDE':
      return { label: 'Overridden price', value: promotionMoney(promotion, promotion.price_override_minor) }
    case 'FREE':
      return { label: 'Discount', value: 'Full price' }
  }
}

/** ---------------------------------------------------------------- the form */

interface PromotionFormState {
  code: string
  label: string
  type: AdminPromotionType
  currency: string
  currency_exponent: string
  percentage_bps: string
  fixed_discount_minor: string
  price_override_minor: string
  bonus_units: string
  minimum_order_minor: string
  maximum_discount_minor: string
  max_redemptions: string
  per_user_limit: string
  new_customer_only: boolean
  stackable: boolean
  priority: string
  starts_date: string
  ends_date: string
  enabled: boolean
  package_ids: number[]
  reason: string
}

const emptyForm = (): PromotionFormState => ({
  code: '',
  label: '',
  type: 'PERCENTAGE',
  currency: 'USD',
  currency_exponent: '2',
  percentage_bps: '',
  fixed_discount_minor: '',
  price_override_minor: '',
  bonus_units: '',
  minimum_order_minor: '0',
  maximum_discount_minor: '',
  max_redemptions: '',
  per_user_limit: '',
  new_customer_only: false,
  stackable: false,
  priority: '0',
  starts_date: '',
  ends_date: '',
  enabled: false,
  package_ids: [],
  reason: ''
})

const formOpen = ref(false)
const saving = ref(false)
const form = ref<PromotionFormState>(emptyForm())
const formError = ref<string | null>(null)
const editing = ref<AdminPromotion | null>(null)
const formRef = useTemplateRef<{ setErrors: (errors: FormError[]) => void }>('formRef')

/** Slugs on the promotion being edited that no package in the catalogue matches. */
const unresolvedSlugs = ref<string[]>([])

const resetForm = () => {
  form.value = emptyForm()
  formError.value = null
  editing.value = null
  unresolvedSlugs.value = []
  formRef.value?.setErrors([])
}

const openCreate = () => {
  resetForm()
  formOpen.value = true
}

const openEdit = (promotion: AdminPromotion) => {
  resetForm()
  editing.value = promotion

  const scope = resolvePackageScope(promotion.package_slugs, packageChoices.value)

  unresolvedSlugs.value = scope.unresolved

  form.value = {
    code: promotion.code,
    label: promotion.label,
    type: promotion.type,
    currency: promotion.currency,
    currency_exponent: String(promotion.currency_exponent),
    percentage_bps: promotion.percentage_bps === null ? '' : String(promotion.percentage_bps),
    fixed_discount_minor: promotion.fixed_discount_minor ?? '',
    price_override_minor: promotion.price_override_minor ?? '',
    bonus_units: promotion.bonus_units ?? '',
    minimum_order_minor: promotion.minimum_order_minor,
    maximum_discount_minor: promotion.maximum_discount_minor ?? '',
    max_redemptions: promotion.max_redemptions === null ? '' : String(promotion.max_redemptions),
    per_user_limit: promotion.per_user_limit === null ? '' : String(promotion.per_user_limit),
    new_customer_only: promotion.new_customer_only,
    stackable: promotion.stackable,
    priority: String(promotion.priority),
    // Slicing the UTC instant keeps the date the control plane recorded.
    starts_date: promotion.starts_at?.slice(0, 10) ?? '',
    ends_date: promotion.ends_at?.slice(0, 10) ?? '',
    enabled: promotion.enabled,
    package_ids: scope.ids,
    reason: ''
  }

  formOpen.value = true
}

const togglePackage = (id: number, selected: boolean) => {
  const current = new Set(form.value.package_ids)

  if (selected) {
    current.add(id)
  } else {
    current.delete(id)
  }

  // Catalogue order, so two promotions with the same scope read alike.
  form.value.package_ids = packageChoices.value.filter(choice => current.has(choice.id)).map(choice => choice.id)
}

/** Which amount field this type requires, if any. */
const amountField = computed<keyof PromotionFormState | null>(() => {
  switch (form.value.type) {
    case 'PERCENTAGE':
      return 'percentage_bps'
    case 'FIXED':
      return 'fixed_discount_minor'
    case 'PRICE_OVERRIDE':
      return 'price_override_minor'
    case 'BONUS':
      return 'bonus_units'
    default:
      return null
  }
})

/**
 * A whole number, or null when the field is blank.
 *
 * `parseOptionalInteger` returns `undefined` for anything that is not an integer, so
 * a decimal or a stray character is reported as invalid instead of being silently
 * truncated on the way to an `integer` validator.
 */
const integerError = (name: string, value: string, options: { min: number, max?: number, required: boolean }) => {
  const parsed = parseOptionalInteger(value)

  if (parsed === undefined) {
    return { name, message: 'Enter a whole number, with no decimal point or separators.' }
  }

  if (parsed === null) {
    return options.required ? { name, message: 'This is required for the selected type.' } : null
  }

  if (parsed < options.min) {
    return { name, message: `Must be ${formatCount(options.min)} or more.` }
  }

  if (options.max !== undefined && parsed > options.max) {
    return { name, message: `Must be ${formatCount(options.max)} or less.` }
  }

  return null
}

/**
 * Client-side mirror of the control plane's rules.
 *
 * Deliberately a mirror and not a stricter policy: a rule enforced only here would
 * make the API look inconsistent to anyone using it directly. The server remains
 * the authority and its 422 is mapped onto these same fields.
 */
const validate = (state: PromotionFormState): FormError[] => {
  const errors: Array<FormError | null> = []

  if (!state.code.trim()) {
    errors.push({ name: 'code', message: 'Give the promotion a code customers will type.' })
  } else if (state.code.trim().length > 50) {
    errors.push({ name: 'code', message: 'Keep the code to 50 characters or fewer.' })
  }

  if (!state.label.trim()) {
    errors.push({ name: 'label', message: 'Name it so it is recognisable on an order.' })
  } else if (state.label.trim().length > 150) {
    errors.push({ name: 'label', message: 'Keep the name to 150 characters or fewer.' })
  }

  if (!/^[A-Za-z]{3}$/.test(state.currency.trim())) {
    errors.push({ name: 'currency', message: 'Enter a three-letter currency code.' })
  }
  errors.push(integerError('currency_exponent', state.currency_exponent, { min: 0, max: 6, required: true }))

  if (state.type === 'PERCENTAGE') {
    errors.push(integerError('percentage_bps', state.percentage_bps, { min: 1, max: 10_000, required: true }))
  }

  if (state.type === 'FIXED') {
    errors.push(integerError('fixed_discount_minor', state.fixed_discount_minor, { min: 1, required: true }))
  }

  if (state.type === 'PRICE_OVERRIDE') {
    errors.push(integerError('price_override_minor', state.price_override_minor, { min: 0, required: true }))
  }

  if (state.type === 'BONUS') {
    errors.push(integerError('bonus_units', state.bonus_units, { min: 1, required: true }))
  }

  errors.push(integerError('minimum_order_minor', state.minimum_order_minor, { min: 0, required: true }))
  errors.push(integerError('maximum_discount_minor', state.maximum_discount_minor, { min: 1, required: false }))
  errors.push(integerError('max_redemptions', state.max_redemptions, { min: 1, required: false }))
  errors.push(integerError('per_user_limit', state.per_user_limit, { min: 1, required: false }))
  errors.push(integerError('priority', state.priority, { min: 0, required: true }))

  if (state.starts_date && state.ends_date && state.ends_date <= state.starts_date) {
    errors.push({ name: 'ends_date', message: 'The end date must fall after the start date.' })
  }

  const reason = state.reason.trim()

  if (reason.length < 10) {
    errors.push({ name: 'reason', message: 'Record why, in at least 10 characters. This is written to the audit trail.' })
  } else if (reason.length > 2000) {
    errors.push({ name: 'reason', message: 'Keep the note to 2000 characters or fewer.' })
  }

  return errors.filter((error): error is FormError => error !== null)
}

/** An amount that applies to this type, or null so a stale value is cleared on save. */
const amountFor = (state: PromotionFormState, type: AdminPromotionType, value: string) =>
  state.type === type ? (parseOptionalInteger(value) ?? null) : null

const buildInput = (state: PromotionFormState): AdminPromotionInput => ({
  code: state.code.trim(),
  label: state.label.trim(),
  type: state.type,
  currency: state.currency.trim().toUpperCase(),
  currency_exponent: parseOptionalInteger(state.currency_exponent) ?? 2,
  percentage_bps: amountFor(state, 'PERCENTAGE', state.percentage_bps),
  fixed_discount_minor: amountFor(state, 'FIXED', state.fixed_discount_minor),
  price_override_minor: amountFor(state, 'PRICE_OVERRIDE', state.price_override_minor),
  bonus_units: amountFor(state, 'BONUS', state.bonus_units),
  minimum_order_minor: parseOptionalInteger(state.minimum_order_minor) ?? 0,
  maximum_discount_minor: parseOptionalInteger(state.maximum_discount_minor) ?? null,
  max_redemptions: parseOptionalInteger(state.max_redemptions) ?? null,
  per_user_limit: parseOptionalInteger(state.per_user_limit) ?? null,
  new_customer_only: state.new_customer_only,
  stackable: state.stackable,
  priority: parseOptionalInteger(state.priority) ?? 0,
  starts_at: state.starts_date ? `${state.starts_date}T00:00:00Z` : null,
  ends_at: state.ends_date ? `${state.ends_date}T23:59:59Z` : null,
  enabled: state.enabled,
  package_ids: state.package_ids,
  reason: state.reason.trim()
})

/**
 * True when saving would change a promotion's scope to something the operator did
 * not choose, because a slug on it could not be matched to a package id.
 */
const scopeUnsafe = computed(() =>
  !mappingUsable.value || unresolvedSlugs.value.length > 0
)

const submit = async () => {
  if (scopeUnsafe.value) {
    return
  }

  saving.value = true
  formError.value = null

  const target = editing.value

  try {
    const input = buildInput(form.value)
    const saved = target
      ? await api.admin.updatePromotion(target.id, input)
      : await api.admin.createPromotion(input)

    formOpen.value = false
    resetForm()
    await promotions.refresh()

    toast.add({
      title: target ? 'Promotion updated' : 'Promotion created',
      description: saved.enabled
        ? `${saved.code} is enabled. It applies wherever its schedule and scope allow.`
        : `${saved.code} is saved but disabled, so it will not apply to any order yet.`,
      color: 'success',
      icon: 'i-lucide-circle-check'
    })
  } catch (cause) {
    const error = toSpApiError(cause)

    formRef.value?.setErrors(
      Object.entries(error.errors).map(([name, messages]) => ({
        // Dates are submitted as instants but edited as dates.
        name: name === 'starts_at' ? 'starts_date' : name === 'ends_at' ? 'ends_date' : name,
        message: messages[0] ?? 'This value is not valid.'
      }))
    )

    formError.value = error.isValidation ? null : error.message
  } finally {
    saving.value = false
  }
}

const anyLoading = computed(() => promotions.loading.value || packages.loading.value)
const refreshAll = () => Promise.all([promotions.refresh(), packages.refresh()])

/** Both endpoints sit behind `catalog.manage`, so one refusal covers the page. */
const accessDenied = computed(() => promotions.forbidden.value || packages.forbidden.value)
const accessCode = computed(() =>
  (promotions.forbidden.value ? promotions.error.value?.code : packages.error.value?.code) ?? null
)
</script>

<template>
  <SpDashboardPage
    title="Promotions"
    icon="i-lucide-ticket-percent"
    description="Discount codes, what they apply to and when they run. Every change is recorded in the audit trail against your account."
  >
    <template #actions>
      <UButton
        color="neutral"
        variant="subtle"
        icon="i-lucide-refresh-cw"
        :loading="anyLoading"
        @click="refreshAll()"
      >
        Refresh
      </UButton>
      <UButton
        icon="i-lucide-plus"
        :disabled="accessDenied || !mappingUsable"
        @click="openCreate()"
      >
        New promotion
      </UButton>
    </template>

    <SpStateForbidden
      v-if="accessDenied"
      :code="accessCode"
      permission="catalog.manage"
    />

    <template v-else>
      <UAlert
        v-if="!mappingUsable && !packages.initialLoading.value"
        color="warning"
        variant="subtle"
        icon="i-lucide-triangle-alert"
        title="Promotions cannot be saved right now"
        description="Scoping a promotion needs the package list, because the write contract identifies packages by internal id while the read contract returns slugs. Until the package list loads, saving is disabled — submitting an unresolved scope would silently apply the discount to every package."
      />

      <SpAsyncSection
        :loading="promotions.initialLoading.value"
        :unavailable="promotions.unavailable.value"
        :failed="promotions.failed.value"
        :empty="promotions.isEmpty.value"
        :offline="promotions.error.value?.code === 'network_unreachable'"
        :error-message="promotions.error.value?.message"
        error-title="Promotions could not be loaded"
        loading-variant="rows"
        :loading-count="4"
        @retry="promotions.refresh()"
      >
        <template #empty>
          <SpStateEmpty
            title="No promotions yet"
            description="Nothing is discounted. Create a promotion to offer a code at checkout — it will not apply to any order until you enable it."
            icon="i-lucide-ticket-percent"
          >
            <template #action>
              <UButton
                icon="i-lucide-plus"
                :disabled="!mappingUsable"
                @click="openCreate()"
              >
                New promotion
              </UButton>
            </template>
          </SpStateEmpty>
        </template>

        <ul class="space-y-4">
          <li
            v-for="promotion in promotions.data.value ?? []"
            :key="promotion.id"
            class="overflow-hidden rounded-xl border border-default bg-elevated/30"
          >
            <div class="flex flex-col gap-4 border-b border-default p-5 sm:flex-row sm:items-start sm:justify-between">
              <div class="min-w-0 space-y-2">
                <div class="flex flex-wrap items-center gap-2">
                  <code class="font-mono text-sm font-semibold text-highlighted">{{ promotion.code }}</code>
                  <UBadge
                    v-if="scheduleState(promotion)"
                    :color="scheduleState(promotion)!.color"
                    variant="subtle"
                    size="sm"
                  >
                    {{ scheduleState(promotion)!.label }}
                  </UBadge>
                  <UBadge
                    color="neutral"
                    variant="subtle"
                    size="sm"
                  >
                    {{ TYPE_LABELS[promotion.type] }}
                  </UBadge>
                  <UBadge
                    v-if="promotion.new_customer_only"
                    color="info"
                    variant="subtle"
                    size="sm"
                  >
                    New customers only
                  </UBadge>
                  <UBadge
                    v-if="promotion.stackable"
                    color="warning"
                    variant="subtle"
                    size="sm"
                  >
                    Stackable
                  </UBadge>
                </div>
                <p class="text-sm text-muted">
                  {{ promotion.label }}
                </p>
              </div>

              <UButton
                color="neutral"
                variant="subtle"
                icon="i-lucide-pencil"
                size="sm"
                class="shrink-0"
                :disabled="!mappingUsable"
                @click="openEdit(promotion)"
              >
                Edit
              </UButton>
            </div>

            <dl class="grid gap-x-6 gap-y-4 border-b border-default p-5 sm:grid-cols-2 lg:grid-cols-4">
              <div>
                <dt class="text-xs text-dimmed">
                  {{ valueSummary(promotion).label }}
                </dt>
                <dd class="sp-numeric text-sm text-default">
                  {{ valueSummary(promotion).value }}
                </dd>
              </div>
              <div>
                <dt class="text-xs text-dimmed">
                  Minimum order
                </dt>
                <dd class="sp-numeric text-sm text-default">
                  {{
                    isZeroMinor(promotion.minimum_order_minor)
                      ? 'No minimum'
                      : promotionMoney(promotion, promotion.minimum_order_minor)
                  }}
                </dd>
              </div>
              <div>
                <dt class="text-xs text-dimmed">
                  Maximum discount
                </dt>
                <dd class="sp-numeric text-sm text-default">
                  {{
                    promotion.maximum_discount_minor === null
                      ? 'Uncapped'
                      : promotionMoney(promotion, promotion.maximum_discount_minor)
                  }}
                </dd>
              </div>
              <div>
                <dt class="text-xs text-dimmed">
                  Priority
                </dt>
                <dd class="sp-numeric text-sm text-default">
                  {{ formatCount(promotion.priority) }}
                </dd>
              </div>
            </dl>

            <div class="grid gap-5 p-5 sm:grid-cols-2">
              <div class="space-y-1.5">
                <p class="text-xs text-dimmed">
                  Runs
                </p>
                <p class="text-sm text-default">
                  {{ promotion.starts_at ? formatDateTime(promotion.starts_at) : 'No start date' }}
                  <span class="text-dimmed">→</span>
                  {{ promotion.ends_at ? formatDateTime(promotion.ends_at) : 'No end date' }}
                </p>
                <p class="text-xs text-muted">
                  Redemption caps:
                  {{ promotion.max_redemptions === null ? 'unlimited overall' : `${formatCount(promotion.max_redemptions)} overall` }},
                  {{ promotion.per_user_limit === null ? 'unlimited per customer' : `${formatCount(promotion.per_user_limit)} per customer` }}.
                  Redemptions used are not published by the control plane.
                </p>
              </div>

              <div class="space-y-1.5">
                <p class="text-xs text-dimmed">
                  Applies to
                </p>
                <div
                  v-if="promotion.package_slugs.length > 0"
                  class="flex flex-wrap gap-1.5"
                >
                  <UBadge
                    v-for="slug in promotion.package_slugs"
                    :key="slug"
                    color="neutral"
                    variant="subtle"
                    size="sm"
                    class="font-mono"
                  >
                    {{ slug }}
                  </UBadge>
                </div>
                <p
                  v-else
                  class="text-sm text-default"
                >
                  Every package, including any added later.
                </p>
              </div>
            </div>
          </li>
        </ul>
      </SpAsyncSection>
    </template>

    <UModal
      v-model:open="formOpen"
      :title="editing ? `Edit ${editing.code}` : 'New promotion'"
      :description="editing
        ? 'Every field is replaced on save — the control plane requires the whole promotion, not a patch.'
        : 'The promotion will not apply to any order until it is enabled and inside its schedule.'"
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

          <UAlert
            v-if="unresolvedSlugs.length > 0"
            icon="i-lucide-triangle-alert"
            color="error"
            variant="subtle"
            title="This promotion cannot be saved from here"
            :description="`It is scoped to ${unresolvedSlugs.join(', ')}, which no package in the catalogue matches. Saving would drop that scope and apply the discount more widely than intended.`"
          />

          <div class="grid gap-4 sm:grid-cols-2">
            <UFormField
              label="Code"
              name="code"
              required
              help="What the customer types. Stored uppercase."
            >
              <UInput
                v-model="form.code"
                placeholder="LAUNCH20"
                autofocus
                class="w-full font-mono"
              />
            </UFormField>

            <UFormField
              label="Name"
              name="label"
              required
              help="Shown on the order that used it."
            >
              <UInput
                v-model="form.label"
                placeholder="Launch discount"
                class="w-full"
              />
            </UFormField>
          </div>

          <UFormField
            label="Type"
            name="type"
            required
          >
            <USelectMenu
              v-model="form.type"
              :items="typeChoices"
              value-key="value"
              class="w-full"
            />
          </UFormField>
          <UAlert
            v-if="form.type === 'FREE'"
            icon="i-lucide-info"
            color="neutral"
            variant="subtle"
            title="This makes the order free"
            description="No amount is needed. The minimum order and scope below still decide when it applies."
          />

          <div class="grid gap-4 sm:grid-cols-2">
            <UFormField
              label="Currency"
              name="currency"
              required
              help="Three-letter code, such as USD or KHR."
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
          </div>

          <UFormField
            v-if="amountField === 'percentage_bps'"
            label="Discount"
            name="percentage_bps"
            required
            :help="`Basis points — hundredths of a percent. ${form.percentage_bps ? `${formatBasisPoints(Number(form.percentage_bps))} off.` : '2500 is 25% off.'}`"
          >
            <UInput
              v-model="form.percentage_bps"
              inputmode="numeric"
              placeholder="2500"
              class="w-full"
            />
          </UFormField>

          <UFormField
            v-else-if="amountField === 'fixed_discount_minor'"
            label="Discount"
            name="fixed_discount_minor"
            required
            help="Integer minor units in the currency and exponent above."
          >
            <UInput
              v-model="form.fixed_discount_minor"
              inputmode="numeric"
              placeholder="1000"
              class="w-full"
            />
          </UFormField>

          <UFormField
            v-else-if="amountField === 'price_override_minor'"
            label="Overridden price"
            name="price_override_minor"
            required
            help="Integer minor units. Zero makes the order free."
          >
            <UInput
              v-model="form.price_override_minor"
              inputmode="numeric"
              placeholder="0"
              class="w-full"
            />
          </UFormField>

          <UFormField
            v-else-if="amountField === 'bonus_units'"
            label="Bonus units"
            name="bonus_units"
            required
            help="Extra metered units granted on top of the package."
          >
            <UInput
              v-model="form.bonus_units"
              inputmode="numeric"
              placeholder="1000000"
              class="w-full"
            />
          </UFormField>

          <div class="grid gap-4 sm:grid-cols-2">
            <UFormField
              label="Minimum order"
              name="minimum_order_minor"
              required
              help="Minor units in the currency and exponent above. 0 for no minimum."
            >
              <UInput
                v-model="form.minimum_order_minor"
                inputmode="numeric"
                class="w-full"
              />
            </UFormField>

            <UFormField
              label="Maximum discount"
              name="maximum_discount_minor"
              help="Minor units in the currency and exponent above. Leave blank for no cap."
            >
              <UInput
                v-model="form.maximum_discount_minor"
                inputmode="numeric"
                placeholder="Uncapped"
                class="w-full"
              />
            </UFormField>

            <UFormField
              label="Total redemptions"
              name="max_redemptions"
              help="Leave blank for unlimited."
            >
              <UInput
                v-model="form.max_redemptions"
                inputmode="numeric"
                placeholder="Unlimited"
                class="w-full"
              />
            </UFormField>

            <UFormField
              label="Per customer"
              name="per_user_limit"
              help="Leave blank for unlimited."
            >
              <UInput
                v-model="form.per_user_limit"
                inputmode="numeric"
                placeholder="Unlimited"
                class="w-full"
              />
            </UFormField>

            <UFormField
              label="Starts"
              name="starts_date"
              help="Optional. Begins at 00:00:00 UTC."
            >
              <UInput
                v-model="form.starts_date"
                type="date"
                class="w-full"
              />
            </UFormField>

            <UFormField
              label="Ends"
              name="ends_date"
              help="Optional. Ends at 23:59:59 UTC."
            >
              <UInput
                v-model="form.ends_date"
                type="date"
                class="w-full"
              />
            </UFormField>

            <UFormField
              label="Priority"
              name="priority"
              required
              help="Higher wins when several promotions could apply."
            >
              <UInput
                v-model="form.priority"
                inputmode="numeric"
                class="w-full"
              />
            </UFormField>
          </div>

          <UFormField
            label="Applies to"
            name="package_ids"
          >
            <div class="space-y-3 rounded-lg border border-default p-3">
              <p
                v-if="packageChoices.length === 0"
                class="text-sm text-muted"
              >
                No packages exist yet, so this promotion will apply to every package created later.
              </p>

              <div
                v-for="choice in packageChoices"
                :key="choice.id"
                class="flex gap-3"
              >
                <UCheckbox
                  :model-value="form.package_ids.includes(choice.id)"
                  :aria-label="choice.name"
                  class="mt-0.5"
                  @update:model-value="togglePackage(choice.id, $event === true)"
                />
                <div class="min-w-0">
                  <p class="text-sm text-highlighted">
                    {{ choice.name }}
                  </p>
                  <code class="font-mono text-xs text-dimmed">{{ choice.slug }}</code>
                </div>
              </div>

              <p
                v-if="packageChoices.length > 0 && form.package_ids.length === 0"
                class="rounded bg-warning/10 px-3 py-2 text-xs text-warning"
              >
                Nothing selected, so this applies to <strong>every</strong> package, including any added
                later. Select packages to restrict it.
              </p>
            </div>
          </UFormField>

          <div class="space-y-3 rounded-lg border border-default p-3">
            <UCheckbox
              v-model="form.enabled"
              label="Enabled"
              description="A disabled promotion is never applied, whatever its schedule says."
            />
            <UCheckbox
              v-model="form.new_customer_only"
              label="New customers only"
              description="Restricts the code to accounts that have not ordered before."
            />
            <UCheckbox
              v-model="form.stackable"
              label="Stackable"
              description="Allows this to combine with another promotion on the same order."
            />
          </div>

          <UFormField
            label="Why"
            name="reason"
            required
            help="At least 10 characters. Written to the audit trail against your account, alongside what changed."
          >
            <UTextarea
              v-model="form.reason"
              :rows="3"
              placeholder="Launch campaign approved for August."
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
              :disabled="scopeUnsafe"
            >
              {{ editing ? 'Save promotion' : 'Create promotion' }}
            </UButton>
          </div>
        </UForm>
      </template>
    </UModal>
  </SpDashboardPage>
</template>
