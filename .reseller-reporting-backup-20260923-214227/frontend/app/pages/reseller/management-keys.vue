<script setup lang="ts">
import type { FormError } from '@nuxt/ui'
import type { ResellerManagementKey, ResellerManagementKeyCreated, ResellerManagementScope } from '~/types/reseller'
import { RESELLER_MANAGEMENT_SCOPES } from '~/types/reseller'

/**
 * `sk-spm-*` management keys: the reseller's own automation credential.
 *
 * These are not inference keys and must never be described as if they were. A
 * management key drives the reseller API — creating customers, issuing their keys,
 * allocating quota — and cannot make a model request. Conflating the two would send
 * an operator to put the wrong secret in the wrong place.
 *
 * Scopes are fixed at creation and there is no rotate route, so the only way to
 * change what a key can do is to create a replacement and revoke the old one. The
 * page says that up front rather than offering an action the control plane has not
 * published.
 */
definePageMeta({
  layout: 'dashboard',
  middleware: ['auth']
})

useSeoMeta({
  title: 'Management keys',
  description: 'Scoped credentials for automating your reseller account against the SP Cambo API.',
  robots: 'noindex, nofollow'
})

const api = useSpApi()
const toast = useToast()

const keys = await useSpResource('reseller:management-keys', () => api.reseller.managementKeys(), { server: false })

/**
 * What each scope actually authorises, in the operator's language.
 *
 * The seven values come from `ResellerManagementKeyController::SCOPES`; the
 * descriptions are written here because the control plane publishes only the
 * names. Anything the backend adds later still renders — as its raw scope name,
 * which is honest — rather than being silently dropped from the picker.
 *
 * `authorises` lists the `/reseller-management` endpoints each scope gates, read
 * off the `management.scope:` middleware in `routes/api.php`. Two of the seven
 * gate nothing: the control plane will grant them, but no route reads them, so a
 * key holding only those can call nothing at all. That is stated plainly here —
 * a reseller who ticks "Read usage", gets a key and then sees no usage endpoint
 * to call would reasonably conclude the key was broken.
 */
const SCOPE_COPY: Record<ResellerManagementScope, { label: string, description: string, write: boolean, authorises: string[] }> = {
  'customers:read': {
    label: 'Read customers',
    description: 'List the customers you manage and their status.',
    write: false,
    authorises: ['GET /reseller-management/customers']
  },
  'customers:write': {
    label: 'Create customers',
    description: 'Create managed customer accounts, including their initial password.',
    write: true,
    authorises: ['POST /reseller-management/customers']
  },
  'keys:read': {
    label: 'Read keys',
    description: 'List the inference keys belonging to your customers.',
    write: false,
    authorises: ['GET /reseller-management/customers/{id}/api-keys']
  },
  'keys:write': {
    label: 'Issue and revoke keys',
    description: 'Issue new inference keys for a customer and revoke existing ones.',
    write: true,
    authorises: [
      'POST /reseller-management/customers/{id}/api-keys',
      'POST /reseller-management/customers/{id}/api-keys/{key}/revoke'
    ]
  },
  'allocations:read': {
    label: 'Read allocations',
    description: 'Intended for reading back the quota transfers you have made. No endpoint reads this scope yet, so granting it authorises nothing today.',
    write: false,
    authorises: []
  },
  'allocations:write': {
    label: 'Allocate quota',
    description: 'Move units out of your own inventory into a customer\'s account.',
    write: true,
    authorises: ['POST /reseller-management/customers/{id}/allocations']
  },
  'usage:read': {
    label: 'Read usage',
    description: 'Intended for reading customer usage and activity. No endpoint reads this scope yet, so granting it authorises nothing today.',
    write: false,
    authorises: []
  }
}

const scopeLabel = (scope: string) => SCOPE_COPY[scope as ResellerManagementScope]?.label ?? scope
const isWriteScope = (scope: string) => SCOPE_COPY[scope as ResellerManagementScope]?.write ?? false

/**
 * A scope the API will store but no route enforces. Unknown scopes are not treated
 * as inert: the backend may have added one this page has not been taught about, and
 * claiming it does nothing would be a worse guess than saying nothing.
 */
const isInertScope = (scope: string) => SCOPE_COPY[scope as ResellerManagementScope]?.authorises.length === 0

const scopeChoices = RESELLER_MANAGEMENT_SCOPES.map(scope => ({
  value: scope,
  ...SCOPE_COPY[scope]
}))

/** ---------------------------------------------------------------- create */

interface CreateFormState {
  label: string
  scopes: ResellerManagementScope[]
  expiry_date: string
}

const createOpen = ref(false)
const creating = ref(false)
const createForm = ref<CreateFormState>({ label: '', scopes: [], expiry_date: '' })
const createError = ref<string | null>(null)
const createFormRef = useTemplateRef<{ setErrors: (errors: FormError[]) => void }>('createFormRef')

