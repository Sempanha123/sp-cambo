<script setup lang="ts">
import type { AdminRedeemCode } from '~/types/admin'

definePageMeta({ layout: 'dashboard', middleware: ['auth'] })
useSeoMeta({ title: 'Redeem codes', robots: 'noindex, nofollow' })

const api = useSpApi()
const toast = useToast()
const codes = await useSpResource('admin:redeem-codes', () => api.admin.redeemCodes(), { server: false })
const aliases = await useSpResource('admin:model-aliases:redeem', () => api.admin.modelAliases(), { server: false })

const formOpen = ref(false)
const saving = ref(false)
const revealedCode = ref<string | null>(null)
const editing = ref<AdminRedeemCode | null>(null)
const form = reactive({
  label: '', billing_mode: 'TOKEN_QUOTA' as 'TOKEN_QUOTA' | 'CREDIT_BALANCE', units: '20000', duration_seconds: '86400',
  allowed_model_alias_ids: [] as number[], max_redemptions: '', per_user_limit: '1', starts_at: '', ends_at: '', enabled: true
})

const reset = () => {
  editing.value = null
  form.label = ''
  form.billing_mode = 'TOKEN_QUOTA'
  form.units = '20000'
  form.duration_seconds = '86400'
  form.allowed_model_alias_ids = []
  form.max_redemptions = ''
  form.per_user_limit = '1'
  form.starts_at = ''
  form.ends_at = ''
  form.enabled = true
}

const openCreate = () => {
  reset()
  formOpen.value = true
}
const openEdit = (code: AdminRedeemCode) => {
  editing.value = code
  form.label = code.label
  form.max_redemptions = code.max_redemptions === null ? '' : String(code.max_redemptions)
  form.per_user_limit = String(code.per_user_limit)
  form.starts_at = code.starts_at?.slice(0, 16) ?? ''
  form.ends_at = code.ends_at?.slice(0, 16) ?? ''
  form.enabled = code.enabled
  formOpen.value = true
}

