<script setup lang="ts">
import type { MoneyAmount, PublicModel } from '~/types/commerce'

useSeoMeta({
  title: 'Model catalogue',
  description: 'Every model available through SP Cambo, with its public alias, capabilities, limits and credit pricing.'
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
    { label: 'All families', value: 'all' },
    ...[...seen.entries()].map(([value, label]) => ({ label, value }))
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
      || model.family_label.toLowerCase().includes(term)
      || (model.description ?? '').toLowerCase().includes(term)

    return matchesFamily && matchesTerm
  })
})

const capabilityLabels = [
  { key: 'streaming', label: 'Streaming', icon: 'i-lucide-radio' },
  { key: 'tools', label: 'Tools', icon: 'i-lucide-wrench' },
  { key: 'vision', label: 'Vision', icon: 'i-lucide-image' },
  { key: 'reasoning', label: 'Reasoning', icon: 'i-lucide-brain' }
] as const

const surfaceLabels = [
  { key: 'messages_api', label: 'Messages API', icon: 'i-lucide-message-square' },
  { key: 'responses_api', label: 'Responses API', icon: 'i-lucide-repeat-2' },
  { key: 'chat_completions_api', label: 'Chat Completions', icon: 'i-lucide-messages-square' }
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
  if (!pricing) return []

  const rows: PriceRow[] = [
    { label: 'Input', amount: pricing.input_per_million, note: null },
    { label: 'Output', amount: pricing.output_per_million, note: null }
  ]

  if (pricing.cache_read_per_million) rows.push({ label: 'Cache', amount: pricing.cache_read_per_million, note: null })
  if (pricing.cache_write_per_million) rows.push({ label: 'Cache write', amount: pricing.cache_write_per_million, note: null })

  if (model.capabilities.reasoning === true) {
    rows.push(pricing.reasoning_per_million
      ? { label: 'Reasoning', amount: pricing.reasoning_per_million, note: null }
      : { label: 'Reasoning', amount: null, note: 'Output rate' })
  }

  return rows
}

const primaryPriceRows = (model: PublicModel) => pricingRows(model).slice(0, 3)

const statCards = computed(() => [
  { label: 'Published models', value: models.data.value?.length ?? 0, icon: 'i-lucide-boxes' },
  { label: 'Stable aliases', value: models.data.value?.length ?? 0, icon: 'i-lucide-link-2' },
  { label: 'One gateway', value: 'API', icon: 'i-lucide-braces' }
])
</script>

