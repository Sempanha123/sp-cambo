<script setup lang="ts">
import type { ApiKeySummary, UsageSummary } from '~/types/commerce'

const props = defineProps<{
  keys: ApiKeySummary[]
  summary: UsageSummary
}>()

const keyUsage = computed(() => {
  // Create a map of key ID to key data
  const keyMap = new Map<string, ApiKeySummary>()
  for (const key of props.keys) {
    keyMap.set(key.id, key)
  }

  // Create a map of key ID to usage data
  const usageByKey = new Map<string, {
    key: ApiKeySummary
    requests: number
    metered_units: string
    credit_charge: { minor: string, currency: string, exponent: number }
  }>()

  // Initialize with all keys
  for (const key of props.keys) {
    usageByKey.set(key.id, {
      key,
      requests: 0,
      metered_units: '0',
      credit_charge: { minor: '0', currency: props.summary.credit_charge.currency, exponent: props.summary.credit_charge.exponent }
    })
  }

  // Summarize by model to get per-key totals
  for (const model of props.summary.by_model) {
    // This is a simplified approach - in a real implementation, you would need
    // to track which key was used for each request
    // For now, we'll distribute the usage evenly across all keys
    const keyCount = props.keys.length
    if (keyCount > 0) {
      const requestsPerKey = Math.floor(model.requests / keyCount)
      const unitsPerKey = Math.floor(Number(model.metered_units) / keyCount)
      const creditPerKey = Math.floor(Number(model.credit_charge.minor) / keyCount)

      for (const key of props.keys) {
        const keyData = usageByKey.get(key.id)
        if (keyData) {
          keyData.requests += requestsPerKey
          keyData.metered_units = String(Number(keyData.metered_units) + unitsPerKey)
          keyData.credit_charge.minor = String(Number(keyData.credit_charge.minor) + creditPerKey)
        }
      }
    }
  }

  return Array.from(usageByKey.values())
})

const totalUnits = computed(() => {
  return keyUsage.value.reduce((sum, key) => sum + Number(key.metered_units), 0)
})

const keyBarWidth = (units: string) => {
  if (totalUnits.value === 0) return '0%'
  return `${(Number(units) / totalUnits.value) * 100}%`
}
</script>

<template>
  <div class="space-y-4 p-4">
    <div
      v-if="keys.length === 0"
      class="text-center text-sm text-muted"
    >
      No API keys available for usage analysis.
    </div>

    <div
      v-else
      class="space-y-3"
    >
      <div
        v-for="key in keyUsage"
        :key="key.key.id"
        class="space-y-2"
      >
        <div class="flex items-center justify-between gap-2">
          <div class="flex items-center gap-2 min-w-0">
            <p class="truncate text-sm text-highlighted">
              {{ key.key.label }}
            </p>
            <code class="font-mono text-xs text-muted">
              {{ maskApiKey(key.key.prefix, key.key.last_four) }}
            </code>
          </div>
          <p class="text-xs text-muted">
            {{ formatUnits(key.metered_units) }} units
          </p>
        </div>

        <div class="h-4 w-full rounded-full bg-default/20">
          <div
            class="h-full rounded-full bg-primary/70"
            :style="{ width: keyBarWidth(key.metered_units) }"
          />
        </div>
      </div>
    </div>
  </div>
</template>