const resetCreateForm = () => {
  createForm.value = { label: '', scopes: [], expiry_date: '' }
  createError.value = null
  createFormRef.value?.setErrors([])
}

const toggleScope = (scope: ResellerManagementScope, enabled: boolean) => {
  const current = new Set(createForm.value.scopes)

  if (enabled) {
    current.add(scope)
  } else {
    current.delete(scope)
  }

  // Kept in the catalogue's order so two keys with the same scopes read alike.
  createForm.value.scopes = RESELLER_MANAGEMENT_SCOPES.filter(entry => current.has(entry))
}

const grantsWrite = computed(() => createForm.value.scopes.some(isWriteScope))

/**
 * Every ticked scope is one no route enforces. The control plane would issue this
 * key quite happily and every call made with it would be refused, so the form warns
 * instead of blocking — what a scope is worth is the backend's decision, not this
 * page's, and a reseller may be provisioning ahead of an endpoint that is coming.
 */
const grantsNothing = computed(() =>
  createForm.value.scopes.length > 0 && createForm.value.scopes.every(isInertScope)
)

const validateCreate = (state: CreateFormState): FormError[] => {
  const errors: FormError[] = []

  if (!state.label.trim()) {
    errors.push({ name: 'label', message: 'Name the key after the automation that will use it.' })
  } else if (state.label.trim().length > 100) {
    errors.push({ name: 'label', message: 'Keep the name to 100 characters or fewer.' })
  }

  if (state.scopes.length === 0) {
    errors.push({ name: 'scopes', message: 'Choose at least one scope — a key with none could do nothing.' })
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

const openReveal = (result: ResellerManagementKeyCreated) => {
  revealSecret.value = result.secret
  revealLabel.value = result.key.label
  revealOpen.value = true
}

/** The secret must not outlive the dialog that showed it. */
const clearReveal = () => {
  revealSecret.value = null
  revealLabel.value = ''
}

const submitCreate = async () => {
  creating.value = true
  createError.value = null

  try {
    const result = await api.reseller.createManagementKey({
      label: createForm.value.label.trim(),
      scopes: createForm.value.scopes,
      expires_at: createForm.value.expiry_date
        ? new Date(`${createForm.value.expiry_date}T23:59:59Z`).toISOString()
        : null
    })

    createOpen.value = false
    resetCreateForm()
    openReveal(result)
    await keys.refresh()
  } catch (cause) {
    const error = toSpApiError(cause)

    createFormRef.value?.setErrors(
      Object.entries(error.errors).map(([name, messages]) => ({
        name,
        // `scopes.*` messages arrive keyed per index; surface them on the group.
        message: messages[0] ?? 'This value is not valid.'
      }))
    )

    createError.value = error.isValidation ? null : error.message
  } finally {
    creating.value = false
  }
}

/** ---------------------------------------------------------------- revoke */

const revokeTarget = ref<ResellerManagementKey | null>(null)
const revoking = ref(false)

const confirmRevoke = async () => {
  const key = revokeTarget.value

  if (!key) {
    return
  }

  revoking.value = true

  try {
    await api.reseller.revokeManagementKey(key.id)
    revokeTarget.value = null
    await keys.refresh()

    toast.add({
      title: 'Management key revoked',
      description: `${key.label} can no longer reach the reseller API. Anything automated with it will start failing now.`,
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

const activeCount = computed(() => (keys.data.value ?? []).filter(key => key.status === 'ACTIVE').length)
</script>

<template>
  <SpDashboardPage
    title="Management keys"
    icon="i-lucide-terminal"
    description="Credentials for automating your reseller account against the SP Cambo API. They manage customers, keys and allocations — they cannot make inference requests."
  >
    <template #actions>
      <UButton
        to="/docs/reseller-api"
        color="neutral"
        variant="subtle"
        icon="i-lucide-book-open"
      >
        Reseller API docs
      </UButton>
      <UButton
        icon="i-lucide-plus"
        @click="resetCreateForm(); createOpen = true"
      >
        Create key
      </UButton>
    </template>

    <SpStateForbidden
      v-if="keys.forbidden.value"
      :code="keys.error.value?.code ?? null"
      permission="reseller.manage"
    />

    <section
      v-else
      class="space-y-4"
    >
      <SpSectionHeading
        :title="keys.data.value ? `Your management keys (${activeCount} active)` : 'Your management keys'"
        description="Each secret is shown once, at creation. This list can only ever show a prefix and the last four characters."
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
        </template>
      </SpSectionHeading>

      <SpAsyncSection
        :loading="keys.initialLoading.value"
        :unavailable="keys.unavailable.value"
        :failed="keys.failed.value"
        :empty="keys.isEmpty.value"
        :offline="keys.error.value?.code === 'network_unreachable'"
        :error-message="keys.error.value?.message"
        error-title="Your management keys could not be loaded"
        empty-title="No management keys yet"
        empty-description="Create one only if you are automating against the API. Managing customers through this dashboard needs no key at all."
        empty-icon="i-lucide-terminal"
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
                      Created
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
                  <span class="text-xs text-dimmed">Scopes</span>
                  <UBadge
                    v-for="scope in key.scopes"
                    :key="scope"
                    :color="isWriteScope(scope) ? 'warning' : 'neutral'"
                    variant="subtle"
                    size="sm"
                  >
                    {{ scopeLabel(scope) }}
                  </UBadge>
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

      <UAlert
        icon="i-lucide-info"
        color="neutral"
        variant="subtle"
        title="Scopes are fixed once the key exists"
        description="SP Cambo has no route to change a key's scopes or to rotate its secret. To widen, narrow or replace one, create a new key with the scopes you want and revoke the old one."
      />
    </section>

    <!-- Create -->
    <UModal
      v-model:open="createOpen"
      title="Create a management key"
      description="Grant only the scopes the automation actually needs. Anything you leave out, the key cannot do."
    >
      <template #body>
        <UForm
          ref="createFormRef"
          :state="createForm"
          :validate="validateCreate"
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
            label="Name"
            name="label"
            required
            help="Name it after the automation that will hold it — “billing sync”, “onboarding worker”."
          >
            <UInput
              v-model="createForm.label"
              placeholder="Onboarding worker"
              autofocus
              class="w-full"
            />
          </UFormField>

          <UFormField
            label="Scopes"
            name="scopes"
            required
          >
            <div class="space-y-3 rounded-lg border border-default p-3">
              <div
                v-for="choice in scopeChoices"
                :key="choice.value"
                class="flex gap-3"
              >
                <UCheckbox
                  :model-value="createForm.scopes.includes(choice.value)"
                  :aria-label="choice.label"
                  class="mt-0.5"
                  @update:model-value="toggleScope(choice.value, $event === true)"
                />
                <div class="min-w-0 space-y-0.5">
                  <div class="flex flex-wrap items-center gap-2">
                    <p class="text-sm font-medium text-highlighted">
                      {{ choice.label }}
                    </p>
                    <UBadge
                      v-if="choice.write"
                      color="warning"
                      variant="subtle"
                      size="sm"
                    >
                      Makes changes
                    </UBadge>
                    <UBadge
                      v-if="choice.authorises.length === 0"
                      color="neutral"
                      variant="subtle"
                      size="sm"
                    >
                      No endpoint yet
                    </UBadge>
                  </div>
                  <p class="text-xs text-muted">
                    {{ choice.description }}
                  </p>
                  <code class="font-mono text-xs text-dimmed">{{ choice.value }}</code>
                  <p
                    v-for="endpoint in choice.authorises"
                    :key="endpoint"
                    class="font-mono text-xs break-all text-dimmed"
                  >
                    {{ endpoint }}
                  </p>
                </div>
              </div>
            </div>
          </UFormField>

          <UAlert
            v-if="grantsNothing"
            icon="i-lucide-info"
            color="neutral"
            variant="subtle"
            title="This key would be refused everywhere"
            description="Every scope you have chosen is one no reseller API endpoint reads yet. The key will be created, but each call made with it answers 403 insufficient_scope. Add a scope that names an endpoint above, or create the key later."
          />

          <UAlert
            v-if="grantsWrite"
            icon="i-lucide-triangle-alert"
            color="warning"
            variant="subtle"
            title="This key will be able to change things"
            description="Whoever holds it can act on your reseller account without signing in — including moving units out of your own inventory. Store it where only the automation can read it."
          />

          <UFormField
            label="Expiry"
            name="expiry_date"
            help="Optional, and worth setting. The key stops working at 23:59:59 UTC on this date."
          >
            <UInput
              v-model="createForm.expiry_date"
              type="date"
              class="w-full"
            />
          </UFormField>

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
              Create key
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
      audience="management"
      @close="clearReveal"
    />

    <!-- Revoke -->
    <UModal
      :open="revokeTarget !== null"
      title="Revoke this management key?"
      description="It stops reaching the reseller API the moment you confirm, and cannot be re-enabled."
      @update:open="revokeTarget = null"
    >
      <template #body>
        <div class="space-y-4">
          <p class="text-sm text-muted">
            Anything automated with
            <strong class="text-highlighted">{{ revokeTarget?.label }}</strong>
            will start failing to authenticate straight away. Your customers, their keys and every allocation
            already made are untouched — only this credential stops working.
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
