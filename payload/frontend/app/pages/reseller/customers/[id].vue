<script setup lang="ts">
import type { FormError } from '@nuxt/ui'
import type { EntitlementLot } from '~/types/commerce'
import type {
  ResellerAllocation,
  ResellerCustomerKey,
  ResellerCustomerKeyCreated,
  ResellerCustomerStatus
} from '~/types/reseller'

definePageMeta({
  layout: 'dashboard',
  middleware: ['auth']
})

const route = useRoute()
const api = useSpApi()
const toast = useToast()

const customerId = computed(() => String(route.params.id ?? ''))

const customers = await useSpResource(
  'reseller:customers',
  () => api.reseller.customers(),
  { server: false }
)

const customer = computed(() =>
  (customers.data.value ?? []).find(entry => entry.id === customerId.value) ?? null
)

const notFound = computed(() =>
  !customers.initialLoading.value
  && customers.error.value === null
  && customers.data.value !== null
  && customer.value === null
)

const isActive = computed(() => customer.value?.status === 'ACTIVE')

useSeoMeta({
  title: () => customer.value
    ? `${customer.value.name} — managed customer`
    : 'Managed customer',
  description: 'Sell package quota and manage inference keys for one reseller customer.',
  robots: 'noindex, nofollow'
})

/* -------------------------------------------------------------------------- */
/* Reseller inventory                                                         */
/* -------------------------------------------------------------------------- */

const inventory = await useSpResource(
  'reseller:inventory',
  () => api.reseller.inventory(),
  { server: false }
)

const now = ref(Date.now())
let clock: ReturnType<typeof setInterval> | undefined

onMounted(() => {
  clock = setInterval(() => {
    now.value = Date.now()
  }, 60_000)
})

onUnmounted(() => {
  if (clock !== undefined) clearInterval(clock)
})

const availableUnitsForLot = (lot: EntitlementLot): string => {
  const remaining = BigInt(lot.remaining_units || '0')
  const reserved = BigInt(lot.reserved_units || '0')
  const available = remaining - reserved

  return (available > 0n ? available : 0n).toString()
}

const cleanCreditLabel = (value: string): string =>
  value.replace(/\$(?=\d[\d,]*(?:\.\d+)?\s+Credits?\b)/gi, '')

const displayAvailableForLot = (lot: EntitlementLot): string =>
  lot.display?.available ?? availableUnitsForLot(lot)

const displayUnitForLot = (lot: EntitlementLot): string =>
  lot.display?.unit_label ?? lot.unit_label

const rawUnitsToDisplay = (rawUnits: string, scaleUnits: string): string => {
  try {
    const raw = BigInt(rawUnits || '0')
    const scale = BigInt(scaleUnits || '1')
    if (scale <= 1n) return raw.toString()

    const whole = raw / scale
    const remainder = raw % scale
    if (remainder === 0n) return whole.toString()

    const precision = Math.max(0, String(scale).length - 1)
    const fraction = remainder.toString().padStart(precision, '0').replace(/0+$/, '')
    return `${whole}.${fraction}`
  } catch {
    return rawUnits
  }
}

const displayLotQuantity = (lot: EntitlementLot, rawUnits?: string): string => {
  if (lot.display) {
    const value = rawUnits === undefined
      ? lot.display.available
      : rawUnitsToDisplay(rawUnits, lot.display.raw_units_per_display_unit)
    return formatDecimalQuantity(value, lot.display.unit_label)
  }

  return `${formatUnits(rawUnits ?? availableUnitsForLot(lot))} ${lot.unit_label}`
}

const transferDisplayValue = (
  rawUnits: string,
  display?: ResellerAllocation['display'] | null
): string => {
  if (!display) return formatCompactUnits(rawUnits)
  return formatDecimalQuantity(
    rawUnitsToDisplay(rawUnits, display.raw_units_per_display_unit),
    display.unit_label
  )
}

/**
 * IMPORTANT:
 * Show ONE row/card per purchased reseller package lot.
 *
 * Do not expand one package into one row per model. A Claude package that permits
 * nine Claude aliases is still one shared balance, not nine different balances.
 */
const packageInventory = computed(() =>
  (inventory.data.value ?? []).filter((lot) => {
    if (lot.source !== 'RESELLER_STOCK') return false
    if (lot.access_scope !== 'RESELLER') return false
    if (lot.status !== 'ACTIVE') return false
    if (availableUnitsForLot(lot) === '0') return false

    return !lot.expires_at || Date.parse(lot.expires_at) > now.value
  })
)

