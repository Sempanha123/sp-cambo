<script setup lang="ts">
import type { FormError } from '@nuxt/ui'

/**
 * Managed customers a reseller owns.
 *
 * `reseller.manage` is enforced by the control plane on every route below, so
 * this page is reachable by URL and renders the server's 403 honestly. It never
 * decides access itself.
 *
 * A managed customer is a real SP Cambo account created on the reseller's behalf.
 * The customer's initial password is chosen here and never returned by the API
 * afterwards, so the copy is explicit that the reseller must pass it on.
 */
definePageMeta({
  layout: 'dashboard',
  middleware: ['auth']
})

useSeoMeta({
  title: 'Managed customers',
  description: 'Create and fund the SP Cambo accounts you manage as a reseller.',
  robots: 'noindex, nofollow'
})

const api = useSpApi()
const toast = useToast()

const customers = await useSpResource('reseller:customers', () => api.reseller.customers(), { server: false })

/** ---------------------------------------------------------------- create */

interface CreateFormState {
  name: string
  email: string
  label: string
  password: string
  password_confirmation: string
}

const emptyForm = (): CreateFormState => ({
  name: '',
  email: '',
  label: '',
  password: '',
  password_confirmation: ''
})

const createOpen = ref(false)
const creating = ref(false)
const createForm = ref<CreateFormState>(emptyForm())
const createError = ref<string | null>(null)
const formRef = useTemplateRef<{ setErrors: (errors: FormError[]) => void }>('formRef')

const resetCreateForm = () => {
  createForm.value = emptyForm()
  createError.value = null
}

/**
 * Mirrors the control plane's rules so the reseller is told before submitting.
 * `store` enforces `Password::min(12)->letters()->mixedCase()->numbers()->symbols()`,
 * which is the `strong` policy level — not registration's looser `min:12`.
 */
const validateCreate = (state: CreateFormState): FormError[] => {
  const errors: FormError[] = []

  if (!state.name.trim()) {
    errors.push({ name: 'name', message: 'Enter the customer\'s name.' })
  }

  if (!state.email.trim()) {
    errors.push({ name: 'email', message: 'Enter the customer\'s email address.' })
  } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(state.email.trim())) {
    errors.push({ name: 'email', message: 'That does not look like an email address.' })
  }

  if (!state.label.trim()) {
    errors.push({ name: 'label', message: 'Add a label so you can find this customer in your own records.' })
  } else if (state.label.trim().length > 150) {
    errors.push({ name: 'label', message: 'Keep the label to 150 characters or fewer.' })
  }

  if (!meetsPasswordPolicy(state.password)) {
    errors.push({ name: 'password', message: 'This password does not meet every rule below.' })
  }

  if (state.password_confirmation !== state.password) {
    errors.push({ name: 'password_confirmation', message: 'The two passwords do not match.' })
  }

  return errors
}

const submitCreate = async () => {
  creating.value = true
  createError.value = null

  try {
    const created = await api.reseller.createCustomer({
      name: createForm.value.name.trim(),
      email: createForm.value.email.trim(),
      label: createForm.value.label.trim(),
      password: createForm.value.password,
      password_confirmation: createForm.value.password_confirmation
    })

    createOpen.value = false
    resetCreateForm()
    await customers.refresh()

    toast.add({
      title: 'Customer created',
      description: `${created.name} can sign in now. Give them the password you just set — SP Cambo cannot show it again.`,
      color: 'success',
      icon: 'i-lucide-user-check'
    })
  } catch (cause) {
    const error = toSpApiError(cause)

    // Server-side field messages land on the inputs they belong to, not in a banner.
    formRef.value?.setErrors(
      Object.entries(error.errors).map(([name, messages]) => ({
        name,
        message: messages[0] ?? 'This value is not valid.'
      }))
    )

    createError.value = error.isValidation ? null : error.message
  } finally {
    creating.value = false
  }
}

/** ----------------------------------------------------------------- list */

const search = ref('')

const visibleCustomers = computed(() => {
  const all = customers.data.value ?? []
  const term = search.value.trim().toLowerCase()

  if (!term) {
    return all
  }

  return all.filter(customer =>
    customer.name.toLowerCase().includes(term)
    || customer.email.toLowerCase().includes(term)
    || customer.label.toLowerCase().includes(term)
  )
})

const activeCount = computed(() => (customers.data.value ?? []).filter(item => item.status === 'ACTIVE').length)
</script>

