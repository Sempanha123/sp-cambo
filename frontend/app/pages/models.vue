<script setup lang="ts">
import type { MoneyAmount, PublicModel } from '~/types/commerce'

useSeoMeta({
  title: 'Model catalogue',
  description: 'Every model available through SP Cambo, with its public alias, capabilities, limits and credit pricing. Aliases stay stable while upstream routing changes.'
})

const api = useSpApi()
const models = await useSpResource('catalog:models', () => api.catalog.models())

const search = ref('')
const selectedFamily = ref<string>('all')

const families = computed(() => {
  const list = models.data.value ?? []
  const seen = new Map<string, string>()

  for (const model of list) {
    seen.set(model.family, model.family_label)
  }

  return [
    { label: 'All families', value: 'all', icon: 'i-lucide-layout-grid' },
    ...[...seen.entries()].map(([value, label]) => ({
      label,
      value,
      icon: modelPresentation(label, label).icon
    }))
  ]
})

const filtered = computed(() => {
  const list = models.data.value ?? []
  const term = search.value.trim().toLowerCase()

  return list.filter((model) => {
    const matchesFamily = selectedFamily.value === 'all' || model.family === selectedFamily.value
    const matchesTerm = term === ''
      || model.public_alias.toLowerCase().includes(term)
      || model.display_name.toLowerCase().includes(term)
      || (model.description ?? '').toLowerCase().includes(term)

    return matchesFamily && matchesTerm
  })
})

const capabilityLabels = [
  { key: 'streaming', label: 'Streaming', icon: 'i-lucide-radio' },
  { key: 'tools', label: 'Tool calls', icon: 'i-lucide-wrench' },
  { key: 'vision', label: 'Vision', icon: 'i-lucide-image' },
  { key: 'reasoning', label: 'Reasoning', icon: 'i-lucide-brain' }
] as const

const surfaceLabels = [
  { key: 'messages_api', label: 'Messages API', icon: 'i-lucide-message-square' },
  { key: 'responses_api', label: 'Responses API', icon: 'i-lucide-repeat-2' },
  { key: 'chat_completions_api', label: 'Chat Completions API', icon: 'i-lucide-messages-square' }
] as const

const statedSurfaces = (model: PublicModel) =>
  surfaceLabels
    .filter(surface => typeof model.capabilities[surface.key] === 'boolean')
    .map(surface => ({ ...surface, supported: model.capabilities[surface.key] === true }))

interface PriceRow {
  label: string
  amount: MoneyAmount | null
  note: string | null
}

const pricingRows = (model: PublicModel): PriceRow[] => {
  const pricing = model.credit_pricing

  if (!pricing) {
    return []
  }

  const rows: PriceRow[] = [
    { label: 'Input', amount: pricing.input_per_million, note: null },
    { label: 'Output', amount: pricing.output_per_million, note: null }
  ]

  if (pricing.cache_read_per_million) {
    rows.push({ label: 'Cached input', amount: pricing.cache_read_per_million, note: null })
  }

  if (pricing.cache_write_per_million) {
    rows.push({ label: 'Cache write', amount: pricing.cache_write_per_million, note: null })
  }

  if (model.capabilities.reasoning === true) {
    rows.push(pricing.reasoning_per_million
      ? { label: 'Reasoning', amount: pricing.reasoning_per_million, note: null }
      : { label: 'Reasoning', amount: null, note: 'Output rate' })
  }

  return rows
}

const multiplierRows = (model: PublicModel) => {
  const values = model.limits.billing_multipliers_bps ?? {}
  const labels = [
    ['input', 'Input'],
    ['output', 'Output'],
    ['cache_read', 'Cached input'],
    ['cache_write', 'Cache write'],
    ['reasoning', 'Reasoning']
  ] as const

  return labels
    .filter(([key]) => typeof values[key] === 'number' && values[key] !== 10_000 && (model.limits.billing_usage_classes ?? []).includes(key))
    .map(([key, label]) => ({ key, label, value: `${((values[key] ?? 10_000) / 10_000).toFixed(2)}×` }))
}
</script>