const packageOptions = computed(() =>
  packageInventory.value.map(lot => ({
    label: `${cleanCreditLabel(lot.package_name)} — ${displayLotQuantity(lot)} remaining`,
    value: lot.id
  }))
)

/* -------------------------------------------------------------------------- */
/* Package allocation                                                         */
/* -------------------------------------------------------------------------- */

interface PackageAllocationForm {
  inventory_lot_id: string
  units: string
  reason: string
}

const emptyAllocation = (): PackageAllocationForm => ({
  inventory_lot_id: '',
  units: '',
  reason: ''
})

const allocateOpen = ref(false)
const allocating = ref(false)
const allocation = ref<PackageAllocationForm>(emptyAllocation())
const allocationError = ref<string | null>(null)
const allocationFormRef = useTemplateRef<{ setErrors: (errors: FormError[]) => void }>('allocationFormRef')
const allocationKey = ref<string | null>(null)

const selectedPackage = computed(() =>
  packageInventory.value.find(lot => lot.id === allocation.value.inventory_lot_id) ?? null
)

const selectedAvailableUnits = computed(() =>
  selectedPackage.value ? availableUnitsForLot(selectedPackage.value) : '0'
)

const selectedAvailableDisplay = computed(() =>
  selectedPackage.value?.display?.available ?? selectedAvailableUnits.value
)

const selectedDisplayUnit = computed(() =>
  selectedPackage.value?.display?.unit_label ?? selectedPackage.value?.unit_label ?? 'units'
)

const selectedSupportsFractions = computed(() =>
  (selectedPackage.value?.display?.raw_units_per_display_unit ?? '1') !== '1'
)

const mintAllocationKey = () => {
  try {
    allocationKey.value = newIdempotencyKey('package-sale')
  } catch {
    allocationKey.value = null
    allocationError.value = 'This browser could not create the safety key required for this sale. Reload over HTTPS and try again.'
  }
}

watch(allocation, mintAllocationKey, { deep: true })

const openAllocate = (lot?: EntitlementLot) => {
  allocation.value = {
    inventory_lot_id: lot?.id ?? '',
    units: '',
    reason: ''
  }
  allocationError.value = null
  mintAllocationKey()
  allocateOpen.value = true
}

const validateAllocation = (state: PackageAllocationForm): FormError[] => {
  const errors: FormError[] = []

  if (!state.inventory_lot_id) {
    errors.push({
      name: 'inventory_lot_id',
      message: 'Choose which package inventory to sell from.'
    })
  }

  const digits = state.units.trim()
  const validQuantity = selectedSupportsFractions.value
    ? /^\d+(?:\.\d{1,5})?$/.test(digits)
    : /^\d+$/.test(digits)

  if (!digits) {
    errors.push({ name: 'units', message: `Enter how many ${selectedDisplayUnit.value} to sell.` })
  } else if (!validQuantity) {
    errors.push({
      name: 'units',
      message: selectedSupportsFractions.value
        ? 'Enter a positive amount with up to 5 decimal places.'
        : 'Enter a whole number with no commas or decimal point.'
    })
  } else if (compareDecimalQuantities(digits, '0') <= 0) {
    errors.push({ name: 'units', message: 'Sell more than zero.' })
  } else if (
    selectedPackage.value
    && compareDecimalQuantities(digits, selectedAvailableDisplay.value) > 0
  ) {
    errors.push({
      name: 'units',
      message: `Only ${formatDecimalQuantity(selectedAvailableDisplay.value, selectedDisplayUnit.value)} remain in this package.`
    })
  }

  const reason = state.reason.trim()

  if (reason.length < 10) {
    errors.push({
      name: 'reason',
      message: 'Write at least 10 characters for the audit trail.'
    })
  } else if (reason.length > 2000) {
    errors.push({
      name: 'reason',
      message: 'Keep the reason to 2,000 characters or fewer.'
    })
  }

  return errors
}

