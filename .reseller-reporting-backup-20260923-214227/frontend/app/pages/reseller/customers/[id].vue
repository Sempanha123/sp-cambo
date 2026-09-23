<script setup lang="ts">
import type { FormError } from '@nuxt/ui'
import type { BillingMode } from '~/types/commerce'
import type {
  ResellerCustomerKey,
  ResellerCustomerKeyCreated,
  ResellerCustomerStatus
} from '~/types/reseller'

/**
 * One managed customer: fund their quota, and issue the inference keys they use.
 *
 * There is no single-customer GET route, so the customer is selected out of the
 * shared `reseller:customers` index. That keeps one source of truth for the list
 * and this page, and means a stale id renders an honest not-found state rather
 * than an empty shell.
 *
 * The customer is derived from REST-backed roster state, so lifecycle mutations
 * refresh that collection rather than optimistically changing an account locally.
 * Allocation and new-key creation require `ACTIVE`; existing keys stay listable
 * and revocable after suspension or closure for credential cleanup.
 */
definePageMeta({
  layout: 'dashboard',
  middleware: ['auth']
})

const route = useRoute()
const api = useSpApi()
const toast = useToast()

const customerId = computed(() => String(route.params.id ?? ''))

const customers = await useSpResource('reseller:customers', () => api.reseller.customers(), { server: false })

const customer = computed(() =>
  (customers.data.value ?? []).find(entry => entry.id === customerId.value) ?? null
)

/** Only true once the list has genuinely loaded and this id is not in it. */
const notFound = computed(() =>
  !customers.initialLoading.value
  && customers.error.value === null
  && customers.data.value !== null
  && customer.value === null
)

const isActive = computed(() => customer.value?.status === 'ACTIVE')

/** ------------------------------------------------------------- lifecycle */

interface LifecycleFormState {
  reason: string
}

type LifecycleAction = 'suspend' | 'reactivate' | 'close'

interface LifecycleActionDetails {
  action: LifecycleAction
  status: ResellerCustomerStatus
  label: string
  title: string
  description: string
  confirmation: string
  buttonColor: 'warning' | 'success' | 'error'
  icon: string
  toast: {
    title: string
    description: string
    color: 'warning' | 'success'
    icon: string
  }
}

const LIFECYCLE_ACTIONS: Record<LifecycleAction, LifecycleActionDetails> = {
  suspend: {
    action: 'suspend',
    status: 'SUSPENDED',
    label: 'Suspend',
    title: 'Suspend this customer?',
    description: 'Their account will be suspended. New allocations and new inference-key issuance stay paused until you reactivate the relationship.',
    confirmation: 'Suspend customer',
    buttonColor: 'warning',
    icon: 'i-lucide-pause-circle',
    toast: {
      title: 'Customer suspended',
      description: 'New allocations and new inference-key issuance are paused. Existing keys remain available for review and revocation.',
      color: 'warning',
      icon: 'i-lucide-pause-circle'
    }
  },
  reactivate: {
    action: 'reactivate',
    status: 'ACTIVE',
    label: 'Reactivate',
    title: 'Reactivate this customer?',
    description: 'Their managed-customer relationship will become active again, allowing new allocations and inference-key issuance.',
    confirmation: 'Reactivate customer',
    buttonColor: 'success',
    icon: 'i-lucide-circle-play',
    toast: {
      title: 'Customer reactivated',
      description: 'New allocations and new inference-key issuance are available again.',
      color: 'success',
      icon: 'i-lucide-circle-play'
    }
  },
  close: {
    action: 'close',
    status: 'CLOSED',
    label: 'Close',
    title: 'Close this customer?',
    description: 'This permanently closes the managed-customer relationship. It cannot be reactivated. Existing keys remain visible so you can revoke them.',
    confirmation: 'Close customer permanently',
    buttonColor: 'error',
    icon: 'i-lucide-circle-x',
    toast: {
      title: 'Customer closed',
      description: 'The relationship is permanently closed. Existing keys remain available for review and revocation.',
      color: 'warning',
      icon: 'i-lucide-circle-x'
    }
  }
}

const lifecycleActions = computed(() => {
  switch (customer.value?.status) {
    case 'ACTIVE':
      return [LIFECYCLE_ACTIONS.suspend, LIFECYCLE_ACTIONS.close]
    case 'SUSPENDED':
      return [LIFECYCLE_ACTIONS.reactivate, LIFECYCLE_ACTIONS.close]
    default:
      return []
  }
})

const lifecycleOpen = ref(false)
const lifecycleTarget = ref<LifecycleActionDetails | null>(null)
const lifecycle = ref<LifecycleFormState>({ reason: '' })
const lifecycleError = ref<string | null>(null)
const updatingLifecycle = ref(false)
const lifecycleFormRef = useTemplateRef<{ setErrors: (errors: FormError[]) => void }>('lifecycleFormRef')

const resetLifecycle = () => {
  lifecycle.value = { reason: '' }
  lifecycleError.value = null
  lifecycleFormRef.value?.setErrors([])
}

