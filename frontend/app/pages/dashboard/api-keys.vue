<script setup lang="ts">
import type { ApiKeyCreated, ApiKeyStatusReport, ApiKeySummary, RequestActivity } from '~/types/commerce'
import type { FormError } from '@nuxt/ui'

definePageMeta({
  layout: 'dashboard',
  middleware: ['auth']
})

useSeoMeta({
  title: 'API keys',
  description: 'Create, scope, rotate and revoke the keys your CLI tools and applications use.',
  robots: 'noindex'
})

const api = useSpApi()
const toast = useToast()

const keys = await useSpResource('dashboard:api-keys', () => api.account.apiKeys(), { server: false })
const models = await useSpResource('catalog:models', () => api.catalog.models(), { server: false })

// Recent request activity per key. `/me/activity` is the authoritative per-request
// contract; usage-summary buckets are aggregate time windows and must not be
// rendered as individual requests.
const usageActivities = ref<Record<string, RequestActivity[]>>({})
const usageLoading = ref<Record<string, boolean>>({})
const usagePollTimers = ref<Record<string, ReturnType<typeof setInterval>>>({})

const fetchUsageActivity = async (keyId: string) => {
  usageLoading.value[keyId] = true
  try {
    const data = await api.account.activity({ limit: 10, key_id: keyId })
    usageActivities.value[keyId] = data
  } catch (error) {
    console.error('Failed to fetch API-key activity:', error)
  } finally {
    usageLoading.value[keyId] = false
  }
}

// Start polling for usage updates (30s interval)
const startUsagePoll = (keyId: string) => {
  if (usagePollTimers.value[keyId]) return
  fetchUsageActivity(keyId)
  usagePollTimers.value[keyId] = setInterval(() => fetchUsageActivity(keyId), 30000)
}

const stopUsagePoll = (keyId: string) => {
  if (usagePollTimers.value[keyId]) {
    clearInterval(usagePollTimers.value[keyId])
    // delete usagePollTimers.value[keyId]
  }
}

onBeforeUnmount(() => {
  Object.keys(usagePollTimers.value).forEach(stopUsagePoll)
})

const aliasOptions = computed(() =>
  (models.data.value ?? []).map(model => ({ label: model.public_alias, value: model.public_alias }))
)

/** ---------------------------------------------------------------- create */

interface CreateFormState {
  label: string
  allowed_model_aliases: string[]
  expiry_date: string
}

const createOpen = ref(false)
const creating = ref(false)
const createForm = ref<CreateFormState>({
  label: '',
  allowed_model_aliases: [],
  expiry_date: ''
})
const createError = ref<string | null>(null)
const createFormRef = useTemplateRef<{ setErrors: (errors: FormError[]) => void }>('createFormRef')

const resetCreateForm = () => {
  createForm.value = { label: '', allowed_model_aliases: [], expiry_date: '' }
  createError.value = null
  createFormRef.value?.setErrors([])
}

