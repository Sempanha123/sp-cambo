<script setup lang="ts">
import type { AdminPlaygroundSettings } from '~/types/admin'

definePageMeta({ layout: 'dashboard', middleware: ['auth'] })
useSeoMeta({ title: 'Playground settings', robots: 'noindex, nofollow' })

const api = useSpApi()
const toast = useToast()
const settings = await useSpResource('admin:playground-settings', () => api.admin.playgroundSettings(), { server: false })
const aliases = await useSpResource('admin:model-aliases:playground', () => api.admin.modelAliases(), { server: false })
const saving = ref(false)

type PlaygroundSettingsForm = Omit<AdminPlaygroundSettings, 'gateway_base_url' | 'default_model_alias'> & {
  gateway_base_url: string
  default_model_alias: string | undefined
}

const form = reactive<PlaygroundSettingsForm>({
  enabled: true,
  daily_token_quota: 20000,
  max_output_tokens: 2048,
  allowed_model_aliases: [],
  gateway_base_url: '',
  default_model_alias: undefined,
  allow_model_switching: true
})

const publishedAliases = computed(() => (aliases.data.value ?? [])
  .filter(alias => alias.publication_ready === true)
  .map(alias => ({ label: alias.public_alias, value: alias.public_alias })))

watch([() => settings.data.value, () => aliases.data.value], ([value]) => {
  if (!value) return
  const published = new Set(publishedAliases.value.map(item => item.value))
  const allowed = value.allowed_model_aliases.filter(alias => published.has(alias))
  const defaultAlias = value.default_model_alias && allowed.includes(value.default_model_alias)
    ? value.default_model_alias
    : allowed[0]

  Object.assign(form, {
    ...value,
    gateway_base_url: value.gateway_base_url ?? '',
    default_model_alias: defaultAlias,
    allowed_model_aliases: allowed
  })
}, { immediate: true })

const freeAliasOptions = computed(() => publishedAliases.value.filter(item => form.allowed_model_aliases.includes(item.value)))

watch(() => [...form.allowed_model_aliases], (allowed) => {
  if (form.default_model_alias && !allowed.includes(form.default_model_alias)) {
    form.default_model_alias = allowed[0]
  }
})

const save = async () => {
  saving.value = true
  try {
    const value = await api.admin.updatePlaygroundSettings({
      enabled: form.enabled,
      daily_token_quota: Number(form.daily_token_quota),
      max_output_tokens: Number(form.max_output_tokens),
      allowed_model_aliases: [...form.allowed_model_aliases],
      gateway_base_url: form.gateway_base_url?.trim() || null,
      default_model_alias: form.default_model_alias || null,
      allow_model_switching: form.allow_model_switching
    })
    settings.data.value = value
    toast.add({ title: 'Playground settings saved', color: 'success' })
  } catch (error) {
    toast.add({ title: 'Settings could not be saved', description: error instanceof Error ? error.message : 'Please try again.', color: 'error' })
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <SpDashboardPage
    title="Playground settings"
    eyebrow="Customer chat"
    description="Configure the hosted customer chat Playground. Requests still pass through the SP Cambo gateway so model routing, reservations, token settlement, limits and billing remain enforced."
  >
    <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
      <UCard class="sp-premium-card sp-app-card">
        <div class="space-y-5">
          <USwitch
            v-model="form.enabled"
            label="Enable customer Playground"
          />

          <div class="grid gap-4 sm:grid-cols-2">
            <UFormField
              label="Daily free tokens"
              help="Granted once per customer per day. Hosted chat stops when this isolated allowance is exhausted and resumes after the daily reset."
            >
              <UInputNumber
                v-model="form.daily_token_quota"
                :min="0"
                :max="1000000000"
                class="w-full"
              />
            </UFormField>
            <UFormField
              label="Maximum output tokens"
              help="Hard cap for one hosted chat response."
            >
              <UInputNumber
                v-model="form.max_output_tokens"
                :min="1"
                :max="65536"
                class="w-full"
              />
            </UFormField>
          </div>

          <UFormField
            label="Free Playground models"
            help="Choose published public aliases that can spend the daily free allowance. Renamed aliases are refreshed automatically."
          >
            <USelectMenu
              v-model="form.allowed_model_aliases"
              :items="publishedAliases"
              value-key="value"
              multiple
              class="w-full"
              placeholder="Choose public aliases"
            />
          </UFormField>

          <div class="grid gap-4 sm:grid-cols-2">
            <UFormField
              label="Default chat model"
              help="The customer Playground opens with this public alias. Only the alias is shown to customers in the model picker."
            >
              <USelectMenu
                v-model="form.default_model_alias"
                :items="freeAliasOptions"
                value-key="value"
                class="w-full"
                placeholder="Choose default model"
              />
            </UFormField>

            <UFormField
              label="Customer model switching"
              help="Turn this off to lock the Playground to the default alias."
            >
              <div class="pt-2">
                <USwitch
                  v-model="form.allow_model_switching"
                  :label="form.allow_model_switching ? 'Customers can switch' : 'Locked to default model'"
                />
              </div>
            </UFormField>
          </div>

          <UFormField
            label="Playground gateway base URL"
            help="Optional server-side override, for example http://127.0.0.1:3010. Leave blank to use SP_CAMBO_GATEWAY_BASE_URL. Point this at an SP Cambo/OmniRoute-compatible gateway, not directly at an upstream provider, or billing and quota enforcement would be bypassed."
          >
            <UInput
              v-model="form.gateway_base_url"
              class="w-full"
              placeholder="Use global gateway URL"
            />
          </UFormField>

          <div class="flex justify-end">
            <UButton
              :loading="saving"
              @click="save"
            >
              Save Playground settings
            </UButton>
          </div>
        </div>
      </UCard>

      <div class="space-y-4">
        <UAlert
          icon="i-lucide-layers-3"
          color="neutral"
          variant="subtle"
          title="Free-first funding with explicit fallback"
          description="Hosted Playground requests use the isolated daily free lot first. After it is exhausted, a customer may explicitly continue with eligible redeemed, purchased-token or credit balance. Customer API keys can never spend the daily lot."
        />
        <UAlert
          icon="i-lucide-route"
          color="info"
          variant="subtle"
          title="Models still come from Providers"
          description="Configure provider connection revisions, private models and public aliases in Providers first. This page only chooses which already-published aliases the chat Playground may use."
        />
      </div>
    </div>
  </SpDashboardPage>
</template>