const save = async () => {
  saving.value = true
  try {
    if (editing.value) {
      await api.admin.updateRedeemCode(editing.value.id, {
        label: form.label.trim(),
        max_redemptions: form.max_redemptions ? Number(form.max_redemptions) : null,
        per_user_limit: Number(form.per_user_limit),
        starts_at: form.starts_at || null,
        ends_at: form.ends_at || null,
        enabled: form.enabled
      })
      toast.add({ title: 'Redeem code updated', color: 'success' })
    } else {
      const created = await api.admin.createRedeemCode({
        label: form.label.trim(),
        billing_mode: form.billing_mode,
        units: Number(form.units),
        duration_seconds: Number(form.duration_seconds),
        allowed_model_alias_ids: form.allowed_model_alias_ids,
        billing_rules: null,
        max_redemptions: form.max_redemptions ? Number(form.max_redemptions) : null,
        per_user_limit: Number(form.per_user_limit),
        starts_at: form.starts_at || null,
        ends_at: form.ends_at || null,
        enabled: form.enabled
      })
      revealedCode.value = created.code ?? null
      toast.add({ title: 'Redeem code issued', description: 'Copy the plaintext code now; it is not stored for later display.', color: 'success' })
    }
    formOpen.value = false
    await codes.refresh()
  } catch (error) {
    toast.add({ title: 'Redeem code could not be saved', description: error instanceof Error ? error.message : 'Please check the form.', color: 'error' })
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <SpDashboardPage
    title="Redeem codes"
    description="Issue free token or credit grants without bypassing the normal entitlement ledger."
    eyebrow="Catalog management"
  >
    <template #actions>
      <UButton
        icon="i-lucide-plus"
        @click="openCreate"
      >
        Issue redeem code
      </UButton>
    </template>

    <UAlert
      v-if="revealedCode"
      class="mb-5"
      color="warning"
      variant="subtle"
      title="Copy this code now"
    >
      <template #description>
        <code class="mt-2 block overflow-x-auto rounded-md bg-default px-3 py-2 text-sm">{{ revealedCode }}</code>
      </template>
    </UAlert>

    <SpAsyncSection
      :loading="codes.initialLoading.value"
      :unavailable="codes.unavailable.value"
      :failed="codes.failed.value"
      :empty="codes.isEmpty.value"
      :offline="codes.error.value?.code === 'network_unreachable'"
      :error-message="codes.error.value?.message"
      empty-title="No redeem codes yet"
      empty-description="Issue a code to grant controlled promotional quota."
      @retry="codes.refresh()"
    >
      <div class="grid gap-4 lg:grid-cols-2">
        <UCard
          v-for="code in codes.data.value ?? []"
          :key="code.id"
        >
          <div class="flex items-start justify-between gap-4">
            <div>
              <div class="flex items-center gap-2">
                <h2 class="font-semibold text-highlighted">
                  {{ code.label }}
                </h2>
                <UBadge
                  :color="code.enabled ? 'success' : 'neutral'"
                  variant="subtle"
                >
                  {{ code.enabled ? 'Enabled' : 'Disabled' }}
                </UBadge>
              </div>
              <code class="mt-2 block text-sm text-muted">{{ code.masked_code }}</code>
            </div>
            <UButton
              icon="i-lucide-pencil"
              color="neutral"
              variant="ghost"
              @click="openEdit(code)"
            >
              Edit
            </UButton>
          </div>
          <dl class="mt-5 grid grid-cols-2 gap-4 text-sm">
            <div>
              <dt class="text-muted">
                Grant
              </dt><dd class="font-medium text-highlighted">
                {{ code.units }} {{ code.billing_mode === 'TOKEN_QUOTA' ? 'tokens' : 'microcredits' }}
              </dd>
            </div>
            <div>
              <dt class="text-muted">
                Lifetime
              </dt><dd class="font-medium text-highlighted">
                {{ code.duration_seconds }} sec
              </dd>
            </div>
            <div>
              <dt class="text-muted">
                Redemptions
              </dt><dd class="font-medium text-highlighted">
                {{ code.redemptions }} / {{ code.max_redemptions ?? '∞' }}
              </dd>
            </div>
            <div>
              <dt class="text-muted">
                Per user
              </dt><dd class="font-medium text-highlighted">
                {{ code.per_user_limit }}
              </dd>
            </div>
          </dl>
          <p class="mt-4 text-xs text-muted">
            Models: {{ code.allowed_model_aliases.join(', ') || 'None' }}
          </p>
        </UCard>
      </div>
    </SpAsyncSection>

    <UModal
      v-model:open="formOpen"
      :title="editing ? 'Edit redeem code' : 'Issue redeem code'"
    >
      <template #body>
        <div class="space-y-4">
          <UFormField label="Label">
            <UInput
              v-model="form.label"
              class="w-full"
            />
          </UFormField>
          <template v-if="!editing">
            <div class="grid gap-4 sm:grid-cols-2">
              <UFormField label="Billing mode">
                <USelect
                  v-model="form.billing_mode"
                  :items="[{ label: 'Token quota', value: 'TOKEN_QUOTA' }, { label: 'Credit balance', value: 'CREDIT_BALANCE' }]"
                  class="w-full"
                />
              </UFormField>
              <UFormField label="Units">
                <UInput
                  v-model="form.units"
                  type="number"
                  min="1"
                  class="w-full"
                />
              </UFormField>
              <UFormField label="Duration seconds">
                <UInput
                  v-model="form.duration_seconds"
                  type="number"
                  min="60"
                  class="w-full"
                />
              </UFormField>
              <UFormField label="Per-user limit">
                <UInput
                  v-model="form.per_user_limit"
                  type="number"
                  min="1"
                  class="w-full"
                />
              </UFormField>
            </div>
            <UFormField label="Allowed models">
              <div class="space-y-2 rounded-lg border border-default p-3">
                <UCheckbox
                  v-for="alias in aliases.data.value ?? []"
                  :key="alias.id"
                  v-model="form.allowed_model_alias_ids"
                  :value="Number(alias.id)"
                  :label="alias.public_alias"
                />
              </div>
            </UFormField>
          </template>
          <UFormField
            v-else
            label="Per-user limit"
          >
            <UInput
              v-model="form.per_user_limit"
              type="number"
              min="1"
              class="w-full"
            />
          </UFormField>
          <UFormField label="Maximum redemptions">
            <UInput
              v-model="form.max_redemptions"
              type="number"
              min="1"
              placeholder="Unlimited"
              class="w-full"
            />
          </UFormField>
          <div class="grid gap-4 sm:grid-cols-2">
            <UFormField label="Starts at">
              <UInput
                v-model="form.starts_at"
                type="datetime-local"
                class="w-full"
              />
            </UFormField>
            <UFormField label="Ends at">
              <UInput
                v-model="form.ends_at"
                type="datetime-local"
                class="w-full"
              />
            </UFormField>
          </div>
          <UCheckbox
            v-model="form.enabled"
            label="Enabled"
          />
        </div>
      </template>
      <template #footer>
        <div class="flex w-full justify-end gap-2">
          <UButton
            color="neutral"
            variant="ghost"
            @click="formOpen = false"
          >
            Cancel
          </UButton>
          <UButton
            :loading="saving"
            @click="save"
          >
            {{ editing ? 'Save changes' : 'Issue code' }}
          </UButton>
        </div>
      </template>
    </UModal>
  </SpDashboardPage>
</template>