<template>
  <div class="sp-r8-models-page">
    <UContainer class="pt-10 sm:pt-14">
      <section class="sp-r8-model-hero">
        <div class="sp-r8-model-hero__copy">
          <div class="flex flex-wrap items-center gap-2">
            <UBadge color="neutral" variant="subtle" class="rounded-full">
              <span class="flex items-center gap-2">
                <span class="relative flex size-2">
                  <span class="absolute inline-flex size-full animate-ping rounded-full bg-success opacity-50" />
                  <span class="relative inline-flex size-2 rounded-full bg-success" />
                </span>
                LIVE MODEL CATALOGUE
              </span>
            </UBadge>
            <span class="sp-r8-kicker">Public aliases · prepaid access</span>
          </div>

          <div class="space-y-4">
            <h1 class="max-w-2xl text-4xl font-semibold tracking-tight text-highlighted text-balance sm:text-5xl lg:text-6xl">
              Choose the right
              <span class="sp-r8-gradient-text">AI model</span>
              for your workflow.
            </h1>

            <p class="max-w-2xl text-base leading-7 text-muted text-pretty sm:text-lg">
              Compare stable SP Cambo public aliases, capabilities, limits and credit pricing.
              Use the same alias in Playground, CLI tools or your API integration.
            </p>
          </div>

          <div class="grid max-w-2xl gap-2 sm:grid-cols-3">
            <div
              v-for="item in statCards"
              :key="item.label"
              class="sp-r8-stat-chip"
            >
              <span class="sp-r8-stat-chip__icon">
                <UIcon :name="item.icon" class="size-4" />
              </span>
              <span>
                <strong>{{ item.value }}</strong>
                <small>{{ item.label }}</small>
              </span>
            </div>
          </div>
        </div>

        <div class="sp-r8-orbit-scene" aria-hidden="true">
          <div class="sp-r8-orbit-scene__halo" />
          <div class="sp-r8-orbit sp-r8-orbit--a" />
          <div class="sp-r8-orbit sp-r8-orbit--b" />
          <div class="sp-r8-orbit sp-r8-orbit--c" />

          <div class="sp-r8-orbit-core">
            <UIcon name="i-lucide-sparkles" class="size-14" />
          </div>

          <span class="sp-r8-orbit-node sp-r8-orbit-node--code">
            <UIcon name="i-lucide-code-xml" class="size-5" />
          </span>
          <span class="sp-r8-orbit-node sp-r8-orbit-node--lock">
            <UIcon name="i-lucide-lock-keyhole" class="size-5" />
          </span>
          <span class="sp-r8-orbit-node sp-r8-orbit-node--zap">
            <UIcon name="i-lucide-zap" class="size-5" />
          </span>
        </div>
      </section>

      <div
        v-if="models.data.value && models.data.value.length > 0"
        class="sp-r8-filterbar mt-7"
      >
        <UInput
          v-model="search"
          icon="i-lucide-search"
          placeholder="Search models or public aliases..."
          class="min-w-0 flex-1 sm:max-w-sm"
          aria-label="Search models"
        />

        <USelectMenu
          v-model="selectedFamily"
          :items="families"
          value-key="value"
          class="w-full sm:w-52"
          aria-label="Filter by family"
        />

        <span class="sp-r8-result-count">
          {{ filtered.length }} / {{ models.data.value.length }}
        </span>
      </div>
    </UContainer>

    <UContainer class="pb-14 pt-6 sm:pb-16">
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
          class="sp-r8-empty"
        >
          No models match your filters.
        </div>

        <div
          v-else
          class="grid gap-3 lg:grid-cols-2"
        >
          <article
            v-for="model in filtered"
            :key="model.public_alias"
            class="sp-r8-model-card"
          >
            <div class="sp-r8-model-card__top">
              <div class="flex min-w-0 items-start gap-3">
                <SpModelLogo
                  :model="model.public_alias"
                  :label="model.display_name"
                  size="lg"
                />

                <div class="min-w-0">
                  <div class="flex min-w-0 flex-wrap items-center gap-2">
                    <h2 class="truncate text-base font-semibold text-highlighted sm:text-lg">
                      {{ model.display_name }}
                    </h2>
                    <SpStatusBadge :status="model.status" />
                  </div>

                  <div class="mt-1.5 flex min-w-0 items-center gap-2">
                    <SpPublicAliasIcon
                      :alias="model.public_alias"
                      :label="model.display_name"
                      size="xs"
                    />
                    <code class="truncate font-mono text-[11px] text-muted">
                      {{ model.public_alias }}
                    </code>
                    <UBadge color="success" variant="subtle" size="xs">
                      Alias
                    </UBadge>
                  </div>
                </div>
              </div>

              <SpCopyButton
                :value="model.public_alias"
                size="sm"
              />
            </div>

            <p
              v-if="model.description"
              class="sp-r8-model-card__description"
            >
              {{ model.description }}
            </p>

            <div class="flex flex-wrap gap-1.5">
              <UBadge
                v-for="capability in capabilityLabels"
                :key="capability.key"
                :color="model.capabilities[capability.key] ? 'primary' : 'neutral'"
                :variant="model.capabilities[capability.key] ? 'subtle' : 'outline'"
                size="xs"
                :icon="capability.icon"
                :class="model.capabilities[capability.key] ? undefined : 'opacity-40'"
              >
                {{ capability.label }}
              </UBadge>
            </div>

            <div class="sp-r8-model-card__body">
              <dl class="sp-r8-spec-grid">
                <div>
                  <dt>Context window</dt>
                  <dd>{{ formatCount(model.capabilities.context_tokens) }}</dd>
                </div>
                <div>
                  <dt>Max output</dt>
                  <dd>{{ formatCount(model.capabilities.max_output_tokens) }}</dd>
                </div>
                <div>
                  <dt>Requests / min</dt>
                  <dd>{{ model.limits.requests_per_minute === null ? 'Per package' : formatCount(model.limits.requests_per_minute) }}</dd>
                </div>
                <div>
                  <dt>Concurrency</dt>
                  <dd>{{ model.limits.concurrency === null ? 'Per package' : formatCount(model.limits.concurrency) }}</dd>
                </div>
              </dl>

              <div class="sp-r8-price-strip">
                <template v-if="model.credit_pricing">
                  <div
                    v-for="row in primaryPriceRows(model)"
                    :key="row.label"
                  >
                    <span>{{ row.label }}</span>
                    <strong>{{ row.amount ? formatMoney(row.amount) : row.note }}</strong>
                  </div>
                </template>

                <span v-else class="text-xs text-muted">
                  Sold through token packages
                </span>
              </div>
            </div>

            <div
              v-if="statedSurfaces(model).length > 0"
              class="flex flex-wrap gap-1.5"
            >
              <UBadge
                v-for="surface in statedSurfaces(model).filter(item => item.supported)"
                :key="surface.key"
                color="success"
                variant="subtle"
                size="xs"
                :icon="surface.icon"
              >
                {{ surface.label }}
              </UBadge>
            </div>

            <div class="sp-r8-model-card__actions">
              <UButton
                to="/pricing"
                size="sm"
                color="neutral"
                variant="subtle"
                icon="i-lucide-package"
                class="flex-1 justify-center"
              >
                View packages
              </UButton>

              <UButton
                :to="`/dashboard/playground?model=${encodeURIComponent(model.public_alias)}`"
                size="sm"
                variant="soft"
                icon="i-lucide-play"
                class="flex-1 justify-center"
              >
                Try model
              </UButton>
            </div>
          </article>
        </div>
      </SpAsyncSection>
    </UContainer>

    <UContainer class="pb-16">
      <section class="sp-r8-final-cta">
        <div>
          <p class="text-sm font-medium text-highlighted">
            Ready to build?
          </p>
          <p class="mt-1 text-sm text-muted">
            Choose a package, then use the same public alias in Playground or your own app.
          </p>
        </div>

        <div class="flex flex-wrap gap-2">
          <UButton to="/pricing" color="neutral" variant="subtle">
            See packages
          </UButton>
          <UButton to="/docs/quick-start" trailing-icon="i-lucide-arrow-right">
            Quick start
          </UButton>
        </div>
      </section>
    </UContainer>
  </div>