const openLifecycle = (action: LifecycleActionDetails) => {
  lifecycleTarget.value = action
  resetLifecycle()
  lifecycleOpen.value = true
}

const closeLifecycle = (force = false) => {
  if (updatingLifecycle.value && !force) {
    return
  }

  lifecycleOpen.value = false
  lifecycleTarget.value = null
  resetLifecycle()
}

const validateLifecycle = (state: LifecycleFormState): FormError[] => {
  const reason = state.reason.trim()

  if (reason.length < 10) {
    return [{ name: 'reason', message: 'Write at least 10 characters — this goes into the audit trail.' }]
  }

  if (reason.length > 2000) {
    return [{ name: 'reason', message: 'Keep the reason to 2,000 characters or fewer.' }]
  }

  return []
}

const refreshLifecycleState = async () => {
  await Promise.all([
    customers.refresh(),
    keys.refresh(),
    inventory.refresh()
  ])
}

const submitLifecycle = async () => {
  const target = lifecycleTarget.value

  if (!target || !customer.value) {
    return
  }

  updatingLifecycle.value = true
  lifecycleError.value = null

  try {
    await api.reseller.updateCustomerStatus(customer.value.id, {
      status: target.status,
      reason: lifecycle.value.reason.trim()
    })

    await refreshLifecycleState()
    closeLifecycle(true)

    toast.add({
      ...target.toast,
      description: `${customer.value?.name ?? 'The customer'} is now ${target.status.toLowerCase()}. ${target.toast.description}`
    })
  } catch (cause) {
    const error = toSpApiError(cause)

    lifecycleFormRef.value?.setErrors(
      Object.entries(error.errors).map(([name, messages]) => ({
        name,
        message: messages[0] ?? 'This value is not valid.'
      }))
    )

    lifecycleError.value = error.isValidation ? null : error.message

    if (error.code === 'invalid_status_transition') {
      await refreshLifecycleState()
    }
  } finally {
    updatingLifecycle.value = false
  }
}

useSeoMeta({
  title: () => customer.value ? `${customer.value.name} — managed customer` : 'Managed customer',
  description: 'Allocate quota and manage the inference keys for one customer you resell to.',
  robots: 'noindex, nofollow'
})

/** ------------------------------------------------------------- inventory */

/**
 * The reseller's own lots. An allocation moves units out of these, so the form
 * reads them to show what can actually be funded before anything is submitted.
 */
const inventory = await useSpResource('reseller:inventory', () => api.account.entitlements(), { server: false })
const models = await useSpResource('catalog:models', () => api.catalog.models(), { server: false })

/**
 * One clock for every expiry comparison on this page, ticking each minute so a lot
 * that lapses while the page is open stops being offered as funding.
 */
const now = ref(Date.now())
let clock: ReturnType<typeof setInterval> | undefined

onMounted(() => {
  clock = setInterval(() => {
    now.value = Date.now()
  }, 60_000)
})

onUnmounted(() => {
  if (clock !== undefined) {
    clearInterval(clock)
  }
})

const fundable = computed(() => fundableAliases(inventory.data.value ?? [], now.value))
const aliasesOverlap = computed(() => hasSharedAliasLots(inventory.data.value ?? [], now.value))

/** ------------------------------------------------------------ allocation */

interface AllocationFormState {
  billing_mode: BillingMode
  public_model_alias: string
  units: string
  reason: string
}

const emptyAllocation = (): AllocationFormState => ({
  billing_mode: 'TOKEN_QUOTA',
  public_model_alias: '',
  units: '',
  reason: ''
})

const allocateOpen = ref(false)
const allocating = ref(false)
const allocation = ref<AllocationFormState>(emptyAllocation())
const allocationError = ref<string | null>(null)
const allocationUnconfirmed = ref(false)
const allocationFormRef = useTemplateRef<{ setErrors: (errors: FormError[]) => void }>('allocationFormRef')

/**
 * The key the control plane deduplicates this transfer on.
 *
 * It is held stable while the form is unchanged, so resubmitting after a dropped
 * response replays the original transfer instead of making a second one. Any edit
 * mints a new key, so a deliberate second allocation is a second transfer rather
 * than being swallowed as a replay.
 *
 * `reason` counts as an edit even though the server compares only the reseller,
 * customer, mode, alias and units. Without that, editing the reason and
 * resubmitting would silently return the earlier transfer and the reseller would
 * believe an audit reason had been recorded that never was.
 */
const allocationKey = ref<string | null>(null)

const mintAllocationKey = () => {
  try {
    allocationKey.value = newIdempotencyKey('alloc')
  } catch {
    allocationKey.value = null
    allocationError.value = 'This browser cannot generate the safety key that stops a transfer being made twice. Open SP Cambo over HTTPS and try again.'
  }
}

watch(allocation, mintAllocationKey, { deep: true })

const openAllocate = () => {
  allocation.value = emptyAllocation()
  allocationError.value = null
  allocationUnconfirmed.value = false
  mintAllocationKey()
  allocateOpen.value = true
}