const validateCreate = (state: CreateFormState): FormError[] => {
  const errors: FormError[] = []

  if (!state.label.trim()) {
    errors.push({ name: 'label', message: 'Give the key a name so you can tell it apart later.' })
  } else if (state.label.trim().length > 64) {
    errors.push({ name: 'label', message: 'Keep the name to 64 characters or fewer.' })
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

/** ---------------------------------------------------- one-time reveal */

const revealOpen = ref(false)
const revealSecret = ref<string | null>(null)
const revealLabel = ref('')
const revealContext = ref<'created' | 'rotated'>('created')

const openReveal = (result: ApiKeyCreated, context: 'created' | 'rotated') => {
  revealSecret.value = result.secret
  revealLabel.value = result.key.label
  revealContext.value = context
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
    const result = await api.account.createApiKey({
      label: createForm.value.label.trim(),
      allowed_model_aliases: createForm.value.allowed_model_aliases.length > 0
        ? createForm.value.allowed_model_aliases
        : undefined,
      expires_at: createForm.value.expiry_date
        ? new Date(`${createForm.value.expiry_date}T23:59:59Z`).toISOString()
        : null
    })

    createOpen.value = false
    resetCreateForm()
    openReveal(result, 'created')
    await keys.refresh()
  } catch (cause) {
    const error = toSpApiError(cause)

    // Server-side field messages land on the inputs they belong to, not in a banner.
    createFormRef.value?.setErrors(
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

/** ------------------------------------------------------------- actions */

/** Which key is mid-action, so only that row shows a spinner. */
const pending = ref<{ id: string, action: string } | null>(null)

const isPending = (id: string, action?: string) =>
  pending.value?.id === id && (action === undefined || pending.value.action === action)

const runAction = async (id: string, action: string, fn: () => Promise<void>) => {
  pending.value = { id, action }

  try {
    await fn()
  } catch (cause) {
    const error = toSpApiError(cause)
    toast.add({ title: 'That did not work', description: error.message, color: 'error', icon: 'i-lucide-circle-x' })
  } finally {
    pending.value = null
  }
}

const setStatus = (key: ApiKeySummary, status: 'ACTIVE' | 'DISABLED' | 'REVOKED') =>
  runAction(key.id, `status:${status}`, async () => {
    await api.account.setApiKeyStatus(key.id, status)
    await keys.refresh()

    const wording: Record<typeof status, string> = {
      ACTIVE: 'Key enabled',
      DISABLED: 'Key disabled',
      REVOKED: 'Key revoked'
    }

    toast.add({
      title: wording[status],
      description: `${key.label} — ${status === 'REVOKED' ? 'this cannot be undone.' : 'change takes effect immediately.'}`,
      color: status === 'REVOKED' ? 'warning' : 'success',
      icon: status === 'REVOKED' ? 'i-lucide-shield-x' : 'i-lucide-shield-check'
    })
  })

/** ------------------------------------------------------------- rotate */

const rotateTarget = ref<ApiKeySummary | null>(null)

const confirmRotate = async () => {
  const key = rotateTarget.value

  if (!key) {
    return
  }

  await runAction(key.id, 'rotate', async () => {
    const result = await api.account.rotateApiKey(key.id)
    rotateTarget.value = null
    openReveal(result, 'rotated')
    await keys.refresh()
  })
}

/** ------------------------------------------------------------- revoke */

const revokeTarget = ref<ApiKeySummary | null>(null)
const revokeConfirmation = ref('')

const revokeReady = computed(() => revokeConfirmation.value.trim().toUpperCase() === 'REVOKE')

const confirmRevoke = async () => {
  const key = revokeTarget.value

  if (!key || !revokeReady.value) {
    return
  }

  await setStatus(key, 'REVOKED')
  revokeTarget.value = null
  revokeConfirmation.value = ''
}

/** --------------------------------------------------------------- test */

const testOpen = ref(false)
const testKey = ref<ApiKeySummary | null>(null)
const testReport = ref<ApiKeyStatusReport | null>(null)
const testError = ref<string | null>(null)

const origin = window.location.origin

const copyKeyCheckerLink = () => {
  const link = `${origin}/public/key-checker`
  navigator.clipboard.writeText(link)
  toast.add({
    title: 'Checker URL copied',
    description: 'The public checker never puts a plaintext API key in the URL.',
    color: 'success',
    icon: 'i-lucide-copy'
  })
}

const runTest = (key: ApiKeySummary) =>
  runAction(key.id, 'test', async () => {
    testKey.value = key
    testReport.value = null
    testError.value = null
    testOpen.value = true

    try {
      testReport.value = await api.account.testApiKey(key.id)
    } catch (cause) {
      testError.value = toSpApiError(cause).message
    }
  })

const menuItems = (key: ApiKeySummary) => {
  const revoked = key.status === 'REVOKED'
  // const origin = window.location.origin

  return [[
    {
      label: 'Test key',
      icon: 'i-lucide-stethoscope',
      disabled: revoked,
      onSelect: () => runTest(key)
    },
    {
      label: 'Copy checker URL',
      icon: 'i-lucide-link',
      onSelect: () => copyKeyCheckerLink()
    },
    {
      label: 'Rotate secret',
      icon: 'i-lucide-refresh-cw',
      disabled: revoked,
      onSelect: () => { rotateTarget.value = key }
    },
    key.status === 'DISABLED'
      ? {
          label: 'Enable',
          icon: 'i-lucide-play',
          onSelect: () => setStatus(key, 'ACTIVE')
        }
      : {
          label: 'Disable',
          icon: 'i-lucide-pause',
          disabled: revoked || key.status === 'EXPIRED',
          onSelect: () => setStatus(key, 'DISABLED')
        }
  ], [
    {
      label: 'Revoke permanently',
      icon: 'i-lucide-shield-x',
      color: 'error' as const,
      disabled: revoked,
      onSelect: () => {
        revokeConfirmation.value = ''
        revokeTarget.value = key
      }
    }
  ]]
}

const activeCount = computed(() => (keys.data.value ?? []).filter(key => key.status === 'ACTIVE').length)

/** ------------------------------------------------------------- ceilings */

interface CeilingRow {
  label: string
  value: string
}

/**
 * The per-key ceilings the control plane recorded for this key.
 *
 * Only limits that are actually set are listed. A null is not zero and not
 * "unlimited" either — it means no ceiling is recorded *on the key*, and the copy
 * below says exactly that rather than promising a customer they can call as fast as
 * they like. The gateway enforces whatever is recorded here and nothing else, so a
 * number shown on this card is a number a request can be refused for.
 */
const ceilingRows = (key: { limits: ApiKeySummary['limits'] }): CeilingRow[] => {
  const limits = key.limits ?? {}

  const rows: Array<{ label: string, value: number | null | undefined, format: (value: number) => string }> = [
    { label: 'Requests / minute', value: limits.requests_per_minute, format: formatCount },
    { label: 'Tokens / minute', value: limits.tokens_per_minute, format: formatCount },
    { label: 'Concurrent requests', value: limits.concurrency, format: formatCount },
    { label: 'Max request size', value: limits.max_request_bytes, format: formatBytes },
    { label: 'Max output tokens', value: limits.max_output_tokens, format: formatCount }
  ]

  return rows
    .filter((row): row is typeof row & { value: number } => typeof row.value === 'number')
    .map(row => ({ label: row.label, value: row.format(row.value) }))
}
</script>

<template>
  <SpDashboardPage
    title="API keys"
    icon="i-lucide-key-round"
    description="Keys authenticate inference requests. They cannot manage your account, buy packages or read each other."
  >
    <template #actions>
      <UButton
        icon="i-lucide-plus"
        @click="resetCreateForm(); createOpen = true"
      >
        Create key
      </UButton>
    </template>

    <UCard
      v-if="testKey"
      class="mt-6"
    >
      <template #header>
        <h3 class="text-lg font-semibold">
          Shareable Key Checker
        </h3>
      </template>

      <div class="flex flex-col sm:flex-row gap-4 items-center">
        <UInput
          :model-value="testKey ? `${origin}/public/key-checker` : ''"
          readonly
          class="flex-1"
          size="lg"
        >
          <template #trailing>
            <UButton
              variant="ghost"
              color="neutral"
              icon="i-lucide-copy"
              @click="testKey && copyKeyCheckerLink()"
            />
          </template>
        </UInput>

        <UButton
          :to="testKey ? `/public/key-checker` : ''"
          target="_blank"
          size="lg"
        >
          Open Checker
        </UButton>
      </div>

      <template #footer>
        <p class="text-sm text-gray-600">
          Share the checker page, then paste the key directly into the secure form. API keys are never placed in URLs.
        </p>
      </template>
    </UCard>

    <section class="space-y-4">
      <SpSectionHeading
        :title="keys.data.value ? `Your keys (${activeCount} active)` : 'Your keys'"
        description="Secrets are shown once at creation or rotation. This list can only ever show a prefix and the last four characters."
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
        unavailable-title="Key management is not available yet"
        unavailable-description="The control plane has not published the API key endpoints. Nothing is wrong with your account, and no keys have been lost."
        empty-title="No keys yet"
        empty-description="Create one to connect Claude Code, Codex CLI or your own SDK integration."
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
            :class="key.status === 'REVOKED' ? 'opacity-60' : undefined"
            @mouseenter="startUsagePoll(key.id)"
            @mouseleave="stopUsagePoll(key.id)"
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
                  >Every model your entitlements allow</span>
                </div>

                <!--
                  The ceilings a request can actually be refused for. Listed because a
                  429 with no visible limit is indistinguishable from a fault, and a
                  customer sizing their concurrency has nowhere else to read this.
                -->
                <div class="flex flex-wrap items-baseline gap-x-4 gap-y-1">
                  <span class="text-xs text-dimmed">Ceilings</span>
                  <template v-if="ceilingRows(key).length > 0">
                    <span
                      v-for="row in ceilingRows(key)"
                      :key="row.label"
                      class="text-xs text-muted"
                    >
                      {{ row.label }}
                      <span class="sp-numeric text-default">{{ row.value }}</span>
                    </span>
                  </template>
                  <span
                    v-else
                    class="text-xs text-muted"
                  >
                    None recorded on this key, so nothing here is enforced against it
                    specifically. Service-wide protections still apply to every request — see
                    <NuxtLink
                      to="/docs/rate-limits"
                      class="text-primary underline underline-offset-2"
                    >rate limits</NuxtLink>.
                  </span>
                </div>

                <!-- Recent per-request activity. This intentionally uses /me/activity,
                     not aggregate /me/usage/summary buckets. -->
                <div class="mt-4 border-t border-default pt-4">
                  <h4 class="mb-3 text-sm font-medium text-highlighted">
                    Recent Usage
                  </h4>
                  <div
                    v-if="usageLoading[key.id]"
                    class="py-4 text-center"
                  >
                    <UProgress
                      animation="carousel"
                      :ui="{ indicator: 'hidden' }"
                    />
                  </div>
                  <div
                    v-else-if="(usageActivities[key.id]?.length ?? 0) > 0"
                    class="overflow-x-auto"
                  >
                    <table class="w-full text-xs">
                      <thead>
                        <tr class="border-b text-left text-muted">
                          <th class="pb-2">Time</th>
                          <th class="pb-2">Public Model</th>
                          <th class="pb-2">Status</th>
                          <th class="pb-2 text-right">Input</th>
                          <th class="pb-2 text-right">Output</th>
                          <th class="pb-2 text-right">Total</th>
                          <th class="pb-2 text-right">Customer Charge</th>
                          <th class="pb-2 text-right">Latency</th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr
                          v-for="request in (usageActivities[key.id] ?? [])"
                          :key="request.id"
                          class="border-b border-default/60 last:border-0"
                        >
                          <td class="py-2 whitespace-nowrap">
                            {{ formatDateTime(request.started_at) }}
                          </td>
                          <td class="py-2 font-mono">
                            {{ request.public_model }}
                          </td>
                          <td class="py-2">
                            <SpStatusBadge :status="request.state" />
                          </td>
                          <td class="py-2 text-right sp-numeric">
                            {{ request.input_tokens?.toLocaleString() ?? '—' }}
                          </td>
                          <td class="py-2 text-right sp-numeric">
                            {{ request.output_tokens?.toLocaleString() ?? '—' }}
                          </td>
                          <td class="py-2 text-right sp-numeric">
                            {{ request.total_tokens?.toLocaleString() ?? '—' }}
                          </td>
                          <td class="py-2 text-right font-medium sp-numeric">
                            {{ request.credit_charge ? formatMoney(request.credit_charge) : '—' }}
                          </td>
                          <td class="py-2 text-right sp-numeric">
                            {{ request.duration_ms === null ? '—' : `${request.duration_ms.toLocaleString()} ms` }}
                          </td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                  <div
                    v-else
                    class="py-4 text-center text-muted"
                  >
                    No recent usage for this key.
                  </div>
                </div>
              </div>

              <div class="flex shrink-0 flex-wrap items-center justify-end gap-2">
                <UButton
                  :to="{ path: '/dashboard/usage', query: { key: key.id } }"
                  color="neutral"
                  variant="subtle"
                  size="sm"
                  icon="i-lucide-chart-line"
                >
                  View activity
                </UButton>
                <UButton
                  color="neutral"
                  variant="subtle"
                  size="sm"
                  icon="i-lucide-stethoscope"
                  :loading="isPending(key.id, 'test')"
                  :disabled="key.status === 'REVOKED' || isPending(key.id)"
                  @click="runTest(key)"
                >
                  Test
                </UButton>
                <UDropdownMenu
                  :items="menuItems(key)"
                  :content="{ align: 'end' }"
                >
                  <UButton
                    color="neutral"
                    variant="ghost"
                    icon="i-lucide-ellipsis-vertical"
                    :loading="isPending(key.id) && !isPending(key.id, 'test')"
                    aria-label="Key actions"
                  />
                </UDropdownMenu>
              </div>
            </div>
          </li>
        </ul>
      </SpAsyncSection>

      <p class="text-sm text-muted">
        Give each environment its own key so revoking one never takes the others down. Full guidance is in
        <NuxtLink
          to="/docs/authentication"
          class="text-primary underline decoration-dotted underline-offset-4"
        >
          authentication
        </NuxtLink>.
      </p>
    </section>

    <!-- Create -->
    <UModal
      v-model:open="createOpen"
      title="Create an API key"
      description="Scope it to what the environment actually needs. You can rotate or revoke it at any time."
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
            help="Something you will recognise in an activity log — “laptop”, “CI”, “production worker”."
          >
            <UInput
              v-model="createForm.label"
              placeholder="Laptop"
              autofocus
              class="w-full"
            />
          </UFormField>

          <UFormField
            label="Model scope"
            name="allowed_model_aliases"
            :help="models.unavailable.value
              ? 'The model catalogue is not published yet, so scoping cannot be set here. The key will allow every model your entitlements permit.'
              : 'Leave empty to allow every model your entitlements permit.'"
          >
            <USelectMenu
              v-model="createForm.allowed_model_aliases"
              :items="aliasOptions"
              value-key="value"
              multiple
              :disabled="models.unavailable.value || aliasOptions.length === 0"
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
      :context="revealContext"
      @close="clearReveal"
    />

    <!-- Rotate -->
    <UModal
      :open="rotateTarget !== null"
      title="Rotate this secret?"
      description="A new secret is issued immediately and the current one stops working at the same moment."
      @update:open="rotateTarget = null"
    >
      <template #body>
        <div class="space-y-4">
          <p class="text-sm text-muted">
            Anything still using the old secret for
            <strong class="text-highlighted">{{ rotateTarget?.label }}</strong>
            will start failing with an authentication error until you update it. The key's name, scope and
            limits are unchanged.
          </p>
          <UAlert
            icon="i-lucide-info"
            color="neutral"
            variant="subtle"
            description="Rotation is the correct fix if you never captured the original secret, or if it may have leaked."
          />
        </div>
      </template>

      <template #footer>
        <div class="flex w-full justify-end gap-2">
          <UButton
            color="neutral"
            variant="ghost"
            @click="rotateTarget = null"
          >
            Cancel
          </UButton>
          <UButton
            :loading="rotateTarget ? isPending(rotateTarget.id, 'rotate') : false"
            @click="confirmRotate"
          >
            Rotate secret
          </UButton>
        </div>
      </template>
    </UModal>

    <!-- Revoke -->
    <UModal
      :open="revokeTarget !== null"
      title="Revoke this key permanently?"
      description="Revocation is immediate and cannot be undone. A revoked key can never be re-enabled."
      @update:open="revokeTarget = null"
    >
      <template #body>
        <div class="space-y-4">
          <p class="text-sm text-muted">
            Every request using
            <strong class="text-highlighted">{{ revokeTarget?.label }}</strong>
            will fail from now on. Your other keys, your balance and your entitlements are unaffected, and
            quota already spent is not refunded.
          </p>

          <UFormField
            label="Type REVOKE to confirm"
            name="confirm"
          >
            <UInput
              v-model="revokeConfirmation"
              placeholder="REVOKE"
              autocomplete="off"
              class="w-full"
            />
          </UFormField>
        </div>
      </template>

      <template #footer>
        <div class="flex w-full justify-end gap-2">
          <UButton
            color="neutral"
            variant="ghost"
            @click="revokeTarget = null"
          >
            Cancel
          </UButton>
          <UButton
            color="error"
            :disabled="!revokeReady"
            :loading="revokeTarget ? isPending(revokeTarget.id, 'status:REVOKED') : false"
            @click="confirmRevoke"
          >
            Revoke key
          </UButton>
        </div>
      </template>
    </UModal>

    <!-- Test -->
    <UModal
      v-model:open="testOpen"
      title="Key check"
      description="Validation only. This does not run an inference request and does not spend any quota."
    >
      <template #body>
        <div class="space-y-4">
          <p class="text-sm font-medium text-highlighted">
            {{ testKey?.label }}
          </p>

          <UAlert
            v-if="testError"
            role="alert"
            icon="i-lucide-circle-alert"
            color="error"
            variant="subtle"
            :description="testError"
          />

          <SpStateLoading
            v-else-if="!testReport"
            variant="text"
            :count="3"
          />

          <template v-else>
            <UAlert
              :icon="testReport.valid ? 'i-lucide-circle-check' : 'i-lucide-circle-x'"
              :color="testReport.valid ? 'success' : 'error'"
              variant="subtle"
              :title="testReport.valid ? 'This key can make requests' : 'This key cannot make requests'"
            />

            <dl class="divide-y divide-default overflow-hidden rounded-lg border border-default text-sm">
              <div class="flex items-center justify-between gap-4 px-4 py-2.5">
                <dt class="text-muted">
                  Status
                </dt>
                <dd>
                  <SpStatusBadge :status="testReport.status.toLowerCase()" />
                </dd>
              </div>
              <div class="flex items-center justify-between gap-4 px-4 py-2.5">
                <dt class="text-muted">
                  Expires
                </dt>
                <dd class="text-default">
                  {{ testReport.expires_at ? formatDateTime(testReport.expires_at) : 'No expiry' }}
                </dd>
              </div>
              <div class="flex items-center justify-between gap-4 px-4 py-2.5">
                <dt class="text-muted">
                  Tokens remaining
                </dt>
                <dd class="sp-numeric text-default">
                  {{ testReport.token_quota_remaining === null ? 'Not applicable' : formatUnits(testReport.token_quota_remaining) }}
                </dd>
              </div>
              <div class="flex items-center justify-between gap-4 px-4 py-2.5">
                <dt class="text-muted">
                  Credit remaining
                </dt>
                <dd class="sp-numeric text-default">
                  {{ testReport.credit_remaining ? formatMoney(testReport.credit_remaining) : testReport.credit_balances?.length ? testReport.credit_balances.map(formatMoney).join(' + ') : 'Not applicable' }}
                </dd>
              </div>
              <div class="flex items-center justify-between gap-4 px-4 py-2.5">
                <dt class="text-muted">
                  Service
                </dt>
                <dd>
                  <SpStatusBadge :status="testReport.service_status" />
                </dd>
              </div>
            </dl>

            <p class="text-xs text-muted">
              This non-billable check reports only balances this credential can actually spend,
              after active reservations. “Not applicable” means the key has no active balance in
              that billing mode. See
              <NuxtLink
                to="/dashboard/entitlements"
                class="text-primary underline underline-offset-2"
              >your entitlements</NuxtLink>
              for the lot-by-lot ledger.
            </p>

            <div class="space-y-1.5">
              <p class="text-xs font-medium tracking-wide text-dimmed uppercase">
                Scope
              </p>
              <div
                v-if="testReport.allowed_model_aliases.length > 0"
                class="flex flex-wrap gap-1.5"
              >
                <UBadge
                  v-for="alias in testReport.allowed_model_aliases"
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
                class="text-sm text-muted"
              >
                Every model your entitlements allow.
              </p>
            </div>

            <div class="space-y-1.5">
              <p class="text-xs font-medium tracking-wide text-dimmed uppercase">
                Ceilings
              </p>
              <dl
                v-if="ceilingRows(testReport).length > 0"
                class="divide-y divide-default overflow-hidden rounded-lg border border-default text-sm"
              >
                <div
                  v-for="row in ceilingRows(testReport)"
                  :key="row.label"
                  class="flex items-center justify-between gap-4 px-4 py-2.5"
                >
                  <dt class="text-muted">
                    {{ row.label }}
                  </dt>
                  <dd class="sp-numeric text-default">
                    {{ row.value }}
                  </dd>
                </div>
              </dl>
              <p
                v-else
                class="text-sm text-muted"
              >
                No per-key ceiling is recorded, so nothing is enforced against this key
                specifically. Service-wide protections still apply to every request.
              </p>
            </div>
          </template>
        </div>
      </template>

      <template #footer>
        <div class="flex w-full justify-end">
          <UButton
            color="neutral"
            variant="subtle"
            @click="testOpen = false"
          >
            Close
          </UButton>
        </div>
      </template>
    </UModal>
  </SpDashboardPage>
</template>