</template>

<style scoped>
.sp-r8-model-hero {
  position: relative;
  display: grid;
  min-height: 25rem;
  align-items: center;
  gap: 2rem;
  overflow: hidden;
  border: 1px solid rgb(255 255 255 / .045);
  border-radius: 1.75rem;
  background:
    radial-gradient(circle at 78% 42%, rgb(64 103 255 / .10), transparent 23rem),
    linear-gradient(145deg, rgb(255 255 255 / .018), transparent 46%),
    color-mix(in oklab, var(--ui-bg-elevated) 34%, transparent);
  padding: 2rem;
  box-shadow: inset 0 1px 0 rgb(255 255 255 / .025);
  backdrop-filter: blur(16px);
}

.sp-r8-model-hero__copy {
  position: relative;
  z-index: 2;
  max-width: 46rem;
}

.sp-r8-kicker {
  font-size: .68rem;
  font-weight: 700;
  letter-spacing: .12em;
  text-transform: uppercase;
  color: var(--ui-text-dimmed);
}

.sp-r8-gradient-text {
  color: transparent;
  background: linear-gradient(110deg, rgb(96 161 255), rgb(83 211 255), rgb(137 107 255));
  background-size: 180% 100%;
  background-clip: text;
  -webkit-background-clip: text;
  animation: sp-r8-gradient 7s linear infinite;
}

.sp-r8-stat-chip {
  display: flex;
  align-items: center;
  gap: .65rem;
  border: 1px solid rgb(255 255 255 / .04);
  border-radius: .9rem;
  background: color-mix(in oklab, var(--ui-bg) 34%, transparent);
  padding: .65rem .75rem;
}

.sp-r8-stat-chip__icon {
  display: grid;
  width: 2rem;
  height: 2rem;
  flex: none;
  place-items: center;
  border-radius: .65rem;
  background: color-mix(in oklab, var(--ui-primary) 9%, transparent);
  color: var(--ui-primary);
}

.sp-r8-stat-chip strong,
.sp-r8-stat-chip small {
  display: block;
}

.sp-r8-stat-chip strong {
  font-size: .78rem;
  color: var(--ui-text-highlighted);
}

.sp-r8-stat-chip small {
  margin-top: .1rem;
  font-size: .66rem;
  color: var(--ui-text-muted);
}

.sp-r8-orbit-scene {
  position: absolute;
  right: -2rem;
  top: 50%;
  width: 34rem;
  height: 27rem;
  transform: translateY(-50%);
  opacity: .92;
}