<template>
  <div>
    <UContainer class="py-14 sm:py-16">
      <div class="max-w-3xl space-y-4">
        <h1 class="text-4xl font-semibold tracking-tight text-highlighted text-balance">
          Model catalogue
        </h1>
        <p class="text-lg text-muted text-pretty">
          Pick the model you want, copy its public alias, then use the same alias in Playground,
          Claude Code, Codex-compatible clients or your API integration. SP Cambo keeps these
          public aliases stable while private routing can change behind the gateway.
        </p>
      </div>

      <div
        v-if="models.data.value && models.data.value.length > 0"
        class="mt-10 flex flex-col gap-3 sm:flex-row sm:items-center"
      >
        <UInput
          v-model="search"
          icon="i-lucide-search"
          placeholder="Search models or public aliases"
          class="sm:max-w-xs"
          aria-label="Search models"
        />
        <USelectMenu
          v-model="selectedFamily"
          :items="families"
          value-key="value"
          class="sm:max-w-56"
          aria-label="Filter by family"
        />
        <p class="text-sm text-muted sm:ms-auto">
          {{ filtered.length }} of {{ models.data.value.length }} models
        </p>
      </div>

      <div class="mt-8">
        <SpAsyncSection
          :loading="models.initialLoading.value"
          :unavailable="models.unavailable.value"
          :failed="models.failed.value"
          :empty="models.isEmpty.value"
          :offline="models.error.value?.code === 'network_unreachable'"
          :error-message="models.error.value?.message"
          unavailable-description="The model catalogue is published by the SP Cambo control plane. It has not been made available yet, so no models are listed here."
          empty-title="No models published yet"
          empty-description="Models appear here as soon as an administrator publishes them."
          empty-icon="i-lucide-boxes"
          loading-variant="cards"
          :loading-count="6"
          @retry="models.refresh()"
        >
          <div
            v-if="filtered.length === 0"
            class="rounded-lg border border-dashed border-default px-6 py-12 text-center"
          >
            <p class="text-sm text-muted">
              No models match your filters.
            </p>
          </div>

          <div
            v-else
            class="grid gap-5 lg:grid-cols-2"
          >
            <article
              v-for="model in filtered"
              :key="model.public_alias"
              class="sp-model-catalog-card flex min-w-0 flex-col gap-4 rounded-2xl p-5 sm:p-6"
            >
              <div class="flex min-w-0 items-start justify-between gap-3">
                <div class="flex min-w-0 items-start gap-3">
                  <!-- Keep the larger R5 artwork for the model identity. -->
                  <SpModelLogo
                    :model="model.public_alias"
                    :label="model.display_name"
                    size="lg"
                  />
                  <div class="min-w-0 space-y-1.5">
                    <h2 class="truncate text-lg font-semibold text-highlighted">
                      {{ model.display_name }}
                    </h2>
                    <div class="flex flex-wrap items-center gap-2">
                      <UBadge color="neutral" variant="subtle" size="sm">
                        {{ model.family_label }}
                      </UBadge>
                      <SpStatusBadge :status="model.status" />
                    </div>
                  </div>
                </div>
              </div>

              <!-- R7: user-provided small GIF appears specifically with Public alias. -->
              <div class="sp-public-alias-panel">
                <div class="flex min-w-0 items-center gap-2.5">
                  <SpPublicAliasIcon
                    :alias="model.public_alias"
                    :label="model.display_name"
                    size="md"
                  />

                  <div class="min-w-0 flex-1">
                    <p class="text-[10px] font-semibold tracking-[0.12em] text-muted uppercase">
                      Public alias
                    </p>
                    <code class="mt-0.5 block truncate font-mono text-xs font-medium text-toned">
                      {{ model.public_alias }}
                    </code>
                  </div>

                  <SpCopyButton
                    :value="model.public_alias"
                    size="sm"
                  />
                </div>
              </div>

              <p
                v-if="model.description"
                class="text-sm text-muted text-pretty"
              >
                {{ model.description }}
              </p>

              <ul class="flex flex-wrap gap-2">
                <li
                  v-for="capability in capabilityLabels"
                  :key="capability.key"
                >
                  <UBadge
                    :color="model.capabilities[capability.key] ? 'primary' : 'neutral'"
                    :variant="model.capabilities[capability.key] ? 'subtle' : 'outline'"
                    size="sm"
                    :icon="capability.icon"
                    :class="model.capabilities[capability.key] ? undefined : 'opacity-50'"
                  >
                    {{ capability.label }}
                  </UBadge>
                </li>
              </ul>

              <ul
                v-if="statedSurfaces(model).length > 0"
                class="flex flex-wrap gap-2"
              >
                <li
                  v-for="surface in statedSurfaces(model)"
                  :key="surface.key"
                >
                  <UBadge
                    :color="surface.supported ? 'success' : 'neutral'"
                    :variant="surface.supported ? 'subtle' : 'outline'"
                    size="sm"
                    :icon="surface.icon"
                    :class="surface.supported ? undefined : 'opacity-50'"
                  >
                    {{ surface.label }}
                  </UBadge>
                </li>
              </ul>

              <p
                v-else
                class="text-xs text-muted text-pretty"
              >
                This model states no inference protocol. A protocol an alias does not state is
                refused with <code class="font-mono">model_unavailable</code>, so confirm one works
                before you build against it.
              </p>

              <dl class="grid grid-cols-2 gap-x-4 gap-y-3 border-t border-default/70 pt-4 text-sm">
                <div>
                  <dt class="text-xs text-dimmed">Context window</dt>
                  <dd class="sp-numeric text-default">
                    {{ formatCount(model.capabilities.context_tokens) }}
                  </dd>
                </div>
                <div>
                  <dt class="text-xs text-dimmed">Max output</dt>
                  <dd class="sp-numeric text-default">
                    {{ formatCount(model.capabilities.max_output_tokens) }}
                  </dd>
                </div>
                <div>
                  <dt class="text-xs text-dimmed">Requests / minute</dt>
                  <dd class="sp-numeric text-default">
                    {{ model.limits.requests_per_minute === null ? 'Per package' : formatCount(model.limits.requests_per_minute) }}
                  </dd>
                </div>
                <div>
                  <dt class="text-xs text-dimmed">Concurrency</dt>
                  <dd class="sp-numeric text-default">
                    {{ model.limits.concurrency === null ? 'Per package' : formatCount(model.limits.concurrency) }}
                  </dd>
                </div>
              </dl>

              <div
                v-if="model.credit_pricing"
                class="space-y-2 rounded-xl bg-default/40 p-4"
              >
                <p class="text-xs font-medium tracking-wide text-muted uppercase">
                  Credit pricing per 1M Tokens
                </p>
                <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                  <div
                    v-for="row in pricingRows(model)"
                    :key="row.label"
                    class="flex items-baseline justify-between gap-2"
                  >
                    <dt class="text-muted">{{ row.label }}</dt>
                    <dd
                      class="font-medium text-highlighted"
                      :class="row.amount ? 'sp-numeric' : undefined"
                    >
                      {{ row.amount ? formatMoney(row.amount) : row.note }}
                    </dd>
                  </div>
                </dl>

                <p
                  v-if="pricingRows(model).some(row => row.note !== null)"
                  class="text-xs text-muted text-pretty"
                >
                  This model states no separate reasoning rate, so reasoning tokens are charged at the
                  output rate. They are not free, and a thinking-heavy request can produce more of them
                  than visible output.
                </p>

                <div
                  v-if="multiplierRows(model).length > 0"
                  class="border-t border-default/70 pt-3"
                >
                  <p class="text-xs font-medium tracking-wide text-muted uppercase">
                    Token usage multiplier
                  </p>
                  <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                    <div
                      v-for="row in multiplierRows(model)"
                      :key="row.key"
                      class="flex items-baseline justify-between gap-2"
                    >
                      <dt class="text-muted">{{ row.label }}</dt>
                      <dd class="sp-numeric font-medium text-highlighted">
                        {{ row.value }}
                      </dd>
                    </div>
                  </dl>
                  <p class="mt-2 text-xs text-muted text-pretty">
                    Balance settlement uses only SP Cambo's local meter. Repeated prompt prefixes can use the 0.25x local cached-input rate; provider usage/cache counters never change your balance.
                  </p>
                </div>
              </div>

              <p v-else class="text-xs text-muted">
                Sold through token packages rather than credit pricing.
              </p>

              <div class="mt-auto flex flex-wrap gap-2 border-t border-default/70 pt-4">
                <UButton
                  to="/pricing"
                  size="sm"
                  color="neutral"
                  variant="subtle"
                  icon="i-lucide-package"
                >
                  View packages
                </UButton>
                <UButton
                  :to="`/dashboard/playground?model=${encodeURIComponent(model.public_alias)}`"
                  size="sm"
                  variant="soft"
                  icon="i-lucide-message-circle"
                >
                  Try model
                </UButton>
              </div>
            </article>
          </div>
        </SpAsyncSection>
      </div>
    </UContainer>

    <div class="border-t border-default/70 bg-elevated/20">
      <UContainer class="flex flex-col items-start justify-between gap-4 py-10 sm:flex-row sm:items-center">
        <div class="space-y-1">
          <p class="font-medium text-highlighted">
            Ready to call one of these models?
          </p>
          <p class="text-sm text-muted">
            Copy the Public alias above and use it in Playground, Claude Code, Codex CLI or your API request.
          </p>
        </div>
        <div class="flex flex-wrap gap-3">
          <UButton
            to="/docs/quick-start"
            trailing-icon="i-lucide-arrow-right"
          >
            Quick start
          </UButton>
          <UButton
            to="/pricing"
            color="neutral"
            variant="subtle"
          >
            See packages
          </UButton>
        </div>
      </UContainer>
    </div>
  </div>