<template>
  <SpDashboardPage
    title="Managed customers"
    icon="i-lucide-users"
    description="Accounts you created and fund as a reseller. Each one is a full SP Cambo account: the customer signs in, holds their own keys and sees their own usage."
  >
    <template #actions>
      <UButton
        icon="i-lucide-user-plus"
        @click="resetCreateForm(); createOpen = true"
      >
        Add customer
      </UButton>
    </template>

    <SpStateForbidden
      v-if="customers.forbidden.value"
      :code="customers.error.value?.code ?? null"
      permission="reseller.manage"
    />

    <section
      v-else
      class="space-y-4"
    >
      <SpSectionHeading
        :title="customers.data.value ? `Your customers (${activeCount} active)` : 'Your customers'"
        description="Only accounts you manage appear here. Another reseller's customers are not visible to you at all."
      >
        <template #actions>
          <UInput
            v-model="search"
            icon="i-lucide-search"
            placeholder="Search name, email or label"
            class="w-full sm:w-64"
            :disabled="(customers.data.value ?? []).length === 0"
          />
          <UButton
            color="neutral"
            variant="ghost"
            size="sm"
            icon="i-lucide-refresh-cw"
            :loading="customers.loading.value"
            @click="customers.refresh()"
          >
            Refresh
          </UButton>
        </template>
      </SpSectionHeading>

      <SpAsyncSection
        :loading="customers.initialLoading.value"
        :unavailable="customers.unavailable.value"
        :failed="customers.failed.value"
        :empty="customers.isEmpty.value"
        :offline="customers.error.value?.code === 'network_unreachable'"
        :error-message="customers.error.value?.message"
        error-title="Your customer list could not be loaded"
        empty-title="No managed customers yet"
        empty-description="Create one to issue them keys and allocate units from your own inventory."
        empty-icon="i-lucide-users"
        loading-variant="rows"
        @retry="customers.refresh()"
      >
        <div
          v-if="customers.data.value"
          class="space-y-3"
        >
          <p
            v-if="visibleCustomers.length === 0"
            class="rounded-lg border border-dashed border-default px-4 py-6 text-center text-sm text-muted"
          >
            No customer matches “{{ search }}”.
          </p>

          <ul
            v-else
            class="space-y-3"
          >
            <li
              v-for="customer in visibleCustomers"
              :key="customer.id"
              class="rounded-lg border border-default bg-elevated/30 p-4"
              :class="customer.status === 'ACTIVE' ? undefined : 'opacity-70'"
            >
              <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0 space-y-1.5">
                  <div class="flex flex-wrap items-center gap-2">
                    <p class="truncate font-medium text-highlighted">
                      {{ customer.name }}
                    </p>
                    <SpStatusBadge :status="customer.status.toLowerCase()" />
                  </div>

                  <p class="truncate text-sm text-muted">
                    {{ customer.email }}
                  </p>

                  <dl class="flex flex-wrap gap-x-5 gap-y-1 text-xs text-muted">
                    <div class="flex gap-1.5">
                      <dt class="text-dimmed">
                        Your label
                      </dt>
                      <dd class="text-default">
                        {{ customer.label }}
                      </dd>
                    </div>
                    <div class="flex gap-1.5">
                      <dt class="text-dimmed">
                        Created
                      </dt>
                      <dd>{{ formatDate(customer.created_at) }}</dd>
                    </div>
                  </dl>
                </div>

                <UButton
                  :to="`/reseller/customers/${customer.id}`"
                  color="neutral"
                  variant="subtle"
                  size="sm"
                  trailing-icon="i-lucide-arrow-right"
                  class="shrink-0"
                >
                  Manage
                </UButton>
              </div>
            </li>
          </ul>
        </div>
      </SpAsyncSection>

      <p class="text-sm text-muted">
        Allocations move units out of your own inventory rather than buying new ones. Top up in
        <NuxtLink
          to="/dashboard/buy"
          class="text-primary underline decoration-dotted underline-offset-4"
        >
          buy tokens &amp; credits
        </NuxtLink>
        before allocating.
      </p>
    </section>

    <!-- Create -->
    <UModal
      v-model:open="createOpen"
      title="Add a managed customer"
      description="This creates a real SP Cambo account. The customer can sign in immediately with the password you set here."
    >
      <template #body>
        <UForm
          ref="formRef"
          :state="createForm"
          :validate="validateCreate"
          :validate-on="['blur', 'change']"
          class="space-y-5"
          @submit="submitCreate"
        >
          <UAlert
            v-if="createError"
            role="alert"
            icon="i-lucide-circle-alert"
            color="error"
            variant="subtle"
            :description="createError"
          />

          <UFormField
            label="Customer name"
            name="name"
            required
          >
            <UInput
              v-model="createForm.name"
              autofocus
              autocomplete="off"
              class="w-full"
            />
          </UFormField>

          <UFormField
            label="Email address"
            name="email"
            required
            help="The address they will sign in with. It must not already belong to an SP Cambo account."
          >
            <UInput
              v-model="createForm.email"
              type="email"
              autocomplete="off"
              class="w-full"
            />
          </UFormField>

          <UFormField
            label="Your label"
            name="label"
            required
            help="For your records only. The customer never sees it."
          >
            <UInput
              v-model="createForm.label"
              placeholder="Phnom Penh office"
              autocomplete="off"
              class="w-full"
            />
          </UFormField>

          <UFormField
            label="Initial password"
            name="password"
            required
          >
            <UInput
              v-model="createForm.password"
              type="password"
              autocomplete="new-password"
              class="w-full"
            />
            <template #help>
              <SpPasswordPolicy :value="createForm.password" />
            </template>
          </UFormField>

          <UFormField
            label="Confirm password"
            name="password_confirmation"
            required
          >
            <UInput
              v-model="createForm.password_confirmation"
              type="password"
              autocomplete="new-password"
              class="w-full"
            />
          </UFormField>

          <UAlert
            icon="i-lucide-key"
            color="warning"
            variant="subtle"
            title="Deliver this password yourself"
            description="SP Cambo does not email it and cannot show it again. Send it over a channel the customer controls and ask them to change it after their first sign-in."
          />

          <div class="flex justify-end gap-2 pt-1">
            <UButton
              color="neutral"
              variant="ghost"
              :disabled="creating"
              @click="createOpen = false"
            >
              Cancel
            </UButton>
            <UButton
              type="submit"
              :loading="creating"
            >
              Create customer
            </UButton>
          </div>
        </UForm>
      </template>
    </UModal>
  </SpDashboardPage>
</template>