.sp-r8-orbit-scene__halo {
  position: absolute;
  inset: 16%;
  border-radius: 9999px;
  background: radial-gradient(circle, rgb(69 104 255 / .14), transparent 68%);
  filter: blur(12px);
  animation: sp-r8-halo 6s ease-in-out infinite;
}

.sp-r8-orbit {
  position: absolute;
  left: 50%;
  top: 50%;
  border: 1px solid rgb(100 136 255 / .16);
  border-radius: 9999px;
}

.sp-r8-orbit--a {
  width: 21rem;
  height: 13rem;
  transform: translate(-50%, -50%) rotate(-15deg);
  animation: sp-r8-orbit-a 17s linear infinite;
}

.sp-r8-orbit--b {
  width: 17rem;
  height: 17rem;
  transform: translate(-50%, -50%);
  border-color: rgb(126 84 255 / .12);
  animation: sp-r8-orbit-b 21s linear infinite reverse;
}

.sp-r8-orbit--c {
  width: 25rem;
  height: 9rem;
  transform: translate(-50%, -50%) rotate(25deg);
  border-style: dashed;
  border-color: rgb(59 183 255 / .12);
  animation: sp-r8-orbit-c 26s linear infinite;
}

.sp-r8-orbit-core {
  position: absolute;
  left: 50%;
  top: 50%;
  display: grid;
  width: 8rem;
  height: 8rem;
  place-items: center;
  transform: translate(-50%, -50%) rotate(-7deg);
  border: 1px solid rgb(138 160 255 / .22);
  border-radius: 29%;
  color: rgb(156 192 255);
  background:
    radial-gradient(circle at 28% 18%, rgb(255 255 255 / .10), transparent 36%),
    linear-gradient(145deg, rgb(64 94 218 / .74), rgb(80 63 180 / .72));
  box-shadow:
    inset 0 1px 0 rgb(255 255 255 / .14),
    0 28px 60px rgb(30 48 135 / .22),
    0 0 65px rgb(63 101 255 / .10);
  animation: sp-r8-core-float 5.2s ease-in-out infinite;
}

.sp-r8-orbit-node {
  position: absolute;
  display: grid;
  width: 3.2rem;
  height: 3.2rem;
  place-items: center;
  border: 1px solid rgb(135 153 215 / .12);
  border-radius: 1rem;
  color: rgb(184 201 247);
  background: rgb(20 31 62 / .62);
  box-shadow: inset 0 1px 0 rgb(255 255 255 / .06);
  backdrop-filter: blur(12px);
  animation: sp-r8-node-float 4.8s ease-in-out infinite;
}

.sp-r8-orbit-node--code { left: 14%; top: 19%; animation-delay: -1s; }
.sp-r8-orbit-node--lock { right: 11%; top: 38%; animation-delay: -2.2s; }
.sp-r8-orbit-node--zap { right: 24%; bottom: 10%; animation-delay: -3.4s; }

.sp-r8-filterbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: .7rem;
  border: 1px solid rgb(255 255 255 / .045);
  border-radius: 1rem;
  background: color-mix(in oklab, var(--ui-bg-elevated) 38%, transparent);
  padding: .65rem;
  backdrop-filter: blur(14px);
}

.sp-r8-result-count {
  margin-left: auto;
  border-radius: 9999px;
  background: color-mix(in oklab, var(--ui-primary) 7%, transparent);
  padding: .38rem .65rem;
  font-size: .68rem;
  color: var(--ui-text-muted);
}

.sp-r8-model-card {
  min-width: 0;
  border: 1px solid rgb(255 255 255 / .045);
  background:
    linear-gradient(145deg, rgb(255 255 255 / .015), transparent 45%),
    color-mix(in oklab, var(--ui-bg-elevated) 42%, transparent);
  padding: 1rem;
  box-shadow:
    inset 0 1px 0 rgb(255 255 255 / .02),
    0 18px 44px -38px rgb(60 105 255 / .18);
  backdrop-filter: blur(14px);
  transition: transform .24s ease, border-color .24s ease, background-color .24s ease;
}

.sp-r8-model-card:hover {
  transform: translateY(-2px);
  border-color: color-mix(in oklab, var(--ui-primary) 18%, transparent);
  background-color: color-mix(in oklab, var(--ui-primary) 2.5%, transparent);
}