</template>

<style scoped>
.sp-model-catalog-card {
  border: 1px solid rgb(255 255 255 / .055);
  background:
    linear-gradient(145deg, rgb(255 255 255 / .018), transparent 48%),
    color-mix(in oklab, var(--ui-bg-elevated) 50%, transparent);
  box-shadow:
    inset 0 1px 0 rgb(255 255 255 / .025),
    0 22px 55px -42px color-mix(in oklab, var(--ui-primary) 24%, transparent);
  backdrop-filter: blur(14px);
  transition:
    transform .26s ease,
    border-color .26s ease,
    box-shadow .26s ease;
}

.sp-model-catalog-card:hover {
  transform: translateY(-3px);
  border-color: color-mix(in oklab, var(--ui-primary) 22%, transparent);
  box-shadow:
    inset 0 1px 0 rgb(255 255 255 / .04),
    0 26px 60px -38px color-mix(in oklab, var(--ui-primary) 30%, transparent);
}

.sp-public-alias-panel {
  min-width: 0;
  border: 1px solid rgb(255 255 255 / .04);
  border-radius: .85rem;
  padding: .7rem .75rem;
  background:
    linear-gradient(90deg, color-mix(in oklab, var(--ui-primary) 4%, transparent), transparent 58%),
    color-mix(in oklab, var(--ui-bg) 36%, transparent);
  box-shadow: inset 0 1px 0 rgb(255 255 255 / .02);
}

@media (max-width: 639px) {
  .sp-model-catalog-card {
    padding: 1rem;
  }

  .sp-public-alias-panel {
    padding: .65rem;
  }
}
</style>