const modeOptions: Array<{ label: string, value: BillingMode }> = [
  { label: 'Token quota', value: 'TOKEN_QUOTA' },
  { label: 'Credit balance', value: 'CREDIT_BALANCE' }
]

const modeLabel = (mode: BillingMode) => mode === 'TOKEN_QUOTA' ? 'Token quota' : 'Credit balance'

/** Aliases the reseller holds inventory for, in the mode currently selected. */
const aliasOptions = computed(() =>
  fundable.value
    .filter(entry => entry.billing_mode === allocation.value.billing_mode)
    .map(entry => ({ label: entry.alias, value: entry.alias }))
)

const selectedFunding = computed(() =>
  fundable.value.find(entry =>
    entry.billing_mode === allocation.value.billing_mode
    && entry.alias === allocation.value.public_model_alias
  ) ?? null
)

const availableUnits = computed(() => selectedFunding.value?.available_units ?? '0')
const unitLabel = computed(() => selectedFunding.value?.unit_label ?? 'units')

/**
 * Units travel as a JSON integer. Past `Number.MAX_SAFE_INTEGER` the value loses
 * precision before it ever leaves the browser, so the form refuses it rather than
 * quietly transferring a different amount from the one that was typed.
 */
const MAX_SAFE_UNITS = String(Number.MAX_SAFE_INTEGER)

const validateAllocation = (state: AllocationFormState): FormError[] => {
  const errors: FormError[] = []

  if (!state.public_model_alias) {
    errors.push({ name: 'public_model_alias', message: 'Choose the model this quota is for.' })
  }

  const digits = state.units.trim()

  if (!digits) {
    errors.push({ name: 'units', message: 'Enter how many units to transfer.' })
  } else if (!/^\d+$/.test(digits)) {
    errors.push({ name: 'units', message: 'Enter a whole number of units, with no separators or decimal point.' })
  } else if (compareUnits(digits, '1') < 0) {
    errors.push({ name: 'units', message: 'Transfer at least one unit.' })
  } else if (compareUnits(digits, MAX_SAFE_UNITS) > 0) {
    errors.push({ name: 'units', message: 'That is more than one allocation can carry. Split it across several transfers.' })
  } else if (selectedFunding.value && compareUnits(digits, availableUnits.value) > 0) {
    errors.push({
      name: 'units',
      message: `You hold ${formatUnits(availableUnits.value)} ${unitLabel.value} for this model. SP Cambo refuses a transfer larger than your own inventory.`
    })
  }

  const reason = state.reason.trim()

  if (reason.length < 10) {
    errors.push({ name: 'reason', message: 'Write at least 10 characters — this goes into the audit trail.' })
  } else if (reason.length > 2000) {
    errors.push({ name: 'reason', message: 'Keep the reason to 2,000 characters or fewer.' })
  }

  return errors
}

const submitAllocation = async () => {
  const key = allocationKey.value

  if (!key) {
    mintAllocationKey()

    return
  }

  allocating.value = true
  allocationError.value = null
  allocationUnconfirmed.value = false

  const label = unitLabel.value

  try {
    const result = await api.reseller.allocate(customerId.value, {
      billing_mode: allocation.value.billing_mode,
      public_model_alias: allocation.value.public_model_alias,
      units: Number(allocation.value.units.trim()),
      idempotency_key: key,
      reason: allocation.value.reason.trim()
    })

    allocateOpen.value = false
    allocation.value = emptyAllocation()
    await inventory.refresh()

    toast.add({
      title: 'Quota allocated',
      description: `${formatUnits(result.units)} ${label} for ${result.public_model_alias} are now in ${customer.value?.name ?? 'the customer'}'s account.`,
      color: 'success',
      icon: 'i-lucide-arrow-right-left'
    })
  } catch (cause) {
    const error = toSpApiError(cause)

    allocationFormRef.value?.setErrors(
      Object.entries(error.errors).map(([name, messages]) => ({
        name,
        message: messages[0] ?? 'This value is not valid.'
      }))
    )

    /*
     * Only a genuine server fault leaves the transfer in doubt.
     *
     * `ResellerAllocationService` throws every one of its refusals before it
     * writes anything, inside `DB::transaction`, so a refusal it names has
     * definitely transferred nothing. The problem is that it throws bare
     * `InvalidArgumentException`s and `bootstrap/app.php` has no arm for them, so
     * an unmanaged customer and a reused idempotency key both arrive today as a
     * generic 500 — indistinguishable from a fault that really did leave the
     * outcome unknown. While that is the case the honest reading of a 500 is
     * "unconfirmed", and the alert says resubmitting this exact form cannot
     * transfer twice.
     *
     * A 409 is already handled here so the page improves the moment those
     * refusals are mapped: it is a definite answer, the backend's own sentence
     * explains it, and nothing needs re-checking. A 409 response remains the authoritative safe conflict signal.
     */
    allocationUnconfirmed.value = error.code === 'server_error'
    allocationError.value = error.isValidation ? null : error.message

    if (allocationUnconfirmed.value) {
      await inventory.refresh()
    }

    /*
     * A key that clashed is spent for these inputs and will clash again. Minting
     * a fresh one is safe precisely because a conflict transferred nothing —
     * which is why it must not be done for an unconfirmed 500, where a new key
     * would turn a retry into a second transfer.
     */
    if (error.isConflict) {
      mintAllocationKey()
      await inventory.refresh()
    }
  } finally {
    allocating.value = false
  }
}