.sp-r8-model-card__top {
  display: flex;
  min-width: 0;
  align-items: flex-start;
  justify-content: space-between;
  gap: .75rem;
}

.sp-r8-model-card__description {
  min-height: 2.6rem;
  overflow: hidden;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  font-size: .78rem;
  line-height: 1.3rem;
  color: var(--ui-text-muted);
}

.sp-r8-model-card__body {
  display: grid;
  gap: .8rem;
}

.sp-r8-spec-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: .65rem 1rem;
  border-top: 1px solid rgb(255 255 255 / .04);
  padding-top: .85rem;
}

.sp-r8-spec-grid dt {
  font-size: .65rem;
  color: var(--ui-text-dimmed);
}

.sp-r8-spec-grid dd {
  margin-top: .1rem;
  font-size: .78rem;
  color: var(--ui-text-default);
}

.sp-r8-price-strip {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: .5rem;
  border-radius: .8rem;
  background: color-mix(in oklab, var(--ui-bg) 42%, transparent);
  padding: .62rem .7rem;
}

.sp-r8-price-strip > div {
  min-width: 0;
}

.sp-r8-price-strip span,
.sp-r8-price-strip strong {
  display: block;
}

.sp-r8-price-strip span {
  font-size: .62rem;
  color: var(--ui-text-dimmed);
}

.sp-r8-price-strip strong {
  margin-top: .1rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: .73rem;
  color: var(--ui-text-highlighted);
}

.sp-r8-model-card__actions {
  display: flex;
  gap: .5rem;
  border-top: 1px solid rgb(255 255 255 / .04);
  padding-top: .8rem;
}

.sp-r8-empty {
  border: 1px dashed rgb(255 255 255 / .08);
  border-radius: 1rem;
  padding: 3rem 1rem;
  text-align: center;
  font-size: .875rem;
  color: var(--ui-text-muted);
}

.sp-r8-final-cta {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  border: 1px solid rgb(255 255 255 / .045);
  border-radius: 1.25rem;
  background:
    radial-gradient(circle at 85% 50%, rgb(77 109 255 / .08), transparent 18rem),
    color-mix(in oklab, var(--ui-bg-elevated) 34%, transparent);
  padding: 1rem 1.1rem;
  backdrop-filter: blur(14px);
}

@keyframes sp-r8-gradient {
  from { background-position: 0% 50%; }
  to { background-position: 180% 50%; }
}

@keyframes sp-r8-halo {
  0%, 100% { transform: scale(.92); opacity: .5; }
  50% { transform: scale(1.07); opacity: .82; }
}

@keyframes sp-r8-orbit-a {
  from { transform: translate(-50%, -50%) rotate(-15deg); }
  to { transform: translate(-50%, -50%) rotate(345deg); }
}

@keyframes sp-r8-orbit-b {
  from { transform: translate(-50%, -50%) rotate(0); }
  to { transform: translate(-50%, -50%) rotate(360deg); }
}

@keyframes sp-r8-orbit-c {
  from { transform: translate(-50%, -50%) rotate(25deg); }
  to { transform: translate(-50%, -50%) rotate(385deg); }
}

@keyframes sp-r8-core-float {
  0%, 100% { transform: translate(-50%, -50%) rotate(-7deg) translateY(0); }
  50% { transform: translate(-50%, -50%) rotate(-4deg) translateY(-10px); }
}

@keyframes sp-r8-node-float {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-8px); }
}

@media (min-width: 1024px) {
  .sp-r8-model-hero {
    grid-template-columns: 1.1fr .9fr;
    padding: 2.5rem;
  }
}

@media (max-width: 1023px) {
  .sp-r8-orbit-scene {
    opacity: .28;
    right: -14rem;
  }
}

@media (max-width: 639px) {
  .sp-r8-model-hero {
    min-height: auto;
    padding: 1.1rem;
  }

  .sp-r8-orbit-scene {
    display: none;
  }

  .sp-r8-result-count {
    margin-left: 0;
  }

  .sp-r8-model-card__actions {
    flex-direction: column;
  }

  .sp-r8-final-cta {
    align-items: flex-start;
    flex-direction: column;
  }
}

@media (prefers-reduced-motion: reduce) {
  .sp-r8-models-page *,
  .sp-r8-models-page *::before,
  .sp-r8-models-page *::after {
    animation-duration: .001ms !important;
    animation-iteration-count: 1 !important;
    transition-duration: .001ms !important;
  }
}
</style>