const submitAllocation = async () => {
  const idempotencyKey = allocationKey.value

  if (!idempotencyKey) {
    mintAllocationKey()
    return
  }

  allocating.value = true
  allocationError.value = null

  try {
    const result = await api.request<ResellerAllocation>(
      `/reseller/customers/${encodeURIComponent(customerId.value)}/allocations`,
      {
        method: 'POST',
        body: {
          inventory_lot_id: allocation.value.inventory_lot_id,
          display_units: allocation.value.units.trim(),
          idempotency_key: idempotencyKey,
          reason: allocation.value.reason.trim()
        }
      }
    )

    allocateOpen.value = false
    allocation.value = emptyAllocation()

    await Promise.all([
      inventory.refresh(),
      allocationHistory.refresh()
    ])

    toast.add({
      title: 'Package quota sold',
      description: `${result.display?.sold
        ? formatDecimalQuantity(result.display.sold, result.display.unit_label)
        : formatUnits(result.units)} from ${result.package_name ?? 'the package'} are now in ${customer.value?.name ?? 'the customer'}'s account and share the package's included models.`,
      color: 'success',
      icon: 'i-lucide-package-check'
    })
  } catch (cause) {
    const error = toSpApiError(cause)

    allocationFormRef.value?.setErrors(
      Object.entries(error.errors).map(([name, messages]) => ({
        name,
        message: messages[0] ?? 'This value is not valid.'
      }))
    )

    allocationError.value = error.isValidation ? null : error.message

    if (error.isConflict || error.code === 'server_error') {
      await inventory.refresh()
    }
  } finally {
    allocating.value = false
  }
}

/* -------------------------------------------------------------------------- */
/* Reporting                                                                  */
/* -------------------------------------------------------------------------- */

const allocationHistory = await useSpResource(
  'reseller:customer-allocations',
  () => api.reseller.customerAllocations(customerId.value, { limit: 50 }),
  { server: false, immediate: false }
)


/* -------------------------------------------------------------------------- */
/* Keys                                                                       */
/* -------------------------------------------------------------------------- */

const keys = await useSpResource(
  'reseller:customer-keys',
  () => api.reseller.customerKeys(customerId.value),
  { server: false, immediate: false }
)

const managedCustomerId = computed(() => customer.value?.id ?? null)

watch([customerId, managedCustomerId], ([id, managedId]) => {
  if (id && managedId === id) {
    keys.refresh()
    allocationHistory.refresh()
  }
}, { immediate: true })

const customerAllowedAliases = computed(() => {
  const aliases = new Set<string>()

  for (const transfer of allocationHistory.data.value ?? []) {
    for (const alias of transfer.allowed_model_aliases ?? []) {
      aliases.add(alias)
    }
  }

  return [...aliases].sort()
})

const customerModelOptions = computed(() =>
  customerAllowedAliases.value.map(alias => ({
    label: alias,
    value: alias
  }))
)

interface KeyFormState {
  label: string
  allowed_model_aliases: string[]
  expiry_date: string
}

const keyOpen = ref(false)
const creatingKey = ref(false)
const keyForm = ref<KeyFormState>({
  label: '',
  allowed_model_aliases: [],
  expiry_date: ''
})
const keyError = ref<string | null>(null)
const keyFormRef = useTemplateRef<{ setErrors: (errors: FormError[]) => void }>('keyFormRef')

const openKey = () => {
  keyForm.value = {
    label: '',
    // By default scope the new key to every model this managed customer actually
    // received through reseller allocations.
    allowed_model_aliases: [...customerAllowedAliases.value],
    expiry_date: ''
  }
  keyError.value = null
  keyFormRef.value?.setErrors([])
  keyOpen.value = true
}

const validateKey = (state: KeyFormState): FormError[] => {
  const errors: FormError[] = []

  if (!state.label.trim()) {
    errors.push({
      name: 'label',
      message: 'Give the key a name the customer will recognise.'
    })
  } else if (state.label.trim().length > 100) {
    errors.push({
      name: 'label',
      message: 'Keep the key name to 100 characters or fewer.'
    })
  }

  if (state.allowed_model_aliases.length === 0) {
    errors.push({
      name: 'allowed_model_aliases',
      message: 'Choose at least one model funded for this customer.'
    })
  }

  if (state.expiry_date) {
    const parsed = new Date(`${state.expiry_date}T23:59:59Z`)

    if (Number.isNaN(parsed.getTime())) {
      errors.push({ name: 'expiry_date', message: 'That is not a valid date.' })
    } else if (parsed.getTime() <= Date.now()) {
      errors.push({
        name: 'expiry_date',
        message: 'Choose a date in the future.'
      })
    }
  }

  return errors
}