/** ----------------------------------------------------------------- keys */

const keys = await useSpResource(
  'reseller:customer-keys',
  () => api.reseller.customerKeys(customerId.value),
  { server: false, immediate: false }
)

/*
 * Fetch only after the roster establishes that this is one of the reseller's
 * customers. The backend keeps existing keys readable and revocable after
 * suspension or closure, so relationship status must not suppress this request.
 */
const managedCustomerId = computed(() => customer.value?.id ?? null)

watch([customerId, managedCustomerId], ([id, managedId]) => {
  if (id && managedId === id) {
    keys.refresh()
  }
}, { immediate: true })

interface KeyFormState {
  label: string
  allowed_model_aliases: string[]
  expiry_date: string
}

const keyOpen = ref(false)
const creatingKey = ref(false)
const keyForm = ref<KeyFormState>({ label: '', allowed_model_aliases: [], expiry_date: '' })
const keyError = ref<string | null>(null)
const keyFormRef = useTemplateRef<{ setErrors: (errors: FormError[]) => void }>('keyFormRef')

const catalogAliasOptions = computed(() =>
  (models.data.value ?? []).map(model => ({ label: model.public_alias, value: model.public_alias }))
)

const resetKeyForm = () => {
  keyForm.value = { label: '', allowed_model_aliases: [], expiry_date: '' }
  keyError.value = null
  keyFormRef.value?.setErrors([])
}

const validateKey = (state: KeyFormState): FormError[] => {
  const errors: FormError[] = []

  if (!state.label.trim()) {
    errors.push({ name: 'label', message: 'Give the key a name the customer will recognise.' })
  } else if (state.label.trim().length > 100) {
    errors.push({ name: 'label', message: 'Keep the name to 100 characters or fewer.' })
  }

  if (state.expiry_date) {
    const parsed = new Date(`${state.expiry_date}T23:59:59Z`)

    if (Number.isNaN(parsed.getTime())) {
      errors.push({ name: 'expiry_date', message: 'That is not a valid date.' })
    } else if (parsed.getTime() <= Date.now()) {
      errors.push({ name: 'expiry_date', message: 'Choose a date in the future.' })
    }
  }

  return errors
}

/** ------------------------------------------------------ one-time reveal */

const revealOpen = ref(false)
const revealSecret = ref<string | null>(null)
const revealLabel = ref('')

const openReveal = (result: ResellerCustomerKeyCreated) => {
  revealSecret.value = result.secret
  revealLabel.value = result.key.label
  revealOpen.value = true
}

/** The secret must not outlive the dialog that showed it. */
const clearReveal = () => {
  revealSecret.value = null
  revealLabel.value = ''
}

const submitKey = async () => {
  creatingKey.value = true
  keyError.value = null

  try {
    const result = await api.reseller.createCustomerKey(customerId.value, {
      label: keyForm.value.label.trim(),
      allowed_model_aliases: keyForm.value.allowed_model_aliases.length > 0
        ? keyForm.value.allowed_model_aliases
        : undefined,
      expires_at: keyForm.value.expiry_date
        ? new Date(`${keyForm.value.expiry_date}T23:59:59Z`).toISOString()
        : null
    })

    keyOpen.value = false
    resetKeyForm()
    openReveal(result)
    await keys.refresh()
  } catch (cause) {
    const error = toSpApiError(cause)

    keyFormRef.value?.setErrors(
      Object.entries(error.errors).map(([name, messages]) => ({
        name,
        message: messages[0] ?? 'This value is not valid.'
      }))
    )

    keyError.value = error.isValidation ? null : error.message
  } finally {
    creatingKey.value = false
  }
}

/** ---------------------------------------------------------------- revoke */

const revokeTarget = ref<ResellerCustomerKey | null>(null)
const revoking = ref(false)

const confirmRevoke = async () => {
  const key = revokeTarget.value

  if (!key) {
    return
  }

  revoking.value = true

  try {
    await api.reseller.revokeCustomerKey(customerId.value, key.id)
    revokeTarget.value = null
    await keys.refresh()

    toast.add({
      title: 'Key revoked',
      description: `${key.label} stopped working immediately. This cannot be undone — issue a new key if the customer still needs one.`,
      color: 'warning',
      icon: 'i-lucide-shield-x'
    })
  } catch (cause) {
    toast.add({
      title: 'The key was not revoked',
      description: toSpApiError(cause).message,
      color: 'error',
      icon: 'i-lucide-circle-x'
    })
  } finally {
    revoking.value = false
  }
}

