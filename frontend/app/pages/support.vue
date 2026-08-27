<script setup lang="ts">
const channel = useSupportChannel()

useSeoMeta({
  title: 'Support',
  description: 'SP Cambo support, service status and troubleshooting links.'
})

const config = useRuntimeConfig()
const telegramUsername = computed(() => String(config.public.telegramBotUsername || '').replace(/^@/, '').trim())
const telegramUrl = computed(() => telegramUsername.value ? `https://t.me/${telegramUsername.value}` : null)
</script>

<template>
  <div class="mx-auto w-full max-w-5xl px-4 py-12 sm:px-6 lg:px-8">
    <div class="max-w-2xl">
      <span class="sp-khmer-chip">ជំនួយ · Support</span>
      <h1 class="mt-4 text-4xl font-semibold tracking-tight text-highlighted">
        Get help with SP Cambo
      </h1>
      <p class="mt-4 text-base leading-7 text-muted">
        Start with the status and troubleshooting pages for API or payment problems. If this deployment publishes a human support channel, it is shown below without exposing private operator contact data.
      </p>
    </div>

    <div class="mt-8 grid gap-4 md:grid-cols-2">
      <UCard class="sp-app-card">
        <template #header>
          <div class="flex items-center gap-3">
            <UIcon name="i-lucide-circle-help" class="size-5 text-primary" />
            <div><p class="font-semibold text-highlighted">Human support</p><p class="text-sm text-muted">Configured by the SP Cambo operator</p></div>
          </div>
        </template>
        <div v-if="channel" class="space-y-3">
          <p class="text-sm text-muted">Use the published support channel for account review, payment verification or a request ID that needs investigation.</p>
          <SpSupportLink />
        </div>
        <UAlert
          v-else
          color="warning"
          variant="subtle"
          icon="i-lucide-triangle-alert"
          title="No human support channel is published yet"
          description="The operator can set NUXT_PUBLIC_SUPPORT_URL to an email address, help desk, or HTTPS chat link. SP Cambo does not invent an address that may be unmonitored."
        />
      </UCard>

      <UCard class="sp-app-card">
        <template #header>
          <div class="flex items-center gap-3">
            <UIcon name="i-lucide-send" class="size-5 text-primary" />
            <div><p class="font-semibold text-highlighted">Telegram Store</p><p class="text-sm text-muted">Storefront and customer self-service</p></div>
          </div>
        </template>
        <p class="text-sm text-muted">Open the standalone bot for Store, Balance, Models, Orders, Updates and Language.</p>
        <UButton v-if="telegramUrl" class="mt-4" :to="telegramUrl" target="_blank" external icon="i-lucide-send">Open Telegram Store</UButton>
        <UAlert v-else class="mt-4" color="neutral" variant="subtle" title="Telegram username not published" description="Configure NUXT_PUBLIC_TELEGRAM_BOT_USERNAME before release if the Telegram storefront is enabled." />
      </UCard>
    </div>

    <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
      <UCard class="sp-app-card"><p class="font-semibold text-highlighted">Service status</p><p class="mt-2 text-sm text-muted">Check measured platform health before retrying a failed request.</p><UButton class="mt-4" to="/status" color="neutral" variant="subtle" icon="i-lucide-activity">View status</UButton></UCard>
      <UCard class="sp-app-card"><p class="font-semibold text-highlighted">API errors</p><p class="mt-2 text-sm text-muted">Stable error codes, retry guidance and what information is safe to share.</p><UButton class="mt-4" to="/docs/errors" color="neutral" variant="subtle" icon="i-lucide-circle-alert">Error guide</UButton></UCard>
      <UCard class="sp-app-card"><p class="font-semibold text-highlighted">Setup help</p><p class="mt-2 text-sm text-muted">Claude Code, Codex-compatible and cURL configuration without exposing your key.</p><UButton class="mt-4" to="/docs/quick-start" color="neutral" variant="subtle" icon="i-lucide-terminal">Quick start</UButton></UCard>
    </div>

    <UAlert class="mt-6" color="neutral" variant="subtle" icon="i-lucide-shield-check" title="Never send your full API key" description="Support can investigate with your masked key, order/reference ID, request ID and timestamp. Do not post API keys, passwords, Bakong tokens or provider credentials in chat or screenshots." />
  </div>
</template>