const revealOpen = ref(false)
const revealSecret = ref<string | null>(null)
const revealLabel = ref('')

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
      allowed_model_aliases: keyForm.value.allowed_model_aliases,
      expires_at: keyForm.value.expiry_date
        ? new Date(`${keyForm.value.expiry_date}T23:59:59Z`).toISOString()
        : null
    })

    keyOpen.value = false
    revealSecret.value = result.secret
    revealLabel.value = result.key.label
    revealOpen.value = true
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

const revokeTarget = ref<ResellerCustomerKey | null>(null)
const revoking = ref(false)

const confirmRevoke = async () => {
  const key = revokeTarget.value
  if (!key) return

  revoking.value = true

  try {
    await api.reseller.revokeCustomerKey(customerId.value, key.id)
    revokeTarget.value = null
    await keys.refresh()

    toast.add({
      title: 'Key revoked',
      description: `${key.label} stopped working immediately.`,
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

const activeKeyCount = computed(() =>
  (keys.data.value ?? []).filter(key => key.status === 'ACTIVE').length
)

/* -------------------------------------------------------------------------- */
/* Customer lifecycle                                                         */
/* -------------------------------------------------------------------------- */

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
}

const lifecycleMap: Record<LifecycleAction, LifecycleActionDetails> = {
  suspend: {
    action: 'suspend',
    status: 'SUSPENDED',
    label: 'Suspend',
    title: 'Suspend this customer?',
    description: 'New sales and new inference keys will pause until the relationship is reactivated.',
    confirmation: 'Suspend customer',
    buttonColor: 'warning',
    icon: 'i-lucide-pause-circle'
  },
  reactivate: {
    action: 'reactivate',
    status: 'ACTIVE',
    label: 'Reactivate',
    title: 'Reactivate this customer?',
    description: 'Package sales and key issuance will be available again.',
    confirmation: 'Reactivate customer',
    buttonColor: 'success',
    icon: 'i-lucide-circle-play'
  },
  close: {
    action: 'close',
    status: 'CLOSED',
    label: 'Close',
    title: 'Close this customer?',
    description: 'This permanently closes the managed-customer relationship.',
    confirmation: 'Close customer permanently',
    buttonColor: 'error',
    icon: 'i-lucide-circle-x'
  }
}

const lifecycleActions = computed(() => {
  if (customer.value?.status === 'ACTIVE') {
    return [lifecycleMap.suspend, lifecycleMap.close]
  }

  if (customer.value?.status === 'SUSPENDED') {
    return [lifecycleMap.reactivate, lifecycleMap.close]
  }

  return []
})

const lifecycleOpen = ref(false)
const lifecycleTarget = ref<LifecycleActionDetails | null>(null)
const lifecycle = ref<LifecycleFormState>({ reason: '' })
const lifecycleError = ref<string | null>(null)
const updatingLifecycle = ref(false)

const openLifecycle = (action: LifecycleActionDetails) => {
  lifecycleTarget.value = action
  lifecycle.value = { reason: '' }
  lifecycleError.value = null
  lifecycleOpen.value = true
}

const validateLifecycle = (state: LifecycleFormState): FormError[] => {
  const reason = state.reason.trim()

  if (reason.length < 10) {
    return [{
      name: 'reason',
      message: 'Write at least 10 characters for the audit trail.'
    }]
  }

  if (reason.length > 2000) {
    return [{
      name: 'reason',
      message: 'Keep the reason to 2,000 characters or fewer.'
    }]
  }

  return []
}

const submitLifecycle = async () => {
  const target = lifecycleTarget.value
  if (!target || !customer.value) return

  updatingLifecycle.value = true
  lifecycleError.value = null

  try {
    await api.reseller.updateCustomerStatus(customer.value.id, {
      status: target.status,
      reason: lifecycle.value.reason.trim()
    })

    lifecycleOpen.value = false
    lifecycleTarget.value = null

    await Promise.all([
      customers.refresh(),
      keys.refresh(),
      inventory.refresh()
    ])

    toast.add({
      title: `Customer ${target.status.toLowerCase()}`,
      description: `${customer.value?.name ?? 'The customer'} is now ${target.status.toLowerCase()}.`,
      color: target.status === 'ACTIVE' ? 'success' : 'warning',
      icon: target.icon
    })
  } catch (cause) {
    const error = toSpApiError(cause)
    lifecycleError.value = error.message
  } finally {
    updatingLifecycle.value = false
  }
}
</script>

<template>
  <SpDashboardPage
    :title="customer?.name ?? 'Managed customer'"
    icon="i-lucide-user-round"
    :description="customer
      ? `${customer.email} — ${customer.label}`
      : 'Sell package quota and manage API keys for this customer.'"
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
      loading-variant="cards"
      @retry="customers.refresh()"
    >
      <template #empty>
        <SpStateEmpty
          title="Customer not found"
          description="This customer is not in your managed-customer list."
          icon="i-lucide-user-x"
        />
      </template>

      <div
        v-if="customer"
        class="space-y-10"
      >
        <section class="rounded-lg border border-default bg-elevated/30 p-4 sm:p-5">
          <dl class="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <div class="space-y-1.5">
              <dt class="text-xs text-dimmed">Status</dt>
              <dd>
                <SpStatusBadge :status="customer.status.toLowerCase()" />
              </dd>
            </div>

            <div class="space-y-1.5">
              <dt class="text-xs text-dimmed">Your label</dt>
              <dd class="truncate text-sm text-default">
                {{ customer.label }}
              </dd>
            </div>

            <div class="space-y-1.5">
              <dt class="text-xs text-dimmed">Created</dt>
              <dd class="text-sm text-default">
                {{ formatDate(customer.created_at) }}
              </dd>
            </div>

            <div class="space-y-1.5">
              <dt class="text-xs text-dimmed">Active keys</dt>
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
          description="New package sales and new API keys are paused."
        />

        <!-- Package inventory: ONE card per package, never one card per model -->
        <section class="space-y-4">
          <SpSectionHeading
            :level="3"
            title="Sell package quota"
            description="Each card is one real reseller inventory package. All model badges inside a card share the same remaining balance."
          >
            <template #actions>
              <UButton
                icon="i-lucide-package-plus"
                size="sm"
                :disabled="!isActive || packageInventory.length === 0"
                @click="openAllocate()"
              >
                Sell quota
              </UButton>
            </template>
          </SpSectionHeading>

          <SpAsyncSection
            :loading="inventory.initialLoading.value"
            :unavailable="inventory.unavailable.value"
            :forbidden="inventory.forbidden.value"
            :failed="inventory.failed.value"
            :empty="!inventory.initialLoading.value && inventory.error.value === null && packageInventory.length === 0"
            :offline="inventory.error.value?.code === 'network_unreachable'"
            :error-message="inventory.error.value?.message"
            empty-title="No reseller inventory"
            empty-description="Buy a normal package with the reseller account first."
            empty-icon="i-lucide-package-open"
            loading-variant="rows"
            @retry="inventory.refresh()"
          >
            <div class="grid gap-4 lg:grid-cols-2">
              <article
                v-for="lot in packageInventory"
                :key="lot.id"
                class="rounded-xl border border-default bg-elevated/30 p-5"
              >
                <div class="flex items-start justify-between gap-4">
                  <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                      <h3 class="truncate font-medium text-highlighted">
                        {{ cleanCreditLabel(lot.package_name) }}
                      </h3>
                      <UBadge
                        color="primary"
                        variant="subtle"
                        size="sm"
                      >
                        Reseller inventory
                      </UBadge>
                    </div>

                    <p class="mt-1 text-xs text-muted">
                      {{ lot.family_label }} ·
                      {{ lot.billing_mode === 'TOKEN_QUOTA' ? 'Token quota' : 'Credit balance' }}
                    </p>
                  </div>

                  <div class="shrink-0 text-right">
                    <p class="sp-numeric text-xl font-semibold text-highlighted">
                      {{ displayLotQuantity(lot) }}
                    </p>
                    <p class="text-xs text-dimmed">
                      remaining
                    </p>
                  </div>
                </div>

                <div class="mt-4 flex flex-wrap gap-1.5">
                  <SpModelBadge
                    v-for="alias in lot.allowed_model_aliases"
                    :key="alias"
                    :model="alias"
                    compact
                  />
                </div>

                <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-default pt-3">
                  <p class="text-xs text-muted">
                    {{ lot.allowed_model_aliases.length }} models share this balance
                    <template v-if="lot.expires_at">
                      · expires {{ formatDateTime(lot.expires_at) }}
                    </template>
                  </p>

                  <UButton
                    size="sm"
                    variant="subtle"
                    icon="i-lucide-arrow-right"
                    :disabled="!isActive"
                    @click="openAllocate(lot)"
                  >
                    Sell from package
                  </UButton>
                </div>
              </article>
            </div>
          </SpAsyncSection>
        </section>

        <!-- Allocation history -->
        <section class="space-y-4">
          <SpSectionHeading
            :level="3"
            title="Sales history"
            description="Package quota already transferred from your inventory to this customer."
          >
            <template #actions>
              <UButton
                color="neutral"
                variant="ghost"
                size="sm"
                icon="i-lucide-refresh-cw"
                :loading="allocationHistory.loading.value"
                @click="allocationHistory.refresh()"
              >
                Refresh
              </UButton>
            </template>
          </SpSectionHeading>

          <SpAsyncSection
            :loading="allocationHistory.initialLoading.value"
            :unavailable="allocationHistory.unavailable.value"
            :failed="allocationHistory.failed.value"
            :empty="allocationHistory.isEmpty.value"
            :offline="allocationHistory.error.value?.code === 'network_unreachable'"
            :error-message="allocationHistory.error.value?.message"
            empty-title="No sales yet"
            empty-description="Package quota sold to this customer will appear here."
            empty-icon="i-lucide-receipt"
            loading-variant="rows"
            @retry="allocationHistory.refresh()"
          >
            <ul
              v-if="allocationHistory.data.value"
              class="space-y-3"
            >
              <li
                v-for="transfer in allocationHistory.data.value"
                :key="transfer.id"
                class="rounded-lg border border-default bg-elevated/30 p-4"
              >
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                  <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                      <p class="font-medium text-highlighted">
                        {{ transfer.package_name ? cleanCreditLabel(transfer.package_name) : (transfer.public_model_alias ?? 'Quota allocation') }}
                      </p>

                      <UBadge
                        v-if="transfer.allocation_kind === 'PACKAGE'"
                        color="primary"
                        variant="subtle"
                        size="sm"
                      >
                        Multi-model package
                      </UBadge>
                    </div>

                    <p class="mt-1 text-xs text-muted">
                      {{ formatDateTime(transfer.created_at) }} · {{ transfer.reason }}
                    </p>

                    <div
                      v-if="transfer.allowed_model_aliases?.length"
                      class="mt-3 flex flex-wrap gap-1.5"
                    >
                      <SpModelBadge
                        v-for="alias in transfer.allowed_model_aliases"
                        :key="alias"
                        :model="alias"
                        compact
                      />
                    </div>
                  </div>

                  <div class="shrink-0 text-right">
                    <p class="sp-numeric text-base font-semibold text-highlighted">
                      {{ transfer.display?.sold ? formatDecimalQuantity(transfer.display.sold, transfer.display.unit_label) : formatCompactUnits(transfer.units) }}
                    </p>
                    <p class="text-xs text-dimmed">sold</p>
                  </div>
                </div>

                <!-- Live customer balance for this exact sale. -->
                <div
                  v-if="transfer.customer_balance"
                  class="mt-4 border-t border-default pt-4"
                >
                  <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-lg bg-elevated/50 p-3">
                      <p class="text-[11px] uppercase tracking-wide text-dimmed">
                        Sold
                      </p>
                      <p class="sp-numeric mt-1 text-sm font-semibold text-highlighted">
                        {{ transfer.customer_balance.display ? transferDisplayValue(transfer.customer_balance.original_units, transfer.customer_balance.display) : formatCompactUnits(transfer.customer_balance.original_units) }}
                      </p>
                    </div>

                    <div class="rounded-lg bg-elevated/50 p-3">
                      <p class="text-[11px] uppercase tracking-wide text-dimmed">
                        Customer remaining
                      </p>
                      <p class="sp-numeric mt-1 text-sm font-semibold text-success">
                        {{ transfer.customer_balance.display ? transferDisplayValue(transfer.customer_balance.remaining_units, transfer.customer_balance.display) : formatCompactUnits(transfer.customer_balance.remaining_units) }}
                      </p>
                    </div>

                    <div class="rounded-lg bg-elevated/50 p-3">
                      <p class="text-[11px] uppercase tracking-wide text-dimmed">
                        Used
                      </p>
                      <p class="sp-numeric mt-1 text-sm font-semibold text-highlighted">
                        {{ transfer.customer_balance.display ? transferDisplayValue(transfer.customer_balance.used_units, transfer.customer_balance.display) : formatCompactUnits(transfer.customer_balance.used_units) }}
                      </p>
                    </div>

                    <div class="rounded-lg bg-elevated/50 p-3">
                      <p class="text-[11px] uppercase tracking-wide text-dimmed">
                        Reserved
                      </p>
                      <p class="sp-numeric mt-1 text-sm font-semibold text-highlighted">
                        {{ transfer.customer_balance.display ? transferDisplayValue(transfer.customer_balance.reserved_units, transfer.customer_balance.display) : formatCompactUnits(transfer.customer_balance.reserved_units) }}
                      </p>
                    </div>
                  </div>

                  <UProgress
                    class="mt-3"
                    :model-value="percentOfUnits(
                      transfer.customer_balance.remaining_units,
                      transfer.customer_balance.original_units
                    ) ?? 0"
                    :max="100"
                    size="sm"
                    :aria-label="`${formatUnits(transfer.customer_balance.remaining_units)} units remaining from this sale`"
                  />

                  <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted">
                    <span>
                      {{ percentOfUnits(
                        transfer.customer_balance.remaining_units,
                        transfer.customer_balance.original_units
                      ) ?? 0 }}% remaining
                    </span>

                    <span v-if="transfer.customer_balance.status">
                      Status: {{ transfer.customer_balance.status }}
                    </span>

                    <span v-if="transfer.customer_balance.expires_at">
                      Expires {{ formatDateTime(transfer.customer_balance.expires_at) }}
                    </span>

                    <span v-if="transfer.customer_balance.reserved_units !== '0'">
                      Available now:
                      {{ formatCompactUnits(transfer.customer_balance.available_units) }}
                    </span>
                  </div>
                </div>

                <p
                  v-else
                  class="mt-4 border-t border-default pt-3 text-xs text-muted"
                >
                  Current customer balance is not available for this older sale.
                </p>
              </li>
            </ul>
          </SpAsyncSection>
        </section>

        <!-- Keys -->
        <section class="space-y-4">
          <SpSectionHeading
            :level="3"
            :title="keys.data.value ? `Inference keys (${activeKeyCount} active)` : 'Inference keys'"
            description="Keys issued for this customer. A multi-model package can use one key across all included package models."
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
                :disabled="!isActive || customerAllowedAliases.length === 0"
                @click="openKey()"
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
            empty-title="No keys issued yet"
            empty-description="Sell package quota first, then issue a key for the customer's included models."
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
                      <p class="font-medium text-highlighted">
                        {{ key.label }}
                      </p>
                      <SpStatusBadge :status="key.status.toLowerCase()" />
                    </div>

                    <code class="block font-mono text-xs text-muted">
                      {{ maskApiKey(key.prefix, key.last_four) }}
                    </code>

                    <div class="flex flex-wrap gap-1.5">
                      <SpModelBadge
                        v-for="alias in key.allowed_model_aliases"
                        :key="alias"
                        :model="alias"
                        compact
                      />
                    </div>

                    <p class="text-xs text-muted">
                      Created {{ formatDateTime(key.created_at) }}
                      · Last used {{ key.last_used_at ? formatDateTime(key.last_used_at) : 'never' }}
                    </p>
                  </div>

                  <UButton
                    color="error"
                    variant="subtle"
                    size="sm"
                    icon="i-lucide-shield-x"
                    :disabled="key.status === 'REVOKED'"
                    @click="revokeTarget = key"
                  >
                    Revoke
                  </UButton>
                </div>
              </li>
            </ul>
          </SpAsyncSection>
        </section>
      </div>
    </SpAsyncSection>

    <!-- Sell package quota -->
    <UModal
      v-model:open="allocateOpen"
      title="Sell package quota"
      description="The customer receives one shared balance across every model included in the selected package."
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
          <UAlert
            v-if="allocationError"
            role="alert"
            icon="i-lucide-circle-alert"
            color="error"
            variant="subtle"
            :description="allocationError"
          />

          <UFormField
            label="Package"
            name="inventory_lot_id"
            required
            help="Choose one real reseller inventory package. The package's full model scope is copied automatically."
          >
            <USelectMenu
              v-model="allocation.inventory_lot_id"
              :items="packageOptions"
              value-key="value"
              placeholder="Choose package"
              class="w-full"
            />
          </UFormField>

          <div
            v-if="selectedPackage"
            class="rounded-lg border border-default bg-elevated/30 p-4"
          >
            <div class="flex items-center justify-between gap-3">
              <p class="font-medium text-highlighted">
                {{ cleanCreditLabel(selectedPackage.package_name) }}
              </p>
              <p class="sp-numeric text-sm font-semibold text-highlighted">
                {{ formatDecimalQuantity(selectedAvailableDisplay, selectedDisplayUnit) }} remaining
              </p>
            </div>

            <div class="mt-3 flex flex-wrap gap-1.5">
              <SpModelBadge
                v-for="alias in selectedPackage.allowed_model_aliases"
                :key="alias"
                :model="alias"
                compact
              />
            </div>

            <p class="mt-3 text-xs text-muted">
              One shared balance across these {{ selectedPackage.allowed_model_aliases.length }} models.
            </p>
          </div>

          <UFormField
            :label="`${selectedDisplayUnit} to sell`"
            name="units"
            required
          >
            <UInput
              v-model="allocation.units"
              :inputmode="selectedSupportsFractions ? 'decimal' : 'numeric'"
              autocomplete="off"
              :placeholder="selectedSupportsFractions ? '10' : '1000000'"
              class="w-full"
            />
          </UFormField>

          <UFormField
            label="Reason"
            name="reason"
            required
            help="Recorded in the audit trail."
          >
            <UTextarea
              v-model="allocation.reason"
              :rows="3"
              :placeholder="selectedSupportsFractions ? 'Customer purchased ten Credits.' : 'Customer purchased package quota.'"
              class="w-full"
            />
          </UFormField>

          <UAlert
            icon="i-lucide-info"
            color="neutral"
            variant="subtle"
            title="Shared package balance"
            description="One shared package balance is sold across every included model. Credit packages are sold and displayed in Credits; Token packages stay in Tokens."
          />

          <div class="flex justify-end gap-2">
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
              icon="i-lucide-package-check"
            >
              Sell quota
            </UButton>
          </div>
        </UForm>
      </template>
    </UModal>

    <!-- Issue key -->
    <UModal
      v-model:open="keyOpen"
      title="Issue inference key"
      description="By default every model funded for this customer is selected."
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
            label="Key name"
            name="label"
            required
          >
            <UInput
              v-model="keyForm.label"
              placeholder="Production API"
              autofocus
              class="w-full"
            />
          </UFormField>

          <UFormField
            label="Allowed models"
            name="allowed_model_aliases"
            required
            help="Only models funded for this managed customer are offered."
          >
            <USelectMenu
              v-model="keyForm.allowed_model_aliases"
              :items="customerModelOptions"
              value-key="value"
              multiple
              class="w-full"
            />
          </UFormField>

          <UFormField
            label="Expiry"
            name="expiry_date"
            help="Optional."
          >
            <UInput
              v-model="keyForm.expiry_date"
              type="date"
              class="w-full"
            />
          </UFormField>

          <div class="flex justify-end gap-2">
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

    <!-- Customer lifecycle -->
    <UModal
      v-model:open="lifecycleOpen"
      :title="lifecycleTarget?.title ?? 'Update customer'"
      :description="lifecycleTarget?.description ?? ''"
    >
      <template #body>
        <UForm
          :state="lifecycle"
          :validate="validateLifecycle"
          class="space-y-5"
          @submit="submitLifecycle"
        >
          <UAlert
            v-if="lifecycleError"
            color="error"
            variant="subtle"
            :description="lifecycleError"
          />

          <UFormField
            label="Reason"
            name="reason"
            required
          >
            <UTextarea
              v-model="lifecycle.reason"
              :rows="4"
              class="w-full"
            />
          </UFormField>

          <div class="flex justify-end gap-2">
            <UButton
              color="neutral"
              variant="ghost"
              :disabled="updatingLifecycle"
              @click="lifecycleOpen = false"
            >
              Cancel
            </UButton>

            <UButton
              type="submit"
              :color="lifecycleTarget?.buttonColor ?? 'primary'"
              :loading="updatingLifecycle"
            >
              {{ lifecycleTarget?.confirmation ?? 'Update' }}
            </UButton>
          </div>
        </UForm>
      </template>
    </UModal>

    <!-- Revoke key -->
    <UModal
      :open="revokeTarget !== null"
      title="Revoke this key?"
      description="The key stops authenticating immediately. The customer's quota is not removed."
      @update:open="open => { if (!open) revokeTarget = null }"
    >
      <template #body>
        <div class="space-y-4">
          <p class="text-sm text-muted">
            Revoke <strong class="text-highlighted">{{ revokeTarget?.label }}</strong>?
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
              :loading="revoking"
              @click="confirmRevoke"
            >
              Revoke
            </UButton>
          </div>
        </div>
      </template>
    </UModal>
  </SpDashboardPage>
</template>