const activeKeyCount = computed(() => (keys.data.value ?? []).filter(key => key.status === 'ACTIVE').length)
</script>

<template>
  <SpDashboardPage
    :title="customer?.name ?? 'Managed customer'"
    icon="i-lucide-user-round"
    :description="customer
      ? `${customer.email} — labelled “${customer.label}” in your own records.`
      : 'Fund quota and issue inference keys for one customer you resell to.'"
  >
    <template #actions>
      <div class="flex flex-wrap justify-end gap-2">
        <UButton
          v-for="action in lifecycleActions"
          :key="action.action"
          :color="action.buttonColor"
          variant="subtle"
          :icon="action.icon"
          @click="openLifecycle(action)"
        >
          {{ action.label }}
        </UButton>
        <UButton
          to="/reseller"
          color="neutral"
          variant="ghost"
          icon="i-lucide-arrow-left"
        >
          All customers
        </UButton>
      </div>
    </template>

    <SpStateForbidden
      v-if="customers.forbidden.value"
      :code="customers.error.value?.code ?? null"
      permission="reseller.manage"
    />

    <SpAsyncSection
      v-else
      :loading="customers.initialLoading.value"
      :unavailable="customers.unavailable.value"
      :failed="customers.failed.value"
      :empty="notFound"
      :offline="customers.error.value?.code === 'network_unreachable'"
      :error-message="customers.error.value?.message"
      error-title="This customer could not be loaded"
      unavailable-title="Your customer list is not available"
      unavailable-description="SP Cambo could not be reached, so this customer cannot be looked up. Nothing about their account has changed."
      loading-variant="cards"
      @retry="customers.refresh()"
    >
      <template #empty>
        <SpStateEmpty
          title="That customer is not on your list"
          description="The id in this address does not match any customer you manage. It may have been removed, or it may belong to another reseller — in which case SP Cambo will never show it to you."
          icon="i-lucide-user-x"
        >
          <template #action>
            <UButton
              to="/reseller"
              color="neutral"
              variant="subtle"
              icon="i-lucide-arrow-left"
            >
              Back to your customers
            </UButton>
          </template>
        </SpStateEmpty>
      </template>

      <div
        v-if="customer"
        class="space-y-10"
      >
        <!-- Summary -->
        <section class="rounded-lg border border-default bg-elevated/30 p-4 sm:p-5">
          <dl class="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <div class="space-y-1.5">
              <dt class="text-xs text-dimmed">
                Status
              </dt>
              <dd>
                <SpStatusBadge :status="customer.status.toLowerCase()" />
              </dd>
            </div>
            <div class="space-y-1.5">
              <dt class="text-xs text-dimmed">
                Your label
              </dt>
              <dd class="truncate text-sm text-default">
                {{ customer.label }}
              </dd>
            </div>
            <div class="space-y-1.5">
              <dt class="text-xs text-dimmed">
                Created
              </dt>
              <dd class="text-sm text-default">
                {{ formatDate(customer.created_at) }}
              </dd>
            </div>
            <div class="space-y-1.5">
              <dt class="text-xs text-dimmed">
                Active keys
              </dt>
              <dd class="text-sm text-default">
                {{ keys.data.value ? activeKeyCount : '—' }}
              </dd>
            </div>
          </dl>
        </section>

        <UAlert
          v-if="!isActive"
          icon="i-lucide-user-x"
          color="warning"
          variant="subtle"
          :title="`This customer is ${customer.status.toLowerCase()}`"
          description="New allocations and inference-key issuance are paused. Existing keys remain visible, and you can still revoke any active key for credential cleanup."
        />

        <!-- Allocation -->
        <section class="space-y-4">
          <SpSectionHeading
            :level="3"
            title="Allocate quota"
            description="Units move out of your own inventory — this is a transfer, not a purchase. Nothing is charged to you here."
          >
            <template #actions>
              <UButton
                icon="i-lucide-arrow-right-left"
                size="sm"
                :disabled="!isActive || fundable.length === 0"
                @click="openAllocate()"
              >
                Allocate
              </UButton>
            </template>
          </SpSectionHeading>

          <SpAsyncSection
            :loading="inventory.initialLoading.value"
            :unavailable="inventory.unavailable.value"
            :forbidden="inventory.forbidden.value"
            :failed="inventory.failed.value"
            :empty="!inventory.initialLoading.value && inventory.error.value === null && fundable.length === 0"
            :offline="inventory.error.value?.code === 'network_unreachable'"
            :error-message="inventory.error.value?.message"
            :forbidden-code="inventory.error.value?.code ?? null"
            error-title="Your inventory could not be loaded"
            unavailable-title="Your inventory is not available"
            unavailable-description="An allocation draws on your own entitlement lots, and SP Cambo cannot read them right now. Nothing has been transferred."
            empty-title="You hold nothing to allocate"
            empty-description="An allocation moves units out of your own lots. Buy quota first, then come back to fund this customer."
            empty-icon="i-lucide-package-open"
            loading-variant="rows"
            @retry="inventory.refresh()"
          >
            <div class="space-y-3">
              <ul class="grid gap-3 sm:grid-cols-2">
                <li
                  v-for="entry in fundable"
                  :key="`${entry.billing_mode}:${entry.alias}`"
                  class="rounded-lg border border-default bg-elevated/30 p-4"
                >
                  <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0 space-y-1">
                      <code class="block truncate font-mono text-sm text-highlighted">{{ entry.alias }}</code>
                      <p class="text-xs text-muted">
                        {{ modeLabel(entry.billing_mode) }} ·
                        {{ entry.lot_count }} {{ entry.lot_count === 1 ? 'lot' : 'lots' }}
                      </p>
                    </div>
                    <div class="shrink-0 text-right">
                      <p class="font-mono text-sm text-highlighted">
                        {{ formatCompactUnits(entry.available_units) }}
                      </p>
                      <p class="text-xs text-dimmed">
                        {{ entry.unit_label }}
                      </p>
                    </div>
                  </div>
                  <p class="mt-2 text-xs text-muted">
                    {{ entry.next_expires_at
                      ? `Soonest lot expires ${formatDateTime(entry.next_expires_at)}`
                      : 'None of these lots expire' }}
                  </p>
                </li>
              </ul>

              <p
                v-if="aliasesOverlap"
                class="text-xs text-muted"
              >
                At least one of your lots permits several models, so the same units are counted under each of them.
                These figures cannot be added together.
              </p>
            </div>
          </SpAsyncSection>
        </section>

        <!-- Keys -->
        <section class="space-y-4">
          <SpSectionHeading
            :level="3"
            :title="keys.data.value ? `Inference keys (${activeKeyCount} active)` : 'Inference keys'"
            description="Keys you issue on the customer's behalf. They authenticate the customer's own requests and spend the customer's own quota."
          >
            <template #actions>
              <UButton
                color="neutral"
                variant="ghost"
                size="sm"
                icon="i-lucide-refresh-cw"
                :loading="keys.loading.value"
                @click="keys.refresh()"
              >
                Refresh
              </UButton>
              <UButton
                icon="i-lucide-plus"
                size="sm"
                :disabled="!isActive"
                @click="resetKeyForm(); keyOpen = true"
              >
                Issue key
              </UButton>
            </template>
          </SpSectionHeading>

          <SpAsyncSection
            :loading="keys.initialLoading.value"
            :unavailable="keys.unavailable.value"
            :forbidden="keys.forbidden.value"
            :failed="keys.failed.value"
            :empty="keys.isEmpty.value"
            :offline="keys.error.value?.code === 'network_unreachable'"
            :error-message="keys.error.value?.message"
            :forbidden-code="keys.error.value?.code ?? null"
            forbidden-permission="reseller.manage"
            error-title="This customer's keys could not be loaded"
            empty-title="No keys issued yet"
            empty-description="Issue one so the customer can connect Claude Code, Codex CLI or their own SDK integration."
            empty-icon="i-lucide-key-round"
            loading-variant="rows"
            @retry="keys.refresh()"
          >
            <ul
              v-if="keys.data.value"
              class="space-y-3"
            >
              <li
                v-for="key in keys.data.value"
                :key="key.id"
                class="rounded-lg border border-default bg-elevated/30 p-4"
                :class="key.status === 'ACTIVE' ? undefined : 'opacity-60'"
              >
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                  <div class="min-w-0 space-y-2">
                    <div class="flex flex-wrap items-center gap-2">
                      <p class="truncate font-medium text-highlighted">
                        {{ key.label }}
                      </p>
                      <SpStatusBadge :status="key.status.toLowerCase()" />
                    </div>

                    <code class="block font-mono text-xs text-muted">
                      {{ maskApiKey(key.prefix, key.last_four) }}
                    </code>

                    <dl class="flex flex-wrap gap-x-5 gap-y-1 text-xs text-muted">
                      <div class="flex gap-1.5">
                        <dt class="text-dimmed">
                          Issued
                        </dt>
                        <dd>{{ formatDate(key.created_at) }}</dd>
                      </div>
                      <div class="flex gap-1.5">
                        <dt class="text-dimmed">
                          Last used
                        </dt>
                        <dd>{{ key.last_used_at ? formatDateTime(key.last_used_at) : 'Never' }}</dd>
                      </div>
                      <div class="flex gap-1.5">
                        <dt class="text-dimmed">
                          Expires
                        </dt>
                        <dd>{{ key.expires_at ? formatDateTime(key.expires_at) : 'No expiry' }}</dd>
                      </div>
                    </dl>

                    <div class="flex flex-wrap items-center gap-1.5">
                      <span class="text-xs text-dimmed">Scope</span>
                      <template v-if="key.allowed_model_aliases.length > 0">
                        <UBadge
                          v-for="alias in key.allowed_model_aliases"
                          :key="alias"
                          color="neutral"
                          variant="subtle"
                          size="sm"
                          class="font-mono"
                        >
                          {{ alias }}
                        </UBadge>
                      </template>
                      <span
                        v-else
                        class="text-xs text-muted"
                      >Every model the customer's own quota allows</span>
                    </div>
                  </div>

                  <UButton
                    color="error"
                    variant="subtle"
                    size="sm"
                    icon="i-lucide-shield-x"
                    class="shrink-0"
                    :disabled="key.status === 'REVOKED'"
                    @click="revokeTarget = key"
                  >
                    Revoke
                  </UButton>
                </div>
              </li>
            </ul>
          </SpAsyncSection>

          <p class="text-sm text-muted">
            A key issued here cannot be rotated, by you or by the customer — revoke it and issue a new one instead.
            SP Cambo shows each secret once, at the moment it is created.
          </p>
        </section>
      </div>
    </SpAsyncSection>

    <!-- Customer lifecycle -->
    <UModal
      :open="lifecycleOpen"
      :title="lifecycleTarget?.title ?? 'Update customer status'"
      :description="lifecycleTarget?.description ?? ''"
      @update:open="open => { if (!open) closeLifecycle() }"
    >
      <template #body>
        <UForm
          ref="lifecycleFormRef"
          :state="lifecycle"
          :validate="validateLifecycle"
          :validate-on="['blur', 'change']"
          class="space-y-5"
          @submit="submitLifecycle"
        >
          <UAlert
            v-if="lifecycleError"
            role="alert"
            icon="i-lucide-circle-alert"
            color="error"
            variant="subtle"
            :description="lifecycleError"
          />

          <UFormField
            label="Reason"
            name="reason"
            required
            help="Recorded in the immutable audit trail. Use 10–2,000 characters."
          >
            <UTextarea
              v-model="lifecycle.reason"
              :rows="4"
              autofocus
              placeholder="Reason for changing this managed-customer relationship"
              class="w-full"
            />
          </UFormField>

          <UAlert
            v-if="lifecycleTarget?.action === 'close'"
            icon="i-lucide-triangle-alert"
            color="warning"
            variant="subtle"
            title="This cannot be reversed"
            description="A closed managed-customer relationship is terminal. You will still be able to review and revoke existing keys."
          />

          <div class="flex justify-end gap-2 pt-1">
            <UButton
              color="neutral"
              variant="ghost"
              :disabled="updatingLifecycle"
              @click="closeLifecycle()"
            >
              Cancel
            </UButton>
            <UButton
              type="submit"
              :color="lifecycleTarget?.buttonColor ?? 'primary'"
              :icon="lifecycleTarget?.icon"
              :loading="updatingLifecycle"
            >
              {{ lifecycleTarget?.confirmation ?? 'Update customer status' }}
            </UButton>
          </div>
        </UForm>
      </template>
    </UModal>

    <!-- Allocate -->
    <UModal
      v-model:open="allocateOpen"
      title="Allocate quota to this customer"
      description="Units leave your inventory and land in the customer's account immediately, with the soonest-expiring of your lots spent first."
    >
      <template #body>
        <UForm
          ref="allocationFormRef"
          :state="allocation"
          :validate="validateAllocation"
          :validate-on="['blur', 'change']"
          class="space-y-5"
          @submit="submitAllocation"
        >
          <!--
            Announced, and the highest-stakes announcement in the product: the
            reseller is being told that real quota may or may not have moved. A
            silent banner here means someone who cannot see the screen retries a
            transfer whose outcome nobody knows.
          -->
          <UAlert
            v-if="allocationUnconfirmed"
            role="alert"
            icon="i-lucide-triangle-alert"
            color="warning"
            variant="subtle"
            title="SP Cambo could not confirm this transfer"
            description="The control plane returned a server error without saying whether the transfer was recorded. Your inventory figures behind this dialog have been reloaded — check them. Submitting this same form again cannot transfer twice; changing any value in it starts a new transfer."
          />

          <UAlert
            v-else-if="allocationError"
            role="alert"
            icon="i-lucide-circle-alert"
            color="error"
            variant="subtle"
            :description="allocationError"
          />

          <UFormField
            label="Billing mode"
            name="billing_mode"
            required
            help="Has to match the lots you hold. A token-quota lot cannot fund a credit balance."
          >
            <USelectMenu
              v-model="allocation.billing_mode"
              :items="modeOptions"
              value-key="value"
              class="w-full"
            />
          </UFormField>

          <UFormField
            label="Model"
            name="public_model_alias"
            required
            :help="aliasOptions.length === 0
              ? 'You hold no lots for this billing mode. Switch mode, or buy quota first.'
              : 'Only models you currently hold inventory for are listed.'"
          >
            <USelectMenu
              v-model="allocation.public_model_alias"
              :items="aliasOptions"
              value-key="value"
              :disabled="aliasOptions.length === 0"
              placeholder="Choose a model"
              class="w-full"
            />
          </UFormField>

          <UFormField
            label="Units"
            name="units"
            required
          >
            <UInput
              v-model="allocation.units"
              inputmode="numeric"
              autocomplete="off"
              placeholder="1000000"
              class="w-full"
            />
            <template #help>
              <span v-if="selectedFunding">
                You hold
                <strong class="text-highlighted">{{ formatUnits(availableUnits) }}</strong>
                {{ unitLabel }} for this model, across
                {{ selectedFunding.lot_count }} {{ selectedFunding.lot_count === 1 ? 'lot' : 'lots' }}.
              </span>
              <span v-else>Whole units only. Choose a model to see what you hold.</span>
            </template>
          </UFormField>

          <UFormField
            label="Reason"
            name="reason"
            required
            help="Recorded in the audit trail against both accounts. At least 10 characters."
          >
            <UTextarea
              v-model="allocation.reason"
              :rows="3"
              placeholder="Monthly top-up agreed with the customer"
              class="w-full"
            />
          </UFormField>

          <UAlert
            icon="i-lucide-info"
            color="neutral"
            variant="subtle"
            title="This cannot be reversed here"
            description="SP Cambo has no route to pull an allocation back. The units become the customer's, and expire when the lot they came from expires."
          />

          <div class="flex justify-end gap-2 pt-1">
            <UButton
              color="neutral"
              variant="ghost"
              :disabled="allocating"
              @click="allocateOpen = false"
            >
              Cancel
            </UButton>
            <UButton
              type="submit"
              :loading="allocating"
            >
              Allocate units
            </UButton>
          </div>
        </UForm>
      </template>
    </UModal>

    <!-- Issue key -->
    <UModal
      v-model:open="keyOpen"
      title="Issue an inference key"
      description="The secret is shown once. You will need to deliver it to the customer yourself."
    >
      <template #body>
        <UForm
          ref="keyFormRef"
          :state="keyForm"
          :validate="validateKey"
          class="space-y-5"
          @submit="submitKey"
        >
          <UAlert
            v-if="keyError"
            role="alert"
            icon="i-lucide-circle-alert"
            color="error"
            variant="subtle"
            :description="keyError"
          />

          <UFormField
            label="Name"
            name="label"
            required
            help="Shown to the customer in their own key list. Name it after where it will run."
          >
            <UInput
              v-model="keyForm.label"
              placeholder="Production worker"
              autofocus
              class="w-full"
            />
          </UFormField>

          <UFormField
            label="Model scope"
            name="allowed_model_aliases"
            :help="models.unavailable.value
              ? 'The model catalogue is not published yet, so scoping cannot be set here. The key will allow every model the customer\'s quota permits.'
              : 'Leave empty to allow every model the customer\'s own quota permits.'"
          >
            <USelectMenu
              v-model="keyForm.allowed_model_aliases"
              :items="catalogAliasOptions"
              value-key="value"
              multiple
              :disabled="models.unavailable.value || catalogAliasOptions.length === 0"
              :loading="models.loading.value"
              placeholder="All permitted models"
              class="w-full"
            />
          </UFormField>

          <UFormField
            label="Expiry"
            name="expiry_date"
            help="Optional. The key stops working at 23:59:59 UTC on this date."
          >
            <UInput
              v-model="keyForm.expiry_date"
              type="date"
              class="w-full"
            />
          </UFormField>

          <div class="flex justify-end gap-2 pt-1">
            <UButton
              color="neutral"
              variant="ghost"
              :disabled="creatingKey"
              @click="keyOpen = false"
            >
              Cancel
            </UButton>
            <UButton
              type="submit"
              :loading="creatingKey"
            >
              Issue key
            </UButton>
          </div>
        </UForm>
      </template>
    </UModal>

    <SpApiKeyRevealModal
      v-model:open="revealOpen"
      :secret="revealSecret"
      :key-label="revealLabel"
      context="created"
      audience="managed"
      :owner-label="customer?.name ?? null"
      @close="clearReveal"
    />

    <!-- Revoke -->
    <UModal
      :open="revokeTarget !== null"
      title="Revoke this key?"
      description="It stops authenticating requests the moment you confirm, and cannot be re-enabled."
      @update:open="revokeTarget = null"
    >
      <template #body>
        <div class="space-y-4">
          <p class="text-sm text-muted">
            Anything the customer is running with
            <strong class="text-highlighted">{{ revokeTarget?.label }}</strong>
            will start failing with an authentication error straight away. Their quota is untouched — only this
            credential stops working.
          </p>

          <div class="flex justify-end gap-2">
            <UButton
              color="neutral"
              variant="ghost"
              :disabled="revoking"
              @click="revokeTarget = null"
            >
              Keep it
            </UButton>
            <UButton
              color="error"
              icon="i-lucide-shield-x"
              :loading="revoking"
              @click="confirmRevoke"
            >
              Revoke permanently
            </UButton>
          </div>
        </div>
      </template>
    </UModal>
  </SpDashboardPage>
</template>
