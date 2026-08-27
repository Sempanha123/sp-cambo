<script setup lang="ts">
definePageMeta({ layout: 'dashboard', middleware: ['auth'] })
useSeoMeta({ title: 'Platform settings', robots: 'noindex' })

const settings = [
  { title: 'Playground policy', description: 'Daily free quota, allowed public models, output ceiling and customer model switching.', icon: 'i-lucide-flask-conical', to: '/admin/playground' },
  { title: 'Telegram Store', description: 'Bot readiness, subscribers, announcements, package Buy buttons and failed-delivery retry.', icon: 'i-lucide-send', to: '/admin/telegram' },
  { title: 'Providers & routing', description: 'Upstream connection revisions, active READY route, model discovery, aliases and resale readiness.', icon: 'i-lucide-server', to: '/admin/providers' },
  { title: 'Customers & access', description: 'Customer account state, API-key issuance/revocation, entitlement expiry and request metering.', icon: 'i-lucide-users-round', to: '/admin/access' },
  { title: 'System health', description: 'Database, queue, scheduler, gateway, provider route, KHQR, Bakong and Telegram readiness.', icon: 'i-lucide-heart-pulse', to: '/admin/system-health' },
  { title: 'Audit log', description: 'Immutable operator history with secret redaction applied before persistence.', icon: 'i-lucide-scroll-text', to: '/admin/audit-log' }
]
</script>

<template>
  <SpDashboardPage title="Platform settings" icon="i-lucide-settings" description="Safe entry points for SP Cambo operational configuration. Secrets stay in server-side environment configuration and are never editable in the browser.">
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
      <NuxtLink v-for="item in settings" :key="item.to" :to="item.to" class="group rounded-xl border border-default bg-default p-5 transition hover:border-primary/50 hover:bg-elevated">
        <div class="flex items-start gap-4"><div class="rounded-lg bg-elevated p-2.5"><UIcon :name="item.icon" class="size-5 text-primary" /></div><div><p class="font-semibold text-highlighted group-hover:text-primary">{{ item.title }}</p><p class="mt-2 text-sm leading-6 text-muted">{{ item.description }}</p></div></div>
      </NuxtLink>
    </div>
    <UAlert class="mt-6" color="warning" variant="subtle" icon="i-lucide-lock-keyhole" title="Secret configuration is intentionally not exposed here" description="Provider credentials, Bakong tokens, Telegram bot tokens, internal gateway secrets and application keys must remain in environment or secure secret storage." />
  </SpDashboardPage>
</template>
