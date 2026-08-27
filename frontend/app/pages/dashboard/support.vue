<script setup lang="ts">
definePageMeta({
  layout: 'dashboard',
  middleware: ['auth']
})

useSeoMeta({
  title: 'Support',
  description: 'Get help with SP Cambo orders, API access, usage and Telegram Store.',
  robots: 'noindex'
})
</script>

<template>
  <SpDashboardPage
    title="Support"
    description="Get help without leaving your SP Cambo workspace. Never send a full API key or payment secret."
  >
    <template #actions>
      <SpSupportLink label="Open support" />
    </template>

    <div class="grid gap-4 lg:grid-cols-2">
      <UCard class="sp-app-card">
        <template #header>
          <div class="flex items-center gap-3">
            <UIcon name="i-lucide-message-circle" class="size-5 text-primary" />
            <div>
              <p class="font-semibold text-highlighted">Contact support</p>
              <p class="text-sm text-muted">Use the configured SP Cambo support channel.</p>
            </div>
          </div>
        </template>

        <p class="text-sm leading-6 text-muted">
          Include only the information needed to investigate: a masked API-key prefix/last four,
          order reference, request ID and approximate timestamp. Do not send credentials or payment secrets.
        </p>

        <div class="mt-4">
          <SpSupportLink label="Contact SP Cambo support" />
        </div>
      </UCard>

      <UCard class="sp-app-card">
        <template #header>
          <div class="flex items-center gap-3">
            <UIcon name="i-lucide-send" class="size-5 text-primary" />
            <div>
              <p class="font-semibold text-highlighted">Telegram Store</p>
              <p class="text-sm text-muted">Store, balance, orders, updates and language.</p>
            </div>
          </div>
        </template>

        <p class="text-sm leading-6 text-muted">
          The Telegram bot is the fastest place to review Store purchases and delivery status when your account is linked.
        </p>

        <UButton
          class="mt-4"
          color="neutral"
          variant="soft"
          icon="i-lucide-send"
          to="/dashboard/telegram"
          label="Telegram bot"
        />
      </UCard>
    </div>

    <UAlert
      class="mt-6"
      color="neutral"
      variant="subtle"
      icon="i-lucide-shield-check"
      title="Keep secrets private"
      description="Support can investigate with masked identifiers. Never send full API keys, passwords, Bakong tokens, provider credentials, Telegram bot tokens or payment transaction secrets."
    />
  </SpDashboardPage>
</template>
