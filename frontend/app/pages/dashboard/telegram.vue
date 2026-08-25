<script setup lang="ts">
definePageMeta({ layout: 'dashboard', middleware: ['auth'] })
useSeoMeta({ title: 'Telegram Store', robots: 'noindex, nofollow' })

const config = useRuntimeConfig()
const botUsername = computed(() => String(config.public.telegramBotUsername || '').trim().replace(/^@/, ''))
const botUrl = computed(() => botUsername.value ? `https://t.me/${botUsername.value}?start=store` : null)
const features = [
  ['🛍 Store', 'Browse published token/credit packages and open a package detail before buying.'],
  ['💰 Balance', 'See spendable token and credit balance, with a shortcut to the no-login Key Checker.'],
  ['🧠 Models', 'See currently published customer-facing model aliases.'],
  ['📋 Orders', 'Review recent Telegram purchases and re-check the latest pending payment.'],
  ['📣 Updates', 'Opt in/out of new-model, new-package and package-update announcements.'],
  ['🌐 Language', 'Switch the storefront between English and Khmer.']
]
</script>

<template>
  <SpDashboardPage title="Telegram Store" eyebrow="Standalone sales channel" description="SP Cambo has its own Telegram storefront: browse packages, pay with Bakong KHQR, receive an API key, check orders/balance, and control product updates without a website login.">
    <div class="grid gap-5 xl:grid-cols-[minmax(0,1.15fr)_minmax(20rem,.85fr)]">
      <UCard class="sp-premium-card">
        <template #header><div><h2 class="font-semibold text-highlighted">Customer storefront</h2><p class="mt-1 text-sm text-muted">A private Telegram identity gets its own SP Cambo customer workspace automatically. The old /link command remains compatibility-only.</p></div></template>
        <div class="grid gap-3 sm:grid-cols-2">
          <div v-for="feature in features" :key="feature[0]" class="rounded-xl border border-default bg-elevated/30 p-4"><strong class="text-sm text-highlighted">{{ feature[0] }}</strong><p class="mt-1 text-xs leading-5 text-muted">{{ feature[1] }}</p></div>
        </div>
        <div class="mt-5 flex flex-wrap gap-3">
          <UButton v-if="botUrl" :to="botUrl" target="_blank" icon="i-lucide-send" trailing-icon="i-lucide-external-link">Open @{{ botUsername }}</UButton>
          <UButton to="/public/key-checker" color="neutral" variant="subtle" icon="i-lucide-gauge">Open public Key Checker</UButton>
        </div>
        <UAlert v-if="!botUrl" class="mt-5" color="warning" variant="subtle" icon="i-lucide-settings" title="Set the public bot username" description="Set NUXT_PUBLIC_TELEGRAM_BOT_USERNAME to your bot username (without @). Laravel still uses TELEGRAM_BOT_TOKEN and TELEGRAM_WEBHOOK_SECRET for the real bot connection." />
      </UCard>

      <div class="space-y-5">
        <UCard class="sp-premium-card">
          <template #header><h3 class="font-semibold text-highlighted">Buy → delivery</h3></template>
          <ol class="space-y-3 text-sm text-muted">
            <li><strong class="text-highlighted">1.</strong> Open Store and choose a package.</li>
            <li><strong class="text-highlighted">2.</strong> Tap Buy now to create an exact SP Cambo order.</li>
            <li><strong class="text-highlighted">3.</strong> Pay the generated Bakong KHQR. The payload can also be copied from the payment message.</li>
            <li><strong class="text-highlighted">4.</strong> The scheduler verifies payment automatically.</li>
            <li><strong class="text-highlighted">5.</strong> The bot sends the newly generated key once, model access, Key Checker shortcut, Claude Code setup and OpenAI/Codex base URL.</li>
          </ol>
        </UCard>
        <UAlert color="success" variant="subtle" icon="i-lucide-bell-ring" title="Automatic store updates" description="When an admin publishes a new model or a sellable package changes, SP Cambo queues an opt-in Telegram announcement. Package messages can include a Buy button. Customers can mute updates at any time." />
        <UAlert color="neutral" variant="subtle" icon="i-lucide-refresh-cw" title="Keep the scheduler running" description="telegram:reconcile-purchases verifies pending orders; telegram:broadcast-announcements delivers queued model/package updates. Both run every minute in the Laravel scheduler." />
      </div>
    </div>
  </SpDashboardPage>
</template>
